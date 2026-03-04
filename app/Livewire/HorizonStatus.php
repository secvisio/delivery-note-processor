<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HorizonStatus extends Component
{
    /**
     * @var bool
     */
    public bool $horizonActive = false;

    /**
     * @return bool
     */
    public function getHorizonStatusProperty(): bool
    {
        $masters = app(MasterSupervisorRepository::class)->all();
        return count($masters) > 0;
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $this->horizonActive = $this->getHorizonStatusProperty();

        return view('livewire.horizon-status');
    }
}
