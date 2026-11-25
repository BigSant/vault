@extends('layout')
@section('title', 'Members')
@section('content')
<h1>Members</h1>
@php
    $roles = ['viewer' => 'viewer — metadata only', 'editor' => 'editor — create, change, reveal', 'owner' => 'owner — members, agents, grants'];
@endphp
<p class="note">Viewers see entry metadata. Editors can manage and reveal entries. Owners also manage members, agents and grants.</p>
<div class="scroll"><table class="board compact-board members">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>@foreach($members as $m)<tr class="is-idle"><td data-label="Name"><b>{{ $m->name }}</b> @if($m->id == auth()->id())<small>you</small>@endif</td><td data-label="Email">{{ $m->email }}</td>
        <td data-label="Role"><form class="controls" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
            <input type="hidden" name="name" value="{{ $m->name }}"><input type="hidden" name="email" value="{{ $m->email }}">
            <select name="role" aria-label="Role for {{ $m->name }}">@foreach(array_keys($roles) as $role)<option value="{{ $role }}" @selected($role === $m->role)>{{ $role }}</option>@endforeach</select><button aria-label="Save role for {{ $m->name }}">Save</button>
        </form></td>
        <td data-label="">@if($m->id != auth()->id())<form method="post" action="/workspaces/{{ $space->id }}/members" data-confirm="Remove {{ $m->name }} from this workspace?">@csrf
            <input type="hidden" name="name" value="{{ $m->name }}"><input type="hidden" name="email" value="{{ $m->email }}">
            <button class="stop" aria-label="Remove {{ $m->name }}" name="role" value="remove">Remove</button>
        </form>@endif</td></tr>@endforeach</tbody>
</table></div>
<h2>Add a member</h2>
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
        <label><span>Name</span><input name="name" required maxlength="100"></label>
        <label><span>Email</span><input name="email" type="email" required></label>
        <label><span>First password <small>new user only</small></span><input name="password" type="password" minlength="14" autocomplete="new-password"></label>
        <label><span>Role</span><select name="role">@foreach($roles as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach</select></label>
        @include('confirm-inline', ['verb' => 'add this member'])
        <div class="form-foot"><p class="note">Hand the password over privately. MFA is set up at the first sign-in. An existing account's password is not changed.</p><button class="go">Add member</button></div>
    </form>
@endsection
