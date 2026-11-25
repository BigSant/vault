@extends('layout')
@section('title', 'Identities')
@section('content')
<div class="head"><h1>Agents</h1><a class="btn" href="#add-agent">+ Add an agent</a></div>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])

@if($machines->isEmpty())
    <div class="empty">No agent identities yet. Add one below.</div>
@else
<div class="scroll"><table class="board compact-board identities">
    <thead><tr><th scope="col">Agent</th><th scope="col">Expires (UTC)</th><th scope="col">Active grants</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($machines as $m)
        @php $mState = $m->revoked_at ? 'revoked' : (strtotime($m->expires_at) <= time() ? 'expired' : 'active'); @endphp
        <tr id="agent-{{ $m->id }}" class="{{ $mState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td class="name" data-label="Agent"><b>{{ $m->name }}</b></td>
            <td class="when" data-label="Expires (UTC)">
                @if($m->revoked_at)
                    Revoked <span class="nobreak">{{ substr($m->revoked_at, 0, 16) }}</span> UTC
                @else
                    <small class="k">{{ strtotime($m->expires_at) <= time() ? 'Expired' : 'Expires' }}</small> {{ substr($m->expires_at, 0, 16) }} <small class="k">UTC</small>
                @endif
            </td>
            <td data-label="Active grants">@if($m->active_grants)<a href="/workspaces/{{ $space->id }}/grants?agent={{ $m->id }}">{{ $m->active_grants }} {{ Str::plural('grant', $m->active_grants) }} →</a>@else<span class="muted">no grants</span>@endif</td>
            <td data-label="State"><span class="chip {{ $mState === 'active' ? 'ok' : 'muted' }}">{{ $mState }}</span></td>
            <td data-label="">@if($mState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/machines/{{ $m->id }}" data-confirm="Revoke agent {{ $m->name }}? Its token stops working at once.">@csrf<button class="stop" aria-label="Revoke agent {{ $m->name }}">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<h2 id="add-agent">Add an agent</h2>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/machines">@csrf
        <p class="note">Creates a bearer token, shown once.</p>
        <label><span>Name</span><input name="name" value="{{ old('name') }}" placeholder="e.g. deploy-bot" required maxlength="100"></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="{{ old('days', 7) }}" required></label>
        @include('confirm-inline', ['verb' => 'add this agent'])
        <div class="form-foot"><p class="note">Up to 30 days. It can do nothing until you grant actions.</p><button class="go">Add agent</button></div>
    </form>
@endsection
