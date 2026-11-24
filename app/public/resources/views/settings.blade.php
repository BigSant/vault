@extends('layout')
@section('title', 'Access')
@section('content')
@php
    $activeMachines = $machines->whereNull('revoked_at');
    $machineNames = $machines->pluck('name', 'id');
    $entryNames = $entries->pluck('name', 'id');
    $activeGrants = $grants->whereNull('revoked_at')->filter(fn ($g) => strtotime($g->expires_at) > time());
    $roles = ['viewer' => 'viewer — metadata only', 'editor' => 'editor — create, change, reveal', 'owner' => 'owner — members, agents, policy'];
@endphp
<h1>Access</h1>
<div class="stats">
    <div class="stat"><b>{{ $members->count() }}</b><span>people</span></div>
    <div class="stat"><b>{{ $activeMachines->count() }}</b><span>agents</span></div>
    <div class="stat"><b>{{ $activeGrants->count() }}</b><span>active grants</span></div>
    <div class="stat"><b>{{ $operations->where('state', 'pending')->count() }}</b><span>waiting for you</span></div>
</div>
<p class="note">People, API/CLI and browser permissions are managed separately. A new agent has no access to any entry.</p>

@include('operations', ['workspace' => $space->id, 'refreshUrl' => '/workspaces/'.$space->id.'/settings'])

<h2>Agents</h2>
@if($machines->isEmpty())
    <div class="empty">No agent identities yet. Create one below.</div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Agent</th><th scope="col">Expires</th><th scope="col">State</th><th scope="col"></th></tr></thead>
    <tbody>
    @foreach($machines as $m)
        @php $mState = $m->revoked_at ? 'revoked' : (strtotime($m->expires_at) <= time() ? 'expired' : 'active'); @endphp
        <tr class="{{ $mState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td class="name" data-label="Agent"><b>{{ $m->name }}</b></td>
            <td class="when" data-label="Expires">{{ $m->expires_at }}</td>
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
        <div class="form-foot"><p class="note">Up to 30 days. It can do nothing until you grant actions.</p><button>Create agent</button></div>
    </form>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/grants">@csrf
        <h3>Grant an action<small>Lets one agent run one action with one entry.</small></h3>
        @if(count($actions) === 0)<div class="callout warn"><p>No actions are configured yet. The operator has to register the allowed target systems first.</p></div>@endif
        <label><span>Agent</span><select name="machine_id">@foreach($activeMachines as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></label>
        <label><span>Entry</span><select name="entry_id">@foreach($entries as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ $e->type }}</option>@endforeach</select></label>
        <label><span>Action</span><select name="action">@foreach($actions as $id => $a)<option value="{{ $id }}">{{ $id }} · {{ $a['kind'] }}</option>@endforeach</select></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="1" required></label>
        <label class="check"><input type="checkbox" name="approval" value="1" checked> Ask me before every run</label>
        <div class="form-foot"><p class="note">The entry's type must have the fields the action reads.</p><button>Grant</button></div>
    </form>
</div>

<h2>Grants</h2>
@if($grants->isEmpty())
    <div class="empty">No grants yet.</div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Action</th><th scope="col">Agent</th><th scope="col">Entry</th><th scope="col">Expires</th><th scope="col">State</th><th scope="col"></th></tr></thead>
    <tbody>
    @foreach($grants as $g)
        @php $gState = $g->revoked_at ? 'revoked' : (strtotime($g->expires_at) <= time() ? 'expired' : 'active'); @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Action"><code>{{ $g->action }}</code></td>
            <td data-label="Agent">{{ $machineNames[$g->machine_id] ?? $g->machine_id }}</td>
            <td data-label="Entry">@if(isset($entryNames[$g->entry_id]))<a href="/entries/{{ $g->entry_id }}">{{ $entryNames[$g->entry_id] }}</a>@else<span class="muted">archived entry</span>@endif</td>
            <td class="when" data-label="Expires">{{ $g->expires_at }}</td>
            <td data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
            <td data-label="">@unless($g->revoked_at)<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/grants/{{ $g->id }}">@csrf<button class="stop">Revoke</button></form>@endunless</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<h2>People</h2>
<div class="scroll"><table class="list">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th></tr></thead>
    <tbody>@foreach($members as $m)<tr><td><b>{{ $m->name }}</b></td><td>{{ $m->email }}</td><td><span class="chip {{ $m->role === 'owner' ? 'warn' : ($m->role === 'editor' ? 'ok' : 'steel') }}">{{ $m->role }}</span></td></tr>@endforeach</tbody>
</table></div>
<div class="deck">
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
        <h3>Add or change a member</h3>
        <label><span>Name</span><input name="name" required maxlength="100"></label>
        <label><span>Email</span><input name="email" type="email" required></label>
        <label><span>First password <small>new user only</small></span><input name="password" type="password" minlength="14" autocomplete="new-password"></label>
        <label><span>Role</span><select name="role">@foreach($roles as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach<option value="remove">remove membership</option></select></label>
        <div class="form-foot"><p class="note">Hand the password over privately. MFA is set up at the first sign-in. An existing account's password is not changed.</p><button>Save member</button></div>
    </form>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/import">@csrf
        <h3>Import entries<small>A JSON array of up to 50 entries in the documented format.</small></h3>
        <label class="stack"><span>JSON</span><textarea name="import" rows="6" required spellcheck="false" autocomplete="off"></textarea></label>
        <div class="form-foot"><p class="note">The import is never logged and not refilled after an error.</p><button>Import</button></div>
    </form>
</div>
@endsection
