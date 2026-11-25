@extends('layout')
@section('title', 'Grants')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'grants', 'space' => $space])

<form class="toolbar" method="get">
    <label>Agent <select name="agent"><option value="">All agents</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected($m->id === $agent?->id)>{{ $m->name }}</option>@endforeach</select></label>
    <label>Entry <select name="entry"><option value="">All entries</option>@foreach($entries as $e)<option value="{{ $e->id }}" @selected($e->id === $entry?->id)>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }}</option>@endforeach</select></label>
    <button>Apply</button>@if($agent || $entry)<a href="/workspaces/{{ $space->id }}/grants">Clear filters</a>@endif
</form>
@if($agent || $entry)
<p class="filter-status">Showing {{ $grants->count() }} of {{ $total }}
    @if($agent)<span class="chip filter">Agent: {{ $agent->name }}</span>@endif
    @if($entry)<span class="chip filter">Entry: {{ $entry->name }} — {{ $entry->project }} / {{ $entry->environment }}</span>@endif
</p>
@endif
@if($grants->isEmpty())
    <div class="empty">No matching grants.</div>
@else
<div class="scroll"><table class="board compact-board grants">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Entry</th><th scope="col">Approval</th><th scope="col">Expiry (UTC)</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($grants as $g)
        @php
            $machine = $machines[$g->machine_id];
            $target = $entries[$g->entry_id];
            $revoke = "Revoke grant {$g->action} for {$machine->name} on {$target->name} — {$target->project} / {$target->environment}";
            $gState = $g->revoked_at || $machine->revoked_at ? 'revoked' : (min(strtotime($g->expires_at), strtotime($machine->expires_at)) <= time() ? 'expired' : 'active');
        @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Agent">{{ $machine->name }}</td>
            <td class="end" data-label="Action"><code>{{ $g->action }}</code></td>
            <td class="full" data-label="Entry"><a href="/entries/{{ $g->entry_id }}">{{ $target->name }}</a> <small>{{ $target->project }} / @include('sign', ['name' => $target->environment, 'production' => $target->production])</small></td>
            <td data-label="Approval">{{ $g->approval ? 'asks every time' : 'runs without asking' }}</td>
            <td class="when" data-label="Expires (UTC)"><small class="k">{{ strtotime($g->expires_at) <= time() ? 'Expired' : 'Expires' }}</small> {{ substr($g->expires_at, 0, 16) }} <small class="k">UTC</small></td>
            <td data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
            <td data-label="">@if($gState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/grants/{{ $g->id }}" data-confirm="{{ $revoke }}?">@csrf<button class="stop" aria-label="{{ $revoke }}">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<h2>Grant an action</h2>
@php
    $activeMachines = $machines->whereNull('revoked_at')->filter(fn ($m) => strtotime($m->expires_at) > time());
    $activeEntries = $entries->where('state', 'active');
@endphp
@if($activeMachines->isEmpty() || $activeEntries->isEmpty() || !$actions)
    <div class="empty">
        @if($activeMachines->isEmpty())<p>No active agents. <a href="/workspaces/{{ $space->id }}/agents">Add an agent in Identities</a>.</p>@endif
        @if($activeEntries->isEmpty())<p>No active entries. <a href="/?workspace={{ $space->id }}">Manage Entries</a>.</p>@endif
        @unless($actions)<p>No actions are configured. Ask the operator to register the allowed target systems.</p>@endunless
    </div>
@else
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/grants">@csrf
        <label><span>Agent</span><select name="machine_id">@foreach($activeMachines as $m)<option value="{{ $m->id }}"{{ $m->id === $agent?->id ? ' selected' : '' }}>{{ $m->name }}</option>@endforeach</select></label>
        <label><span>Entry</span><select name="entry_id">@foreach($activeEntries as $e)<option value="{{ $e->id }}"{{ $e->id === $entry?->id ? ' selected' : '' }}>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }} · {{ $typeNames[$e->type] ?? $e->type }}</option>@endforeach</select></label>
        <label><span>Action</span><select name="action">@foreach($actions as $id => $a)<option value="{{ $id }}">{{ $id }}</option>@endforeach</select></label>
        <div class="note">@foreach($actions as $id => $a)<div>{{ $id }} requires {{ implode(', ', \App\Vault\EntryTypes::actionFields($a['kind'])) }}.</div>@endforeach</div>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="1" required></label>
        <label class="check"><input type="checkbox" name="approval" value="1" checked> Ask me before every run</label>
        @include('confirm-inline', ['verb' => 'grant this action'])
        <div class="form-foot"><p class="note">The entry's type must have the fields the action reads.</p><button class="go">Grant</button></div>
    </form>
@endif
@endsection
