@extends('layout')
@section('title', 'Entries')
@section('content')
@php
    $canEdit = $space && $space->role !== 'viewer';
    $archived = request('state') === 'archived';
    $dueOnly = request()->boolean('due');
    $now = time();
    $activeCount = array_key_exists('active', $stats) ? (int) $stats['active'] : ($archived ? null : $entries->total());
    $dueCount = (int) ($stats['due'] ?? 0);
    $prodCount = (int) ($stats['production'] ?? 0);
    $usedRecent = (int) ($stats['used'] ?? 0);
    $drawerCount = (request()->filled('project') ? 1 : 0)
        + (request()->filled('environment') ? 1 : 0)
        + (request()->filled('type') ? 1 : 0)
        + ($archived ? 1 : 0)
        + ($dueOnly ? 1 : 0);
    $hasFilters = request()->filled('q') || $drawerCount > 0;
@endphp
<div class="head">
    <h1>{{ $archived ? 'Archived entries' : 'Entries' }}</h1>
    @if($canEdit)
    <div class="controls">
        <a class="btn" href="/workspaces/{{ $space->id }}/import">Import</a>
        <a class="btn go" href="/entries/new?workspace={{ $space->id }}">+ Add an entry</a>
    </div>
    @endif
</div>
@if($space)
<div class="stats">
    <div class="stat"><b>{{ $activeCount ?? '—' }}</b><span>{{ $archived ? 'active entries' : 'entries' }}</span></div>
    <a class="stat stat-link{{ $dueCount > 0 ? ' has-due' : '' }}{{ $dueOnly ? ' is-on' : '' }}" href="/?workspace={{ $space->id }}&due=1"><b>{{ $dueCount }}</b><span>rotation due →</span></a>
    <div class="stat"><b>{{ $prodCount }}</b><span>production</span></div>
    <div class="stat"><b>{{ $usedRecent }}</b><span>used by agents · 7 days</span></div>
</div>

<form method="get" class="catalog-filter">
    <input type="hidden" name="workspace" value="{{ $space->id }}">
    <div class="search-row">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, tag, alias" maxlength="100" aria-label="Search entries">
        <button>Search</button>
    </div>
    <details class="filters">
        <summary>Filters @if($drawerCount)<span class="chip warn">{{ $drawerCount }}</span>@endif</summary>
        <div class="inner filter-grid">
            <label>Project<select name="project"><option value="">all</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project') === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
            <label>Environment<select name="environment"><option value="">all</option>@foreach($environments as $e)<option value="{{ $e->id }}" @selected(request('environment') === $e->id)>{{ $projects->firstWhere('id', $e->project_id)?->name }} / {{ $e->name }}</option>@endforeach</select></label>
            <label>Type<select name="type"><option value="">all</option>@foreach($typeNames as $type => $label)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>@endforeach</select></label>
            <label>State<select name="state"><option value="active">active</option><option value="archived" @selected($archived)>archived</option></select></label>
            <label class="check"><input type="checkbox" name="due" value="1" @checked($dueOnly)> Rotation due only</label>
            <div class="filter-actions"><button>Apply filters</button></div>
        </div>
    </details>
</form>

@if($hasFilters)
<div class="filter-status">
    <span>
        @if($archived)
            {{ $entries->total() }} archived
        @elseif($activeCount !== null)
            Showing {{ $entries->total() }} of {{ $activeCount }}
        @else
            Showing {{ $entries->total() }}
        @endif
    </span>
    @if($dueOnly)<span class="chip warn">Rotation due</span>@endif
    @if($archived)<span class="chip muted">Archived</span>@endif
    <a href="/?workspace={{ $space->id }}">Clear filters</a>
</div>
@endif
@endif

@if($entries->isEmpty())
    <div class="empty">
        @if(!$space)
            <b>No workspaces yet.</b>
            <a href="/workspaces/new">Create a workspace</a> to start storing entries.
        @elseif($projects->isEmpty())
            <b>No projects in this workspace yet.</b>
            @if($canEdit)Add a project in <a href="/workspaces/{{ $space->id }}/structure">Setup</a> first.@else Ask an editor or owner to create a project.@endif
        @elseif($archived || $hasFilters)
            <b>No matching entries.</b>
            Nothing matches the current filters.@if($hasFilters) <a href="/?workspace={{ $space->id }}">Clear filters</a>.@endif
        @elseif($canEdit)
            <b>No entries here yet.</b>
            <a href="/entries/new?workspace={{ $space->id }}">Add the first entry</a> or <a href="/workspaces/{{ $space->id }}/import">import from JSON</a>. Secret values are never shown in this list.
        @else
            <b>No entries here yet.</b>
            Nothing has been added to this workspace yet.
        @endif
    </div>
@else
<div class="scroll"><table class="board entries">
    <thead><tr><th scope="col">Entry · tags</th><th scope="col">Project</th><th scope="col">Environment</th><th scope="col">Type</th><th scope="col">Updated</th><th scope="col">Last used (UTC)</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        @php $due = $e->rotate_at && strtotime($e->rotate_at) < $now; @endphp
        <tr class="{{ $e->state === 'archived' ? 'is-idle' : ($due ? '' : 'is-ok') }}">
            <td class="name" data-label=""><a href="/entries/{{ $e->id }}">{{ $e->name }}</a>@if($e->tags)<small>{{ $e->tags }}</small>@endif</td>
            <td class="proj" data-label="Project">{{ $e->project }}</td>
            <td class="env" data-label="Environment">@include('sign', ['name' => $e->environment, 'production' => $e->production])</td>
            <td class="type" data-label="Type">{{ $typeNames[$e->type] ?? $e->type }}</td>
            <td class="when updated" data-label="Updated">{{ substr($e->updated_at, 0, 10) }}@if($due)<small><span class="chip warn">rotate</span> due {{ substr($e->rotate_at, 0, 10) }}</small>@endif</td>
            <td class="when used{{ $e->last_used_at ? '' : ' is-empty' }}" data-label="Last used (UTC)">{{ $e->last_used_at ? substr($e->last_used_at, 0, 16) : '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@if($entries->lastPage() > 1)
<div class="pager"><span>@if($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">← Previous</a>@endif</span><span class="muted">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span><span>@if($entries->hasMorePages())<a href="{{ $entries->nextPageUrl() }}">Next →</a>@endif</span></div>
@endif
@endif
@endsection
