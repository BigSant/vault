{{-- Password field for a long sensitive form, so an expired confirmation never loses what was typed.
     Params: $verb (default 'save'). Uses the shared $confirmedUntil. Renders nothing while the confirmation
     has more than 4 minutes left; Access::recent() accepts confirm_password from the POST body. --}}
@php
    $until = isset($confirmedUntil) ? (int) $confirmedUntil : null;
    $expired = $until === null || $until <= time();
    $verb = $verb ?? 'save';
@endphp
@if($expired || $until - time() < 240)
<div class="confirm-inline">
    <h3>Confirm it is you</h3>
    <p class="note">@if($expired)Your password confirmation has expired.@else Your password confirmation ends at {{ gmdate('H:i', $until) }} UTC.@endif Enter your password to {{ $verb }}.</p>
    <label><span>Current password</span><input type="password" name="confirm_password" autocomplete="current-password" @if($expired) required @endif></label>
</div>
@endif
