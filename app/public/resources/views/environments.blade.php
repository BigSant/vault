@extends('layout')
@section('title', 'Environments')
@section('content')
{{-- Setup › Environments: the workspace's one list of environments, used by every project (like entry types).
     One fold per environment (rename, production, remove), then "Add an environment". --}}
@php
    $entries = fn (int $n) => $n.' '.($n === 1 ? 'entry' : 'entries');
    $addPath = '/workspaces/'.$space->id.'/environments';
    $oldAdd = session('error_form') === $addPath;
@endphp
<div class="head"><h1>Setup</h1><a class="btn" href="#add-environment">+ Add an environment</a></div>
@include('tabs', ['area' => 'setup', 'current' => 'environments', 'space' => $space])
<p class="note">Every project uses these environments. A production environment is marked red wherever an entry shows it. Only an environment without entries can be removed (archived entries count as entries).</p>

@unless($environments->isEmpty())
@include('confirm-inline', ['verb' => 'change environments', 'ask' => 'Enter your current password, then save, add or remove below.', 'shared' => 'confirm-environments'])
@endunless
@forelse($environments as $env)
    @php
        $n = $counts[$env->id] ?? 0;
        $envPath = $addPath.'/'.$env->id;
        $oldEdit = session('error_form') === $envPath;
        $oldDelete = session('error_form') === $envPath.'/delete';
        $envName = $oldEdit && is_string(old('name')) ? old('name') : $env->name;
        $envProd = $oldEdit ? old('production') === '1' : (bool) $env->production;
    @endphp
    <details class="fold env-fold capped" id="env-{{ $env->id }}"@if($oldEdit || $oldDelete) open @endif>
        <summary><span class="fold-name">@include('sign', ['name' => $env->name, 'production' => $env->production])</span>
            <span class="fold-tail"><span class="fold-meta">{{ $entries($n) }}</span>
            <span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span><span class="sr-only"> environment {{ $env->name }}</span></span></span></summary>
        <div class="inner">
            <form method="post" action="{{ $envPath }}" data-confirm-from="confirm-environments">@csrf
                <label><span>Name</span><input name="name" value="{{ $envName }}" required maxlength="60" aria-label="Name of environment {{ $env->name }}"></label>
                <label class="check"><input type="checkbox" name="production" value="1" @checked($envProd) aria-label="Production for environment {{ $env->name }}"> Production</label>
                <div class="form-foot"><p class="note">@if($n > 0)Holds {{ $entries($n) }}, so it cannot be removed.@endif</p><button aria-label="Save environment {{ $env->name }}" data-held-name="Save environment: {{ $env->name }}">Save environment</button></div>
            </form>
            @if($n === 0)<form class="fold-foot" method="post" action="{{ $envPath }}/delete" data-confirm="Remove environment {{ $env->name }}?" data-confirm-from="confirm-environments">@csrf<button class="stop" aria-label="Remove environment {{ $env->name }}" data-held-name="Remove environment: {{ $env->name }}">Remove environment</button></form>@endif
        </div>
    </details>
@empty
    <div class="empty capped"><b>No environments yet.</b>An entry needs an environment. <a href="#add-environment">Add the first environment</a>.</div>
@endforelse

<h2 id="add-environment">Add an environment</h2>
<form class="action" method="post" action="{{ $addPath }}"@unless($environments->isEmpty()) data-confirm-from="confirm-environments"@endunless>@csrf
    <label><span>Name</span><input name="name" value="{{ $oldAdd && is_string(old('name')) ? old('name') : '' }}" required maxlength="60" placeholder="e.g. staging"></label>
    <label class="check"><input type="checkbox" name="production" value="1" @checked($oldAdd && old('production') === '1')> Production</label>
    @if($environments->isEmpty())
        @include('confirm-inline', ['verb' => 'add this environment'])
    @else
        @include('confirm-inline', ['verb' => 'add this environment', 'shared' => 'confirm-add-environment', 'primary' => 'confirm-environments', 'primaryShown' => true, 'fallback' => true])
    @endif
    <div class="form-foot"><p class="note">Every project can use it at once.</p><button class="go" data-held-name="Add environment: " data-held-field="name">Add environment</button></div>
</form>
@endsection
