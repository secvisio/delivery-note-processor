<?php

declare(strict_types=1);

use App\Services\FilenameNormalizer;
use App\Services\FileRenamingService;

uses(Tests\TestCase::class);

it('builds the canonical ls_{id}_{company}.ext when both are present', function () {
    expect(FileRenamingService::generate([
        'delivery_note_id' => '8532',
        'company_name' => 'Grafisk Maskinfabrik A/S',
        'extension' => 'pdf',
    ]))->toBe('ls_8532_grafisk-maskinfabrik-a-s.pdf');
});

it('strips dashes from the id but keeps them in the company slug', function () {
    expect(FileRenamingService::generate([
        'delivery_note_id' => 'P22-04/61',
        'company_name' => 'Siegwerk Backnang GmbH',
        'extension' => 'pdf',
    ]))->toBe('ls_p220461_siegwerk-backnang-gmbh.pdf');
});

it('appends a timestamp when the company is missing', function () {
    expect(FileRenamingService::generate([
        'delivery_note_id' => '123456P',
        'company_name' => null,
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('ls_123456p_xxxxxx_20260505124055.pdf');
});

it('appends a timestamp when the delivery id is missing', function () {
    expect(FileRenamingService::generate([
        'delivery_note_id' => null,
        'company_name' => 'The Company',
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('ls_xxxxxx_the-company_20260505124055.pdf');
});

it('uses xxxxxx for both segments and appends a timestamp when both are missing', function () {
    expect(FileRenamingService::generate([
        'delivery_note_id' => null,
        'company_name' => null,
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('ls_xxxxxx_xxxxxx_20260505124055.pdf');
});

it('treats whitespace-only and symbol-only segments as missing', function () {
    expect(FileRenamingService::generate([
        'delivery_note_id' => '   ',
        'company_name' => '***',
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('ls_xxxxxx_xxxxxx_20260505124055.pdf');
});

it('handles slashes, ampersands, dots, commas and umlauts safely', function () {
    expect(FilenameNormalizer::sanitizeCompanyName('Grafisk Maskinfabrik A/S'))->toBe('grafisk-maskinfabrik-a-s')
        ->and(FilenameNormalizer::sanitizeCompanyName('GmbH & Co. KG'))->toBe('gmbh-co-kg')
        ->and(FilenameNormalizer::sanitizeCompanyName('Müller-Lüdenscheidt'))->toBe('muller-ludenscheidt')
        ->and(FilenameNormalizer::sanitizeCompanyName('Foo, Bar Inc.'))->toBe('foo-bar-inc')
        ->and(FilenameNormalizer::sanitizeCompanyName('Foo\\Bar Inc'))->toBe('foo-bar-inc');
});

it('strips non-alphanumerics from identifiers without inserting separators', function () {
    expect(FilenameNormalizer::sanitizeIdentifier('P22-04/61'))->toBe('p220461')
        ->and(FilenameNormalizer::sanitizeIdentifier(' 8532 '))->toBe('8532')
        ->and(FilenameNormalizer::sanitizeIdentifier(null))->toBe('')
        ->and(FilenameNormalizer::sanitizeIdentifier('***'))->toBe('');
});

it('whitelists extensions and falls back to pdf', function () {
    expect(FilenameNormalizer::sanitizeExtension('PDF'))->toBe('pdf')
        ->and(FilenameNormalizer::sanitizeExtension('jpeg'))->toBe('jpeg')
        ->and(FilenameNormalizer::sanitizeExtension('php'))->toBe('pdf')
        ->and(FilenameNormalizer::sanitizeExtension(null))->toBe('pdf')
        ->and(FilenameNormalizer::sanitizeExtension(''))->toBe('pdf');
});

it('always emits the literal ls_ prefix even with hostile inputs', function () {
    $hostile = [
        ['id' => 'A/S', 'company' => 'Foo\\Bar'],
        ['id' => null, 'company' => 'Drop:Tables'],
        ['id' => '../../etc/passwd', 'company' => '***'],
        ['id' => 'Müller*?<>"|', 'company' => 'Acme & Co.'],
        ['id' => '', 'company' => ''],
    ];

    foreach ($hostile as $row) {
        $filename = FileRenamingService::generate([
            'delivery_note_id' => $row['id'],
            'company_name' => $row['company'],
            'extension' => 'pdf',
            'timestamp' => '20260505124055',
        ]);

        expect($filename)
            ->toStartWith('ls_')
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

it('does not append a timestamp when both id and company are present', function () {
    $filename = FileRenamingService::generate([
        'delivery_note_id' => '8532',
        'company_name' => 'Grafisk Maskinfabrik A/S',
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]);

    expect($filename)
        ->toBe('ls_8532_grafisk-maskinfabrik-a-s.pdf')
        ->not->toContain('20260505124055');
});

it('is byte-for-byte idempotent across repeated calls', function () {
    $data = [
        'delivery_note_id' => 'P22-04/61',
        'company_name' => 'Müller GmbH & Co. KG',
        'extension' => 'PDF',
        'timestamp' => '20260505124055',
    ];

    $first = FileRenamingService::generate($data);

    foreach (range(1, 50) as $_) {
        expect(FileRenamingService::generate($data))->toBe($first);
    }
});

it('reports isFallback true only when both id and company are unusable', function () {
    expect(FileRenamingService::isFallback([
        'delivery_note_id' => null,
        'company_name' => '',
    ]))->toBeTrue()
        ->and(FileRenamingService::isFallback([
            'delivery_note_id' => '8532',
            'company_name' => null,
        ]))->toBeFalse()
        ->and(FileRenamingService::isFallback([
            'delivery_note_id' => null,
            'company_name' => 'Acme',
        ]))->toBeFalse();
});

it('rejects malformed timestamps and falls back to wall clock', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::create(2026, 5, 5, 12, 40, 55));

    expect(FileRenamingService::generate([
        'delivery_note_id' => null,
        'company_name' => null,
        'extension' => 'pdf',
        'timestamp' => 'not-a-timestamp',
    ]))->toBe('ls_xxxxxx_xxxxxx_20260505124055.pdf');

    Carbon\Carbon::setTestNow();
});

it('builds PA_{auftrag}_{produktion}.ext when both production-order values are present', function () {
    expect(FileRenamingService::generateProductionOrder([
        'auftragsnummer' => '123456',
        'produktion' => '98765',
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('PA_123456_98765.pdf');
});

it('uses xxxxxx for a missing Auftrag without appending a timestamp', function () {
    expect(FileRenamingService::generateProductionOrder([
        'auftragsnummer' => null,
        'produktion' => '98765',
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('PA_xxxxxx_98765.pdf');
});

it('uses xxxxxx for a missing Produktion without appending a timestamp', function () {
    expect(FileRenamingService::generateProductionOrder([
        'auftragsnummer' => '123456',
        'produktion' => null,
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('PA_123456_xxxxxx.pdf');
});

it('appends a timestamp only when BOTH production-order values are missing', function () {
    expect(FileRenamingService::generateProductionOrder([
        'auftragsnummer' => null,
        'produktion' => null,
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('PA_xxxxxx_xxxxxx_20260505124055.pdf');
});

it('treats whitespace-only and symbol-only production-order values as missing', function () {
    expect(FileRenamingService::generateProductionOrder([
        'auftragsnummer' => '   ',
        'produktion' => '***',
        'extension' => 'pdf',
        'timestamp' => '20260505124055',
    ]))->toBe('PA_xxxxxx_xxxxxx_20260505124055.pdf');
});

it('keeps the original (whitelisted) extension for production orders', function () {
    expect(FileRenamingService::generateProductionOrder([
        'auftragsnummer' => '123456',
        'produktion' => '98765',
        'extension' => 'JPG',
    ]))->toBe('PA_123456_98765.jpg');
});

it('always emits the literal PA_ prefix for production orders even with hostile inputs', function () {
    $hostile = [
        ['auftrag' => 'A/S', 'produktion' => '../../etc/passwd'],
        ['auftrag' => null, 'produktion' => 'Drop:Tables'],
        ['auftrag' => 'Müller*?<>"|', 'produktion' => 'Acme & Co.'],
        ['auftrag' => '', 'produktion' => ''],
    ];

    foreach ($hostile as $row) {
        $filename = FileRenamingService::generateProductionOrder([
            'auftragsnummer' => $row['auftrag'],
            'produktion' => $row['produktion'],
            'extension' => 'pdf',
            'timestamp' => '20260505124055',
        ]);

        expect($filename)
            ->toStartWith('PA_')
            ->not->toContain('/')
            ->not->toContain('\\')
            ->not->toContain(':')
            ->not->toContain('|')
            ->not->toContain('*')
            ->not->toContain('?')
            ->not->toContain('<')
            ->not->toContain('>')
            ->not->toContain('..');
    }
});
