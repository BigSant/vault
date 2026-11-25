@extends('layout')
@section('title', 'Account')
@section('content')
<h1>Account</h1>
<table class="facts">
    <tr><th scope="row">Name</th><td>{{ $user->name }}</td></tr>
    <tr><th scope="row">Email</th><td>{{ $user->email }}</td></tr>
    <tr><th scope="row">Password confirmation</th><td>
        @if($confirmedUntil)
            <span class="chip ok">valid</span> until {{ gmdate('H:i', $confirmedUntil) }} UTC
        @else
            <span class="chip muted">needed</span> <a href="#confirm">Confirm your password</a> to reveal values or change access.
        @endif
    </td></tr>
</table>

{{-- The heading stays "Security" whatever the confirmation state; each card names itself with an h3.
     While a confirmation is needed the page shows one password field at rest: "Change sign-in password" is then a
     closed fold beside the confirm form (open again when its form came back with an error). --}}
<h2 id="security">Security</h2>
<div class="deck">
    @unless($confirmedUntil)
    <form class="action" id="confirm" method="post" action="/confirm">@csrf
        <input type="hidden" name="return" value="/account">
        <h3>Password confirmation<small>Valid for 5 minutes. Needed to reveal values and to change entries, access or setup.</small></h3>
        <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password"></label>
        <div class="form-foot"><p class="note">Reveals and access changes are written to the activity log.</p><button class="go">Confirm</button></div>
    </form>
    <details class="fold password-fold"@if(session('error_form') === '/password') open @endif>
        <summary><h3 class="fold-name">Change sign-in password</h3><span class="fold-act"><span class="when-closed">Open</span><span class="when-open">Close</span></span></summary>
        <div class="inner">
    <form method="post" action="/password">@csrf
    @else
    <form class="action" method="post" action="/password">@csrf
        <h3>Change sign-in password</h3>
    @endunless
        {{-- With the fold open two "Current password" fields are on the page: this one says what it is for. --}}
        <label><span>Current password @unless($confirmedUntil)<small>to change it</small>@endunless</span><input name="current_password" type="password" required maxlength="200" autocomplete="current-password"></label>
        <label><span>New password</span><input name="password" type="password" required minlength="14" maxlength="200" autocomplete="new-password"></label>
        <label><span>Repeat new password</span><input name="password_confirmation" type="password" required maxlength="200" autocomplete="new-password"></label>
        <div class="form-foot"><p class="note">At least 14 characters. Signs out every session, this one too.</p><button>Change password and sign out</button></div>
    </form>
    @unless($confirmedUntil)
        </div>
    </details>
    @endunless
</div>

<h2>Workspaces</h2>
@if($navSpaces->isEmpty())
    <div class="empty capped">You are in no workspace yet. <a class="more-link" href="/workspaces/new">Add a workspace</a></div>
@else
<div class="scroll capped"><table class="list">
    <thead><tr><th scope="col">Workspace</th><th scope="col">Your role</th></tr></thead>
    <tbody>
    @foreach($navSpaces as $s)
        <tr><td><a href="/?workspace={{ $s->id }}">{{ $s->name }}</a></td><td><span class="chip role">{{ $s->role }}</span></td></tr>
    @endforeach
    </tbody>
</table></div>
<p class="note"><a class="more-link" href="/workspaces/new">Add a workspace</a> to keep another set of projects, entries, members and agents apart.</p>
@endif

<h2>Sign out</h2>
{{-- A plain box, not a form card with the amber top: the note on the left, the plain button on the right. --}}
<form class="action quiet" method="post" action="/logout">@csrf
    <div class="form-foot"><p class="note">Ends this session. Sign in again with your password and authenticator code.</p><button aria-label="Sign out of this session">Sign out</button></div>
</form>
@endsection
