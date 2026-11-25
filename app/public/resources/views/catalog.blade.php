@extends('layout')
@section('title', 'Entries')
@section('content')
@php
    $canEdit = $space && $space->role !== 'viewer';
    $archived = request('state') === 'archived';
    $now = time();
    $dueCount = $stats['due'] ?? 0;
    $prodCount = $stats['production'] ?? 0;
    $usedRecent = $stats['used'] ?? 0;
    $hasFilters = request()->filled('q') || request()->filled('project') || request()->filled('environment') || request()->filled('type');
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
    <div class="stat"><b>{{ $entries->total() }}</b><span>{{ $archived ? 'archived' : 'entries' }}</span></div>
    <div class="stat"><b>{{ $dueCount }}</b><span>rotation due</span></div>
    <div class="stat"><b>{{ $prodCount }}</b><span>production</span></div>
    <div class="stat"><b>{{ $usedRecent }}</b><span>agent use · 7 days</span></div>
</div>

<form method="get" class="toolbar">
    <input type="hidden" name="workspace" value="{{ $space->id }}">
    <label class="grow">Search<input name="q" value="{{ request('q') }}" placeholder="name, tag or alias" maxlength="100"></label>
    <label>Project<select name="project"><option value="">all</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project') === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
    <label>Environment<select name="environment"><option value="">all</option>@foreach($environments as $e)<option value="{{ $e->id }}" @selected(request('environment') === $e->id)>{{ $projects->firstWhere('id', $e->project_id)?->name }} / {{ $e->name }}</option>@endforeach</select></label>
    <label>Type<select name="type"><option value="">all</option>@foreach($typeNames as $type => $label)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>@endforeach</select></label>
    <label>State<select name="state"><option value="active">active</option><option value="archived" @selected($archived)>archived</option></select></label>
    <button>Search</button>
</form>
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
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Entry · tags</th><th scope="col">Project</th><th scope="col">Environment</th><th scope="col">Type</th><th scope="col">Updated</th><th scope="col">Last used by an agent</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        @php $due = $e->rotate_at && strtotime($e->rotate_at) < $now; @endphp
        <tr class="{{ $e->state === 'archived' ? 'is-idle' : ($due ? '' : 'is-ok') }}">
            <td class="name" data-label="Entry · tags"><a href="/entries/{{ $e->id }}">{{ $e->name }}</a>@if($e->tags)<small>{{ $e->tags }}</small>@endif</td>
            <td data-label="Project">{{ $e->project }}</td>
            <td data-label="Environment">@include('sign', ['name' => $e->environment, 'production' => $e->production])</td>
            <td data-label="Type">{{ $typeNames[$e->type] ?? $e->type }}</td>
            <td class="when" data-label="Updated">{{ substr($e->updated_at, 0, 10) }}@if($due)<small><span class="chip warn">rotate</span> due {{ substr($e->rotate_at, 0, 10) }}</small>@endif</td>
            <td class="when" data-label="Last used by an agent">{{ $e->last_used_at ? substr($e->last_used_at, 0, 16) : '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<div class="pager"><span>@if($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">← Previous</a>@endif</span><span class="muted">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span><span>@if($entries->hasMorePages())<a href="{{ $entries->nextPageUrl() }}">Next →</a>@endif</span></div>
@endif
@endsection
