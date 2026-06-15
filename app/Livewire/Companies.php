<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompanyIdentity;
use App\Services\CompanyNameNormalizer;
use App\Services\FilenameNormalizer;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Management list for canonical company identities.
 *
 * The only editable value is the human-readable canonical name. Saving it
 * regenerates the filename-safe `rename_slug` (the value the renamer actually
 * uses for FUTURE files) and the `normalized_canonical` match key, so future
 * scans keep resolving consistently. It never touches alias rows and never
 * renames already-processed files.
 */
#[Layout('layouts.app')]
class Companies extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public ?int $editingId = null;

    public string $editName = '';

    /**
     * Begin inline-editing the canonical name of one identity.
     */
    public function edit(int $id): void
    {
        $identity = CompanyIdentity::query()->findOrFail($id);

        $this->editingId = $identity->id;
        $this->editName = (string)$identity->canonical_name;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->editName = '';
        $this->resetErrorBag();
    }

    /**
     * Persist the edited canonical name and regenerate the derived
     * filename-safe slug + match key. Aliases and processed files are left
     * untouched; only future renaming is affected.
     */
    public function save(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($validated['editName']);
        $slug = FilenameNormalizer::sanitizeCompanyName($name);

        // The name must survive sanitization as a usable filename segment;
        // otherwise the rename_slug would silently collapse to empty.
        if ($slug === '') {
            $this->addError('editName', __('default.The company name does not produce a valid filename.'));
            return;
        }

        $identity = CompanyIdentity::query()->findOrFail($this->editingId);
        $identity->canonical_name = $name;
        $identity->rename_slug = $slug;
        $identity->normalized_canonical = (new CompanyNameNormalizer())->normalize($name);
        $identity->save();

        $this->cancel();
        session()->flash('status', __('default.Company updated.'));
    }

    public function render(): View
    {
        return view('livewire.companies', [
            'companies' => CompanyIdentity::query()
                ->withCount('aliases')
                ->orderByDesc('last_seen_at')
                ->paginate(config('delivery_note_processor.delivery_notes_items_per_page')),
        ]);
    }
}
