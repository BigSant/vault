@extends('layout')
@section('title', 'Projects')
@section('content')
@php $yesNo = fn ($name, $on, $form = null) => '<select name="'.e($name).'"'.($form ? ' form="'.e($form).'"' : '').' aria-label="Production"><option value="0">no</option><option value="1"'.($on ? ' selected' : '').'>yes</option></select>'; @endphp
<div class="head"><h1>Setup</h1><div class="controls"><a class="btn go" href="#add-project">+ Add a project</a></div></div>
@include('tabs', ['area' => 'setup', 'current' => 'structure', 'space' => $space])
<div class="stats">
    <div class="stat"><b>{{ $projects->count() }}</b><span>projects</span></div>
    <div class="stat"><b>{{ $environments->flatten()->count() }}</b><span>environments</span></div>
    <div class="stat"><b>{{ $counts->sum() }}</b><span>entries</span></div>
</div>
<p class="note">A project groups entries by environment. Only empty projects and environments can be removed (archived entries count as entries).</p>

@forelse($projects as $project)
    @php $envs = $environments[$project->id] ?? collect(); $projectEntries = $envs->sum(fn ($e) => $counts[$e->id] ?? 0); @endphp
    <h2 id="p-{{ $project->id }}">{{ $project->name }}</h2>
    <div class="card">
        <div class="form-foot">
            <form class="inline" method="post" action="/workspaces/{{ $space->id }}/projects/{{ $project->id }}">@csrf
                <input name="name" value="{{ $project->name }}" required maxlength="100" aria-label="Project name"><button>Rename</button>
            </form>
            @if($projectEntries === 0)
                <form class="controls" method="post" action="/workspaces/{{ $space->id }}/projects/{{ $project->id }}/delete" data-confirm="Remove project “{{ $project->name }}” and all its environments?">@csrf<button class="stop">Remove project</button></form>
            @else
                <span class="chip muted">{{ $projectEntries }} {{ $projectEntries === 1 ? 'entry' : 'entries' }}</span>
            @endif
        </div>
        <div class="scroll"><table class="list">
            <thead><tr><th scope="col">Environment</th><th scope="col">Name</th><th scope="col">Production</th><th scope="col">Entries</th><th scope="col"></th></tr></thead>
            <tbody>
            @foreach($envs as $env)
                @php $formId = 'env-'.$env->id; $n = $counts[$env->id] ?? 0; @endphp
                <tr>
                    <td>@include('sign', ['name' => $env->name, 'production' => $env->production])</td>
                    <td><input form="{{ $formId }}" name="name" value="{{ $env->name }}" required maxlength="60" aria-label="Environment name"></td>
                    <td>{!! $yesNo('production', $env->production, $formId) !!}</td>
                    <td>{{ $n }}</td>
                    <td><div class="controls">
                        <form id="{{ $formId }}" method="post" action="/workspaces/{{ $space->id }}/environments/{{ $env->id }}">@csrf<button>Save</button></form>
                        @if($n === 0)<form method="post" action="/workspaces/{{ $space->id }}/environments/{{ $env->id }}/delete" data-confirm="Remove environment “{{ $env->name }}”?">@csrf<button class="stop">Remove</button></form>@endif
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <form class="inline add-env" method="post" action="/workspaces/{{ $space->id }}/environments">@csrf
            <input type="hidden" name="project_id" value="{{ $project->id }}"><input type="hidden" name="back" value="structure">
            <input name="name" required maxlength="60" placeholder="new environment" aria-label="New environment">
            <label class="check"><input type="checkbox" name="production" value="1"> production</label>
            <button>Add environment</button>
        </form>
    </div>
@empty
    <div class="empty"><b>No projects yet</b>An entry lives in an environment of a project. <a href="#add-project">Add the first project</a></div>
@endforelse

<h2>New projects</h2>
<div class="deck">
    <form class="action" id="add-project" method="post" action="/workspaces/{{ $space->id }}/projects">@csrf<input type="hidden" name="back" value="structure">
        <h3>Add a project</h3>
        <label><span>Name</span><input name="name" required maxlength="100" placeholder="shop"></label>
        <div class="form-foot"><p class="note">Gets these environments: {{ implode(', ', array_column($template, 'name')) ?: 'none' }}.</p><button>Add project</button></div>
    </form>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/template">@csrf
        <h3>Environment template<small>Used for new projects only. Existing projects keep theirs.</small></h3>
        <table class="list">
            <thead><tr><th scope="col">Environment</th><th scope="col">Production</th></tr></thead>
            <tbody id="template-rows">
            @foreach($template as $env)
                <tr><td><input name="env_name[]" value="{{ $env['name'] }}" maxlength="60" aria-label="Environment"></td><td>{!! $yesNo('env_production[]', $env['production']) !!}</td></tr>
            @endforeach
                <tr data-row-template><td><input name="env_name[]" maxlength="60" placeholder="new environment" aria-label="Environment"></td><td>{!! $yesNo('env_production[]', false) !!}</td></tr>
            </tbody>
        </table>
        <div class="form-foot"><p class="note">Clear a name to drop it from the template.</p><div class="controls"><button type="button" data-add-row="template-rows">+ Row</button><button>Save template</button></div></div>
    </form>
</div>
@endsection
