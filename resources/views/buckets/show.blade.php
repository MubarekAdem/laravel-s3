@extends('layouts.console')

@section('title', $bucket->name)
@section('page-title', $bucket->name)

@section('breadcrumb')
    <a href="{{ route('dashboard', ['view' => 'buckets']) }}">Buckets</a>
    <x-console-icon name="chevron-right" />
    <span aria-current="page">{{ $bucket->name }}</span>
@endsection

@push('styles')
    <style>
        .bucket-browser .bucket-heading { overflow-wrap: anywhere; }
        .bucket-browser .bucket-filters { align-items: end; flex-wrap: wrap; padding: 20px; }
        .bucket-browser .bucket-prefix { flex: 1 1 280px; }
        .bucket-browser .bucket-state { flex: 0 1 180px; }
        .bucket-browser .bucket-object { display: flex; align-items: center; gap: 12px; min-width: 240px; }
        .bucket-browser .bucket-object > svg { flex: 0 0 20px; color: #64748b; }
        .bucket-browser .bucket-object-name { font-weight: 600; overflow-wrap: anywhere; }
        .bucket-browser .bucket-object-path { margin-top: 4px; font-size: 12px; overflow-wrap: anywhere; }
        .bucket-browser .bucket-type { max-width: 220px; overflow-wrap: anywhere; }
        .bucket-browser .bucket-modified { white-space: nowrap; }
        .bucket-browser .bucket-pagination { flex-wrap: wrap; gap: 12px; padding: 20px; }
        .bucket-purge .bucket-purge-key { overflow-wrap: anywhere; }
        .bucket-purge .bucket-confirm { display: flex; align-items: start; gap: 10px; }
        .bucket-purge .bucket-confirm input { margin-top: 4px; flex: 0 0 auto; }
        @media (max-width: 640px) {
            .bucket-browser .bucket-state { flex: 1 1 180px; }
            .bucket-browser .bucket-filters > .btn { width: 100%; }
        }
    </style>
@endpush

@section('content')
    <div class="bucket-browser">
        <div class="page-heading">
            <div>
                <p class="page-eyebrow">Bucket inventory</p>
                <h1 class="page-title bucket-heading">{{ $bucket->name }}</h1>
                <p class="page-subtitle">Browse objects, download files, and manage this bucket’s trash.</p>
            </div>
            <a class="btn btn-secondary" href="{{ route('dashboard', ['view' => 'buckets']) }}"><x-console-icon name="arrow-left" /> All buckets</a>
        </div>

        <div class="metric-strip" aria-label="Bucket summary">
            <div class="metric">
                <div class="metric-label">Matching objects</div>
                <div class="metric-value">{{ number_format($objects->total()) }}</div>
                <div class="metric-note">{{ $state === 'trash' ? 'Trash' : 'Active' }}{{ $prefix !== '' ? ' · Filtered by prefix' : '' }}</div>
            </div>
            <div class="metric">
                <div class="metric-label">Stored in bucket</div>
                <div class="metric-value"><x-byte-size :bytes="$usedBytes" /></div>
                <div class="metric-note">Bucket storage usage</div>
            </div>
            <div class="metric">
                <div class="metric-label">Storage quota</div>
                <div class="metric-value">
                    @if ($bucket->quota_bytes)
                        <x-byte-size :bytes="$bucket->quota_bytes" />
                    @else
                        No limit
                    @endif
                </div>
                <div class="metric-note">{{ $bucket->quota_bytes ? 'Configured bucket limit' : 'No bucket quota configured' }}</div>
            </div>
        </div>

        <section class="panel" aria-labelledby="bucket-objects-title">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title" id="bucket-objects-title">Objects</h2>
                    <p class="panel-subtitle">{{ $state === 'trash' ? 'Restore objects or permanently delete them from storage.' : 'Files stored under their full object keys.' }}</p>
                </div>
            </div>
            <nav class="tabs" aria-label="Object state">
                <a class="{{ $state === 'active' ? 'active' : '' }}" href="{{ url()->current() }}?{{ http_build_query(['state' => 'active', 'prefix' => $prefix]) }}" @if ($state === 'active') aria-current="page" @endif><x-console-icon name="file" /> Active</a>
                <a class="{{ $state === 'trash' ? 'active' : '' }}" href="{{ url()->current() }}?{{ http_build_query(['state' => 'trash', 'prefix' => $prefix]) }}" @if ($state === 'trash') aria-current="page" @endif><x-console-icon name="trash" /> Trash</a>
            </nav>

            <form class="toolbar bucket-filters" method="get" action="{{ url()->current() }}" role="search" aria-label="Filter objects">
                <div class="field bucket-prefix">
                    <label for="bucket-prefix">Object prefix</label>
                    <div class="input-group toolbar-search">
                        <x-console-icon name="search" />
                        <input class="input" id="bucket-prefix" name="prefix" value="{{ $prefix }}" placeholder="e.g. uploads/2026/" type="search">
                    </div>
                </div>
                <div class="field bucket-state">
                    <label for="bucket-state">Object state</label>
                    <select class="input" id="bucket-state" name="state">
                        <option value="active" @selected($state === 'active')>Active</option>
                        <option value="trash" @selected($state === 'trash')>Trash</option>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">Filter</button>
                @if ($prefix !== '')
                    <a class="btn btn-quiet" href="{{ url()->current() }}?{{ http_build_query(['state' => $state]) }}">Clear prefix</a>
                @endif
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Object key</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-right">Size</th>
                            <th scope="col">Modified</th>
                            <th scope="col">Visibility</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($objects as $object)
                            <tr>
                                <td>
                                    <div class="bucket-object">
                                        <x-console-icon name="file" />
                                        <div>
                                            <div class="bucket-object-name">{{ basename($object->key) }}</div>
                                            <div class="bucket-object-path mono text-muted">{{ $object->key }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="bucket-type text-muted">{{ $object->content_type ?: 'application/octet-stream' }}</td>
                                <td class="text-right"><x-byte-size :bytes="$object->size" /></td>
                                <td class="bucket-modified text-muted"><time datetime="{{ $object->updated_at->toIso8601String() }}" title="{{ $object->updated_at->toDayDateTimeString() }}">{{ $object->updated_at->diffForHumans() }}</time></td>
                                <td><span class="badge {{ $object->is_public ? 'badge-green' : 'badge-neutral' }}">{{ $object->is_public ? 'Public' : 'Private' }}</span></td>
                                <td>
                                    <div class="row-actions">
                                        @if ($state === 'active')
                                            <a class="btn btn-secondary btn-sm" href="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id.'/download') }}" aria-label="Download {{ $object->key }}"><x-console-icon name="download" /> Download</a>
                                            <form method="post" action="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-quiet btn-sm" type="submit" aria-label="Move {{ $object->key }} to trash"><x-console-icon name="trash" /> Move to trash</button>
                                            </form>
                                        @else
                                            <form method="post" action="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id.'/restore') }}">
                                                @csrf
                                                <button class="btn btn-secondary btn-sm" type="submit" aria-label="Restore {{ $object->key }}"><x-console-icon name="restore" /> Restore</button>
                                            </form>
                                            <button class="btn btn-danger btn-sm" type="button" data-open-dialog="purge-object-{{ $object->id }}" aria-label="Permanently delete {{ $object->key }}"><x-console-icon name="trash" /> Permanently delete</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <div class="empty-icon"><x-console-icon :name="$state === 'trash' ? 'trash' : 'file'" /></div>
                                        <h3>{{ $prefix !== '' ? 'No matching objects' : ($state === 'trash' ? 'Trash is empty' : 'No active objects') }}</h3>
                                        <p>{{ $prefix !== '' ? 'No '.($state === 'trash' ? 'trashed' : 'active').' objects match this prefix. Try a shorter prefix or clear the filter.' : ($state === 'trash' ? 'Objects moved to trash will appear here so you can restore or permanently delete them.' : 'Upload files with your S3 client to see them here, or check the trash for objects to restore.') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($objects->hasPages())
                <div class="pagination bucket-pagination">
                    <span class="text-muted">Showing {{ number_format($objects->firstItem()) }}–{{ number_format($objects->lastItem()) }} of {{ number_format($objects->total()) }}</span>
                    <nav class="row-actions" aria-label="Object pagination">
                        @if ($objects->onFirstPage())
                            <span class="btn btn-quiet btn-sm" aria-disabled="true">Previous</span>
                        @else
                            <a class="btn btn-secondary btn-sm" rel="prev" href="{{ $objects->appends(['state' => $state, 'prefix' => $prefix])->previousPageUrl() }}">Previous</a>
                        @endif
                        @if ($objects->hasMorePages())
                            <a class="btn btn-secondary btn-sm" rel="next" href="{{ $objects->appends(['state' => $state, 'prefix' => $prefix])->nextPageUrl() }}">Next</a>
                        @else
                            <span class="btn btn-quiet btn-sm" aria-disabled="true">Next</span>
                        @endif
                    </nav>
                </div>
            @endif
        </section>
    </div>

    @if ($state === 'trash')
        @foreach ($objects as $object)
            <dialog class="dialog bucket-purge" id="purge-object-{{ $object->id }}" aria-labelledby="purge-title-{{ $object->id }}" aria-describedby="purge-description-{{ $object->id }}">
                <div class="dialog-header">
                    <h2 id="purge-title-{{ $object->id }}">Permanently delete object?</h2>
                    <button class="icon-button dialog-close" type="button" data-close-dialog aria-label="Close delete dialog" autofocus><x-console-icon name="close" /></button>
                </div>
                <form method="post" action="{{ url('/buckets/'.$bucket->name.'/objects/'.$object->id.'/purge') }}">
                    @csrf
                    @method('DELETE')
                    <div class="dialog-body">
                        <p class="mono bucket-purge-key">{{ $object->key }}</p>
                        <p id="purge-description-{{ $object->id }}">This permanently removes the object and its stored data from {{ $bucket->name }}. You cannot restore it after deletion.</p>
                        <label class="bucket-confirm" for="confirm-purge-{{ $object->id }}">
                            <input id="confirm-purge-{{ $object->id }}" type="checkbox" name="confirm_purge" value="1" required>
                            <span>I understand this object will be permanently deleted. This cannot be undone.</span>
                        </label>
                    </div>
                    <div class="dialog-footer">
                        <button class="btn btn-secondary" type="button" data-close-dialog>Cancel</button>
                        <button class="btn btn-danger" type="submit"><x-console-icon name="trash" /> Permanently delete</button>
                    </div>
                </form>
            </dialog>
        @endforeach
    @endif
@endsection
