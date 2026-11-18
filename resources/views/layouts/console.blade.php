<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0c1d32">
    <title>@yield('title', 'Overview') · Laravel-S3</title>
    <link rel="stylesheet" href="{{ asset('css/harbor-console.css') }}">
    <script src="{{ asset('js/harbor-console.js') }}" defer></script>
    @stack('styles')
</head>
<body class="console" data-console-page>
<a href="#workspace" class="skip-link">Skip to workspace</a>
<button class="sidebar-backdrop" type="button" data-nav-close aria-label="Close navigation"></button>
<aside class="sidebar" id="console-sidebar" aria-label="Console navigation">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark"><x-console-icon name="harbor" /></span><span>Laravel-S3<span class="brand-caption">Storage console</span></span></a>
    <div class="workspace-label"><span class="workspace-symbol">L</span><span>Personal workspace<small>Your storage, in one place</small></span><x-console-icon name="chevron-down" /></div>
    <nav class="side-nav" aria-label="Workspace">
        <p class="nav-caption">Workspace</p>
        @foreach (['overview' => ['overview', 'Overview'], 'buckets' => ['bucket', 'Buckets'], 'keys' => ['key', 'Access keys'], 'activity' => ['activity', 'Activity log']] as $viewName => $navigation)
            @php($selected = ($activeView ?? 'buckets') === $viewName)
            <a href="{{ route('dashboard', ['view' => $viewName]) }}" class="side-link {{ $selected ? 'is-active' : '' }}" @if ($selected) aria-current="page" @endif><x-console-icon :name="$navigation[0]" /><span>{{ $navigation[1] }}</span>@if ($viewName === 'buckets' && isset($buckets))<span class="nav-count">{{ $buckets }}</span>@endif</a>
        @endforeach
        <p class="nav-caption nav-caption-spaced">Account</p>
        <a href="{{ route('profile.edit') }}" class="side-link {{ ($activeView ?? '') === 'profile' ? 'is-active' : '' }}" @if (($activeView ?? '') === 'profile') aria-current="page" @endif><x-console-icon name="settings" /><span>My profile</span></a>
        <p class="nav-caption nav-caption-spaced">Resources</p>
        <a href="{{ route('api.docs') }}" class="side-link"><x-console-icon name="book" /><span>API documentation</span><x-console-icon name="external" class="nav-external" /></a>
        <a href="{{ route('home') }}" class="side-link"><x-console-icon name="globe" /><span>Public site</span><x-console-icon name="external" class="nav-external" /></a>
    </nav>
    <div class="sidebar-footer"><div class="local-label"><x-console-icon name="server" />Self-hosted storage</div><p>Running on your infrastructure.</p><span class="region-label">Local file storage</span></div>
    <div class="sidebar-account"><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><a class="sidebar-profile-link" href="{{ route('profile.edit') }}" aria-label="Manage my profile"><strong>{{ auth()->user()->name }}</strong><small>Workspace owner</small></a><form method="post" action="{{ url('/logout') }}">@csrf<button type="submit" class="sidebar-signout" title="Sign out" aria-label="Sign out"><x-console-icon name="logout" /></button></form></div>
</aside>
<div class="console-frame">
    <header class="topbar">
        <div class="breadcrumbs"><button type="button" class="icon-button mobile-nav-toggle" data-nav-toggle aria-controls="console-sidebar" aria-expanded="false" aria-label="Open navigation"><x-console-icon name="menu" /></button><span class="breadcrumb-workspace">Workspace</span><x-console-icon name="chevron-right" /><span>@yield('breadcrumb', 'Overview')</span></div>
        <div class="topbar-actions"><a href="{{ route('api.docs') }}" class="topbar-docs"><x-console-icon name="book" />Developer docs</a><span class="topbar-divider"></span><a href="{{ route('profile.edit') }}" class="avatar avatar-small" title="Manage my profile" aria-label="Manage my profile">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</a></div>
    </header>
    <main id="workspace" class="workspace" tabindex="-1">
        @if (session('status'))<div class="alert alert-success" role="status"><x-console-icon name="check" /><span>{{ session('status') }}</span></div>@endif
        @if ($errors->any())<div class="alert alert-error" role="alert"><x-console-icon name="info" /><div><strong>We couldn’t save those changes.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @yield('content')
        <footer class="workspace-footer"><span>Laravel-S3</span><a href="{{ route('api.docs') }}">Build something with your storage <x-console-icon name="external" /></a></footer>
    </main>
</div>
<div class="toast" role="status" aria-live="polite" data-toast hidden></div>
@stack('scripts')
</body>
</html>
