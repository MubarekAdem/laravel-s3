<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#091827">
    <meta name="description" content="Harbor Storage is a Laravel object storage server with a supported S3 API subset, built for hosting you control.">
    <title>@yield('title', 'Harbor Storage')</title>
    <link rel="stylesheet" href="{{ asset('css/harbor-public.css') }}">
    @stack('styles')
</head>
<body class="harbor-public">
    <a class="harbor-skip-link" href="#main-content">Skip to content</a>
    <header class="harbor-header">
        <div class="harbor-container harbor-header-inner">
            <a class="harbor-brand" href="{{ route('home') }}" aria-label="Harbor Storage home">
                <svg viewBox="0 0 32 36" width="30" height="34" fill="none" aria-hidden="true">
                    <path d="M4 7v20l12 6 12-6V7L16 1 4 7Z" stroke="currentColor" stroke-width="2"/>
                    <path d="M10 11v14m12-14v14M10 18h12" stroke="currentColor" stroke-width="3"/>
                </svg>
                <span>Harbor<span class="harbor-brand-detail"> Storage</span></span>
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
            <div><a class="harbor-footer-brand" href="{{ route('home') }}">Harbor Storage</a><p>A home for your objects. On your terms.</p></div>
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
