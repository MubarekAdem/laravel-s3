@props(['name' => 'file'])
@php
    $paths = [
        'harbor' => 'M4 6 12 2l8 4v12l-8 4-8-4V6Zm4 1v10m8-10v10M8 12h8',
        'overview' => 'M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7Z',
        'bucket' => 'M3 7c0-2 4-4 9-4s9 2 9 4-4 4-9 4-9-2-9-4Zm0 0v10c0 2 4 4 9 4s9-2 9-4V7M3 12c0 2 4 4 9 4s9-2 9-4',
        'key' => 'M15 3a6 6 0 1 1-4 10L3 21H1v-4l8-8a6 6 0 0 1 6-6Zm2 4h.01M5 15l4 4',
        'activity' => 'M3 12h4l3-8 4 16 3-8h4',
        'book' => 'M3 3h6c2 0 3 1 3 3v15c0-2-1-3-3-3H3V3Zm18 0h-6c-2 0-3 1-3 3v15c0-2 1-3 3-3h6V3Z',
        'globe' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z',
        'external' => 'M14 3h7v7m0-7L10 14M10 3H3v18h18v-7',
        'server' => 'M3 3h18v7H3V3Zm0 11h18v7H3v-7ZM7 6.5h.01M7 17.5h.01M12 6.5h5M12 17.5h5',
        'logout' => 'M9 3H3v18h6m6-15 6 6-6 6m-8-6h14',
        'menu' => 'M3 6h18M3 12h18M3 18h18',
        'close' => 'm6 6 12 12M6 18 18 6',
        'plus' => 'M12 5v14M5 12h14',
        'search' => 'M19 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0Zm-2 7 5 5',
        'arrow-right' => 'M4 12h16m-6-6 6 6-6 6',
        'arrow-left' => 'M20 12H4m6-6-6 6 6 6',
        'chevron-right' => 'm9 5 7 7-7 7',
        'chevron-down' => 'm6 9 6 6 6-6',
        'copy' => 'M9 9h12v12H9V9ZM5 15H3V3h12v2',
        'file' => 'M5 2h9l5 5v15H5V2Zm9 0v6h5M9 12h6m-6 4h6',
        'download' => 'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5',
        'trash' => 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7',
        'restore' => 'M3 10a9 9 0 1 1 2 8M3 4v6h6m3-3v5l3 2',
        'check' => 'm5 12 4 4L19 6',
        'info' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 11v6m0-10h.01',
        'shield' => 'm12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Zm-4 10 3 3 5-6',
        'settings' => 'M4 7h16M4 17h16M8 4v6m8 4v6',
    ];
@endphp
<svg {{ $attributes->merge(['class' => 'icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['file'] }}" /></svg>
