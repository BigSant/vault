@extends('layout')
@section('title', 'Grants')
@section('content')
<h1>Grants</h1>
@include('tabs', ['area' => 'agents', 'current' => 'grants', 'space' => $space])
@if($agent || $entry)
<p class="note">Showing grants @if($agent)for {{ $agent->name }} @endif @if($entry)on {{ $entry->name }} @endif · <a href="/workspaces/{{ $space->id }}/grants">Clear filters</a></p>
@endif
@if($grants->isEmpty())
    <div class="empty">No matching grants.</div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Action</th><th scope="col">Agent</th><th scope="col">Entry</th><th scope="col">Expires</th><th scope="col">State</th><th scope="col"></th></tr></thead>
    <tbody>
    @foreach($grants as $g)
        @php
            $machine = $machines[$g->machine_id];
            $gState = $g->revoked_at || $machine->revoked_at ? 'revoked' : (min(strtotime($g->expires_at), strtotime($machine->expires_at)) <= time() ? 'expired' : 'active');
        @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Action"><code>{{ $g->action }}</code></td>
            <td data-label="Agent">{{ $machine->name }}</td>
            <td data-label="Entry"><a href="/entries/{{ $g->entry_id }}">{{ $entries[$g->entry_id]->name }}</a></td>
            <td class="when" data-label="Expires">{{ $g->expires_at }}</td>
            <td data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
            <td data-label="">@unless($g->revoked_at)<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/grants/{{ $g->id }}">@csrf<button class="stop">Revoke</button></form>@endunless</td>
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
        <label><span>Entry</span><select name="entry_id">@foreach($entries->where('state', 'active') as $e)<option value="{{ $e->id }}"{{ $e->id === $entry?->id ? ' selected' : '' }}>{{ $e->name }} · {{ $e->type }}</option>@endforeach</select></label>
        <label><span>Action</span><select name="action">@foreach($actions as $id => $a)<option value="{{ $id }}">{{ $id }} · {{ $a['kind'] }}</option>@endforeach</select></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="1" required></label>
        <label class="check"><input type="checkbox" name="approval" value="1" checked> Ask me before every run</label>
        <div class="form-foot"><p class="note">The entry's type must have the fields the action reads.</p><button class="go">Grant</button></div>
    </form>
</div>
@endsection
