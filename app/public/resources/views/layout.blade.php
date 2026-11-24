<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Vault') · Vault</title><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head><body>
@hasSection('bare')
<main class="login"><a class="mark" href="/">VAULT<small>credential store</small></a>@yield('content')</main>
@else
@php
    $navSpace = $space ?? null;
    $on = fn (string $pattern) => request()->is($pattern) ? ' on' : '';
@endphp
<header class="top">
    <a class="mark" href="/">VAULT<small>{{ $navSpace->name ?? 'credential store' }}</small></a>
    <nav class="nav" aria-label="Main">
        <a class="btn{{ request()->is('/') || request()->is('entries*') ? ' on' : '' }}" href="{{ $navSpace ? '/?workspace='.$navSpace->id : '/' }}">Entries</a>
        @if($navSpace && in_array($navSpace->role ?? null, ['owner', 'editor'], true))
            <a class="btn{{ $on('workspaces/*/structure') }}" href="/workspaces/{{ $navSpace->id }}/structure">Projects</a>
            <a class="btn{{ $on('workspaces/*/types') }}" href="/workspaces/{{ $navSpace->id }}/types">Entry types</a>
        @endif
        @if($navSpace && ($navSpace->role ?? null) === 'owner')
            <a class="btn{{ $on('workspaces/*/settings') }}" href="/workspaces/{{ $navSpace->id }}/settings">Access</a>
            <a class="btn{{ $on('workspaces/*/audit') }}" href="/workspaces/{{ $navSpace->id }}/audit">Activity</a>
        @endif
    </nav>
    <span class="spacer"></span>
    @auth<div class="who"><b>{{ auth()->user()->name }}</b><form action="/logout" method="post">@csrf<button>Lock</button></form></div>@endauth
</header>
<main>
@yield('content')
@auth
<h2>Session</h2>
<div class="deck">
    <form class="action" method="post" action="/confirm">@csrf
        <h3>Confirm sensitive actions<small>Valid for 5 minutes. Needed to reveal values and to change access.</small></h3>
        <label><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"></label>
        <div class="form-foot"><p class="note">Reveals and access changes are written to the activity log.</p><button>Confirm</button></div>
    </form>
    <details>
        <summary>Change sign-in password</summary>
        <div class="inner"><form method="post" action="/password">@csrf
            <label><span>Current password</span><input name="current_password" type="password" required autocomplete="current-password"></label>
            <label><span>New password</span><input name="password" type="password" required minlength="14" maxlength="200" autocomplete="new-password"></label>
            <label><span>Repeat</span><input name="password_confirmation" type="password" required autocomplete="new-password"></label>
            <div class="form-foot"><p class="note">At least 14 characters. Every session signs out.</p><button class="stop">Change and sign out</button></div>
        </form></div>
    </details>
</div>
<p class="foot"><span class="lamp ok"></span> Secret values stay in the vault. Agents only get action results.</p>
@endauth
</main>
@endif
</body></html>
