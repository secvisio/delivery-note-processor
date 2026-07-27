<?php

declare(strict_types=1);

use App\Services\FilenameNormalizer;
use App\Services\FileRenamingService;

uses(Tests\TestCase::class);

/*
|--------------------------------------------------------------------------
| Order-number normalization
|--------------------------------------------------------------------------
|
| The structural hyphen in `260630-01` must survive, which is why the value
| is NOT routed through sanitizeIdentifier() (that strips non-alphanumerics).
*/

it('keeps the structural hyphen of a well-formed order number', function () {
    expect(FilenameNormalizer::sanitizeOrderNumber('260630-01'))->toBe('260630-01');
});

it('collapses OCR spacing variants of the order number onto one form', function (string $input) {
    expect(FilenameNormalizer::sanitizeOrderNumber($input))->toBe('260630-01');
})->with([
    '260630 - 01',
    '260630- 01',
    '260630 -01',
    '  260630-01  ',
]);

it('re-inserts a hyphen that OCR dropped from the order number', function () {
    expect(FilenameNormalizer::sanitizeOrderNumber('26063001'))->toBe('260630-01');
});

it('normalizes unicode dashes in the order number to an ascii hyphen', function () {
    expect(FilenameNormalizer::sanitizeOrderNumber("260630\u{2013}01"))->toBe('260630-01');
});

it('rejects order numbers that do not match the expected shape', function (?string $input) {
    expect(FilenameNormalizer::sanitizeOrderNumber($input))->toBe('');
})->with([
    null,
    '',
    'abc',
    '2606-01',        // too few leading digits
    '2606300-01',     // too many leading digits
    '260630-1',       // too few trailing digits
    'AB1234-01',      // not numeric
]);

it('does not let sanitizeIdentifier be used for order numbers', function () {
    // Guards the reason the dedicated normalizer exists at all.
    expect(FilenameNormalizer::sanitizeIdentifier('260630-01'))->toBe('26063001')
        ->and(FilenameNormalizer::sanitizeOrderNumber('260630-01'))->toBe('260630-01');
});

/*
|--------------------------------------------------------------------------
| Pickup-date normalization
|--------------------------------------------------------------------------
*/

it('normalizes the accepted pickup-date formats to YYYY-MM-DD', function (string $input) {
    expect(FilenameNormalizer::sanitizePickupDate($input))->toBe('2026-06-30');
})->with([
    '2026-06-30',
    '2026.06.30',
    '2026/06/30',
    '30.06.2026',
    '30/06/2026',
    '30-06-2026',
    ' 30.06.2026 ',
]);

it('rejects pickup dates that are not real calendar dates', function (?string $input) {
    expect(FilenameNormalizer::sanitizePickupDate($input))->toBe('');
})->with([
    null,
    '',
    '31.02.2026',   // February has no 31st — must NOT roll over into March
    '2026-13-01',
    '2026-00-10',
    'Abholdatum',
    '30.06.26',     // two-digit year is not an accepted explicit format
]);

/*
|--------------------------------------------------------------------------
| The order number is NEVER a date source
|--------------------------------------------------------------------------
|
| The leading digits of `260630-01` resemble a YYMMDD date, but that is a
| coincidence: they belong to the order number and carry no pickup-date
| meaning. Nothing in the normalizer may turn them into one.
*/

it('exposes no way to turn an order number into a date', function () {
    expect(method_exists(FilenameNormalizer::class, 'pickupDateFromOrderNumber'))->toBeFalse();
});

it('does not read an order number as a pickup date', function (string $order) {
    expect(FilenameNormalizer::sanitizePickupDate($order))->toBe('');
})->with([
    '260630-01',
    '260701-15',
    '260915-03',
    '260630',
    '26063001',
]);

/*
|--------------------------------------------------------------------------
| Frachtbrief filename generation
|--------------------------------------------------------------------------
*/

it('builds the canonical FB_{company}_{date}_{order}.ext when all three are present', function () {
    expect(FileRenamingService::generateFrachtbrief('Musterfirma', '2026-06-30', '260630-01', 'pdf'))
        ->toBe('FB_musterfirma_2026-06-30_260630-01.pdf');
});

it('slugs a multi-word recipient company the same way delivery notes do', function () {
    expect(FileRenamingService::generateFrachtbrief('Musterfirma GmbH', '2026-06-30', '260630-01', 'pdf'))
        ->toBe('FB_musterfirma-gmbh_2026-06-30_260630-01.pdf');
});

it('preserves the order-number hyphen in the generated filename', function () {
    $filename = FileRenamingService::generateFrachtbrief('Musterfirma', '2026-06-30', '260630 - 01', 'pdf');

    expect($filename)->toBe('FB_musterfirma_2026-06-30_260630-01.pdf')
        ->and($filename)->toContain('260630-01');
});

it('appends a timestamp when the company segment falls back', function () {
    expect(FileRenamingService::generateFrachtbrief(null, '2026-06-30', '260630-01', 'pdf', '20260724143025'))
        ->toBe('FB_xxxxxx_2026-06-30_260630-01_20260724143025.pdf');
});

it('appends a timestamp when the pickup-date segment falls back', function () {
    expect(FileRenamingService::generateFrachtbrief('Musterfirma', null, '260630-01', 'pdf', '20260724143025'))
        ->toBe('FB_musterfirma_xxxxxx_260630-01_20260724143025.pdf');
});

it('appends a timestamp when the order-number segment falls back', function () {
    expect(FileRenamingService::generateFrachtbrief('Musterfirma', '2026-06-30', null, 'pdf', '20260724143025'))
        ->toBe('FB_musterfirma_2026-06-30_xxxxxx_20260724143025.pdf');
});

it('renders every segment as the placeholder when nothing could be extracted', function () {
    expect(FileRenamingService::generateFrachtbrief(null, null, null, 'pdf', '20260724143025'))
        ->toBe('FB_xxxxxx_xxxxxx_xxxxxx_20260724143025.pdf');
});

it('falls back to the wall clock when no timestamp is supplied for a partial filename', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::create(2026, 7, 24, 14, 30, 25));

    expect(FileRenamingService::generateFrachtbrief(null, '2026-06-30', '260630-01', 'pdf'))
        ->toBe('FB_xxxxxx_2026-06-30_260630-01_20260724143025.pdf');

    Carbon\Carbon::setTestNow();
});

it('restricts the frachtbrief extension to the existing whitelist', function () {
    expect(FileRenamingService::generateFrachtbrief('Musterfirma', '2026-06-30', '260630-01', 'php'))
        ->toBe('FB_musterfirma_2026-06-30_260630-01.pdf')
        ->and(FileRenamingService::generateFrachtbrief('Musterfirma', '2026-06-30', '260630-01', 'JPG'))
        ->toBe('FB_musterfirma_2026-06-30_260630-01.jpg');
});

it('accepts already-normalized values idempotently', function () {
    $once = FileRenamingService::generateFrachtbrief('musterfirma', '2026-06-30', '260630-01', 'pdf');

    expect($once)->toBe('FB_musterfirma_2026-06-30_260630-01.pdf');
});
