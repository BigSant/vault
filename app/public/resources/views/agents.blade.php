@extends('layout')
@section('title', 'Identities')
@section('content')
<div class="head"><h1>Agents</h1><a class="btn" href="#add-agent">+ Add an agent</a></div>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])

@if($machines->isEmpty())
    <div class="empty">No agents yet. <a href="#add-agent">Add an agent</a>.</div>
@else
{{-- Revoke never goes to the confirm page while script runs: one shared password block above the board, as on
     Members. "Add an agent" names it too and only points to it when the confirmation has expired. --}}
@include('confirm-inline', ['verb' => 'change agents', 'ask' => 'Enter your current password, then revoke or add an agent below.', 'shared' => 'confirm-agents'])
<div class="scroll"><table class="board compact-board identities">
    <thead><tr><th scope="col">Agent</th><th scope="col">Expires (UTC)</th><th scope="col">Active grants</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($machines as $m)
        @php $mState = $m->revoked_at ? 'revoked' : (strtotime($m->expires_at) <= time() ? 'expired' : 'active'); @endphp
        <tr id="agent-{{ $m->id }}" class="{{ $mState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td class="name" data-label="Agent"><b>{{ $m->name }}</b></td>
            <td class="when" data-label="Expires (UTC)">@include('end-date', ['revoked' => $m->revoked_at, 'ends' => $m->expires_at, 'reason' => null, 'own' => null])</td>
            <td class="grants" data-label="Active grants">@if($m->active_grants)<a href="/workspaces/{{ $space->id }}/grants?agent={{ $m->id }}">{{ $m->active_grants }} {{ Str::plural('grant', $m->active_grants) }} →</a>@else<span class="muted">no grants</span>@endif</td>
            <td class="state" data-label="State"><span class="chip {{ $mState === 'active' ? 'ok' : 'muted' }}">{{ $mState }}</span></td>
            <td data-label="">@if($mState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/machines/{{ $m->id }}" data-confirm="Revoke agent {{ $m->name }}? Its token stops working at once." data-confirm-from="confirm-agents">@csrf<button class="stop" aria-label="Revoke agent {{ $m->name }}" data-held-name="Revoke agent: {{ $m->name }}">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<h2 id="add-agent">Add an agent</h2>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/machines"@unless($machines->isEmpty()) data-confirm-from="confirm-agents"@endunless>@csrf
        <p class="note">Creates a bearer token, shown once.</p>
        <label><span>Name</span><input name="name" value="{{ old('name') }}" placeholder="e.g. deploy-bot" required maxlength="100"></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="{{ old('days', 7) }}" required></label>
        @if($machines->isEmpty())
            @include('confirm-inline', ['verb' => 'add this agent'])
        @else
            @include('confirm-inline', ['verb' => 'add this agent', 'shared' => 'confirm-add-agent', 'primary' => 'confirm-agents', 'primaryShown' => true, 'primaryWhere' => 'above the agents', 'fallback' => true])
        @endif
        <div class="form-foot"><p class="note">Up to 30 days. The agent can do nothing until you add a grant.</p><button class="go" data-held-name="Add agent: " data-held-field="name">Add agent</button></div>
    </form>
@endsection
