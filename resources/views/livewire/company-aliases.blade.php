<div>
    <div class="row justify-content-center pt-4">
        <div class="col-md-2 col-lg-2 col-sm-12">
            <div class="sticky-top">
                @include('partials.company-nav', ['active' => 'aliases'])
            </div>
        </div>

        <div class="col-md-10 col-lg-10 col-sm-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('default.Company Aliases') }}</h5>
                    <div>{{ $aliases->links() }}</div>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" style="font-size: .9em;">
                            <thead>
                            <tr>
                                <th>{{ __('default.ID') }}</th>
                                <th>{{ __('default.Company') }}</th>
                                <th>{{ __('default.Raw OCR Name') }}</th>
                                <th>{{ __('default.Normalized Name') }}</th>
                                <th>{{ __('default.Source') }}</th>
                                <th class="text-end">{{ __('default.Match Confidence') }}</th>
                                <th class="text-end">{{ __('default.Times Seen') }}</th>
                                <th>{{ __('default.First Seen') }}</th>
                                <th>{{ __('default.Last Seen') }}</th>
                                <th>{{ __('default.Created At') }}</th>
                                <th>{{ __('default.Updated At') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($aliases as $alias)
                                <tr wire:key="alias-{{ $alias->id }}">
                                    <td>{{ $alias->id }}</td>
                                    <td>
                                        @if ($alias->identity)
                                            {{ $alias->identity->canonical_name }}
                                            <span class="text-muted">(#{{ $alias->company_identity_id }})</span>
                                        @else
                                            <span class="text-muted">#{{ $alias->company_identity_id }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $alias->raw_name }}</td>
                                    <td class="text-muted">{{ $alias->normalized_name }}</td>
                                    <td>{{ $alias->source ?? '—' }}</td>
                                    <td class="text-end">
                                        {{ $alias->match_confidence !== null ? number_format((float) $alias->match_confidence * 100, 0) . '%' : '—' }}
                                    </td>
                                    <td class="text-end">{{ $alias->times_seen }}</td>
                                    <td>{{ optional($alias->first_seen_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>{{ optional($alias->last_seen_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>{{ optional($alias->created_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td>{{ optional($alias->updated_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11"
                                        class="text-center text-muted">{{ __('default.No aliases yet.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-2">{{ $aliases->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
