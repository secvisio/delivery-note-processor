<?php

declare(strict_types=1);

use App\Services\FileConverterService;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

beforeEach(function () {
    Storage::fake('delivery_notes');
});

it('throws when conversion source type is not supported', function () {
    $service = new FileConverterService('converted');

    expect(fn () => $service->run('incoming/file.tif', 'tif'))
        ->toThrow(Exception::class, 'No conversion source type given. e.g. "pdf"');
});
