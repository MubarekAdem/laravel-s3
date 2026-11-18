@props(['page' => 'home'])
@php
    $root = rtrim(config('app.url'), '/');
    $isDocs = $page === 'docs';
    $canonical = $root.($isDocs ? '/api/docs' : '/');
    $title = $isDocs ? 'Laravel-S3 API documentation' : 'Laravel-S3 — Self-hosted object storage in Laravel';
    $description = $isDocs
        ? 'Integrate with Laravel-S3: local object storage, S3-compatible requests, authentication, buckets, multipart uploads, and API examples.'
        : 'Self-hosted object storage built in Laravel. Store files on your server, use an S3-compatible API, and manage buckets and access keys on shared hosting.';
    $graph = [
        ['@type' => 'WebSite', '@id' => $root.'/#website', 'url' => $root.'/', 'name' => 'Laravel-S3', 'inLanguage' => 'en'],
        ['@type' => 'SoftwareSourceCode', '@id' => $root.'/#software', 'name' => 'Laravel-S3', 'description' => 'Self-hosted object storage built in Laravel with an S3-compatible API subset.', 'url' => $root.'/', 'codeRepository' => 'https://github.com/MubarekAdem/laravel-s3', 'programmingLanguage' => 'PHP', 'runtimePlatform' => 'Laravel', 'license' => 'https://opensource.org/licenses/MIT'],
        ['@type' => $isDocs ? 'TechArticle' : 'WebPage', '@id' => $canonical.'#page', 'url' => $canonical, 'name' => $title, 'headline' => $title, 'description' => $description, 'inLanguage' => 'en', 'isPartOf' => ['@id' => $root.'/#website'], 'about' => ['@id' => $root.'/#software']],
    ];
    $structuredData = ['@context' => 'https://schema.org', '@graph' => $graph];
@endphp
<meta name="description" content="{{ $description }}">
<meta name="robots" content="{{ config('seo.indexable') ? 'index, follow, max-image-preview:large' : 'noindex, nofollow' }}">
<link rel="canonical" href="{{ $canonical }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('images/laravel-s3.svg') }}">
<meta property="og:type" content="{{ $isDocs ? 'article' : 'website' }}">
<meta property="og:site_name" content="Laravel-S3">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $root }}/images/laravel-s3-social.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta property="og:image:alt" content="Laravel-S3: Self-hosted object storage, built in Laravel">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $root }}/images/laravel-s3-social.png">
<meta name="twitter:image:alt" content="Laravel-S3: Self-hosted object storage, built in Laravel">
@if (config('seo.google_verification'))<meta name="google-site-verification" content="{{ config('seo.google_verification') }}">@endif
@if (config('seo.bing_verification'))<meta name="msvalidate.01" content="{{ config('seo.bing_verification') }}">@endif
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
