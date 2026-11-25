@extends('layout')
@section('title', 'Grants')
@section('content')
<div class="head"><h1>Agents</h1><a class="btn" href="#add-grant">+ Add a grant</a></div>
@include('tabs', ['area' => 'agents', 'current' => 'grants', 'space' => $space])

@php $filterCount = (int) (bool) $agent + (int) (bool) $entry; @endphp
<details class="filters" data-wide-open>
    <summary>Filters @if($filterCount)<span class="chip muted">{{ $filterCount }}<span class="sr-only"> active</span></span>@endif</summary>
    <form class="toolbar" method="get">
        <label>Agent <select name="agent"><option value="">All agents</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected($m->id === $agent?->id)>{{ $m->name }}</option>@endforeach</select></label>
        <label>Entry <select name="entry"><option value="">All entries</option>@foreach($entries as $e)<option value="{{ $e->id }}" @selected($e->id === $entry?->id)>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }}</option>@endforeach</select></label>
        <button>Apply</button>
    </form>
</details>
@if($agent || $entry)
<p class="filter-status"><span class="filter-count">Showing {{ $grants->count() }} of {{ $total }} grants <a href="/workspaces/{{ $space->id }}/grants">Clear filters</a></span>
    @if($agent)<span class="chip filter">Agent: {{ $agent->name }}</span>@endif
    @if($entry)<span class="chip filter">Entry: {{ $entry->name }} — {{ $entry->project }} / {{ $entry->environment }}</span>@endif
</p>
@endif
@php
    $activeMachines = $machines->whereNull('revoked_at')->filter(fn ($m) => strtotime($m->expires_at) > time());
    $activeEntries = $entries->where('state', 'active');
@endphp
@if($grants->isEmpty())
    <div class="empty capped">{{ $filterCount ? 'No matching grants.' : 'No grants yet.' }}
        @unless($filterCount)
            @if($activeMachines->isEmpty())<a href="/workspaces/{{ $space->id }}/agents">Add an agent in Identities</a>.
            @elseif($activeEntries->isNotEmpty() && $actions)<a href="#add-grant">Add a grant</a>.
            @endif
        @endunless
    </div>
@else
<div class="scroll"><table class="board compact-board grants">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Entry</th><th scope="col">Approval</th><th scope="col">Expires (UTC)</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($grants as $g)
        @php
            $machine = $machines[$g->machine_id];
            $target = $entries[$g->entry_id];
            $revoke = "Revoke grant {$g->action} for {$machine->name} on {$target->name} — {$target->project} / {$target->environment}";
            $gState = $g->state;
        @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td class="who" data-label="Agent"><b class="nobreak">{{ $machine->name }}</b></td>
            <td class="action end" data-label="Action"><code>{{ $g->action }}</code></td>
            <td class="name entry full" data-label="Entry"><a href="/entries/{{ $g->entry_id }}">{{ $target->name }}</a> <small class="nobreak"><span class="k">· </span>{{ $target->project }} / @include('sign', ['name' => $target->environment, 'production' => $target->production])</small></td>
            <td class="approval" data-label="Approval">{{ $g->approval ? 'asks every time' : 'runs without asking' }}</td>
            <td class="when" data-label="Expires (UTC)">@include('end-date', ['revoked' => $g->effective_revoked_at, 'ends' => $g->effective_expires_at, 'reason' => $g->expiry_reason, 'own' => $g->expires_at])</td>
            <td class="state" data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
            <td data-label="">@if($gState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/grants/{{ $g->id }}" data-confirm="{{ $revoke }}?">@csrf<button class="stop" aria-label="{{ $revoke }}">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<h2 id="add-grant">Add a grant</h2>

@if($activeMachines->isEmpty() || $activeEntries->isEmpty() || !$actions)
    <div class="empty capped">
        @if($activeMachines->isEmpty())<p>No active agents. <a href="/workspaces/{{ $space->id }}/agents">Add an agent in Identities</a>.</p>@endif
        @if($activeEntries->isEmpty())<p>No active entries. <a href="/entries/new?workspace={{ $space->id }}">Add an entry</a>.</p>@endif
        @unless($actions)<p>No actions are configured. Ask the operator to register the allowed target systems.</p>@endunless
    </div>
@else
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/grants">@csrf
        <label><span>Agent</span><select name="machine_id" required><option value="">Choose an agent</option>@foreach($activeMachines as $m)<option value="{{ $m->id }}"{{ $m->id === old('machine_id', $agent?->id) ? ' selected' : '' }}>{{ $m->name }}</option>@endforeach</select></label>
        <label><span>Entry</span><select name="entry_id" required><option value="">Choose an entry</option>@foreach($activeEntries as $e)<option data-fields="{{ implode(',', $typeFields[$e->type] ?? []) }}" value="{{ $e->id }}"{{ $e->id === old('entry_id', $entry?->id) ? ' selected' : '' }}>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }} · {{ $typeNames[$e->type] ?? $e->type }}</option>@endforeach</select></label>
        <label><span>Action</span><select name="action" required><option value="">Choose an action</option>@foreach($actions as $id => $a)@php $required = \App\Vault\EntryTypes::actionFields($a['kind']); @endphp<option value="{{ $id }}" data-fields="{{ implode(',', $required) }}" @selected(old('action') === $id)>{{ $id }}</option>@endforeach</select></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="{{ old('days', 1) }}" required></label>
        <label class="check"><input type="checkbox" name="approval" value="1" @checked(old('approval', !session()->hasOldInput()))> Ask me before every run</label>
        @include('confirm-inline', ['verb' => 'grant this action'])
        <div class="form-foot"><p class="note">The entry's type must have the fields the action reads.</p><button class="go">Add grant</button></div>
    </form>
@endif
@endsection
