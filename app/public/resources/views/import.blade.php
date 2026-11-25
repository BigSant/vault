@extends('layout')
@section('title', 'Import entries')
@section('content')
@php
    $environments = $environments ?? collect();
    $types = $types ?? collect();
@endphp
<a class="back" href="/?workspace={{ $space->id }}">← Entries</a>
<h1>Import entries</h1>
<p class="note">Paste a JSON array of entries for <b>{{ $space->name }}</b>. Validation is all-or-nothing: every row is checked before any secret is written to the encrypted store. <a class="more-link format-jump" href="#format">Format ↓</a></p>

{{-- Two columns where both fit --wide (the form, and the Format reference beside it); one column below that, where
     "Format ↓" in the intro jumps to the reference. --}}
<div class="deck import-deck">
<section aria-labelledby="paste">
<h2 id="paste">Paste entries</h2>
@if($environments->isEmpty() || $types->isEmpty())
<div class="empty capped">
    @if($environments->isEmpty())<p>No environments in this workspace yet. <a href="/workspaces/{{ $space->id }}/structure#{{ $firstProjectId ? 'p-'.$firstProjectId : 'add-project' }}">{{ $firstProjectId ? 'Add an environment to a project in Setup' : 'Add a project in Setup, then an environment' }}</a>.</p>@endif
    @if($types->isEmpty())<p>No entry types in this workspace yet. <a href="/workspaces/{{ $space->id }}/types">Add an entry type in Setup</a> first.</p>@endif
</div>
@else
<form class="action" method="post" action="/workspaces/{{ $space->id }}/import">@csrf
    <label class="stack"><span>Entries (JSON) <small>Up to 50 entries per import.</small></span><textarea name="import" rows="10" required spellcheck="false" autocomplete="off"></textarea></label>
    <details id="environment-ids" class="import-ref capped">
        <summary>Environment IDs</summary>
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
    <details id="type-keys" class="import-ref capped">
        <summary>Type and field keys</summary>
        <div class="inner">
            <div class="scroll"><table class="list import-types">
                <thead><tr><th scope="col">Type key</th><th scope="col">Name</th><th scope="col">Field keys</th></tr></thead>
                <tbody>
                @foreach($types as $t)
                    <tr>
                        <td class="type-key"><code>{{ $t->key }}</code></td>
                        <td class="type-name">{{ $t->name }}</td>
                        <td class="type-fields">@foreach($t->fields as $field)<div><code>{{ $field['key'] }}</code> {{ $field['required'] ? 'required' : 'optional' }}</div>@endforeach</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    </details>
    @include('confirm-inline', ['verb' => 'import'])
    <div class="form-foot">
        <p class="note">The import text is never logged and is not refilled after an error.</p>
        <div class="controls"><a class="btn" href="/?workspace={{ $space->id }}">Cancel</a><button class="go">Import</button></div>
    </div>
</form>
@endif
</section>

<section aria-labelledby="format">
<h2 id="format">Format</h2>
<p class="note">Choose a type defined in this workspace. Include its required fields in <code>secret_fields</code>.</p>

<div class="scroll capped"><table class="list import-format">
    <thead><tr><th scope="col">Field</th><th scope="col">Required</th><th scope="col">Description</th></tr></thead>
    <tbody>
        <tr><td class="key"><code>environment_id</code></td><td class="req"><span class="chip">required</span></td><td class="desc">@if($example)UUID from <a href="#environment-ids">Environment IDs</a>.@else UUID of a workspace environment.@endif</td></tr>
        <tr><td class="key"><code>name</code></td><td class="req"><span class="chip">required</span></td><td class="desc">Display name, up to 160 characters.</td></tr>
        <tr><td class="key"><code>alias</code></td><td class="req"><span class="chip">required</span></td><td class="desc">Agent-visible name, up to 100 characters (letters, digits, spaces, <code>.</code>, <code>_</code>, <code>-</code>).</td></tr>
        <tr><td class="key"><code>type</code></td><td class="req"><span class="chip">required</span></td><td class="desc">@if($example)Lowercase key from <a href="#type-keys">Type and field keys</a>.@else Lowercase workspace entry type key.@endif</td></tr>
        <tr><td class="key"><code>secret_fields</code></td><td class="req"><span class="chip">required</span></td><td class="desc">JSON object mapping field keys to string values.</td></tr>
        <tr><td class="key"><code>tags</code></td><td class="req"><span class="chip muted">optional</span></td><td class="desc">Comma-separated tags, up to 200 characters.</td></tr>
        <tr><td class="key"><code>rotate_at</code></td><td class="req"><span class="chip muted">optional</span></td><td class="desc">Rotation reminder date as <code>YYYY-MM-DD</code>.</td></tr>
    </tbody>
</table></div>

@if($example)
<div class="import-example">
    <div class="block-label">Example <button type="button" data-copy="import-example-json" data-copy-name="example">Copy example</button></div>
    {{-- Two-space indent, and lines are never broken (an environment ID stays whole): the block scrolls sideways
         where a line is longer than the column, so it takes the focus for the arrow keys. --}}
    <pre class="cmd capped" id="import-example-json" tabindex="0" role="region" aria-label="Example import JSON">{{ preg_replace_callback('/^(?: {4})+/m', fn ($m) => str_repeat(' ', intdiv(strlen($m[0]), 2)), (string) $example) }}</pre>
</div>
@endif
</section>
</div>
@endsection
