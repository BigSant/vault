@extends('layout')
@section('title', 'Identities')
@section('content')
<div class="head"><h1>Agents</h1><a class="btn" href="#add-agent">+ Add an agent</a></div>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])
@include('notice')

@if($machines->isEmpty())
    <div class="empty">No agents yet. <a href="#add-agent">Add an agent</a>.</div>
@else
{{-- Revoke never goes to the confirm page while script runs: one shared password block above the board, as on
     Members. "Add an agent" names it too and only points to it when the confirmation has expired. --}}
@include('confirm-inline', ['verb' => 'change agents', 'ask' => 'Enter your current password, then revoke or add an agent below.', 'shared' => 'confirm-agents'])
<div class="scroll"><table class="board compact-board identities" aria-labelledby="tab-current">
    <thead><tr><th scope="col">Agent</th><th scope="col">End date (UTC)</th><th scope="col">Active grants</th>@if($linksReady)<th scope="col">One-time links</th>@endif<th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($machines as $m)
        @php $mState = $m->revoked_at ? 'revoked' : (strtotime($m->expires_at) <= time() ? 'expired' : 'active'); @endphp
        <tr id="agent-{{ $m->id }}" class="{{ $mState === 'active' ? (\App\Vault\Access::endsSoon($m->revoked_at, $m->expires_at) ? 'is-wait' : 'is-ok') : 'is-idle' }}">
            <td class="name" data-label="Agent"><b>{{ $m->name }}</b></td>
            <td class="when" data-label="End date (UTC)">@include('end-date', ['revoked' => $m->revoked_at, 'ends' => $m->expires_at, 'reason' => null, 'own' => null])</td>
            <td class="grants" data-label="Active grants">@if($m->active_grants)<a href="/workspaces/{{ $space->id }}/grants?agent={{ $m->id }}">{{ $m->active_grants }} {{ Str::plural('grant', $m->active_grants) }} →</a>@else<span class="muted">no grants</span>@endif</td>
            {{-- Whether the agent may create one-time links (Links). Its entries' values also need a grant of
                 onetimesecret.link; text it writes itself needs only this. --}}
            @if($linksReady)<td class="links" data-label="One-time links">@if($mState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/machines/{{ $m->id }}/links" data-confirm-from="confirm-agents">@csrf<input type="hidden" name="links" value="{{ $m->links ? 0 : 1 }}"><span class="{{ $m->links ? '' : 'muted' }}"><small class="k">One-time links: </small>{{ $m->links ? 'allowed' : 'not allowed' }}</span><button aria-label="{{ $m->links ? 'Stop one-time links for' : 'Allow one-time links for' }} agent {{ $m->name }}" data-held-name="{{ $m->links ? 'Stop one-time links' : 'Allow one-time links' }}: {{ $m->name }}">{{ $m->links ? 'Stop' : 'Allow' }}</button></form>@else<span class="muted"><small class="k">One-time links: </small>{{ $m->links ? 'were allowed' : 'not allowed' }}</span>@endif</td>@endif
            <td class="state" data-label="State"><span class="chip {{ $mState === 'active' ? 'ok' : 'muted' }}">{{ $mState }}</span></td>
            <td data-label="">@if($mState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/machines/{{ $m->id }}" data-confirm="Revoke agent {{ $m->name }}? Its token stops working at once." data-confirm-from="confirm-agents">@csrf<button class="stop" aria-label="Revoke agent {{ $m->name }}" data-held-name="Revoke agent: {{ $m->name }}">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@if($linksReady)<p class="note">One-time links: an allowed agent can send any text it writes as a one-time link from this workspace's Onetime Secret account. An entry's values also need a grant of <code>onetimesecret.link</code>, which asks for approval every time.</p>@endif
@endif

<h2 id="add-agent">Add an agent</h2>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/machines"@unless($machines->isEmpty()) data-confirm-from="confirm-agents"@endunless>@csrf
        <p class="note">Creates a bearer token, shown once.</p>
        <label><span>Name</span><input name="name" value="{{ old('name') }}" placeholder="e.g. deploy-bot" required maxlength="100"></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="{{ old('days', 7) }}" required></label>
        @if($linksReady)<div class="field"><label class="check"><input type="checkbox" name="links" value="1" aria-describedby="agent-links-hint" @checked(old('links') === '1')> May create one-time links</label><small class="hint" id="agent-links-hint">It can then send any text it writes as a one-time link from this workspace's Onetime Secret account. An entry's values also need a grant of onetimesecret.link.</small></div>@endif
        @if($machines->isEmpty())
            @include('confirm-inline', ['verb' => 'add this agent'])
        @else
            @include('confirm-inline', ['verb' => 'add this agent', 'shared' => 'confirm-add-agent', 'primary' => 'confirm-agents', 'primaryShown' => true, 'fallback' => true])
        @endif
        <div class="form-foot"><p class="note">Up to 30 days. The agent can do nothing until you add a grant.</p><button class="go" data-held-name="Add agent: " data-held-field="name">Add agent</button></div>
    </form>
@endsection
