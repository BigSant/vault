@extends('layout')
@section('title', 'Members')
@section('content')
<h1>Members</h1>
@php
    $roles = ['viewer' => 'viewer — metadata only', 'editor' => 'editor — create, change, reveal', 'owner' => 'owner — members, agents, policy'];
@endphp
<p class="note">Viewers see entry metadata. Editors can manage and reveal entries. Owners also manage members, agents and grants.</p>
<div class="scroll"><table class="list">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th></tr></thead>
    <tbody>@foreach($members as $m)<tr><td><b>{{ $m->name }}</b></td><td>{{ $m->email }}</td><td><span class="chip {{ $m->role === 'owner' ? 'warn' : ($m->role === 'editor' ? 'ok' : 'steel') }}">{{ $m->role }}</span></td></tr>@endforeach</tbody>
</table></div>
<div class="deck">
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/members">@csrf
        <h3>Add or change a member</h3>
        <label><span>Name</span><input name="name" required maxlength="100"></label>
        <label><span>Email</span><input name="email" type="email" required></label>
        <label><span>First password <small>new user only</small></span><input name="password" type="password" minlength="14" autocomplete="new-password"></label>
        <label><span>Role</span><select name="role">@foreach($roles as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach<option value="remove">remove membership</option></select></label>
        <div class="form-foot"><p class="note">Hand the password over privately. MFA is set up at the first sign-in. An existing account's password is not changed.</p><button class="go">Save member</button></div>
    </form>
</div>
@endsection
