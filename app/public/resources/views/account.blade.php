@extends('layout')
@section('title', 'Account')
@section('content')
{{-- Sign out is no section of the page: one plain button in the head row, beside the h1. The row is as wide as
     the cards under it (.capped), so the button ends where they end. --}}
<div class="head capped"><h1>Account</h1><form method="post" action="/logout">@csrf<button aria-label="Sign out of this session">Sign out</button></form></div>
<table class="facts">
    <tr><th scope="row">Name</th><td>{{ $user->name }}</td></tr>
    <tr><th scope="row">Email</th><td>{{ $user->email }}</td></tr>
    <tr><th scope="row">Password confirmation</th><td>
        @if($confirmedUntil)
            <span class="chip ok">valid</span> until {{ gmdate('H:i', $confirmedUntil) }} UTC
        @else
            <span class="chip muted">needed</span> <a class="more-link" href="#confirm" data-focus="account-confirm-password">Confirm your password</a>
        @endif
    </td></tr>
</table>

{{-- The heading stays "Security" whatever the confirmation state; each card names itself with an h3.
     While a confirmation is needed the page shows one password field at rest: "Change sign-in password" is then a
     closed fold beside the confirm form (open again when its form came back with an error). A summary holds no
     heading: the fold's name is a span that looks like the card h3 it stands for. The deck is one column at the
     card width in both states, so the page keeps one right edge. --}}
<h2 id="security" class="capped">Security</h2>
<div class="deck wide capped">
    @unless($confirmedUntil)
    <form class="action" id="confirm" method="post" action="/confirm">@csrf
        <input type="hidden" name="return" value="/account">
        <h3>Password confirmation<small>Valid for 5 minutes. Needed to reveal values and to change entries, access or setup.</small></h3>
        <label><span>Current password</span><input type="password" id="account-confirm-password" name="current_password" required maxlength="200" autocomplete="current-password"></label>
        <div class="form-foot"><button class="go">Confirm</button></div>
    </form>
    <details class="fold password-fold"@if(session('error_form') === '/password') open @endif>
        <summary><span class="fold-name legend">Change sign-in password</span><span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span></span></summary>
        <div class="inner">
    <form method="post" action="/password">@csrf
    @else
    <form class="action" method="post" action="/password">@csrf
        <h3>Change sign-in password</h3>
    @endunless
        {{-- With the fold open two "Current password" fields are on the page: this one says what it is for. --}}
        <label><span>Current password @unless($confirmedUntil)<small>to change it</small>@endunless</span><input name="current_password" type="password" required maxlength="200" autocomplete="current-password"></label>
        {{-- New passwords can be checked before they are sent: app.js adds Reveal/Hide to a password input in .with-button. --}}
        <div class="field"><label for="new-password"><span>New password</span></label><span class="with-button"><input id="new-password" name="password" type="password" required minlength="14" maxlength="200" autocomplete="new-password"></span></div>
        <div class="field"><label for="new-password-repeat"><span>Repeat new password</span></label><span class="with-button"><input id="new-password-repeat" name="password_confirmation" type="password" required maxlength="200" autocomplete="new-password"></span></div>
        <div class="form-foot"><p class="note">At least 14 characters. Signs out every session, this one too.</p><button>Change password and sign out</button></div>
    </form>
    @unless($confirmedUntil)
        </div>
    </details>
    @endunless
</div>

<h2 class="capped">Workspaces</h2>
@if($navSpaces->isEmpty())
    <div class="empty capped">You are in no workspace yet. <a class="more-link" href="/workspaces/new">Add a workspace</a>.</div>
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

@endsection
