@extends('layout')
@section('title', 'Agents')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])
<h2>Identities</h2>
@if($machines->isEmpty())
    <div class="empty">No agent identities yet. Create one below.</div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Agent</th><th scope="col">Expires (UTC)</th><th scope="col">Active grants</th><th scope="col">State</th><th scope="col"></th></tr></thead>
    <tbody>
    @foreach($machines as $m)
        @php $mState = $m->revoked_at ? 'revoked' : (strtotime($m->expires_at) <= time() ? 'expired' : 'active'); @endphp
        <tr id="agent-{{ $m->id }}" class="{{ $mState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td class="name" data-label="Agent"><b>{{ $m->name }}</b></td>
            <td class="when" data-label="Expires (UTC)">{{ substr($m->expires_at, 0, 16) }}</td>
            <td data-label="Active grants"><a href="/workspaces/{{ $space->id }}/grants?agent={{ $m->id }}">{{ $m->active_grants }}</a></td>
            <td data-label="State"><span class="chip {{ $mState === 'active' ? 'ok' : 'muted' }}">{{ $mState }}</span></td>
            <td data-label="">@unless($m->revoked_at)<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/machines/{{ $m->id }}" data-confirm="Revoke agent {{ $m->name }}? Its token stops working at once.">@csrf<button class="stop">Revoke</button></form>@endunless</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<div class="deck">
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/machines">@csrf
        <h3>Add an agent<small>Creates a bearer token, shown once.</small></h3>
        <label><span>Name</span><input name="name" placeholder="ai-os" required maxlength="100"></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="7" required></label>
        <div class="form-foot"><p class="note">Up to 30 days. It can do nothing until you grant actions.</p><button class="go">Create agent</button></div>
    </form>
</div>
@endsection
