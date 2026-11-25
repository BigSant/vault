{{-- Password confirmation inside the page, so an expired confirmation never loses what was typed and never sends a
     time-critical action to the confirm page.
     Params: $verb (default 'save'); $shared (optional id); $shownWith (optional region name). Uses $confirmedUntil.
     While the confirmation is valid the block is one quiet line, "Confirmed until HH:MM UTC.", and the field is
     hidden and disabled. When the confirmation has expired the line says so and the field is shown and required;
     app.js makes that switch on a page left open, and at the latest on submit.
     Inside a form the field is that form's own, with or without script.
     With $shared the block stands outside the forms it serves (Reveal in the Secret values card and per version;
     the waiting requests' decisions): each such form names it with data-confirm-from="<id>" and app.js copies the
     password into the form on submit. Without script a shared field could not be sent, so the server prints only
     the line "You will be asked for your password" and the confirm page stays the fallback; app.js swaps in the
     field (data-script-text).
     $shownWith: the block shows only while that refresh region holds waiting rows (app.js showWith).
     Access::recent() accepts confirm_password from the POST body.
     The title looks like the form's section legends (.legend) and is not a heading. --}}
@php
    $until = isset($confirmedUntil) ? (int) $confirmedUntil : null;
    $expired = $until === null || $until <= time();
    $verb = $verb ?? 'save';
    $shared = $shared ?? null;
    $ask = 'Your password confirmation has expired. Enter your current password to '.$verb.'.';
    $noScript = 'Your password confirmation has expired. You will be asked for your password first.';
    $fieldHidden = ! $expired || $shared;
@endphp
<div class="confirm-inline{{ $shared ? ' shared' : '' }}{{ $fieldHidden ? '' : ' is-needed' }}"@if($shared) id="{{ $shared }}"@endif data-confirmed-until="{{ (int) $until }}" data-now="{{ time() }}" data-verb="{{ $verb }}"@isset($shownWith) data-shown-with="{{ $shownWith }}" data-shown-rows @if(! empty($shownHidden)) hidden @endif @endisset>
    <p class="legend" data-when="expired" @if($fieldHidden) hidden @endif>Password confirmation</p>
    <p class="note"><span data-when="valid" @if($expired) hidden @endif>Confirmed until <span class="nobreak">{{ $until ? gmdate('H:i', $until) : '' }} UTC</span>.</span><span data-when="expired"@if($shared) data-script-text="{{ $ask }}"@endif @unless($expired) hidden @endunless>{{ $shared ? $noScript : $ask }}</span>@if($shared) <span class="pending" data-pending aria-hidden="true" hidden></span>@endif</p>
    <label data-when="expired" @if($fieldHidden) hidden @endif><span>Current password</span><input type="password" name="confirm_password"@if($shared) id="{{ $shared }}-password"@endif maxlength="200" autocomplete="current-password" @if($expired && ! $shared) required @else disabled @endif></label>
</div>
