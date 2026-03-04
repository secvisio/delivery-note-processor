<?php

namespace App\Livewire;

use App\Models\Process;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ProcessLog extends Component
{
    use WithPagination;

    /**
     * @var Process|null
     */
    public ?Process $item = null;

    /**
     * @var int|null
     */
    public ?int $selectedProcessId = null;

    /**
     * @var bool
     */
    public bool $showForm = false;

    /**
     * @var string
     */
    public string $mode = 'view'; // view | edit | delete

    protected string $paginationTheme = 'bootstrap';

    /**
     * finished | failed | all
     */
    public string $statusFilter = 'all';

    /**
     * @var bool
     */
    public bool $horizonActive = false;

    /**
     * @return void
     */
    public function mount(): void
    {

    }

    /**
     * @param int $id
     * @param string $column
     * @return void
     */
    public function view(int $id, string $column): void
    {
        $this->selectedProcessId = $id;
        $this->loadItem($id, $column, 'view');
    }

    /**
     * @param int $id
     * @param string $column
     * @param string $mode
     * @return void
     */
    private function loadItem(int $id, string $column, string $mode): void
    {
        $this->item = Process::query()->select(['id','company_name',$column])->where('id', $id)->first();

        if (!$this->item) {
            // Either show an error or just abort gracefully
            session()->flash('error', 'Process not found.');
            $this->showForm = false;
            return;
        }

        $this->mode = $mode;
        $this->showForm = true;
    }

    /**
     * @return void
     */
    public function closePanel(): void
    {
        $this->showForm = false;
        $this->mode = 'view';
        $this->item = null;
        $this->selectedProcessId = null;
    }

    /**
     * Reset page when filter changes
     */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection|null
     */
    public function running(): ? Collection
    {
        return Process::whereIn('status', ['pending', 'running'])
            ->orderByDesc('created_at')
            ->get();
    }


    /**
     * @return LengthAwarePaginator|null
     */
    public function finished(): ? LengthAwarePaginator
    {
        return Process::query()
            ->whereIn('status', ['finished', 'failed'])
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderByDesc('created_at')
            ->paginate(config('delivery_note_processor.delivery_notes_items_per_page'));
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('livewire.process-log', [
            'runningProcesses' => $this->running(),
            'finishedProcesses' => $this->finished(),
        ]);
    }
}
