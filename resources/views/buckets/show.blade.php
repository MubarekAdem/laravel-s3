<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $bucket->name }} · Harbor Storage</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; background: #091827; color: #dbeafe; font: 15px/1.5 Inter, ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 1120px; margin: auto; padding: 40px 24px 64px; } h1, h2, p { margin-top: 0; } h1 { margin-bottom: 4px; font-size: clamp(28px, 4vw, 38px); letter-spacing: -.03em; }
        .eyebrow, .muted { color: #93a4b8; } .eyebrow { margin-bottom: 6px; } .card { padding: 20px; background: #10263a; border: 1px solid #24445f; border-radius: 12px; }
        .toolbar { display: flex; justify-content: space-between; align-items: end; gap: 16px; margin-bottom: 24px; } .stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0 24px; } .stat { min-width: 150px; } .number { display: block; color: #67e8f9; font-size: 24px; font-weight: 700; }
        .filter { display: flex; gap: 8px; margin-bottom: 18px; } input, button { box-sizing: border-box; border-radius: 7px; padding: 10px; border: 1px solid #355b79; font: inherit; } input { flex: 1; background: #0b1d2e; color: #f0f9ff; } button { background: #2563eb; color: #fff; border: 0; font-weight: 700; cursor: pointer; } .danger { background: #7f1d1d; } .danger:hover { background: #991b1b; }
        button:focus-visible, input:focus-visible, a:focus-visible { outline: 3px solid #67e8f9; outline-offset: 2px; } a { color: #67e8f9; font-weight: 700; text-decoration: none; } a:hover { text-decoration: underline; } code { color: #bae6fd; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; } td, th { padding: 12px 7px; border-bottom: 1px solid #24445f; text-align: left; vertical-align: middle; } th { color: #93a4b8; font-size: 12px; font-weight: 600; } .right { text-align: right; } .table-wrap { overflow-x: auto; } .actions { display: flex; justify-content: flex-end; align-items: center; gap: 12px; } .actions button { padding: 7px 9px; } .notice { margin-bottom: 16px; padding: 14px; border-radius: 8px; background: #14532d; } .empty { padding: 18px 0; } .pagination { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 18px; }
        @media (max-width: 720px) { main { padding: 28px 16px 48px; } .toolbar { display: block; } .toolbar > a { display: inline-block; margin-top: 12px; } .actions { justify-content: flex-start; } }
    </style>
</head>
<body>
<main>
    <div class="toolbar">
        <div><p class="eyebrow"><a href="{{ route('dashboard') }}">Harbor Storage</a> / bucket inventory</p><h1><code>{{ $bucket->name }}</code></h1></div>
        <a href="{{ route('dashboard') }}">Back to control room</a>
    </div>

    @if (session('status')) <p class="notice">{{ session('status') }}</p> @endif
    @if ($errors->any())<div role="alert" class="card"><p>Could not complete this action.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="stats">
        <div class="card stat"><span class="number">{{ number_format($objects->total()) }}</span>matching objects</div>
        <div class="card stat"><span class="number">{{ number_format($usedBytes) }} B</span>stored in bucket</div>
        <div class="card stat"><span class="number">{{ $bucket->quota_bytes ? number_format($bucket->quota_bytes) . ' B' : 'No limit' }}</span>storage quota</div>
    </div>

    <section class="card">
        <form class="filter" method="get">
            <input name="prefix" value="{{ $prefix }}" placeholder="Filter by object prefix, for example uploads/2026/" aria-label="Object prefix">
            <select name="state" aria-label="Object state"><option value="active" @selected($state === 'active')>Active</option><option value="trash" @selected($state === 'trash')>Trash</option></select>
            <button>Filter</button>
        </form>
        <div class="table-wrap"><table>
            <thead><tr><th>Object key</th><th>Type</th><th class="right">Size</th><th class="right">Modified</th><th></th></tr></thead>
            <tbody>
            @forelse ($objects as $object)
                <tr>
                    <td><code>{{ $object->key }}</code></td><td>{{ $object->content_type ?: 'application/octet-stream' }}</td><td class="right">{{ number_format($object->size) }} B</td><td class="right">{{ $object->updated_at->diffForHumans() }}</td>
                    <td><div class="actions">@if ($state === 'active')<a href="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id.'/download') }}">Download</a><form method="post" action="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id) }}">@csrf @method('DELETE')<button class="danger">Delete</button></form>@else<form method="post" action="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id.'/restore') }}">@csrf<button>Restore</button></form><details><summary>Permanently delete</summary><form method="post" action="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id.'/purge') }}">@csrf @method('DELETE')<label><input type="checkbox" name="confirm_purge" value="1" required> Permanently delete {{ $object->key }}. This cannot be undone.</label><button class="danger">Permanently delete</button></form></details>@endif</div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted empty">No {{ $state === 'trash' ? 'trashed' : 'active' }} objects match this prefix.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if ($objects->hasPages())
            <div class="pagination"><span class="muted">Showing {{ $objects->firstItem() }}–{{ $objects->lastItem() }} of {{ $objects->total() }}</span><span>@if ($objects->onFirstPage()) <span class="muted">Previous</span> @else <a href="{{ $objects->previousPageUrl() }}">Previous</a> @endif &nbsp; @if ($objects->hasMorePages()) <a href="{{ $objects->nextPageUrl() }}">Next</a> @else <span class="muted">Next</span> @endif</span></div>
        @endif
    </section>
</main>
</body>
</html>
