@extends('layout')
@section('title', 'Projects')
@section('content')
@php
    $yesNo = fn ($name, $on) => '<select name="'.e($name).'"><option value="0">no</option><option value="1"'.($on ? ' selected' : '').'>yes</option></select>';
    $entries = fn (int $n) => $n.' '.($n === 1 ? 'entry' : 'entries');
@endphp
<div class="head"><h1>Setup</h1><div class="controls"><a class="btn" href="#add-project">Add project</a></div></div>
@include('tabs', ['area' => 'setup', 'current' => 'structure', 'space' => $space])
<div class="stats">
    <div class="stat"><b>{{ $projects->count() }}</b><span>projects</span></div>
    <div class="stat"><b>{{ $environments->flatten()->count() }}</b><span>environments</span></div>
    <div class="stat"><b>{{ $counts->sum() }}</b><span>entries</span></div>
</div>
<p class="note">A project groups entries by environment. Only empty projects and environments can be removed (archived entries count as entries).</p>

@forelse($projects as $project)
    @php $envs = $environments[$project->id] ?? collect(); $projectEntries = $envs->sum(fn ($e) => $counts[$e->id] ?? 0); @endphp
    <h2 id="p-{{ $project->id }}"><span class="name">{{ $project->name }}</span> <span class="count">{{ $entries($projectEntries) }}</span></h2>
    <div class="card">
        <details class="fold"><summary><span class="fold-name">Project settings</span> <span class="fold-act">Edit<span class="sr-only"> project {{ $project->name }}</span></span></summary>
        <div class="inner">
            <form class="inline" method="post" action="/workspaces/{{ $space->id }}/projects/{{ $project->id }}">@csrf
                <input name="name" value="{{ $project->name }}" required maxlength="100" aria-label="Name of project {{ $project->name }}"><button aria-label="Rename project {{ $project->name }}">Rename</button>
            </form>
            @if($projectEntries === 0)
                <form class="fold-foot" method="post" action="/workspaces/{{ $space->id }}/projects/{{ $project->id }}/delete" data-confirm="Remove project “{{ $project->name }}” and all its environments?">@csrf<button class="stop" aria-label="Remove project {{ $project->name }}">Remove project</button></form>
            @endif
        </div>
        </details>
        @forelse($envs as $env)
            @php $formId = 'env-'.$env->id; $n = $counts[$env->id] ?? 0; $where = $env->name.' in project '.$project->name; @endphp
            <details class="fold env">
                <summary>
                    <span class="fold-name">@include('sign', ['name' => $env->name, 'production' => $env->production])@if($env->production)<span class="sr-only"> (production)</span>@endif</span>
                    <span class="fold-meta">{{ $entries($n) }}</span>
                    <span class="fold-act">Edit<span class="sr-only"> environment {{ $where }}</span></span>
                </summary>
                <div class="inner">
                    <form id="{{ $formId }}" method="post" action="/workspaces/{{ $space->id }}/environments/{{ $env->id }}">@csrf
                        <label><span>Name</span><input name="name" value="{{ $env->name }}" required maxlength="60"></label>
                        <label><span>Production</span>{!! $yesNo('production', $env->production) !!}</label>
                    </form>
                    <div class="form-foot">
                        <p class="note">@if($n > 0)Holds {{ $entries($n) }}, so it cannot be removed.@endif</p>
                        <div class="controls">
                            @if($n === 0)<form method="post" action="/workspaces/{{ $space->id }}/environments/{{ $env->id }}/delete" data-confirm="Remove environment “{{ $env->name }}”?">@csrf<button class="stop" aria-label="Remove environment {{ $where }}">Remove</button></form>@endif
                            <button form="{{ $formId }}" aria-label="Save environment {{ $where }}">Save</button>
                        </div>
                    </div>
                </div>
            </details>
        @empty
            <p class="note">No environments yet. Add one to store entries in this project.</p>
        @endforelse
        <form class="inline add-env" method="post" action="/workspaces/{{ $space->id }}/environments">@csrf
            <input type="hidden" name="project_id" value="{{ $project->id }}"><input type="hidden" name="back" value="structure">
            <input name="name" required maxlength="60" placeholder="new environment" aria-label="New environment in project {{ $project->name }}">
            <label class="check"><input type="checkbox" name="production" value="1"> production</label>
            <button aria-label="Add environment to project {{ $project->name }}">Add environment</button>
        </form>
    </div>
@empty
    <div class="empty"><b>No projects yet</b>An entry lives in an environment of a project. <a href="#add-project">Add the first project</a></div>
@endforelse

<h2 id="add-project">Add project</h2>
<form class="action" method="post" action="/workspaces/{{ $space->id }}/projects">@csrf<input type="hidden" name="back" value="structure">
    <label><span>Name</span><input name="name" required maxlength="100" placeholder="shop"></label>
    <div class="form-foot"><p class="note">New projects start with: {{ implode(', ', array_column($template, 'name')) ?: 'no environments' }}.</p><button class="go">Add project</button></div>
</form>

<h2>Environment template</h2>
<p class="note">The environments a new project starts with. Existing projects keep theirs.</p>
<form class="action" method="post" action="/workspaces/{{ $space->id }}/template">@csrf
    <table class="list edit">
        <thead><tr><th scope="col">Environment</th><th scope="col">Production</th></tr></thead>
        <tbody id="template-rows">
        @foreach($template as $env)
            <tr><td data-label="Environment"><input name="env_name[]" value="{{ $env['name'] }}" maxlength="60" aria-label="Environment"></td><td data-label="Production">{!! str_replace('<select ', '<select aria-label="Production" ', $yesNo('env_production[]', $env['production'])) !!}</td></tr>
        @endforeach
            <tr data-row-template><td data-label="Environment"><input name="env_name[]" maxlength="60" placeholder="new environment" aria-label="Environment"></td><td data-label="Production">{!! str_replace('<select ', '<select aria-label="Production" ', $yesNo('env_production[]', false)) !!}</td></tr>
        </tbody>
    </table>
    @include('confirm-inline', ['verb' => 'save this template'])
    <div class="form-foot"><p class="note">Clear a name to drop it from the template.</p><div class="controls"><button type="button" data-add-row="template-rows">+ Row</button><button>Save template</button></div></div>
</form>
@endsection
