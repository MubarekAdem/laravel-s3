<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Harbor Storage</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; background: #091827; color: #dbeafe; font: 15px/1.5 Inter, ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 1120px; margin: auto; padding: 40px 24px 64px; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
        h1, h2, p { margin-top: 0; } h1 { margin-bottom: 4px; font-size: clamp(28px, 4vw, 38px); letter-spacing: -.03em; }
        h2 { font-size: 18px; letter-spacing: -.01em; } .muted { color: #93a4b8; } .eyebrow { margin-bottom: 3px; color: #67e8f9; font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 28px 0 16px; }
        .card { padding: 20px; background: #10263a; border: 1px solid #24445f; border-radius: 12px; }
        .number { color: #67e8f9; font-size: 30px; font-weight: 700; } .split { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; gap: 8px; align-items: end; }
        label { display: inline-flex; align-items: center; gap: 6px; } .field { display: grid; gap: 5px; color: #b9c8d8; font-size: 13px; }
        input, button { box-sizing: border-box; border-radius: 7px; padding: 10px; border: 1px solid #355b79; font: inherit; }
        input:not([type=checkbox]) { width: 100%; background: #0b1d2e; color: #f0f9ff; } input[type=checkbox] { accent-color: #38bdf8; }
        button { background: #2563eb; color: #fff; border: 0; font-weight: 700; cursor: pointer; } button:hover { background: #1d4ed8; } button:focus-visible, input:focus-visible { outline: 3px solid #67e8f9; outline-offset: 2px; }
        .danger { background: #7f1d1d; } .danger:hover { background: #991b1b; } .flash, .notice, .errors { margin: 16px 0; padding: 14px; border-radius: 8px; word-break: break-all; }
        .flash { background: #164e63; } .notice { background: #14532d; } .errors { background: #7f1d1d; } .credentials { display: grid; gap: 12px; }
        .scope-list { display: flex; flex-wrap: wrap; gap: 10px 16px; margin: 12px 0; } .bucket-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 7px; margin: 10px 0 16px; }
        table { width: 100%; border-collapse: collapse; } td, th { padding: 11px 6px; border-bottom: 1px solid #24445f; text-align: left; vertical-align: middle; } th { color: #93a4b8; font-size: 12px; font-weight: 600; } .right { text-align: right; } .table-wrap { overflow-x: auto; }
        code { color: #bae6fd; } a { color: #67e8f9; font-weight: 700; text-decoration: none; } a:hover { text-decoration: underline; } .empty { padding: 10px 0; } @media (max-width: 720px) { main { padding: 28px 16px 48px; } header, .split { display: block; } header form { margin-top: 16px; } .split > * { margin-bottom: 16px; } .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <p class="eyebrow">Harbor Storage / control room</p>
            <h1>Object storage on this server.</h1>
        </div>
        <form method="post" action="/logout">@csrf <button>Sign out</button></form>
    </header>

    @if (session('created_secret'))
        <p class="flash">Access key: <b>{{ session('created_secret')[0] }}</b><br>Secret: <b>{{ session('created_secret')[1] }}</b><br>Copy the secret now. It will not be shown again.</p>
    @endif
    @if (session('status')) <p class="notice">{{ session('status') }}</p> @endif
    @if ($errors->any())
        <div class="errors"><strong>Could not save changes.</strong><br>{{ $errors->first() }}</div>
    @endif

    <div class="grid">
        <div class="card"><span class="number">{{ $buckets }}</span><br>Buckets</div>
        <div class="card"><span class="number">{{ $objects }}</span><br>Active objects</div>
        <div class="card"><span class="number">{{ number_format($bytes) }}</span><br>Bytes stored</div>
    </div>

    <div class="split">
        <section class="card">
            <h2>Create a bucket</h2>
            <form method="post" action="/buckets" class="form-row">
                @csrf
                <label class="field">Bucket name <input name="name" value="{{ old('name') }}" placeholder="media-assets" required></label>
                <label class="field">Quota (MB) <input type="number" name="quota_mb" value="{{ old('quota_mb') }}" min="1" placeholder="No limit"></label>
                <button>Create bucket</button>
            </form>
            <p class="muted">Use 3–63 lowercase letters, numbers, dots, or hyphens. Leave quota blank for no limit.</p>
        </section>

        <section class="card">
            <h2>Recent activity</h2>
            <form method="get" class="scope-list"><input name="activity_bucket" value="{{ $activityFilters['activity_bucket'] ?? '' }}" placeholder="Bucket" aria-label="Activity bucket"><input name="activity_action" value="{{ $activityFilters['activity_action'] ?? '' }}" placeholder="Action" aria-label="Activity action"><input name="activity_search" value="{{ $activityFilters['activity_search'] ?? '' }}" placeholder="Actor or object key" aria-label="Search activity"><button>Filter activity</button></form><table>
                @forelse ($events as $event)
                    <tr><td>{{ $event->action }}<br><span class="muted">{{ $event->actor }}</span></td><td>{{ $event->bucket }}<br><code>{{ $event->object_key }}</code></td><td class="muted right">{{ $event->created_at->diffForHumans() }}</td></tr>
                @empty
                    <tr><td class="muted empty">No storage activity yet.</td></tr>
                @endforelse
            </table>
            @if ($events->hasPages())<nav aria-label="Activity pages">@if ($events->previousPageUrl())<a href="{{ $events->previousPageUrl() }}">Previous</a>@endif <span class="muted">Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</span> @if ($events->nextPageUrl())<a href="{{ $events->nextPageUrl() }}">Next</a>@endif</nav>@endif
        </section>
    </div>

    <section class="card" style="margin-bottom:16px">
        <h2>Buckets</h2>
        <form method="get" class="scope-list"><label class="field">Usage by bucket<input name="usage_bucket" value="{{ $activityFilters['usage_bucket'] ?? '' }}" placeholder="All buckets"></label><button>Filter usage</button></form>
        <div class="table-wrap"><table>
            <thead><tr><th>Name</th><th class="right">Objects</th><th class="right">Stored</th><th class="right">Quota</th><th></th></tr></thead>
            <tbody>
            @forelse ($usageRows as $bucket)
                <tr><td><code>{{ $bucket->name }}</code></td><td class="right">{{ number_format($bucket->active_objects_count) }}</td><td class="right">{{ number_format($bucket->active_bytes ?? 0) }} B</td><td class="right"><form method="post" action="{{ url('/buckets/'.$bucket->name) }}">@csrf @method('PATCH')<input type="number" name="quota_mb" min="1" value="{{ $bucket->quota_bytes ? (int) ($bucket->quota_bytes / 1048576) : '' }}" placeholder="No limit"><button>Save</button></form></td><td class="right"><a href="{{ url('/buckets/'.$bucket->name) }}">Browse</a> @if ($bucket->active_objects_count === 0)<form method="post" action="{{ url('/buckets/'.$bucket->name) }}">@csrf @method('DELETE')<button class="danger">Delete</button></form>@endif</td></tr>
            @empty
                <tr><td colspan="5" class="muted empty">Create a bucket to begin storing objects.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <div class="split">
        <section class="card">
            <h2>Create API key</h2>
            <form method="post" action="/keys" class="credentials">
                @csrf
                <div class="scope-list">
                    <label><input type="checkbox" name="scopes[]" value="read" checked> Read</label>
                    <label><input type="checkbox" name="scopes[]" value="list" checked> List buckets and objects</label>
                    <label><input type="checkbox" name="scopes[]" value="write" checked> Write</label>
                    <label><input type="checkbox" name="scopes[]" value="delete"> Delete</label>
                </div>
                @if ($bucketRows->isNotEmpty())
                    <div><span class="muted">Limit this key to selected buckets (leave clear for every bucket).</span><div class="bucket-list">
                        @foreach ($bucketRows as $bucket)
                            <label><input type="checkbox" name="bucket_names[]" value="{{ $bucket->name }}"> {{ $bucket->name }}</label>
                        @endforeach
                    </div></div>
                @endif
                <button>Create credential</button>
            </form>
            <p class="muted">Endpoint: <code>{{ url('/s3') }}</code></p>
        </section>

        <section class="card">
            <h2>Active credentials</h2>
            <div class="table-wrap"><table>
                @forelse ($keys as $key)
                    <tr><td><code>{{ $key->access_key }}</code></td><td><form method="post" action="/keys/{{ $key->id }}">@csrf @method('PATCH')<label><input type="checkbox" name="scopes[]" value="read" @checked(in_array('read', $key->scopes ?? [], true))> Read</label><label><input type="checkbox" name="scopes[]" value="write" @checked(in_array('write', $key->scopes ?? [], true))> Write</label><label><input type="checkbox" name="scopes[]" value="delete" @checked(in_array('delete', $key->scopes ?? [], true))> Delete</label>@foreach ($bucketRows as $bucket)<label><input type="checkbox" name="bucket_names[]" value="{{ $bucket->name }}" @checked(in_array($bucket->name, $key->bucket_names ?? [], true))> {{ $bucket->name }}</label>@endforeach<button>Save</button></form></td><td class="right"><form method="post" action="/keys/{{ $key->id }}">@csrf @method('DELETE') <button class="danger">Revoke</button></form></td></tr>
                @empty
                    <tr><td class="muted empty">Create a credential to connect an S3 client.</td></tr>
                @endforelse
            </table></div>
        </section>
    </div>
</main>
</body>
</html>
