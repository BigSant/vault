@extends('layout')
@section('title', 'Account')
@section('content')
<h1>Account</h1>
<table class="facts">
    <tr><th scope="row">Name</th><td>{{ $user->name }}</td></tr>
    <tr><th scope="row">Email</th><td>{{ $user->email }}</td></tr>
    <tr><th scope="row">Sensitive actions</th><td>
        @if($confirmedUntil)
            <span class="chip ok">confirmed</span> until {{ gmdate('H:i', $confirmedUntil) }} UTC
        @else
            <span class="chip muted">not confirmed</span> <a href="#confirm">Confirm your password</a> to reveal values or change access.
        @endif
    </td></tr>
</table>

{{-- Two cards sit under "Security". With the confirmation done only one form is left, so the heading is its name. --}}
<h2 id="security">{{ $confirmedUntil ? 'Change sign-in password' : 'Security' }}</h2>
<div class="deck">
    @unless($confirmedUntil)
    <form class="action" id="confirm" method="post" action="/confirm">@csrf
        <input type="hidden" name="return" value="/account">
        <h3>Confirm sensitive actions<small>Lasts 5 minutes. Needed to reveal values and to change entries, access or setup.</small></h3>
        <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password"></label>
        <div class="form-foot"><p class="note">Reveals and access changes are written to the activity log.</p><button class="go">Confirm</button></div>
    </form>
    @endunless
    <form class="action" method="post" action="/password">@csrf
        @unless($confirmedUntil)<h3>Change sign-in password</h3>@endunless
        <label><span>Current password</span><input name="current_password" type="password" required maxlength="200" autocomplete="current-password"></label>
        <label><span>New password</span><input name="password" type="password" required minlength="14" maxlength="200" autocomplete="new-password"></label>
        <label><span>Repeat new password</span><input name="password_confirmation" type="password" required maxlength="200" autocomplete="new-password"></label>
        <div class="form-foot"><p class="note">At least 14 characters. Signs out every session, this one too.</p><button class="stop">Change password and sign out</button></div>
    </form>
</div>

<h2>Workspaces</h2>
@if($navSpaces->isEmpty())
    <div class="empty">You are in no workspace yet. <a href="/workspaces/new">Add a workspace</a></div>
@else
<div class="scroll capped"><table class="list">
    <thead><tr><th scope="col">Workspace</th><th scope="col">Your role</th></tr></thead>
    <tbody>
    @foreach($navSpaces as $s)
        <tr><td><a href="/?workspace={{ $s->id }}">{{ $s->name }}</a></td><td><span class="chip role">{{ $s->role }}</span></td></tr>
    @endforeach
    </tbody>
</table></div>
<p class="note"><a href="/workspaces/new">Add a workspace</a> to keep another set of projects, entries, members and agents apart.</p>
@endif

<h2>Sign out</h2>
{{-- One button, nothing to fill in: a plain row at the card width, not a card. --}}
<form class="form-foot plain-row" method="post" action="/logout">@csrf
    <p class="note">Ends this session. Sign in again with your password and authenticator code.</p><button aria-label="Sign out of this session">Sign out</button>
</form>
@endsection
