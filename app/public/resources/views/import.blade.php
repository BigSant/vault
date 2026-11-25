@extends('layout')
@section('title', 'Import entries')
@section('content')
<a class="back" href="/?workspace={{ $space->id }}">← Entries</a>
<h1>Import entries</h1>
<p class="note">Paste a JSON array of 1 to 50 entries for <b>{{ $space->name }}</b>. Every row is validated before any secret is written to the encrypted store.</p>

<form class="action" method="post" action="/workspaces/{{ $space->id }}/import">@csrf
    <h3>JSON payload<small>Up to 50 entries per import.</small></h3>
    <label class="stack"><span>Entries JSON</span><textarea name="import" rows="10" required spellcheck="false" autocomplete="off" placeholder='[{"environment_id":"...","name":"...","alias":"...","type":"api_key","secret":"{\"token\":\"...\"}"}]'></textarea></label>
    <div class="form-foot">
        <p class="note">The import text is never logged and is not refilled after an error. You may be asked to confirm your password first.</p>
        <div class="controls"><a class="btn" href="/?workspace={{ $space->id }}">Cancel</a><button class="go">Import</button></div>
    </div>
</form>

<h2>Format</h2>
<p class="note">Send a JSON array of entry objects. Every entry's <code>type</code> must exist in this workspace and include all required fields for that type.</p>
<div class="scroll"><table class="list">
    <thead><tr><th scope="col">Field</th><th scope="col">Required</th><th scope="col">Description</th></tr></thead>
    <tbody>
        <tr><td><code>environment_id</code></td><td><span class="chip warn">required</span></td><td>UUID of an environment in this workspace.</td></tr>
        <tr><td><code>name</code></td><td><span class="chip warn">required</span></td><td>Display name, up to 160 characters.</td></tr>
        <tr><td><code>alias</code></td><td><span class="chip warn">required</span></td><td>Agent-visible name, up to 100 characters (letters, digits, spaces, <code>.</code>, <code>_</code>, <code>-</code>).</td></tr>
        <tr><td><code>type</code></td><td><span class="chip warn">required</span></td><td>Lowercase entry type key from <a href="/workspaces/{{ $space->id }}/types">Setup</a>.</td></tr>
        <tr><td><code>secret</code></td><td><span class="chip warn">required</span></td><td>JSON object string mapping field keys to string values (or pass <code>secret_fields</code> as a JSON object).</td></tr>
        <tr><td><code>tags</code></td><td><span class="chip muted">optional</span></td><td>Comma-separated tags, up to 200 characters.</td></tr>
        <tr><td><code>rotate_at</code></td><td><span class="chip muted">optional</span></td><td>Rotation reminder date as <code>YYYY-MM-DD</code>.</td></tr>
    </tbody>
</table></div>

<div class="block-label">Example</div>
<pre class="cmd">[
  {
    "environment_id": "00000000-0000-0000-0000-000000000000",
    "name": "Billing API",
    "alias": "billing-api-prod",
    "type": "api_key",
    "secret": "{\"token\":\"replace-me\"}",
    "tags": "billing, api",
    "rotate_at": "2026-12-31"
  }
]</pre>
@endsection
