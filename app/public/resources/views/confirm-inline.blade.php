{{-- Password field for a long sensitive form, so an expired confirmation never loses what was typed.
     Params: $verb (default 'save'). Uses the shared $confirmedUntil. Always rendered, but hidden (and its field
     disabled) while the confirmation has more than 4 minutes left: app.js shows it when the time comes, so a form
     left open still gets the field. Without script the server's choice stands, as before.
     Access::recent() accepts confirm_password from the POST body.
     The title looks like the form's section legends (.legend) and is not a heading. --}}
@php
    $until = isset($confirmedUntil) ? (int) $confirmedUntil : null;
    $expired = $until === null || $until <= time();
    $early = ! $expired && $until - time() >= 240;
    $verb = $verb ?? 'save';
@endphp
<div class="confirm-inline" data-confirmed-until="{{ (int) $until }}" data-now="{{ time() }}" @if($early) hidden @endif>
    <p class="legend">Confirm your identity</p>
    <p class="note"><span data-when="ending" @if($expired) hidden @endif>Your password confirmation ends{{ $until ? ' at '.gmdate('H:i', $until).' UTC' : ' soon' }}.</span><span data-when="expired" @unless($expired) hidden @endunless>Your password confirmation has expired.</span> Enter your password to {{ $verb }}.</p>
    <label><span>Current password</span><input type="password" name="confirm_password" autocomplete="current-password" @if($expired) required @endif @if($early) disabled @endif></label>
</div>
