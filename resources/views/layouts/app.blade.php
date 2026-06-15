<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <script src="https://kit.fontawesome.com/dcf07d4939.js" crossorigin="anonymous"></script>

    <style>
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
    {{ $slot }}
</div>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

@livewireScripts
</body>
</html>
