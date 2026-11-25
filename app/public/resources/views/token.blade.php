@extends('layout')
@section('title', 'Agent added')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])
<h2>Agent added</h2>
<div class="card solo">
    <p><strong>{{ $name }}</strong> · expires {{ $expiresAt->format('Y-m-d H:i') }} UTC.</p>
    <div class="callout warn"><p>Copy the token now. It is shown only once.</p></div>
    <div class="secret-row"><pre data-secret data-keep data-hidden-text="Hidden. If you did not copy the token, revoke this agent and add a new one." class="secret" id="agent-token">{{ $token }}</pre><div class="controls"><button type="button" aria-label="Copy token for {{ $name }}" data-copy="agent-token">Copy token</button></div></div>
    <div class="form-foot"><p class="note">Store the token in a private client file (mode 0600). The agent needs a grant to run an action.</p><a class="btn go" href="/workspaces/{{ $space->id }}/grants?agent={{ $id }}">Next: grant actions</a></div>
</div>
@endsection
