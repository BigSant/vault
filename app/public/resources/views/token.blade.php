@extends('layout')
@section('title', 'Agent added')
@section('content')
{{-- The same head row as Identities, so the tabs stay where they were. --}}
<div class="head"><h1>Agents</h1><a class="btn" href="/workspaces/{{ $space->id }}/agents#add-agent">+ Add an agent</a></div>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])
<h2>Agent added</h2>
<div class="card solo">
    <p><strong>{{ $name }}</strong> · Expires {{ $expiresAt->format('Y-m-d H:i') }} UTC</p>
    <div class="callout warn"><p>Copy the token now. It is shown only once.</p></div>
    <div class="secret-row"><pre data-secret data-keep data-hidden-text="Hidden. If you did not copy the token, revoke this agent and add a new one." class="secret" id="agent-token">{{ $token }}</pre></div>
    <div class="form-foot"><p class="note">Store the token in a private client file (mode 0600). The agent needs a grant to run an action. Leaving this page loses the token.</p><div class="controls"><a class="btn" href="/workspaces/{{ $space->id }}/grants?agent={{ $id }}#add-grant">Add a grant</a><button type="button" class="go" aria-label="Copy token for {{ $name }}" data-copy="agent-token" data-copy-name="token">Copy token</button></div></div>
</div>
@endsection
