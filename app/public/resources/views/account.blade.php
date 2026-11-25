@extends('layout')
@section('title', 'Account')
@section('content')
{{-- Sign out is no section of the page: one plain button in the head row, beside the h1. --}}
<div class="head"><h1>Account</h1><form method="post" action="/logout">@csrf<button aria-label="Sign out of this session">Sign out</button></form></div>
<table class="facts">
    <tr><th scope="row">Name</th><td>{{ $user->name }}</td></tr>
    <tr><th scope="row">Email</th><td>{{ $user->email }}</td></tr>
    <tr><th scope="row">Password confirmation</th><td>
        @if($confirmedUntil)
            <span class="chip ok">valid</span> until {{ gmdate('H:i', $confirmedUntil) }} UTC
        @else
            <span class="chip muted">needed</span> <a href="#confirm">Confirm your password</a>
        @endif
    </td></tr>
</table>

{{-- The heading stays "Security" whatever the confirmation state; each card names itself with an h3.
     While a confirmation is needed the page shows one password field at rest: "Change sign-in password" is then a
     closed fold beside the confirm form (open again when its form came back with an error). A summary holds no
     heading: the fold's name is a span that looks like the card h3 it stands for. --}}
<h2 id="security">Security</h2>
<div class="deck">
    @unless($confirmedUntil)
    <form class="action" id="confirm" method="post" action="/confirm">@csrf
        <input type="hidden" name="return" value="/account">
        <h3>Password confirmation<small>Valid for 5 minutes. Needed to reveal values and to change entries, access or setup.</small></h3>
        <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password"></label>
        <div class="form-foot"><button class="go">Confirm</button></div>
    </form>
    <details class="fold password-fold"@if(session('error_form') === '/password') open @endif>
        <summary><span class="fold-name legend">Change sign-in password</span><span class="fold-act"><span class="when-closed">Open</span><span class="when-open">Close</span></span></summary>
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

@endsection
