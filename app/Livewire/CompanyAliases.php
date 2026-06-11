<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompanyAlias;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only management list of learned company aliases (the raw OCR/LLM
 * company-name variants mapped to a canonical identity). Display only — the
 * matching history must not be mutated from the UI.
 */
#[Layout('layouts.app')]
class CompanyAliases extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public function render(): View
    {
        return view('livewire.company-aliases', [
            'aliases' => CompanyAlias::query()
                ->with('identity')
                ->orderByDesc('last_seen_at')
                ->paginate(config('delivery_note_processor.delivery_notes_items_per_page')),
        ]);
    }
}
