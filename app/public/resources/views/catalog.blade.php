@extends('layout')
@section('title', request('state') === 'archived' ? 'Archived entries' : 'Entries')
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
    $filterCount = (request()->filled('project') ? 1 : 0)
        + (request()->filled('environment') ? 1 : 0)
        + (request()->filled('type') ? 1 : 0)
        + (request()->filled('q') ? 1 : 0) + ($archived ? 1 : 0)
        + ($dueOnly ? 1 : 0);
    $hasFilters = $filterCount > 0;
    $activeProject = request()->filled('project') ? $projects->firstWhere('id', request('project')) : null;
    $activeEnv = request()->filled('environment') ? $environments->firstWhere('id', request('environment')) : null;
    $activeEnvLabel = $activeEnv
        ? trim(($projects->firstWhere('id', $activeEnv->project_id)?->name ?? '').' / '.$activeEnv->name, ' /')
        : null;
    $activeType = request()->filled('type') ? ($typeNames[request('type')] ?? request('type')) : null;
@endphp
<div class="head">
    <h1>{{ $archived ? 'Archived entries' : 'Entries' }}</h1>
    @if(!$archived && $canEdit)
    <div class="controls">
        <a class="btn" href="/workspaces/{{ $space->id }}/import">Import</a>
        <a class="btn go" href="/entries/new?workspace={{ $space->id }}">+ Add an entry</a>
    </div>
    @endif
</div>
@if($space)
<div class="stats">
    @if($archived)
        <div class="stat"><b>{{ $archivedCount }}</b><span>archived entries</span></div>
    @else
        <div class="stat"><b>{{ $activeCount ?? '—' }}</b><span>active entries</span></div>
    <a class="stat stat-link{{ $dueCount > 0 ? ' has-due' : '' }}{{ $dueOnly ? ' is-on' : '' }}" href="{{ '/?'.http_build_query(array_merge(request()->except(['page', 'due']), ['workspace' => $space->id], $dueOnly ? [] : ['due' => 1])) }}"{!! $dueOnly ? ' aria-current="true"' : '' !!}><b>{{ $dueCount }}</b><span>{{ $dueOnly ? 'rotation due' : 'rotation due →' }}</span></a>
    <div class="stat secondary"><b>{{ $prodCount }}</b><span>production</span></div>
    <div class="stat secondary"><b>{{ $usedRecent }}</b><span>used by agents <span class="nobreak">(7 days)</span></span></div>
    @endif
</div>

<details class="filters" data-wide-open>
    <summary>Filters @if($filterCount)<span class="chip muted">{{ $filterCount }}<span class="sr-only"> active</span></span>@endif</summary>
    <form class="toolbar" method="get">
        <input type="hidden" name="workspace" value="{{ $space->id }}">
        <label class="grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, tag, alias" maxlength="100"></label>
        <label>Project<select name="project"><option value="">All projects</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project') === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
        <label>Environment<select name="environment"><option value="">All environments</option>@foreach($environments as $e)<option value="{{ $e->id }}" @selected(request('environment') === $e->id)>{{ $e->project }} / {{ $e->name }}</option>@endforeach</select></label>
        <label>Type<select name="type"><option value="">All types</option>@foreach($typeNames as $type => $label)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>@endforeach</select></label>
        <label>State<select name="state"><option value="active">Active</option><option value="archived" @selected($archived)>Archived</option></select></label>
        <label>Rotation<select name="due"><option value="">All entries</option><option value="1" @selected($dueOnly)>Rotation due only</option></select></label>
        <button>Apply</button>
    </form>
</details>

@if($hasFilters)
<div class="filter-status">
    <span>Showing {{ $entries->total() }} of {{ $scopeCount }}</span>
    @if(request()->filled('q'))<span class="chip filter">Search: {{ request('q') }}</span>@endif
    @if($activeProject)<span class="chip filter">Project: {{ $activeProject->name }}</span>@endif
    @if($activeEnvLabel)<span class="chip filter">Environment: {{ $activeEnvLabel }}</span>@endif
    @if($activeType)<span class="chip filter">Type: {{ $activeType }}</span>@endif
    @if($dueOnly)<span class="chip warn">rotation due</span>@endif
    @if($archived)<span class="chip muted">Archived</span>@endif
    <a href="/?workspace={{ $space->id }}">{{ $archived && $filterCount === 1 ? 'Back to active entries' : 'Clear filters' }}</a>
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
            Nothing matches the current filters.
        @elseif($canEdit)
            <b>No entries here yet.</b>
            <a href="/entries/new?workspace={{ $space->id }}">Add the first entry</a> or <a href="/workspaces/{{ $space->id }}/import">import from JSON</a>. Secret values are never shown in this list.
        @else
            <b>No entries here yet.</b>
            Nothing has been added to this workspace yet.
        @endif
    </div>
@else
<div class="scroll"><table class="board compact-board entries">
    <thead><tr><th scope="col">Entry · tags</th><th scope="col">Project</th><th scope="col">Environment</th><th scope="col">Type</th><th scope="col">Updated</th><th scope="col">Last used (UTC)</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        @php $due = $e->rotate_at && strtotime($e->rotate_at) < $now; @endphp
        <tr class="{{ $e->state === 'archived' ? 'is-idle' : ($due ? 'is-wait' : 'is-ok') }}">
            <td class="name full" data-label="Entry"><a href="/entries/{{ $e->id }}">{{ $e->name }}</a>@if($e->tags)<small>{{ $e->tags }}</small>@endif</td>
            <td class="proj" data-label="Project">{{ $e->project }}</td>
            <td class="env end" data-label="Environment">@include('sign', ['name' => $e->environment, 'production' => $e->production])</td>
            <td class="type" data-label="Type"><span class="k" aria-hidden="true">· </span>{{ $typeNames[$e->type] ?? $e->type }}</td>
            <td class="when updated full" data-label="Updated"><small class="k">Updated</small> {{ substr($e->updated_at, 0, 10) }}@if($due)<small><span class="chip warn">rotation due</span> {{ substr($e->rotate_at, 0, 10) }}</small>@endif</td>
            <td class="when used full{{ $e->last_used_at ? '' : ' is-empty' }}" data-label="Last used (UTC)">@if($e->last_used_at)<small class="k">Last used</small> {{ substr($e->last_used_at, 0, 16) }} <small class="k">UTC</small>@else—@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@if($entries->lastPage() > 1)
<div class="pager"><span>@if($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">← Previous</a>@endif</span><span class="muted">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span><span>@if($entries->hasMorePages())<a href="{{ $entries->nextPageUrl() }}">Next →</a>@endif</span></div>
@endif
@endif
@endsection
