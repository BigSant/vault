@extends('layout')
@section('title', 'Agent token')
@section('content')
<h1>Agent identity created</h1>
<div class="tape"><span>Shown once. Store it now.</span></div>
<div class="card">
    <h3>Access token</h3>
    <pre data-secret class="secret" id="agent-token">{{ $token }}</pre>
    <div class="controls"><button type="button" data-copy="agent-token">Copy token</button></div>
    <p class="note">Put it in a private client file (mode 0600), never in an LLM chat or in project code. The identity has no access until you grant actions.</p>
    <div class="controls"><a class="btn go" href="/workspaces/{{ $space->id }}/grants?agent={{ $id }}">Next: grant actions</a></div>
</div>
@endsection
