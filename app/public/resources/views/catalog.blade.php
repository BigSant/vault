@extends('layout')
@section('title', 'Entries')
@section('content')
@php
    $canEdit = $space && $space->role !== 'viewer';
    $archived = request('state') === 'archived';
    $dueOnly = request('state') === 'due';
    $now = time();
    $activeCount = (int) $stats['active'];
    $dueCount = (int) ($stats['due'] ?? 0);
    $prodCount = (int) ($stats['production'] ?? 0);
    $usedRecent = (int) ($stats['used'] ?? 0);
    $filterCount = (request()->filled('project') ? 1 : 0)
        + (request()->filled('environment') ? 1 : 0)
        + (request()->filled('type') ? 1 : 0)
        + ($archived ? 1 : 0)
        + ($dueOnly ? 1 : 0);
    $hasFilters = $filterCount > 0 || request()->filled('q');
    $activeProject = request()->filled('project') ? $projects->firstWhere('id', request('project')) : null;
    $activeEnv = request()->filled('environment') ? $environments->firstWhere('id', request('environment')) : null;
    $activeEnvLabel = $activeEnv
        ? trim(($projects->firstWhere('id', $activeEnv->project_id)?->name ?? '').' / '.$activeEnv->name, ' /')
        : null;
    $activeType = request()->filled('type') ? ($typeNames[request('type')] ?? request('type')) : null;
    // The rotation-due number is counted inside the search and the project, environment and type filters, which its
    // link keeps: while any of them is on, the label says so.
    $dueScoped = request()->filled('q') || request()->filled('project') || request()->filled('environment') || request()->filled('type');
    $dueLabel = $dueScoped ? 'rotation due in this view' : 'rotation due';
@endphp
<div class="head">
    <h1>Entries</h1>
    @if($canEdit)
    <div class="controls">
        <a class="btn" href="/workspaces/{{ $space->id }}/import">Import</a>
        <a class="btn go" href="/entries/new?workspace={{ $space->id }}">+ Add an entry</a>
    </div>
    @endif
</div>
@if($space)
@unless($archived)
<div class="stats entry-stats">
    <div class="stat"><b>{{ $activeCount }}</b><span>active {{ Str::plural('entry', $activeCount) }}</span></div>
    <a class="stat stat-link{{ $dueCount > 0 ? ' has-due' : '' }}{{ $dueOnly ? ' is-on' : '' }}{{ $dueScoped ? ' is-scoped' : '' }}" href="{{ '/?'.http_build_query(array_merge(request()->except(['page', 'state', 'due']), ['workspace' => $space->id], $dueOnly ? [] : ['state' => 'due'])) }}"{!! $dueOnly ? ' aria-current="true"' : '' !!}><b>{{ $dueCount }}</b><span>{{ $dueOnly ? $dueLabel : $dueLabel.' →' }}</span>@if($dueOnly) <span class="stat-clear">Clear<span class="sr-only"> filter</span> ×</span>@endif</a>
    <div class="stat"><b>{{ $prodCount }}</b><span>in production</span></div>
    <div class="stat"><b>{{ $usedRecent }}</b><span>used by agents, 7 days</span></div>
</div>
@endunless

<form class="catalog-filters" method="get">
    <input type="hidden" name="workspace" value="{{ $space->id }}">
    <div class="toolbar entry-search">
        <label class="grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="name, tag or alias" maxlength="100"></label>
        <button class="catalog-search">Search</button>
    </div>
    <details class="filters" data-wide-open>
        <summary>Filters @if($filterCount)<span class="chip muted">{{ $filterCount }}<span class="sr-only"> active</span></span>@endif</summary>
        <div class="toolbar">
            <label>Project<select name="project"><option value="">All projects</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project') === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
            <label>Environment<select name="environment"><option value="">All environments</option>@foreach($environments as $e)<option value="{{ $e->id }}" @selected(request('environment') === $e->id)>{{ $e->project }} / {{ $e->name }}</option>@endforeach</select></label>
            <label>Type<select name="type"><option value="">All types</option>@foreach($typeNames as $type => $label)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>@endforeach</select></label>
            <label>State<select name="state"><option value="active" @selected(!$dueOnly && !$archived)>Active</option><option value="due" @selected($dueOnly)>Rotation due</option><option value="archived" @selected($archived)>Archived</option></select></label>
            <button>Apply</button>
        </div>
    </details>
</form>

@if($hasFilters)
<div class="filter-status">
    <span class="filter-count">Showing {{ $entries->total() }} of {{ $scopeCount }} {{ Str::plural('entry', $scopeCount) }} <a href="/?workspace={{ $space->id }}">Clear filters</a></span>
    @if(request()->filled('q'))<span class="chip filter">Search: {{ request('q') }}</span>@endif
    @if($activeProject)<span class="chip filter">Project: {{ $activeProject->name }}</span>@endif
    @if($activeEnvLabel)<span class="chip filter">Environment: {{ $activeEnvLabel }}</span>@endif
    @if($activeType)<span class="chip filter">Type: {{ $activeType }}</span>@endif
    @if($dueOnly)<span class="chip filter">State: Rotation due</span>@endif
    @if($archived)<span class="chip filter">State: Archived</span>@endif
</div>
@endif
@endif

@if($entries->isEmpty())
    <div class="empty">
        @if(!$space)
            <b>No workspaces yet.</b>
            <a href="/workspaces/new">Add a workspace</a> to start storing entries.
        @elseif($projects->isEmpty())
            <b>No projects in this workspace yet.</b>
            @if($canEdit)Add a project in <a href="/workspaces/{{ $space->id }}/structure">Setup</a> first.@else Ask an editor or owner to add a project.@endif
        @elseif($archived || $hasFilters)
            <b>No matching entries.</b>
        @elseif($canEdit)
            <b>No entries here yet.</b>
            <a href="/entries/new?workspace={{ $space->id }}">Add an entry</a> or <a href="/workspaces/{{ $space->id }}/import">import from JSON</a>. Secret values are never shown in this list.
        @else
            <b>No entries here yet.</b>
            Nothing has been added to this workspace yet.
        @endif
    </div>
@else
<div class="scroll"><table class="board compact-board entries">
    <thead><tr><th scope="col">Entry</th><th scope="col">Project</th><th scope="col">Environment</th><th scope="col">Type</th><th scope="col">Updated (UTC)</th><th scope="col">Last used (UTC)</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        @php $due = $e->rotate_at && strtotime($e->rotate_at) < $now; @endphp
        <tr class="{{ $e->state === 'archived' ? 'is-idle' : ($due ? 'is-wait' : 'is-ok') }}">
            <td class="name full" data-label="Entry"><a href="/entries/{{ $e->id }}?from={{ rawurlencode($from) }}">{{ $e->name }}</a>@if($e->state === 'archived')<small><span class="chip muted">archived</span></small>@elseif($due)<small><span class="chip warn">rotation due</span> since {{ substr($e->rotate_at, 0, 10) }}</small>@endif @if($e->tags)<small class="entry-tags">Tags: {{ $e->tags }}</small>@endif</td>
            <td class="proj" data-label="Project">{{ $e->project }}</td>
            <td class="env" data-label="Environment">@include('sign', ['name' => $e->environment, 'production' => $e->production])</td>
            <td class="type" data-label="Type"><span class="k" aria-hidden="true">· </span>{{ $typeNames[$e->type] ?? $e->type }}</td>
            <td class="when updated" data-label="Updated (UTC)"><small class="k">Updated</small> {{ substr($e->updated_at, 0, 10) }}</td>
            <td class="when used{{ $e->last_used_at ? '' : ' is-empty' }}" data-label="Last used (UTC)">@if($e->last_used_at)<small class="k">Last used</small> <span class="nobreak">{{ substr($e->last_used_at, 0, 16) }} <small class="k">UTC</small></span> @else — @endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@if($entries->lastPage() > 1)
<nav class="pager" aria-label="Entry pages"><span>@if($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">← Previous</a>@endif</span><span class="muted">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span><span>@if($entries->hasMorePages())<a href="{{ $entries->nextPageUrl() }}">Next →</a>@endif</span></nav>
@endif
@endif
@if($space && !$archived && $archivedCount)
<p class="note"><a class="more-link" href="/?workspace={{ $space->id }}&amp;state=archived">{{ $archivedCount }} archived {{ Str::plural('entry', $archivedCount) }} →</a></p>
@endif
@endsection
