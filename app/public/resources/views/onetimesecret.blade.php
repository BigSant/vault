@extends('layout')
@section('title', 'Onetime Secret')
@section('content')
{{-- Setup › Onetime Secret (owner): the account every one-time link of the workspace is made with, and the lifetimes
     people and agents may choose. The API token goes to OpenBao and is never shown again. --}}
@php
    $saved = (bool) ($settings?->token_saved_at);
    $value = fn ($key, $fallback) => old($key, $settings->$key ?? $fallback);
@endphp
<div class="head"><h1>Setup</h1></div>
@include('tabs', ['area' => 'setup', 'current' => 'onetimesecret', 'space' => $space])
<p class="note">One-time links are made in this Onetime Secret account, so they are also in its dashboard. Use an account of your own; an owner of this workspace can replace it.</p>
@include('notice')

<h2 id="account">Account</h2>
@if($saved)
<table class="facts capped" aria-labelledby="account">
    <tr><th scope="row">Region</th><td>{{ $regions[$settings->region] ?? $settings->region }}</td></tr>
    <tr><th scope="row">Account email</th><td class="name">{{ $settings->email }}</td></tr>
    <tr><th scope="row">API token</th><td>Saved <span class="nobreak">{{ substr($settings->token_saved_at, 0, 16) }} UTC</span></td></tr>
    <tr><th scope="row">Lifetimes</th><td>{{ $lifetimes[$settings->default_lifetime] ?? '' }} by default, up to {{ $lifetimes[$settings->max_lifetime] ?? '' }}</td></tr>
</table>
<form class="action plain" method="post" action="/workspaces/{{ $space->id }}/onetimesecret/check">@csrf
    <div class="form-foot"><p class="note">Signs in to Onetime Secret with the saved email and token. Nothing is created.</p><button>Check account</button></div>
</form>
<form class="action plain" method="post" action="/workspaces/{{ $space->id }}/onetimesecret/remove" data-confirm="Remove the Onetime Secret account?@if($open) {{ $open }} {{ Str::plural('link', $open) }} made in it {{ $open === 1 ? 'is' : 'are' }} still open, and Vault can then no longer check or burn {{ $open === 1 ? 'it' : 'them' }}.@endif No one can make one-time links until an account is set up again.">@csrf
    <div class="form-foot"><p class="note">Deletes the token from OpenBao. Links already out end with their lifetime.</p><button class="stop">Remove account</button></div>
</form>
@else
<p class="note">Not set up yet. Create an account at Onetime Secret in the region you want, then copy its API token from the account settings.</p>
@endif

<h2 id="edit-account">{{ $saved ? 'Change the account' : 'Set up the account' }}</h2>
<form class="action" method="post" action="/workspaces/{{ $space->id }}/onetimesecret">@csrf
    <label><span>Region</span><select name="region">@foreach($regions as $key => $name)<option value="{{ $key }}" @selected($value('region', 'eu') === $key)>{{ $name }}</option>@endforeach</select></label>
    <label><span>Account email</span><input name="email" type="email" maxlength="200" required autocomplete="off" value="{{ $value('email', '') }}"></label>
    <div class="field"><label for="ots-token"><span>API token @if($saved)<small aria-hidden="true">optional</small>@endif</span></label><span class="with-button"><input id="ots-token" name="token" type="password" minlength="8" maxlength="200" autocomplete="off" @if($saved) placeholder="Leave blank to keep" @else required @endif aria-describedby="ots-token-hint"></span><small class="hint" id="ots-token-hint">Stored in OpenBao like an entry's values. It is never shown again.</small></div>
    <label><span>Default lifetime</span><select name="default_lifetime">@foreach($lifetimes as $seconds => $name)<option value="{{ $seconds }}" @selected((int) $value('default_lifetime', 86400) === $seconds)>{{ $name }}</option>@endforeach</select></label>
    <div class="field"><label for="ots-max"><span>Longest lifetime</span></label><select id="ots-max" name="max_lifetime" aria-describedby="ots-max-hint">@foreach($lifetimes as $seconds => $name)<option value="{{ $seconds }}" @selected((int) $value('max_lifetime', 604800) === $seconds)>{{ $name }}</option>@endforeach</select><small class="hint" id="ots-max-hint">People and agents choose up to this. The account's plan may allow less; Onetime Secret then refuses the link.</small></div>
    {{-- Shown while links of the current account are open: moving to another region or email leaves them to their lifetime. --}}
    @if($open)<div class="field check-field"><label class="check"><input type="checkbox" name="orphan" value="1" aria-describedby="ots-orphan-hint"> Change anyway</label><small class="hint" id="ots-orphan-hint">{{ $open }} one-time {{ Str::plural('link', $open) }} made in this account {{ $open === 1 ? 'is' : 'are' }} still open. In another region or with another email, Vault can no longer check or burn {{ $open === 1 ? 'it' : 'them' }}; a new token for the same account changes nothing.</small></div>@endif
    @include('confirm-inline', ['verb' => 'save the account'])
    <div class="form-foot"><p class="note">A link that is already out keeps its lifetime.</p><button class="go">Save account</button></div>
</form>
@endsection
