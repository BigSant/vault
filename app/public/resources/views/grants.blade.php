@extends('layout')
@section('title', 'Grants')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'grants', 'space' => $space])
<h2>Grants</h2>
<form class="toolbar" method="get">
    <label>Agent <select name="agent"><option value="">All agents</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected($m->id === $agent?->id)>{{ $m->name }}</option>@endforeach</select></label>
    <label>Entry <select name="entry"><option value="">All entries</option>@foreach($entries as $e)<option value="{{ $e->id }}" @selected($e->id === $entry?->id)>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }}</option>@endforeach</select></label>
    <button>Apply</button><a href="/workspaces/{{ $space->id }}/grants">Clear filters</a>
</form>
@if($grants->isEmpty())
    <div class="empty">No matching grants.</div>
@else
<div class="scroll"><table class="board compact-board grants">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Entry</th><th scope="col">Approval</th><th scope="col">Expires (UTC)</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($grants as $g)
        @php
            $machine = $machines[$g->machine_id];
            $gState = $g->revoked_at || $machine->revoked_at ? 'revoked' : (min(strtotime($g->expires_at), strtotime($machine->expires_at)) <= time() ? 'expired' : 'active');
        @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Agent">{{ $machine->name }}</td>
            <td data-label="Action"><code>{{ $g->action }}</code></td>
            <td data-label="Entry"><a href="/entries/{{ $g->entry_id }}">{{ $entries[$g->entry_id]->name }}</a></td>
            <td data-label="Approval">{{ $g->approval ? 'every time' : 'no approval' }}</td>
            <td class="when" data-label="Expires (UTC)">{{ substr($g->expires_at, 0, 16) }}</td>
            <td data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
            <td data-label="">@if($gState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/grants/{{ $g->id }}" data-confirm="Revoke this grant for {{ $machine->name }}?">@csrf<button class="stop">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<div class="deck">
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/grants">@csrf
        <h3>Grant an action<small>Lets one agent run one action with one entry.</small></h3>
        @if(count($actions) === 0)<div class="callout warn"><p>No actions are configured yet. The operator has to register the allowed target systems first.</p></div>@endif
        <label><span>Agent</span><select name="machine_id">@foreach($machines->whereNull('revoked_at')->filter(fn ($m) => strtotime($m->expires_at) > time()) as $m)<option value="{{ $m->id }}"{{ $m->id === $agent?->id ? ' selected' : '' }}>{{ $m->name }}</option>@endforeach</select></label>
        <label><span>Entry</span><select name="entry_id">@foreach($entries->where('state', 'active') as $e)<option value="{{ $e->id }}"{{ $e->id === $entry?->id ? ' selected' : '' }}>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }} · {{ $typeNames[$e->type] ?? $e->type }}</option>@endforeach</select></label>
        <label><span>Action</span><select name="action">@foreach($actions as $id => $a)<option value="{{ $id }}">{{ $id }} · requires {{ implode(', ', \App\Vault\EntryTypes::actionFields($a['kind'])) }}</option>@endforeach</select></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="1" required></label>
        <label class="check"><input type="checkbox" name="approval" value="1" checked> Ask me before every run</label>
        @include('confirm-inline', ['verb' => 'grant this action'])
        <div class="form-foot"><p class="note">The entry's type must have the fields the action reads.</p><button class="go">Grant</button></div>
    </form>
</div>
@endsection
