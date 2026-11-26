@extends('layout')
@section('title', 'Link added')
@section('content')
{{-- Shown once, like an agent's token: the link is not on any other page. Leaving asks "Link copied?" until it was
     copied (the token page's guard, data-leave-guard). --}}
<div class="head"><h1>Links</h1></div>
<h2>Link added</h2>
<div class="card solo" data-leave-guard="Link copied?">
    <p><strong>{{ $link->note ?? ($entry->name ?? 'One-time link') }}</strong> <span class="token-expiry"><span class="sep" aria-hidden="true">· </span>Expires <span class="nobreak">{{ substr($link->expires_at, 0, 16) }} UTC</span></span></p>
    <div class="callout warn"><p>Copy the link now{{ $passphrase ? ' and the passphrase' : '' }}. {{ $passphrase ? 'They are' : 'It is' }} shown only once.</p></div>
    <div class="secret-row">
        <pre data-secret data-keep data-hidden-text="Hidden. If you did not copy the link, burn it on Links and add a new one." class="secret" id="link-url">{{ $url }}</pre>
        <div class="controls"><button type="button" class="go" aria-label="Copy the one-time link" data-copy="link-url" data-copy-name="link">Copy link</button><button type="button" data-leave-clear hidden>I've saved it</button></div>
    </div>
    @if($passphrase)
    <div class="secret-row">
        <p class="block-label"><span class="name">Passphrase</span></p>
        <pre data-secret data-keep class="secret" id="link-passphrase">{{ $passphrase }}</pre>
        <div class="controls"><button type="button" aria-label="Copy the passphrase" data-copy="link-passphrase" data-copy-name="passphrase">Copy passphrase</button></div>
    </div>
    <p class="note">Send the passphrase to the recipient another way than the link. It is not shown again either.</p>
    @endif
    <div class="form-foot"><p class="note">Do not open the link yourself: opening it uses it up. Links shows whether it was read.</p><div class="controls"><a class="btn" href="/workspaces/{{ $space->id }}/links" data-leave-done>Back to Links</a></div></div>
</div>
@endsection
