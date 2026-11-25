@extends('layout')
@section('title', 'Agent created')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])
<h2>Agent created</h2>
<div class="card">
    <p>Copy the token now. It is shown only once.</p>
    <div class="secret-row"><pre data-secret data-keep class="secret" id="agent-token">{{ $token }}</pre><button type="button" data-copy="agent-token">Copy token</button></div>
    <div class="form-foot"><p class="note">Store the token in a private client file (mode 0600). The agent needs a grant to run an action.</p><a class="btn go" href="/workspaces/{{ $space->id }}/grants?agent={{ $id }}">Next: grant actions</a></div>
</div>
@endsection
