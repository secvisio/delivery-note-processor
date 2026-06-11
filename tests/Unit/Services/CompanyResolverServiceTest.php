<?php

declare(strict_types=1);

use App\Models\CompanyAlias;
use App\Models\CompanyIdentity;
use App\Services\CompanyResolverService;
use App\Services\FileRenamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new CompanyResolverService();
});

/**
 * Build the delivery-note filename for a resolved company slug, exactly the
 * way DeliveryNoteProcessorService does, so the tests assert the real on-disk
 * name rather than just the slug. A fixed timestamp keeps the fallback case
 * deterministic.
 */
function filenameForSlug(?string $slug, string $id = '123'): string
{
    return FileRenamingService::generate([
        'delivery_note_id' => $id,
        'company_name' => $slug,
        'extension' => 'pdf',
        'timestamp' => '20260101000000',
    ]);
}

it('creates a new canonical identity for a trustworthy first scan', function () {
    $slug = $this->resolver->resolve('Company UX 123 GmbH', 0.95, 'Lieferschein von Company UX 123 GmbH, Musterstrasse 1');

    expect($slug)->toBe('company-ux-123-gmbh');
    expect(CompanyIdentity::count())->toBe(1);

    $identity = CompanyIdentity::first();
    expect($identity->canonical_name)->toBe('Company UX 123 GmbH')
        ->and($identity->rename_slug)->toBe('company-ux-123-gmbh')
        ->and((float)$identity->confidence_at_creation)->toBe(0.95);
    expect(CompanyAlias::where('company_identity_id', $identity->id)->count())->toBe(1);
});

it('maps a later near-identical scan onto the existing canonical slug', function () {
    $ocr = 'Lieferschein Company UX 123 GmbH Musterstrasse';
    $first = $this->resolver->resolve('Company UX 123 GmbH', 0.95, $ocr);

    // A later scan reads "128" instead of "123" — same real company.
    $later = $this->resolver->resolve('Company UX 128 GmbH', 0.95, 'Lieferschein Company UX 128 GmbH');

    expect($later)->toBe($first)
        ->and($later)->toBe('company-ux-123-gmbh');

    // Still a single identity, now with two learned aliases.
    expect(CompanyIdentity::count())->toBe(1);
    $identity = CompanyIdentity::first();
    expect(CompanyAlias::where('company_identity_id', $identity->id)->count())->toBe(2);
});

it('reuses the canonical slug for an exact repeat and bumps times_seen', function () {
    $ocr = 'Lieferschein Acme Logistics AG';
    $this->resolver->resolve('Acme Logistics AG', 0.95, $ocr);
    $this->resolver->resolve('Acme Logistics AG', 0.95, $ocr);

    $alias = CompanyAlias::where('normalized_name', 'acme logistics ag')->first();
    expect($alias)->not->toBeNull()
        ->and($alias->times_seen)->toBe(2);
    expect(CompanyIdentity::count())->toBe(1);
});

it('returns null for a low-confidence company with no existing identity', function () {
    $slug = $this->resolver->resolve('Some Company GmbH', 0.40, 'Lieferschein Some Company GmbH');

    expect($slug)->toBeNull();
    expect(CompanyIdentity::count())->toBe(0);
});

it('returns null when the extracted name has no evidence in the OCR text', function () {
    $slug = $this->resolver->resolve('Hallucinated Corp Ltd', 0.99, 'totally unrelated ocr content about widgets');

    expect($slug)->toBeNull();
    expect(CompanyIdentity::count())->toBe(0);
});

it('refuses to create an identity for generic junk', function () {
    foreach (['Lieferadresse', 'Kunde', 'Deutschland', 'Rechnung'] as $junk) {
        $slug = $this->resolver->resolve($junk, 0.99, "Lieferschein {$junk} Seite 1");
        expect($slug)->toBeNull();
    }

    expect(CompanyIdentity::count())->toBe(0);
});

it('rescues a low-confidence repeat when it strongly matches an existing identity', function () {
    // High-confidence first scan establishes the identity.
    $first = $this->resolver->resolve('Siegwerk Backnang GmbH', 0.95, 'Lieferschein Siegwerk Backnang GmbH');

    // A later LOW-confidence scan of the same company still resolves, because
    // an exact normalized match does not depend on the LLM's confidence.
    $later = $this->resolver->resolve('Siegwerk Backnang GmbH', 0.30, 'Lieferschein Siegwerk Backnang GmbH');

    expect($later)->toBe($first);
    expect(CompanyIdentity::count())->toBe(1);
});

it('treats word-reordered variants as the same company', function () {
    $first = $this->resolver->resolve('Company UX 123', 0.95, 'Lieferschein Company UX 123');
    $reordered = $this->resolver->resolve('Company 123 UX', 0.95, 'Lieferschein Company 123 UX');

    expect($reordered)->toBe($first);
    expect(CompanyIdentity::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| End-to-end filename assertions (resolver → FileRenamingService)
|--------------------------------------------------------------------------
|
| The scenarios below mirror the exact examples in the project spec and
| assert the FINAL filename, not just the canonical slug.
*/

it('first trusted scan produces a filename using company-ux-123', function () {
    $slug = $this->resolver->resolve('Company UX 123', 0.95, 'Lieferschein von Company UX 123, Musterstrasse 1');

    expect($slug)->toBe('company-ux-123')
        ->and(filenameForSlug($slug))->toBe('ls_123_company-ux-123.pdf');
    expect(CompanyIdentity::count())->toBe(1);
});

it('later company-ux-128 reuses canonical company-ux-123 and learns the alias', function () {
    $this->resolver->resolve('Company UX 123', 0.95, 'Lieferschein Company UX 123');
    $later = $this->resolver->resolve('Company UX 128', 0.95, 'Lieferschein Company UX 128');

    expect($later)->toBe('company-ux-123')
        ->and(filenameForSlug($later))->toBe('ls_123_company-ux-123.pdf');

    expect(CompanyIdentity::count())->toBe(1);
    expect(CompanyAlias::where('normalized_name', 'company ux 128')->exists())->toBeTrue();
});

it('token-reordered company-123-ux maps to canonical company-ux-123 filename', function () {
    $this->resolver->resolve('Company UX 123', 0.95, 'Lieferschein Company UX 123');
    $slug = $this->resolver->resolve('Company 123 UX', 0.95, 'Lieferschein Company 123 UX');

    expect($slug)->toBe('company-ux-123')
        ->and(filenameForSlug($slug))->toBe('ls_123_company-ux-123.pdf');
    expect(CompanyIdentity::count())->toBe(1);
});

it('low-confidence company produces an xxxxxx filename and creates no identity', function () {
    $slug = $this->resolver->resolve('Company UX 123', 0.40, 'Lieferschein Company UX 123');

    expect($slug)->toBeNull()
        ->and(filenameForSlug($slug))->toBe('ls_123_xxxxxx_20260101000000.pdf');
    expect(CompanyIdentity::count())->toBe(0);
});

it('hallucinated company (no OCR evidence) produces an xxxxxx filename', function () {
    $slug = $this->resolver->resolve('Globex Industries Ltd', 0.99, 'invoice for assorted widgets and screws');

    expect($slug)->toBeNull()
        ->and(filenameForSlug($slug))->toBe('ls_123_xxxxxx_20260101000000.pdf');
    expect(CompanyIdentity::count())->toBe(0);
});

it('rejects "Seite 1" and other generic junk as a company', function () {
    foreach (['Seite 1', 'Deutschland', 'Lieferadresse', 'Kunde'] as $junk) {
        expect($this->resolver->resolve($junk, 0.99, "Lieferschein {$junk}"))->toBeNull();
    }

    expect(CompanyIdentity::count())->toBe(0);
});
