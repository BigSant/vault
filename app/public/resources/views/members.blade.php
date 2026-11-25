@extends('layout')
@section('title', 'Members')
@section('content')
<div class="head"><h1>Members</h1><a class="btn" href="#add-member">+ Add a member</a></div>
@php
    $soleOwner = $members->where('role', 'owner')->count() === 1;
    $roles = ['viewer', 'editor', 'owner'];
@endphp
<div class="scroll"><table class="board compact-board members">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>@foreach($members as $m)<tr class="is-idle"><td data-label="Name"><b>{{ $m->name }}</b> @if($m->id == auth()->id())<span class="chip muted">you</span>@endif</td><td data-label="Email">{{ $m->email }}</td>
        <td data-label="Role">@if($soleOwner && $m->id == auth()->id() && $m->role === 'owner')
            <span class="chip role">owner</span><small>Add another owner before changing your role</small>
        @else<form class="controls" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
            <input type="hidden" name="name" value="{{ $m->name }}"><input type="hidden" name="email" value="{{ $m->email }}">
            <select name="role" aria-label="Role for {{ $m->name }}">@foreach($roles as $role)<option value="{{ $role }}" @selected($role === $m->role)>{{ ucfirst($role) }}</option>@endforeach</select><button class="plain" aria-label="Save role for {{ $m->name }}">Save role</button>
        </form>@endif</td>
        <td data-label="">@if($m->id != auth()->id())<form method="post" action="/workspaces/{{ $space->id }}/members" data-confirm="Remove {{ $m->name }} from this workspace?">@csrf
            <input type="hidden" name="name" value="{{ $m->name }}"><input type="hidden" name="email" value="{{ $m->email }}">
            <button class="stop" aria-label="Remove {{ $m->name }}" name="role" value="remove">Remove</button>
        </form>@endif</td></tr>@endforeach</tbody>
</table></div>
<p class="note">Viewers see entry metadata. Editors manage and reveal entries and edit Setup. Owners also manage members, agents and grants, approve agent requests, and see workspace activity.</p>
<h2 id="add-member">Add a member</h2>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
        <label><span>Name</span><input name="name" value="{{ old('name') }}" required maxlength="100"></label>
        <label><span>Email</span><input name="email" type="email" value="{{ old('email') }}" required></label>
        <div class="field"><label for="first-password"><span>First password <small>new user only</small></span></label><span class="with-button"><input id="first-password" name="password" type="password" minlength="14" autocomplete="new-password"><button type="button" data-generate="first-password" aria-label="Generate first password">Generate</button></span></div>
        <label><span>Role</span><select name="role">@foreach($roles as $role)<option value="{{ $role }}" @selected(old('role', 'viewer') === $role)>{{ ucfirst($role) }}</option>@endforeach</select></label>
        @include('confirm-inline', ['verb' => 'add this member'])
        <div class="form-foot"><p class="note">Hand the password over privately. The authenticator code is set up at the first sign-in. An existing account's password is not changed.</p><button class="go">Add member</button></div>
    </form>
@endsection
