@extends('layout')
@section('title', 'Members')
@section('content')
<div class="head"><h1>Members</h1><a class="btn" href="#add-member">+ Add a member</a></div>
@php
    $soleOwner = $members->where('role', 'owner')->count() === 1;
    $failedForm = session('error_form') === '/workspaces/'.$space->id.'/members' ? old('form') : null;
    $roles = ['viewer', 'editor', 'owner'];
@endphp
{{-- Save role and Remove never go to the confirm page while script runs: one shared password block above the board
     (as on Requests), named by every row form with data-confirm-from. A row action pressed after the confirmation
     expired is held there under a button that names it: "Change Dana's role to Editor" (data-held-field adds the
     role chosen in that row, which stays chosen) or "Remove Dana"; data-held-data marks the person's name, which
     keeps its case. While the confirmation is valid the block shows nothing: the "Add a member" form states it. --}}
@include('confirm-inline', ['verb' => 'change members', 'ask' => 'Enter your current password, then save a role or remove a member below.', 'shared' => 'confirm-members', 'quietValid' => true])
<div class="scroll"><table class="board compact-board members">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>@foreach($members as $m)<tr class="is-plain"><td data-label="Name"><b>{{ $m->name }}</b> @if($m->id == auth()->id())<span class="chip muted">you</span>@endif</td><td data-label="Email">{{ $m->email }}</td>
        <td data-label="Role">@if($soleOwner && $m->id == auth()->id() && $m->role === 'owner')
            <span class="chip role">owner</span><small>Add another owner before changing your role.</small>
        @else<form class="controls" method="post" action="/workspaces/{{ $space->id }}/members" data-confirm-from="confirm-members">@csrf
            <input type="hidden" name="form" value="row:{{ $m->id }}">
            <input type="hidden" name="name" value="{{ $m->name }}"><input type="hidden" name="email" value="{{ $m->email }}">
            <select name="role" aria-label="Role for {{ $m->name }}">@foreach($roles as $role)<option value="{{ $role }}" @selected($role === ($failedForm === 'row:'.$m->id ? old('role') : $m->role))>{{ ucfirst($role) }}</option>@endforeach</select><button aria-label="Save role for {{ $m->name }}" data-held-name="Change {{ $m->name }}'s role to " data-held-field="role" data-held-lead="Change " data-held-data="{{ $m->name }}">Save role</button>
        </form>@endif</td>
        <td data-label="">@if($m->id != auth()->id())<form method="post" action="/workspaces/{{ $space->id }}/members" data-confirm="Remove {{ $m->name }} from this workspace?" data-confirm-from="confirm-members">@csrf
            <input type="hidden" name="form" value="remove:{{ $m->id }}">
            <input type="hidden" name="name" value="{{ $m->name }}"><input type="hidden" name="email" value="{{ $m->email }}">
            <button class="stop" aria-label="Remove {{ $m->name }}" name="role" value="remove" data-held-name="Remove {{ $m->name }}" data-held-lead="Remove " data-held-data="{{ $m->name }}">Remove</button>
        </form>@endif</td></tr>@endforeach</tbody>
</table></div>
<p class="note">Viewers see entry metadata. Editors manage and reveal entries and edit Setup. Owners also manage members, agents and grants, approve agent requests, and see workspace activity.</p>
<h2 id="add-member">Add a member</h2>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
        <input type="hidden" name="form" value="add">
        <label><span>Name</span><input name="name" value="{{ $failedForm === 'add' ? old('name') : '' }}" required maxlength="100"></label>
        <label><span>Email</span><input name="email" type="email" value="{{ $failedForm === 'add' ? old('email') : '' }}" required></label>
        {{-- The hint is the field's description (aria-describedby), not part of its name. --}}
        <div class="field"><div class="field-name"><label for="first-password"><span>Initial password</span></label><small class="hint" id="first-password-hint">Leave blank for an existing account. At least 14 characters for a new one.</small></div><span class="with-button"><input id="first-password" name="password" type="password" minlength="14" autocomplete="new-password" aria-describedby="first-password-hint"><button type="button" data-generate="first-password" aria-label="Generate initial password">Generate</button></span></div>
        <label><span>Role</span><select name="role">@foreach($roles as $role)<option value="{{ $role }}" @selected(($failedForm === 'add' ? old('role', 'viewer') : 'viewer') === $role)>{{ ucfirst($role) }}</option>@endforeach</select></label>
        @include('confirm-inline', ['verb' => 'add this member'])
        <div class="form-foot"><p class="note">Hand the password over privately. The authenticator code is set up at the first sign-in. An existing account's password is not changed.</p><button class="go">Add member</button></div>
    </form>
@endsection
