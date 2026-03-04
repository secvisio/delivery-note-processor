<div>
    <div class="row justify-content-center pt-4">
        <div class="col-md-2 col-lg-2 col-sm-12">

            <div class="sticky-top" wire:ignore>
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('default.Upload Delivery-Notes') }}</h5>
                        <h6 class="mb-0">{{ __('default.(PDF or Image File)') }}</h6>
                    </div>

                    <div class="card-body">

                        <div id="alert-success" class="alert alert-success d-none"></div>
                        <div id="alert-error" class="alert alert-danger d-none"></div>

                        <form id="upload-form">

                            <div id="drop-zone" class="drop-zone mb-3">
                                <strong>{{ __('default.Drag & drop files here') }}</strong><br>
                                {{ __('default.or click to select files') }}
                            </div>

                            <input type="file" id="delivery-note" name="delivery-note[]" multiple hidden>

                            <div class="preview mb-3" id="preview"></div>

                            <div class="progress mb-3 d-none" id="progress-container">
                                <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
                            </div>

                            <button type="submit" id="uploadBtn" class="btn btn-primary w-100">
                                {{ __('default.Upload') }}
                            </button>
                        </form>

                    </div>
                </div>
            </div>

        </div>

        <div class="col-md-6 col-lg-6 col-sm-12">

            <div class="card">

                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('default.Process Log') }}</h5>

                    <livewire:horizon-status/>

                </div>

                <div wire:poll.5s>

                    <div class="col-12 mb-4">

                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>{{ __('default.Source File') }}</th>
                                <th>{{ __('default.Status') }}</th>
                                <th>{{ __('default.Started') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($runningProcesses as $process)
                                <tr>
                                    <td>{{ basename($process->source_file_path) }}</td>
                                    <td>
                                        @if($process->status === 'running')
                                            <i class="fa-solid fa-circle-notch fa-spin text-success"></i>
                                        @endif
                                    </td>
                                    <td>{{ $process->created_at->format('Y-m-d H:i:s') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted">{{ __('default.No running processes') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="col-12">
                        <div class="row m-2">
                            <div class="col-2">
                                {{ __('default.Processed Items') }}
                            </div>
                            <div class="col-4">
                                <select
                                    class="form-select form-select-sm w-auto"
                                    wire:model="statusFilter"
                                    wire:loading.attr="disabled"
                                    wire:target="statusFilter"
                                >
                                    <option value="all">{{ __('default.All') }}</option>
                                    <option value="finished">{{ __('default.Finished') }}</option>
                                    <option value="failed">{{ __('default.Failed') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <div class="mt-2">
                                    {{ $finishedProcesses->links() }}
                                </div>
                            </div>
                        </div>


                        @foreach ($finishedProcesses as $process)

                            @php
                                $extension = strtolower(pathinfo($process->source_file_path, PATHINFO_EXTENSION));
                                $failedBackground = ($process->status === 'failed') ? '#efdede' : '';
                            @endphp

                            <div class="card m-2 {{ $selectedProcessId === $process->id ? 'bg-warning bg-opacity-25 border-start border-warning' : '' }}" style="background-color: {{ $failedBackground }}; transition: background-color .3s ease;">
                                <div class="row">
                                    <div class="col-md-2 p-4">
                                        <div>{{ __('default.Original') }}</div>
                                        @if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                                            <div class="pdf-preview-wrapper">
                                                <img
                                                    src="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->source_file_path) }}"
                                                    class="rounded img-thumbnail"
                                                    style="max-width: 100px;"
                                                >

                                                <a
                                                    href="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->source_file_path) }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="pdf-preview-overlay"
                                                    aria-label="Open Image"
                                                    title="{{ basename($process->source_file_path) }}"
                                                ></a>
                                            </div>
                                        @elseif ($extension === 'pdf')
                                            <div class="pdf-preview-wrapper">
                                                <embed
                                                    src="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->source_file_path) }}"
                                                    type="application/pdf"
                                                    width="100"
                                                    height="150"
                                                    class="border rounded"
                                                />
                                                <a
                                                    href="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->source_file_path) }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="pdf-preview-overlay"
                                                    aria-label="Open PDF"
                                                    title="{{ basename($process->source_file_path) }}"
                                                ></a>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="col-md-2 p-4">
                                        <div>{{ __('default.Renamed') }}</div>
                                        @if($process->target_file_path !== '')
                                            @if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                                                <div class="pdf-preview-wrapper">
                                                    <img
                                                        src="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->target_file_path) }}"
                                                        class="rounded img-thumbnail"
                                                        style="max-width: 100px;"
                                                    >

                                                    <a
                                                        href="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->target_file_path) }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="pdf-preview-overlay"
                                                        aria-label="Open Image"
                                                        title="{{ basename($process->target_file_path) }}"
                                                    ></a>
                                                </div>
                                            @elseif ($extension === 'pdf')
                                                <div class="pdf-preview-wrapper">
                                                    <embed
                                                        src="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->target_file_path) }}"
                                                        type="application/pdf"
                                                        width="100"
                                                        height="150"
                                                        class="border rounded"
                                                    />
                                                    <a
                                                        href="{{ Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->url($process->target_file_path) }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="pdf-preview-overlay"
                                                        aria-label="Open PDF"
                                                        title="{{ basename($process->target_file_path) }}"
                                                    ></a>
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="col-md-8">
                                        <div class="card-body">
                                            <table class="table table-hover" style="
                                                font-size: .9em;
                                                --bs-table-bg: transparent;
                                                --bs-table-accent-bg: transparent;
                                                --bs-table-striped-bg: rgba(255,255,255,.05);
                                                --bs-table-hover-bg: rgba(255,255,255,.08);
                                            ">
                                                <tbody>
                                                <tr>
                                                    <td class="w-25">{{ __('default.Job Status') }}:</td>
                                                    <td>{{ ucfirst($process->status) }}</td>
                                                </tr>
                                                <tr>
                                                    <td>{{ __('default.Company Name') }}:</td>
                                                    <td class=" d-flex justify-content-between align-items-start">

                                                        @php
                                                            $class = match (true) {
                                                                $process->company_name_certainty >= 0.7 => 'success',
                                                                $process->company_name_certainty > 0.3 && $process->company_name_certainty < 0.7 => 'warning',
                                                                $process->company_name_certainty <= 0.3 => 'danger',
                                                                default => 'primary',
                                                            };
                                                        @endphp

                                                        {{ (null !== $process->company_name) ? $process->company_name : __('default.unknown') }}
                                                        <span class="badge text-bg-{{$class}} rounded-pill">{{ $process->company_name_certainty * 100 }}%</span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>{{ __('default.Delivery Note ID') }}:</td>
                                                    <td class=" d-flex justify-content-between align-items-start">

                                                        @php
                                                            $class = match (true) {
                                                                $process->delivery_note_certainty >= 0.7 => 'success',
                                                                $process->delivery_note_certainty > 0.3 && $process->delivery_note_certainty < 0.7 => 'warning',
                                                                $process->delivery_note_certainty <= 0.3 => 'danger',
                                                                default => 'primary',
                                                            };
                                                        @endphp

                                                        {{ (null !== $process->delivery_note_id) ? $process->delivery_note_id : __('default.unknown') }}
                                                        <span class="badge text-bg-{{$class}} rounded-pill">{{ $process->delivery_note_certainty * 100 }}%</span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>{{ __('default.Invoice ID') }}:</td>
                                                    <td class=" d-flex justify-content-between align-items-start">

                                                        @php
                                                            $class = match (true) {
                                                                $process->invoice_id_certainty >= 0.7 => 'success',
                                                                $process->invoice_id_certainty > 0.3 && $process->invoice_id_certainty < 0.7 => 'warning',
                                                                $process->invoice_id_certainty <= 0.3 => 'danger',
                                                                default => 'primary',
                                                            };
                                                        @endphp

                                                        {{ (null !== $process->invoice_id) ? $process->invoice_id : __('default.unknown') }}
                                                        <span class="badge text-bg-{{$class}} rounded-pill">{{ $process->invoice_id_certainty * 100 }}%</span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td>{{ __('default.Original') }}:</td>
                                                    <td class="d-flex justify-content-between align-items-start">
                                                        <span style="cursor: grabbing;" title="{{ basename($process->source_file_path) }}">
                                                            {{ Str::limit(basename($process->source_file_path), 40, ' ...') }}
                                                        </span>
                                                        <div class="btn-group" role="group"
                                                             aria-label="{{ __('default.Edit Item') }}">
                                                            @if(null !== $process->ocr_result)
                                                                <button
                                                                    type="button"
                                                                    class="btn btn-sm btn-outline-secondary"
                                                                    wire:click="view({{ $process->id }}, 'ocr_result')"
                                                                    wire:loading.attr="disabled"
                                                                >
                                                                    <i class="fa-solid fa-eye"></i>
                                                                </button>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>

                                                @if($process->target_file_path !== '')
                                                    <tr>
                                                        <td>{{ __('default.Renamed To') }}:</td>
                                                        <td class="d-flex justify-content-between align-items-start">
                                                            <span style="cursor: grabbing;" title="{{ basename($process->target_file_path) }}">
                                                                {{ Str::limit(basename($process->target_file_path), 40, ' ...') }}
                                                            </span>
{{--                                                            <div class="btn-group" role="group"--}}
{{--                                                                 aria-label="{{ __('Edit Item') }}">--}}
{{--                                                                <button type="button"--}}
{{--                                                                        class="btn btn-sm btn-outline-primary">--}}
{{--                                                                    <i class="fa-solid fa-pen-to-square"></i>--}}
{{--                                                                </button>--}}
{{--                                                                <button type="button" class="btn btn-sm btn-outline-danger">--}}
{{--                                                                    <i class="fa-solid fa-trash-can"></i>--}}
{{--                                                                </button>--}}
{{--                                                            </div>--}}
                                                        </td>
                                                    </tr>
                                                @endif
                                                <tr>
                                                    <td>{{ __('default.Finished At') }}:</td>
                                                    <td>{{ $process->updated_at->format('d.m.Y H:i:s') }}</td>
                                                </tr>
                                                @if($process->status === 'failed')
                                                    <tr>
                                                        <td>{{ __('default.Failed Message') }}:</td>
                                                        <td>{{ $process->failed_message }}</td>
                                                    </tr>
                                                @endif
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-4 col-lg-4 col-sm-12">
            <div class="sticky-top">
                @if($showForm)

                    @if($mode === 'view')
                        <div class="card {{ $selectedProcessId ? 'border-warning bg-warning bg-opacity-10' : '' }}" style="font-size: .9em;transition: background-color .3s ease;">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                {{ __('default.OCR Result for') }}: {{ $item->company_name }}

                                <button
                                    type="button"
                                    class="btn-close"
                                    aria-label="Close"
                                    wire:click="closePanel"
                                ></button>

                            </div>
                            <div class="card-body">
                                <pre>
                                    {{ $item->ocr_result }}
                                </pre>
                            </div>
                        </div>
                    @endif

                @endif
            </div>
        </div>

    </div>
</div>
