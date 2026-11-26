@extends('layout')
@section('title', 'Projects')
@section('content')
{{-- Setup › Projects: one fold per project (rename, remove), then "Add a project". Environments are their own tab:
     one list for the whole workspace, which every project uses. --}}
@php
    $entries = fn (int $n) => $n.' '.($n === 1 ? 'entry' : 'entries');
    $addPath = '/workspaces/'.$space->id.'/projects';
    $oldAddProject = session('error_form') === null || session('error_form') === $addPath;
@endphp
<div class="head"><h1>Setup</h1><a class="btn" href="#add-project">+ Add a project</a></div>
@include('tabs', ['area' => 'setup', 'current' => 'structure', 'space' => $space])

{{-- No h2 here: it would repeat the active tab. The project folds follow the tabs; the form below keeps its h2. --}}
<p class="note">A project groups entries; an entry also names its environment (Environments tab). Only an empty project can be removed (archived entries count as entries).</p>

{{-- One password block for the page, above the projects: every form below names it (data-confirm-from). With no
     projects there is nothing above the add form, which then has its own field. --}}
@unless($projects->isEmpty())
@include('confirm-inline', ['verb' => 'change projects', 'ask' => 'Enter your current password, then save, add or remove below.', 'shared' => 'confirm-setup'])
@endunless
@forelse($projects as $project)
    @php
        $n = $counts[$project->id] ?? 0;
        $projPath = $addPath.'/'.$project->id;
        $oldRename = session('error_form') === $projPath;
        $oldDelete = session('error_form') === $projPath.'/delete';
    @endphp
    <details class="fold project-fold capped" id="p-{{ $project->id }}"@if($oldRename || $oldDelete) open @endif>
        <summary><span class="fold-name"><span class="name">{{ $project->name }}</span></span>
            <span class="fold-tail"><span class="fold-meta">{{ $entries($n) }}</span>
            <span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span><span class="sr-only"> project {{ $project->name }}</span></span></span></summary>
        <div class="inner">
            <form method="post" action="{{ $projPath }}" data-confirm-from="confirm-setup">@csrf
                <label><span>Name</span><input name="name" value="{{ $oldRename && is_string(old('name')) ? old('name') : $project->name }}" required maxlength="100" aria-label="Name of project {{ $project->name }}"></label>
                <div class="form-foot"><p class="note">@if($n > 0)Holds {{ $entries($n) }}, so it cannot be removed.@endif</p><button aria-label="Save project {{ $project->name }}" data-held-name="Save project: {{ $project->name }}">Save project</button></div>
            </form>
            @if($n === 0)<form class="fold-foot" method="post" action="{{ $projPath }}/delete" data-confirm="Remove project {{ $project->name }}?" data-confirm-from="confirm-setup">@csrf<button class="stop" aria-label="Remove project {{ $project->name }}" data-held-name="Remove project: {{ $project->name }}">Remove project</button></form>@endif
        </div>
    </details>
@empty
    <div class="empty capped"><b>No projects yet.</b>An entry belongs to a project. <a href="#add-project">Add the first project</a>.</div>
@endforelse

<h2 id="add-project">Add a project</h2>
<form class="action" method="post" action="{{ $addPath }}"@unless($projects->isEmpty()) data-confirm-from="confirm-setup"@endunless>@csrf<input type="hidden" name="back" value="structure">
    <label><span>Name</span><input name="name" value="{{ $oldAddProject ? old('name') : '' }}" required maxlength="100" placeholder="e.g. billing"></label>
    @if($projects->isEmpty())
        @include('confirm-inline', ['verb' => 'add this project'])
    @else
        @include('confirm-inline', ['verb' => 'add this project', 'shared' => 'confirm-add-project', 'primary' => 'confirm-setup', 'primaryShown' => true, 'fallback' => true])
    @endif
    <div class="form-foot"><p class="note">Its entries use the workspace's environments.</p><button class="go" data-held-name="Add project: " data-held-field="name">Add project</button></div>
</form>
@endsection
