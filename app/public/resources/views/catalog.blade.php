@extends('layout')
@section('title', 'Entries')
@section('content')
@php $canEdit = $space && $space->role !== 'viewer'; $archived = request('state') === 'archived'; @endphp
<div class="head">
    <h1>{{ $archived ? 'Archived entries' : 'Entries' }}</h1>
    @if($canEdit)<a class="btn go" href="/entries/new?workspace={{ $space->id }}">+ Add an entry</a>@endif
</div>
@if($space)
<div class="stats">
    <div class="stat"><b>{{ $entries->total() }}</b><span>{{ $archived ? 'archived' : 'entries' }}</span></div>
    <div class="stat"><b>{{ $projects->count() }}</b><span>projects</span></div>
    <div class="stat"><b>{{ $environments->count() }}</b><span>environments</span></div>
    <div class="stat"><b>{{ count($typeNames) }}</b><span>entry types</span></div>
    <div class="stat"><b>{{ $spaces->count() }}</b><span>workspaces</span></div>
</div>
@endif

<form method="get" class="toolbar">
    <label>Workspace<select name="workspace">@foreach($spaces as $s)<option value="{{ $s->id }}" @selected($space?->id === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
    <label class="grow">Search<input name="q" value="{{ request('q') }}" placeholder="name, tag or alias" maxlength="100"></label>
    <label>Project<select name="project"><option value="">all</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project') === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
    <label>Environment<select name="environment"><option value="">all</option>@foreach($environments as $e)<option value="{{ $e->id }}" @selected(request('environment') === $e->id)>{{ $projects->firstWhere('id', $e->project_id)?->name }} / {{ $e->name }}</option>@endforeach</select></label>
    <label>Type<select name="type"><option value="">all</option>@foreach($typeNames as $type => $label)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>@endforeach</select></label>
    <label>State<select name="state"><option value="active">active</option><option value="archived" @selected($archived)>archived</option></select></label>
    <button>Search</button>
</form>

@if($entries->isEmpty())
    <div class="empty"><b>No entries here yet.</b>
        @if(!$space) Create a workspace below.
        @elseif($projects->isEmpty()) Start with a project: <a href="/workspaces/{{ $space->id }}/structure">Projects</a>.
        @elseif($canEdit) <a href="/entries/new?workspace={{ $space->id }}">Add the first entry</a>. Secret values are never shown in this list.
        @else Nothing matches the filters.
        @endif
    </div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Entry · tags</th><th scope="col">Project</th><th scope="col">Environment</th><th scope="col">Type</th><th scope="col">Updated</th><th scope="col">Last used by an agent</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        @php $due = $e->rotate_at && strtotime($e->rotate_at) < time(); @endphp
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

<h2>Workspace</h2>
<div class="deck">
    @if($canEdit)
    <form class="action" action="/workspaces/{{ $space->id }}/projects" method="post">@csrf
        <h3>Add a project<small>Gets the environments from the workspace template. <a href="/workspaces/{{ $space->id }}/structure">Change the template</a></small></h3>
        <label><span>Name</span><input name="name" required maxlength="100" placeholder="shop"></label>
        <div class="form-foot"><p class="note"></p><button>Add project</button></div>
    </form>
    @endif
    <form class="action" action="/workspaces" method="post">@csrf
        <h3>Add a workspace<small>A separate set of projects, types, members and agents.</small></h3>
        <label><span>Name</span><input name="name" required maxlength="100"></label>
        <div class="form-foot"><p class="note"></p><button>Add workspace</button></div>
    </form>
</div>
@endsection
