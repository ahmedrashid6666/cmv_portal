<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $appName }}</title>

        {{-- Favicons --}}
        <link rel="icon" type="image/svg+xml" href="{{ url($company['logo']) }}">
        <link rel="icon" href="{{ url($company['logo']) }}">
        <link rel="apple-touch-icon" href="{{ url($company['logo']) }}">
        <meta name="theme-color" content="#1e3a5f">

        {{-- Link preview (WhatsApp, Facebook, etc. — Open Graph) --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:title" content="{{ $appName }}">
        <meta property="og:description" content="{{ $company['name'] }} — Accounts &amp; Finance Management System">
        <meta property="og:image" content="{{ url($company['og_image']) }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:url" content="{{ url()->current() }}">

        {{-- Twitter / X card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $appName }}">
        <meta name="twitter:description" content="{{ $company['name'] }} — Accounts &amp; Finance Management System">
        <meta name="twitter:image" content="{{ url($company['og_image']) }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
