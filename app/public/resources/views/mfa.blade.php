@extends('layout')
@section('title', 'Authenticator code')
@section('bare', '1')
@section('content')
<form class="action" method="post" action="/mfa">@csrf
    <h1>Authenticator code</h1>
    @if($seed)
        <p>{{ $qr ? 'Scan the code' : 'Add the key below' }} with an authenticator app (Aegis, 2FAS, Google Authenticator or similar), then enter the 6-digit code it shows.</p>
        @if($qr)<img class="totp-qr" src="{{ $qr }}" width="240" height="240" alt="Authenticator setup QR code">@endif
        <div class="secret-row">
            <div class="block-label">{{ $qr ? 'Cannot scan? Enter this key by hand' : 'Enter this key in your authenticator app' }}</div>
            <pre data-secret class="secret" data-keep id="totp-seed">{{ $seed }}</pre>
            <div class="controls"><button type="button" data-copy="totp-seed">Copy key</button></div>
        </div>
        <p class="note">Time-based code (TOTP), 6 digits, 30 seconds.</p>
    @else
        <p class="note">Enter the current code from your authenticator app.</p>
    @endif
    <label><span>6-digit code</span><input name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required maxlength="6"></label>
    <div class="form-foot"><p class="note"></p><button class="go">Continue</button></div>
</form>
@endsection
