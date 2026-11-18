<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-search-meta page="docs" />
    <meta name="theme-color" content="#0c1d32">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ asset('css/harbor-docs.css') }}">
    <link rel="describedby" type="application/json" href="{{ route('api.openapi') }}">
    <link rel="alternate" type="text/plain" title="AI reference index" href="{{ route('ai.index') }}">
    <script src="{{ asset('js/harbor-docs.js') }}" defer></script>
</head>
<body>
<a href="#main-content" class="docs-skip">Skip to reference</a>
<header class="docs-header">
    <div class="docs-header-inner">
        <a href="{{ route('home') }}" class="docs-brand"><x-console-icon name="harbor" /><span>Laravel-S3</span><span class="docs-brand-label">Developers</span></a>
        <nav class="docs-top-nav" aria-label="Developer navigation"><a href="#overview" aria-current="page">API reference</a><a href="#quickstart">Quickstart</a><a href="#ai-reference">AI resources</a></nav>
        <a class="docs-console-link" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Owner console <x-console-icon name="external" /></a>
    </div>
</header>
<div class="docs-mobile-bar"><span>Storage API / Reference</span><button type="button" data-docs-toggle aria-controls="docs-sidebar" aria-expanded="false"><x-console-icon name="menu" />Sections</button></div>
<div class="docs-shell">
    <aside id="docs-sidebar" class="docs-sidebar" aria-label="Documentation navigation">
        <label class="docs-search" for="docs-search"><x-console-icon name="search" /><input type="search" id="docs-search" placeholder="Find in reference…" aria-describedby="docs-search-status"><kbd>/</kbd></label>
        <p id="docs-search-status" class="docs-search-status" role="status" aria-live="polite"></p>
        <nav class="docs-nav" aria-label="Documentation sections">
            <p>Get started</p>
            <a href="#overview" aria-current="location">Overview</a><a href="#quickstart">Your first upload</a><a href="#authentication">Authentication</a>
            <p>Storage operations</p>
            <a href="#buckets">Buckets</a><a href="#objects">Objects</a><a href="#multipart">Multipart uploads</a><a href="#presigned">Presigned URLs</a>
            <p>Reference</p>
            <a href="#errors">Errors &amp; retries</a><a href="#limits">Limits &amp; compatibility</a><a href="#ai-reference">AI resources</a>
        </nav>
        <div class="docs-sidebar-resources"><span>Build with the reference</span><a href="{{ route('api.openapi') }}"><x-console-icon name="download" />OpenAPI specification</a><a href="{{ route('ai.full') }}"><x-console-icon name="file" />Full text for AI clients</a></div>
        <div class="docs-server-label"><span class="docs-server-dot"></span>Files stored on your server</div>
    </aside>
    <main id="main-content" tabindex="-1">
        <div class="docs-breadcrumb"><a href="{{ route('home') }}">Laravel-S3</a><x-console-icon name="chevron-right" /><span>API reference</span></div>
        @yield('content')
        <footer class="docs-footer"><span>Laravel-S3 · Built with Laravel</span><a href="{{ route('ai.index') }}">llms.txt</a><a href="{{ route('api.openapi') }}">OpenAPI</a></footer>
    </main>
    <aside class="docs-context" aria-label="Reference resources"><span>Your storage</span><strong>Hosted by you</strong><p>Files stay on your server. Storage requests go directly to Laravel-S3.</p><a href="#authentication">Client authentication</a><a href="#limits">API compatibility</a><hr><span>For your tools</span><a href="{{ route('api.openapi') }}">OpenAPI JSON</a><a href="{{ route('ai.index') }}">llms.txt</a><a href="{{ route('ai.full') }}">llms-full.txt</a></aside>
</div>
</body>
</html>
