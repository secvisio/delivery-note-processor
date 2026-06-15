<div>
    <div class="row justify-content-center pt-4">
        <div class="col-md-2 col-lg-2 col-sm-12">
            <div class="sticky-top">
                @include('partials.company-nav', ['active' => 'companies'])
            </div>
        </div>

        <div class="col-md-10 col-lg-10 col-sm-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('default.Companies') }}</h5>
                    <div>{{ $companies->links() }}</div>
                </div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success py-2">{{ session('status') }}</div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" style="font-size: .9em;">
                            <thead>
                            <tr>
                                <th>{{ __('default.ID') }}</th>
                                <th>{{ __('default.Canonical Name') }}</th>
                                <th>{{ __('default.Rename Slug') }}</th>
                                <th>{{ __('default.Normalized Name') }}</th>
                                <th class="text-end">{{ __('default.Aliases') }}</th>
                                <th class="text-end">{{ __('default.Confidence') }}</th>
                                <th>{{ __('default.First Seen') }}</th>
                                <th>{{ __('default.Last Seen') }}</th>
                                <th>{{ __('default.Created At') }}</th>
                                <th>{{ __('default.Updated At') }}</th>
                                <th class="text-end">{{ __('default.Actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($companies as $company)
                                <tr wire:key="company-{{ $company->id }}">
                                    <td>{{ $company->id }}</td>
                                    <td>
                                        @if ($editingId === $company->id)
                                            <input
                                                    type="text"
                                                    class="form-control form-control-sm @error('editName') is-invalid @enderror"
                                                    wire:model="editName"
                                                    wire:keydown.enter="save"
                                            >
                                            @error('editName')
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                            @enderror
                                        @else
                                            {{ $company->canonical_name }}
                                        @endif
                                    </td>
                                    <td><code>{{ $company->rename_slug }}</code></td>
                                    <td class="text-muted">{{ $company->normalized_canonical }}</td>
                                    <td class="text-end">{{ $company->aliases_count }}</td>
                                    <td class="text-end">
                                        {{ $company->confidence_at_creation !== null ? number_format((float) $company->confidence_at_creation * 100, 0) . '%' : '—' }}
                                    </td>
                                    <td>{{ optional($company->first_seen_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>{{ optional($company->last_seen_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>{{ optional($company->created_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>{{ optional($company->updated_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td class="text-end">
                                        @if ($editingId === $company->id)
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-success" wire:click="save"
                                                        wire:loading.attr="disabled">
                                                    <i class="fa-solid fa-check"></i> {{ __('default.Save') }}
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        wire:click="cancel">
                                                    {{ __('default.Cancel') }}
                                                </button>
                                            </div>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    wire:click="edit({{ $company->id }})">
                                                <i class="fa-solid fa-pen-to-square"></i> {{ __('default.Edit') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11"
                                        class="text-center text-muted">{{ __('default.No companies yet.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-2">{{ $companies->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
