<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#091827">
    <x-search-meta />
    <title>@yield('title', 'Laravel-S3')</title>
    <link rel="stylesheet" href="{{ asset('css/harbor-public.css') }}">
    <link rel="describedby" type="application/json" href="{{ route('api.openapi') }}">
    <link rel="alternate" type="text/plain" title="AI reference index" href="{{ route('ai.index') }}">
    @stack('styles')
</head>
<body class="harbor-public">
    <a class="harbor-skip-link" href="#main-content">Skip to content</a>
    <header class="harbor-header">
        <div class="harbor-container harbor-header-inner">
            <a class="harbor-brand" href="{{ route('home') }}" aria-label="Laravel-S3 home">
                <svg viewBox="0 0 32 36" width="30" height="34" fill="none" aria-hidden="true">
                    <path d="M4 7v20l12 6 12-6V7L16 1 4 7Z" stroke="currentColor" stroke-width="2"/>
                    <path d="M11 10v15h11" stroke="currentColor" stroke-width="3"/>
                </svg>
                <span>Laravel-S3</span>
            </a>
            <nav class="harbor-nav" aria-label="Main navigation">
                <a href="{{ route('api.docs') }}" @if (request()->routeIs('api.docs')) aria-current="page" @endif>API docs</a>
                <a class="harbor-nav-console" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Owner console</a>
            </nav>
        </div>
    </header>

    <main id="main-content" tabindex="-1">@yield('content')</main>

    <footer class="harbor-footer">
        <div class="harbor-container harbor-footer-inner">
            <div><a class="harbor-footer-brand" href="{{ route('home') }}">Laravel-S3</a><p>A home for your objects. On your terms.</p></div>
            <nav aria-label="Footer navigation">
                <a href="{{ route('api.docs') }}">API docs</a>
                <a href="{{ auth()->check() ? route('dashboard') : route('login') }}">Owner console</a>
            </nav>
            <span class="harbor-footer-note">Built with Laravel.</span>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
