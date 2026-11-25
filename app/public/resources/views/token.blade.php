@extends('layout')
@section('title', 'Agent added')
@section('content')
{{-- The same head row as Identities, so the tabs stay where they were. No "+ Add an agent" here.
     Leaving this page loses the token, so while it has not been copied app.js guards every way out inside the app
     (data-leave-guard holds the question): the header, the tabs and both next steps ask "Token copied?" first, in
     the two-step pattern. A copy that worked lifts the guard; so does "I've saved it" (data-leave-clear, shown by
     app.js) for a token that was selected and copied by hand. Without script the links are plain links. --}}
<div class="head"><h1>Agents</h1></div>
@include('tabs', ['area' => 'agents', 'current' => 'agents', 'space' => $space])
<h2>Agent added</h2>
<div class="card solo" data-leave-guard="Token copied?">
    <p><strong>{{ $name }}</strong> <span class="token-expiry"><span class="sep" aria-hidden="true">· </span>Expires <span class="nobreak">{{ $expiresAt->format('Y-m-d H:i') }} UTC</span></span></p>
    <div class="callout warn"><p>Copy the token now. It is shown only once.</p></div>
    <div class="secret-row">
        <pre data-secret data-keep data-hidden-text="Hidden. If you did not copy the token, revoke this agent and add a new one." class="secret" id="agent-token">{{ $token }}</pre>
        <div class="controls"><button type="button" class="go" aria-label="Copy token for {{ $name }}" data-copy="agent-token" data-copy-name="token">Copy token</button><button type="button" data-leave-clear hidden>I've saved it</button></div>
    </div>
    <div class="form-foot"><p class="note">Store the token in a private client file (mode 0600). The agent needs a grant to run an action. Leaving this page loses the token.</p><div class="controls"><a class="btn" href="/workspaces/{{ $space->id }}/grants?agent={{ $id }}#add-grant">Add a grant</a><a class="btn" href="/workspaces/{{ $space->id }}/agents" data-leave-done>Done — back to Identities</a></div></div>
</div>
@endsection
