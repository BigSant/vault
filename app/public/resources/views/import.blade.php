@extends('layout')
@section('title', 'Import entries')
@section('content')
@php
    $environments = $environments ?? collect();
    $types = $types ?? collect();
@endphp
<a class="back" href="/?workspace={{ $space->id }}">← Entries</a>
<h1>Import entries</h1>
<p class="note">Paste a JSON array of entries for <b>{{ $space->name }}</b>. Validation is all-or-nothing: every row is checked before any secret is written to the encrypted store.</p>

<h2>JSON input</h2>
@if($environments->isEmpty() || $types->isEmpty())
<div class="empty capped">
    @if($environments->isEmpty())<p>No environments in this workspace yet. <a href="/workspaces/{{ $space->id }}/structure#{{ $firstProjectId ? 'p-'.$firstProjectId : 'add-project' }}">{{ $firstProjectId ? 'Add an environment to a project in Setup' : 'Add a project in Setup, then an environment' }}</a>.</p>@endif
    @if($types->isEmpty())<p>No entry types in this workspace yet. <a href="/workspaces/{{ $space->id }}/types">Add an entry type in Setup</a> first.</p>@endif
</div>
@else
<form class="action" method="post" action="/workspaces/{{ $space->id }}/import">@csrf
    <label class="stack"><span>Entries (JSON) <small>Up to 50 entries per import.</small></span><textarea name="import" rows="10" required spellcheck="false" autocomplete="off" placeholder='[{"environment_id":"...","name":"...","alias":"...","type":"api_key","secret_fields":{"token":"..."}}]'></textarea></label>
    <details class="import-ref capped">
        <summary>Environment IDs <span class="muted">({{ $environments->count() }})</span></summary>
        <div class="inner">
            <div class="scroll"><table class="list import-ids">
                <thead><tr><th scope="col">Project / environment</th><th scope="col">Environment ID</th></tr></thead>
                <tbody>
                @foreach($environments as $env)
                    <tr>
                        <td class="env-name"><span>{{ $env->project }} @include('sign', ['name' => $env->name, 'production' => $env->production])</span></td>
                        <td class="env-id"><span class="controls"><code id="env-{{ $env->id }}">{{ $env->id }}</code> <button type="button" data-copy="env-{{ $env->id }}" data-copy-name="environment ID" aria-label="Copy environment ID for {{ $env->project }} / {{ $env->name }}">Copy ID</button></span></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    </details>
    <p><a href="#type-keys">Type and field keys</a></p>
    @include('confirm-inline', ['verb' => 'import'])
    <div class="form-foot">
        <p class="note">The import text is never logged and is not refilled after an error.</p>
        <div class="controls"><a class="btn" href="/?workspace={{ $space->id }}">Cancel</a><button class="go">Import</button></div>
    </div>
</form>
@endif

<h2>Format</h2>
<p class="note">Choose a type defined in this workspace. Include its required fields in <code>secret_fields</code>.</p>

<div class="scroll capped"><table class="list import-format">
    <thead><tr><th scope="col">Field</th><th scope="col">Required</th><th scope="col">Description</th></tr></thead>
    <tbody>
        <tr><td class="key"><code>environment_id</code></td><td class="req"><span class="chip">required</span></td><td class="desc">UUID of an environment in this workspace.</td></tr>
        <tr><td class="key"><code>name</code></td><td class="req"><span class="chip">required</span></td><td class="desc">Display name, up to 160 characters.</td></tr>
        <tr><td class="key"><code>alias</code></td><td class="req"><span class="chip">required</span></td><td class="desc">Agent-visible name, up to 100 characters (letters, digits, spaces, <code>.</code>, <code>_</code>, <code>-</code>).</td></tr>
        <tr><td class="key"><code>type</code></td><td class="req"><span class="chip">required</span></td><td class="desc">Lowercase entry type key from <a href="/workspaces/{{ $space->id }}/types">Setup</a>.</td></tr>
        <tr><td class="key"><code>secret_fields</code></td><td class="req"><span class="chip">required</span></td><td class="desc">JSON object mapping field keys to string values (or pass <code>secret</code> as a JSON-encoded string).</td></tr>
        <tr><td class="key"><code>tags</code></td><td class="req"><span class="chip muted">optional</span></td><td class="desc">Comma-separated tags, up to 200 characters.</td></tr>
        <tr><td class="key"><code>rotate_at</code></td><td class="req"><span class="chip muted">optional</span></td><td class="desc">Rotation reminder date as <code>YYYY-MM-DD</code>.</td></tr>
    </tbody>
</table></div>

<div class="block-label">Example</div>
<pre class="cmd capped">[
  {
    "environment_id": "{{ $environments->first()->id ?? '00000000-0000-0000-0000-000000000000' }}",
    "name": "Billing API",
    "alias": "billing-api-prod",
    "type": "api_key",
    "secret_fields": {
      "token": "replace-me"
    },
    "tags": "billing, api",
    "rotate_at": "2026-12-31"
  }
]</pre>

<details class="import-ref capped">
    <summary>Type keys <span class="muted">({{ $types->count() }})</span></summary>
    <div class="inner">
        @if($types->isEmpty())
            <p class="note">No entry types yet. Manage types in <a href="/workspaces/{{ $space->id }}/types">Setup</a>.</p>
        @else
            <div id="type-keys" class="scroll"><table class="list import-types">
                <thead><tr><th scope="col">Type key</th><th scope="col">Name</th><th scope="col">Required fields</th></tr></thead>
                <tbody>
                @foreach($types as $t)
                    @php $reqFields = array_values(array_map(fn ($f) => $f['key'], array_filter($t->fields ?? [], fn ($f) => !empty($f['required'])))); @endphp
                    <tr>
                        <td class="type-key"><code>{{ $t->key }}</code></td>
                        <td class="type-name">{{ $t->name }}</td>
                        <td class="type-fields">@if($reqFields)@foreach($reqFields as $fk)<code>{{ $fk }}</code>@if(!$loop->last) @endif @endforeach @else<span class="muted">none</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</details>
@endsection
