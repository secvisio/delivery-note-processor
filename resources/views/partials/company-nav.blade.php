@php($active = $active ?? null)
<div class="card shadow-sm mt-3">
    <div class="card-header">
        <h6 class="mb-0">{{ __('default.Management') }}</h6>
    </div>
    <div class="list-group list-group-flush">
        <a href="{{ route('dashboard') }}"
           class="list-group-item list-group-item-action {{ $active === 'dashboard' ? 'active' : '' }}">
            <i class="fa-solid fa-gauge me-2"></i>{{ __('default.Process Log') }}
        </a>
        <a href="{{ route('companies') }}"
           class="list-group-item list-group-item-action {{ $active === 'companies' ? 'active' : '' }}">
            <i class="fa-solid fa-building me-2"></i>{{ __('default.Companies') }}
        </a>
        <a href="{{ route('company-aliases') }}"
           class="list-group-item list-group-item-action {{ $active === 'aliases' ? 'active' : '' }}">
            <i class="fa-solid fa-tags me-2"></i>{{ __('default.Company Aliases') }}
        </a>
    </div>
</div>
