<?php

declare(strict_types=1);

use App\Services\FilenameGenerator;
use App\Services\FilenameNormalizer;

uses(Tests\TestCase::class);

it('strips path separators from company names so A/S cannot break the path', function () {
    expect(FilenameNormalizer::sanitizeCompanyName('Grafisk Maskinfabrik A/S'))
        ->toBe('grafisk_maskinfabrik_as')
        ->and(FilenameNormalizer::sanitizeCompanyName('Foo\\Bar Inc'))
        ->toBe('foobar_inc');
});

it('normalizes ampersands, dots, and umlauts deterministically', function () {
    expect(FilenameNormalizer::sanitizeCompanyName('GmbH & Co. KG'))->toBe('gmbh_co_kg')
        ->and(FilenameNormalizer::sanitizeCompanyName('Müller-Lüdenscheidt'))->toBe('muller_ludenscheidt')
        ->and(FilenameNormalizer::sanitizeCompanyName('Kärcher GmbH'))->toBe('karcher_gmbh');
});

it('collapses multiple separators and trims edges', function () {
    expect(FilenameNormalizer::sanitizeCompanyName('  Foo  ;  Bar  ::  Baz...  '))
        ->toBe('foo_bar_baz');
});

it('returns empty string for empty or whitespace-only input', function () {
    expect(FilenameNormalizer::sanitizeCompanyName(null))->toBe('')
        ->and(FilenameNormalizer::sanitizeCompanyName(''))->toBe('')
        ->and(FilenameNormalizer::sanitizeCompanyName('   '))->toBe('')
        ->and(FilenameNormalizer::sanitizeCompanyName('***'))->toBe('');
});

it('strips non-alphanumerics from identifiers without inserting separators', function () {
    expect(FilenameNormalizer::sanitizeIdentifier('P22-04/61'))->toBe('p220461')
        ->and(FilenameNormalizer::sanitizeIdentifier(' 8532 '))->toBe('8532');
});

it('whitelists extensions and falls back to pdf', function () {
    expect(FilenameNormalizer::sanitizeExtension('PDF'))->toBe('pdf')
        ->and(FilenameNormalizer::sanitizeExtension('jpeg'))->toBe('jpeg')
        ->and(FilenameNormalizer::sanitizeExtension('php'))->toBe('pdf')
        ->and(FilenameNormalizer::sanitizeExtension(null))->toBe('pdf')
        ->and(FilenameNormalizer::sanitizeExtension(''))->toBe('pdf');
});

it('builds the canonical id+company filename', function () {
    expect(FilenameGenerator::generate([
        'delivery_note_id' => '8532',
        'company_name' => 'Grafisk Maskinfabrik A/S',
        'extension' => 'pdf',
        'fallback_token' => 'irrelevant',
    ]))->toBe('ls_8532_grafisk_maskinfabrik_as.pdf');
});

it('drops the company segment when only the id is known', function () {
    expect(FilenameGenerator::generate([
        'delivery_note_id' => 'P220461',
        'company_name' => null,
        'extension' => 'pdf',
        'fallback_token' => 'h',
    ]))->toBe('ls_p220461.pdf');
});

it('drops the id segment when only the company is known', function () {
    expect(FilenameGenerator::generate([
        'delivery_note_id' => null,
        'company_name' => 'GmbH & Co. KG',
        'extension' => 'pdf',
        'fallback_token' => 'h',
    ]))->toBe('ls_gmbh_co_kg.pdf');
});

it('uses the fallback token only when both id and company are missing', function () {
    expect(FilenameGenerator::generate([
        'delivery_note_id' => null,
        'company_name' => null,
        'extension' => 'pdf',
        'fallback_token' => 'a1b2c3d4',
    ]))->toBe('ls_a1b2c3d4.pdf');
});

it('falls back to "unknown" when both inputs and the fallback token are missing', function () {
    expect(FilenameGenerator::generate([
        'delivery_note_id' => null,
        'company_name' => null,
        'extension' => 'pdf',
        'fallback_token' => null,
    ]))->toBe('ls_unknown.pdf');
});

it('is byte-for-byte idempotent across repeated calls', function () {
    $data = [
        'delivery_note_id' => 'P22-04/61',
        'company_name' => 'Müller GmbH & Co. KG',
        'extension' => 'PDF',
        'fallback_token' => 'sha256-stub',
    ];

    $first = FilenameGenerator::generate($data);

    foreach (range(1, 50) as $_) {
        expect(FilenameGenerator::generate($data))->toBe($first);
    }
});

it('reports isFallback true only when both id and company are unusable', function () {
    expect(FilenameGenerator::isFallback([
        'delivery_note_id' => null,
        'company_name' => '',
        'fallback_token' => 'h',
    ]))->toBeTrue();

    expect(FilenameGenerator::isFallback([
        'delivery_note_id' => '8532',
        'company_name' => null,
    ]))->toBeFalse();

    expect(FilenameGenerator::isFallback([
        'delivery_note_id' => null,
        'company_name' => 'Acme',
    ]))->toBeFalse();
});

it('never produces a filename containing path separators or windows-reserved chars', function () {
    $hostile = [
        'A/S',
        'Foo\\Bar',
        'Drop:Tables',
        'a;b|c',
        'Acme & Co.',
        '../../etc/passwd',
        'Müller*?<>"|',
    ];

    foreach ($hostile as $name) {
        $filename = FilenameGenerator::generate([
            'delivery_note_id' => '1',
            'company_name' => $name,
            'extension' => 'pdf',
            'fallback_token' => 'h',
        ]);

        expect($filename)
            ->not->toContain('/')
            ->not->toContain('\\')
            ->not->toContain(':')
            ->not->toContain(';')
            ->not->toContain('|')
            ->not->toContain('*')
            ->not->toContain('?')
            ->not->toContain('<')
            ->not->toContain('>')
            ->not->toContain('"')
            ->not->toContain('..')
            ->not->toContain('__');
    }
});
