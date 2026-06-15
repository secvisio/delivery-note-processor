<?php

declare(strict_types=1);

use App\Livewire\Companies;
use App\Models\CompanyAlias;
use App\Models\CompanyIdentity;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(Tests\TestCase::class, RefreshDatabase::class);

function makeIdentityWithAlias(array $overrides = []): CompanyIdentity
{
    $identity = CompanyIdentity::create(array_merge([
        'canonical_name' => 'Company UX 123',
        'rename_slug' => 'company-ux-123',
        'normalized_canonical' => 'company ux 123',
        'created_from_raw_name' => 'Company UX 123',
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now(),
        'confidence_at_creation' => 0.95,
    ], $overrides));

    CompanyAlias::create([
        'company_identity_id' => $identity->id,
        'raw_name' => 'Company UX 128',
        'normalized_name' => 'company ux 128',
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now(),
        'times_seen' => 2,
        'match_confidence' => 0.93,
        'source' => 'fuzzy',
    ]);

    return $identity;
}

it('renders the companies page with the canonical record', function () {
    makeIdentityWithAlias();

    $this->get(route('companies'))
        ->assertOk()
        ->assertSee('Company UX 123')
        ->assertSee('company-ux-123');
});

it('renders the company aliases page read-only', function () {
    makeIdentityWithAlias();

    $this->get(route('company-aliases'))
        ->assertOk()
        ->assertSee('Company UX 128')
        ->assertSee('company ux 128');
});

it('updates canonical name and regenerates the rename slug and normalized key', function () {
    $identity = makeIdentityWithAlias();

    Livewire::test(Companies::class)
        ->call('edit', $identity->id)
        ->set('editName', 'Company UX 123 GmbH')
        ->call('save')
        ->assertHasNoErrors();

    $identity->refresh();
    expect($identity->canonical_name)->toBe('Company UX 123 GmbH')
        ->and($identity->rename_slug)->toBe('company-ux-123-gmbh')
        ->and($identity->normalized_canonical)->toBe('company ux 123 gmbh');
});

it('keeps existing aliases pointing at the same identity after an edit', function () {
    $identity = makeIdentityWithAlias();
    $aliasId = CompanyAlias::first()->id;

    Livewire::test(Companies::class)
        ->call('edit', $identity->id)
        ->set('editName', 'Renamed Company')
        ->call('save')
        ->assertHasNoErrors();

    $alias = CompanyAlias::find($aliasId);
    expect(CompanyAlias::count())->toBe(1)
        ->and($alias->company_identity_id)->toBe($identity->id)
        ->and($alias->raw_name)->toBe('Company UX 128')
        ->and($alias->normalized_name)->toBe('company ux 128')
        ->and($alias->times_seen)->toBe(2);
});

it('does not rename already processed files when a company is edited', function () {
    $identity = makeIdentityWithAlias();

    $process = Process::create([
        'source_file_path' => 'source/scan.pdf',
        'source_file_hash' => 'h',
        'target_file_path' => 'target/ls_123_company-ux-123.pdf',
        'status' => 'finished',
    ]);

    Livewire::test(Companies::class)
        ->call('edit', $identity->id)
        ->set('editName', 'Company UX 123 GmbH')
        ->call('save')
        ->assertHasNoErrors();

    // The historical Process row (and its target filename) is untouched;
    // the new slug only affects FUTURE renames.
    expect($process->fresh()->target_file_path)->toBe('target/ls_123_company-ux-123.pdf');
});

it('rejects an empty company name', function () {
    $identity = makeIdentityWithAlias();

    Livewire::test(Companies::class)
        ->call('edit', $identity->id)
        ->set('editName', '   ')
        ->call('save')
        ->assertHasErrors(['editName']);

    expect($identity->fresh()->canonical_name)->toBe('Company UX 123');
});

it('rejects a name that does not survive filename sanitization', function () {
    $identity = makeIdentityWithAlias();

    Livewire::test(Companies::class)
        ->call('edit', $identity->id)
        ->set('editName', '***')
        ->call('save')
        ->assertHasErrors(['editName']);

    expect($identity->fresh()->rename_slug)->toBe('company-ux-123');
});

it('paginates the companies list', function () {
    for ($i = 1; $i <= 12; $i++) {
        CompanyIdentity::create([
            'canonical_name' => "Company {$i}",
            'rename_slug' => "company-{$i}",
            'normalized_canonical' => "company {$i}",
            'first_seen_at' => now(),
            'last_seen_at' => now()->subMinutes($i),
            'confidence_at_creation' => 0.9,
        ]);
    }

    // Default per-page is 5 → 12 rows must span multiple pages.
    Livewire::test(Companies::class)
        ->assertViewHas('companies', fn($companies) => $companies->total() === 12 && $companies->count() <= 5);
});
