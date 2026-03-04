<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <script src="https://kit.fontawesome.com/dcf07d4939.js" crossorigin="anonymous"></script>

    <style>
        .drop-zone {
            border: 2px dashed #adb5bd;
            border-radius: 0.5rem;
            padding: 30px;
            text-align: center;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .drop-zone.dragover {
            background: #f8f9fa;
            border-color: #0d6efd;
            color: #0d6efd;
        }

        .preview img {
            max-height: 100px;
            margin-right: 10px;
            margin-bottom: 10px;
            border-radius: 6px;
        }

        .pdf-preview-wrapper {
            position: relative;
            width: 100px;
            height: 150px;
        }

        .pdf-preview-overlay {
            position: absolute;
            inset: 0;
            z-index: 9999;
            cursor: pointer;
        }
        .sticky-top {
            position: sticky;
            top: 24px;
            z-index: 1020;
        }
    </style>

    @livewireStyles

</head>
<body class="bg-light">

<div class="m-2" style="width: 98%;">
    <livewire:process-log />
</div>


<!-- jQuery 3.7.1 -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

@livewireScripts

<script>
    $(function () {

        const dropZone = $('#drop-zone');
        const fileInput = $('#delivery-note');
        const preview = $('#preview');
        const progressContainer = $('#progress-container');
        const progressBar = $('.progress-bar');

        let files = [];

        dropZone.on('click', () => fileInput.click());

        dropZone.on('dragover', function (e) {
            e.preventDefault();
            $(this).addClass('dragover');
        });

        dropZone.on('dragleave drop', function () {
            $(this).removeClass('dragover');
        });

        dropZone.on('drop', function (e) {
            e.preventDefault();
            handleFiles(e.originalEvent.dataTransfer.files);
        });

        fileInput.on('change', function () {
            handleFiles(this.files);
        });

        function handleFiles(selectedFiles) {
            // Convert FileList → Array
            const incoming = Array.from(selectedFiles);

            incoming.forEach(file => {
                // Max 10 files total
                if (files.length >= 10) {
                    showError('Maximum 10 files allowed.');
                    return;
                }

                // Prevent duplicates (name + size is usually enough)
                const exists = files.some(f =>
                    f.name === file.name && f.size === file.size
                );

                if (exists) {
                    return;
                }

                // Size validation (10 MB)
                if (file.size > 10 * 1024 * 1024) {
                    showError(
                        `File too large: ${file.name} (${formatBytes(file.size)})`
                    );
                    return;
                }

                // Type validation
                if (
                    !file.type.startsWith('image/') &&
                    file.type !== 'application/pdf'
                ) {
                    showError('Only PDF and image files are allowed.');
                    return;
                }

                files.push(file);
            });

            renderPreview();
        }

        function renderPreview() {
            preview.html('');

            files.forEach(file => {
                let icon = '<i class="fa-regular fa-file"></i>';

                if (file.type.startsWith('image/')) {
                    icon = '<i class="fa-regular fa-image"></i>';
                }

                if (file.type === 'application/pdf') {
                    icon = '<i class="fa-regular fa-file-pdf"></i>';
                }

                preview.append(`
                    <div class="border rounded p-2 me-2 mb-2 d-flex justify-content-between align-items-center">
                        <span>${icon} ${file.name}</span>
                        <button class="btn btn-sm btn-danger remove-file" data-name="${file.name}">
                            &times;
                        </button>
                    </div>
                `);
            });
        }

        preview.on('click', '.remove-file', function () {
            const name = $(this).data('name');
            files = files.filter(f => f.name !== name);
            renderPreview();
        });

        $('#uploadBtn').on('click', function(e) {
            e.preventDefault()

            if (!files.length) {
                showError('Please select at least one file.');
                return;
            }

            let formData = new FormData();
            files.forEach((file, index) => {
                formData.append('files[]', file);
            });
            formData.append('_token', '{{ csrf_token() }}');

            progressContainer.removeClass('d-none');
            progressBar.css('width', '0%').text('0%');

            $.ajax({
                url: '{{ route('upload') }}',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function () {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener("progress", function (e) {
                        if (e.lengthComputable) {
                            let percent = Math.round((e.loaded / e.total) * 100);
                            progressBar.css('width', percent + '%').text(percent + '%');
                        }
                    });
                    return xhr;
                },
                success: function (response) {
                    showSuccess('Files uploaded successfully.');
                    resetForm();
                },
                error: function (xhr) {
                    showError(xhr.responseJSON?.message || 'Upload failed.');
                }
            });
        });

        function showSuccess(msg) {
            $('#alert-success').text(msg).removeClass('d-none');
            $('#alert-error').addClass('d-none');
        }

        function showError(msg) {
            $('#alert-error').text(msg).removeClass('d-none');
            $('#alert-success').addClass('d-none');
        }

        function resetForm() {
            files = [];
            preview.html('');
            fileInput.val('');
            setTimeout(() => progressContainer.addClass('d-none'), 1500);
        }

        function formatBytes(bytes) {
            if (!bytes) return '0 B';

            const k = 1024;
            const sizes = ['B','KB','MB','GB','TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            const value = bytes / Math.pow(k, i);

            return (value % 1 === 0 ? value : value.toFixed(2)) + ' ' + sizes[i];
        }

    })
</script>

</body>
</html>
