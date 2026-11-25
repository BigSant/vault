@extends('layout')
@section('title', 'Projects')
@section('content')
@php
    $entries = fn (int $n) => $n.' '.($n === 1 ? 'entry' : 'entries');
    $oldAddProject = old('project_id') === null && old('production') === null && old('env_name') === null
        && (session('error_form') === null || session('error_form') === '/workspaces/'.$space->id.'/projects');
    $templateRows = array_values($template);
    if (is_array(old('env_name'))) {
        $oldNames = old('env_name');
        $oldProd = is_array(old('env_production')) ? old('env_production') : [];
        $templateRows = [];
        $lastIdx = array_key_last($oldNames);
        foreach ($oldNames as $i => $n) {
            $n = is_string($n) ? $n : '';
            if ($n === '' && $i === $lastIdx) {
                continue;
            }
            $templateRows[] = ['name' => $n, 'production' => ($oldProd[$i] ?? '0') === '1'];
        }
    }
    $nextTemplateIdx = count($templateRows);
@endphp
<div class="head"><h1>Setup</h1><a class="btn" href="#add-project">+ Add a project</a></div>
@include('tabs', ['area' => 'setup', 'current' => 'structure', 'space' => $space])

{{-- No h2 here: it would repeat the active tab. The project cards follow the tabs; the forms below keep their h2. --}}
<p class="note">A project groups entries by environment. Only empty projects and environments can be removed (archived entries count as entries).</p>

{{-- One password block for the page, above the projects: every form below names it (data-confirm-from), so an
     expired confirmation asks here and holds the action under its name. The two form cards below (Add a project,
     the template) only point to it, with a field of their own in <noscript>. --}}
@include('confirm-inline', ['verb' => 'change projects', 'ask' => 'Enter your current password, then save, add or remove below.', 'shared' => 'confirm-setup'])
{{-- The documented deck: two project cards side by side at --wide, one column below that. --}}
<div class="deck project-list">
@forelse($projects as $project)
    @php
        $envs = $environments[$project->id] ?? collect();
        $projectEntries = $envs->sum(fn ($e) => $counts[$e->id] ?? 0);
        $oldAddEnv = old('project_id') === $project->id;
        $projPath = '/workspaces/'.$space->id.'/projects/'.$project->id;
        $oldRename = session('error_form') === $projPath;
        $oldProjectDelete = session('error_form') === $projPath.'/delete';
        $projFormId = 'proj-'.$project->id;
    @endphp
    <div class="card project-card" id="p-{{ $project->id }}">
        <details class="fold project-edit"@if($oldRename || $oldProjectDelete) open @endif>
            <summary>
                <span class="fold-name"><span class="name">{{ $project->name }}</span></span>
                <span class="fold-meta">{{ $entries($projectEntries) }}</span>
                <span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span><span class="sr-only"> project {{ $project->name }}</span></span>
            </summary>
            <div class="inner">
                <form id="{{ $projFormId }}" method="post" action="{{ $projPath }}" data-confirm-from="confirm-setup">@csrf
                    <label><span>Name</span><input name="name" value="{{ $oldRename && is_string(old('name')) ? old('name') : $project->name }}" required maxlength="100" aria-label="Name of project {{ $project->name }}"></label>
                </form>
                <div class="form-foot">
                    <p class="note">@if($projectEntries > 0)Holds {{ $entries($projectEntries) }}, so it cannot be removed.@endif</p>
                    <div class="controls">
                        @if($projectEntries === 0)<form method="post" action="{{ $projPath }}/delete" data-confirm="Remove project “{{ $project->name }}” and all its environments?" data-confirm-from="confirm-setup">@csrf<button class="stop" aria-label="Remove project {{ $project->name }}" data-held-name="Remove project: {{ $project->name }}">Remove project</button></form>@endif
                        <button form="{{ $projFormId }}" aria-label="Save project {{ $project->name }}" data-held-name="Save project: {{ $project->name }}">Save project</button>
                    </div>
                </div>
            </div>
        </details>
        @forelse($envs as $env)
            @php
                $formId = 'env-'.$env->id;
                $n = $counts[$env->id] ?? 0;
                $where = $env->name.' in project '.$project->name;
                $envPath = '/workspaces/'.$space->id.'/environments/'.$env->id;
                $oldEnv = session('error_form') === $envPath;
                $oldEnvDelete = session('error_form') === $envPath.'/delete';
                $envName = $oldEnv && is_string(old('name')) ? old('name') : $env->name;
                $envProd = $oldEnv ? old('production') === '1' : (bool) $env->production;
            @endphp
            <details class="fold env"@if($oldEnv || $oldEnvDelete) open @endif>
                <summary>
                    <span class="fold-name">@include('sign', ['name' => $env->name, 'production' => $env->production])</span>
                    <span class="fold-meta">{{ $entries($n) }}</span>
                    <span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span><span class="sr-only"> environment {{ $where }}</span></span>
                </summary>
                <div class="inner">
                    <form id="{{ $formId }}" method="post" action="{{ $envPath }}" data-confirm-from="confirm-setup">@csrf
                        <label><span>Name</span><input name="name" value="{{ $envName }}" required maxlength="60" aria-label="Name of environment {{ $where }}"></label>
                        <label class="check"><input type="checkbox" name="production" value="1" @checked($envProd) aria-label="Production for environment {{ $where }}"> Production</label>
                    </form>
                    <div class="form-foot">
                        <p class="note">@if($n > 0)Holds {{ $entries($n) }}, so it cannot be removed.@endif</p>
                        <div class="controls">
                            @if($n === 0)<form method="post" action="{{ $envPath }}/delete" data-confirm="Remove environment “{{ $env->name }}”?" data-confirm-from="confirm-setup">@csrf<button class="stop" aria-label="Remove environment {{ $where }}" data-held-name="Remove environment: {{ $project->name }} / {{ $env->name }}">Remove</button></form>@endif
                            <button form="{{ $formId }}" aria-label="Save environment {{ $where }}" data-held-name="Save environment: {{ $project->name }} / {{ $env->name }}">Save environment</button>
                        </div>
                    </div>
                </div>
            </details>
        @empty
            <p class="note">No environments yet. Add one to store entries in this project.</p>
        @endforelse
        <details class="env-add"@if($oldAddEnv) open @endif>
            <summary>+ Add an environment<span class="sr-only"> to project {{ $project->name }}</span></summary>
            <div class="inner">
                <form method="post" action="/workspaces/{{ $space->id }}/environments" data-confirm-from="confirm-setup">@csrf
                    <input type="hidden" name="project_id" value="{{ $project->id }}"><input type="hidden" name="back" value="structure">
                    <label><span>Name</span><input name="name" value="{{ $oldAddEnv && is_string(old('name')) ? old('name') : '' }}" required maxlength="60" placeholder="staging" aria-label="Environment name for project {{ $project->name }}"></label>
                    <label class="check"><input type="checkbox" name="production" value="1" @checked($oldAddEnv && old('production') === '1') aria-label="Production for environment in project {{ $project->name }}"> Production</label>
                    <div class="form-foot"><button aria-label="Add environment to project {{ $project->name }}" data-held-name="Add environment to project: {{ $project->name }}">Add environment</button></div>
                </form>
            </div>
        </details>
    </div>
@empty
    <div class="empty"><b>No projects yet</b>An entry lives in an environment of a project. <a href="#add-project">Add the first project</a></div>
@endforelse
</div>

<h2 id="add-project">Add a project</h2>
<form class="action" method="post" action="/workspaces/{{ $space->id }}/projects" data-confirm-from="confirm-setup">@csrf<input type="hidden" name="back" value="structure">
    <label><span>Name</span><input name="name" value="{{ $oldAddProject ? old('name') : '' }}" required maxlength="100" placeholder="e.g. billing"></label>
    @include('confirm-inline', ['verb' => 'add this project', 'shared' => 'confirm-add-project', 'primary' => 'confirm-setup', 'primaryShown' => true, 'fallback' => true])
    <div class="form-foot"><p class="note">Projects you add start with: {{ implode(', ', array_column($template, 'name')) ?: 'no environments' }}.</p><button class="go" data-held-name="Add project: " data-held-field="name">Add project</button></div>
</form>

<h2 id="template">Environment template</h2>
<p class="note">The environments each project starts with. Existing projects keep theirs.</p>
<form class="action" method="post" action="/workspaces/{{ $space->id }}/template" data-confirm-from="confirm-setup">@csrf
    <table class="list edit env-template">
        <thead><tr><th scope="col">Environment</th><th scope="col">Production</th></tr></thead>
        <tbody id="template-rows">
        @foreach($templateRows as $i => $env)
            @php $rowName = $env['name'] !== '' ? $env['name'] : 'row '.$loop->iteration; @endphp
            <tr><td data-label=""><input name="env_name[{{ $i }}]" value="{{ $env['name'] }}" maxlength="60" aria-label="Environment name, row {{ $loop->iteration }}"></td><td data-label=""><label class="check"><input type="checkbox" name="env_production[{{ $i }}]" value="1" @checked($env['production']) aria-label="Production for {{ $rowName }}"> <span class="phone-only">Production</span></label></td></tr>
        @endforeach
            <tr data-row-template><td data-label=""><input name="env_name[{{ $nextTemplateIdx }}]" maxlength="60" placeholder="e.g. qa" aria-label="Additional environment name"></td><td data-label=""><label class="check"><input type="checkbox" name="env_production[{{ $nextTemplateIdx }}]" value="1" aria-label="Production for additional environment"> <span class="phone-only">Production</span></label></td></tr>
        </tbody>
    </table>
    @include('confirm-inline', ['verb' => 'save this template', 'shared' => 'confirm-template', 'primary' => 'confirm-setup', 'primaryShown' => true, 'fallback' => true])
    <div class="form-foot"><p class="note">Clear a name to drop it from the template.</p><div class="controls"><button type="button" data-add-row="template-rows" aria-label="Add an environment to the template">+ Add an environment</button><button data-held-name="Save environment template">Save template</button></div></div>
</form>
@endsection
