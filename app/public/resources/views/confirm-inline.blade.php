{{-- Password confirmation inside the page, so an expired confirmation never loses what was typed and never sends a
     time-critical action to the confirm page.
     Params: $verb (default 'save'); $ask (optional: the sentence that asks for the password, instead of "Enter your
     current password to <verb>."); $shared (optional id); $shownWith (optional region name); $primary and
     $primaryShown (below). Uses $confirmedUntil.
     While the confirmation is valid the block is one quiet line, "Password confirmed until HH:MM UTC.", and the field is
     hidden and disabled. When the confirmation has expired the line says so and the field is shown and required;
     app.js makes that switch on a page left open, and at the latest on submit.
     Inside a form the field is that form's own, with or without script.
     With $shared the block stands outside the forms it serves (Reveal in the Secret values card and per version;
     the waiting requests' decisions): each such form names it with data-confirm-from="<id>" and app.js copies the
     password into the form on submit. Without script a shared field could not be sent, so the server prints only
     the line "You will be asked for your password" and the confirm page stays the fallback; app.js swaps in the
     field (data-script-text). An action pressed without the password is held, and the block says so where it is
     looked at: a button under the field names the held action ("Allow once: shop.admin-login, request 1a2b3c4d")
     and sends it. A password entered first, with nothing held and no default form, gets the visible line "Now
     choose an action below." (.pending).
     $shownWith: the block shows only while that refresh region holds waiting rows (app.js showWith).
     $primary: the id of another shared block earlier on the same page (the entry page: "confirm-decide" above the
     waiting requests). A page states its confirmation once and shows one password field at a time: while the
     primary block is on screen ($primaryShown at render time; app.js follows it afterwards) this one is deferred.
     It shows nothing while the confirmation is valid, and when it expired a line that points to the primary field,
     whose password then serves this block's forms too. Without script a deferred block shows nothing.
     Access::recent() accepts confirm_password from the POST body.
     The title looks like the form's section legends (.legend) and is not a heading. --}}
@php
    $until = isset($confirmedUntil) ? (int) $confirmedUntil : null;
    $expired = $until === null || $until <= time();
    $verb = $verb ?? 'save';
    $shared = $shared ?? null;
    $ask = 'Your password confirmation has expired. '.($ask ?? 'Enter your current password to '.$verb.'.');
    $noScript = 'Your password confirmation has expired. You will be asked for your password first.';
    $fieldHidden = ! $expired || $shared;
    $primary = $primary ?? null;
    $deferred = $shared && $primary && ! empty($primaryShown);
@endphp
<div class="confirm-inline{{ $shared ? ' shared' : '' }}{{ $fieldHidden ? '' : ' is-needed' }}{{ $deferred ? ' is-deferred is-quiet' : '' }}"@if($shared) id="{{ $shared }}"@endif @if($shared && $primary) data-primary="{{ $primary }}"@endif data-confirmed-until="{{ (int) $until }}" data-now="{{ time() }}" data-verb="{{ $verb }}" data-ask="{{ $ask }}"@isset($shownWith) data-shown-with="{{ $shownWith }}" data-shown-rows @if(! empty($shownHidden)) hidden @endif @endisset>
    <p class="legend" data-when="expired" @if($fieldHidden) hidden @endif>Password confirmation</p>
    <p class="note"><span data-when="valid" @if($expired || $deferred) hidden @endif>Password confirmed until <span class="nobreak">{{ $until ? gmdate('H:i', $until) : '' }} UTC</span>.</span><span data-when="expired"@if($shared) data-script-text="{{ $ask }}"@endif @if(! $expired || $deferred) hidden @endif>{{ $shared ? $noScript : $ask }}</span>@if($shared && $primary)<span data-when="deferred" hidden>Your password confirmation has expired. <a class="more-link" href="#{{ $primary }}-password" data-confirm-goto="{{ $primary }}">Go to the password field</a> above the waiting requests, enter your current password, then {{ $verb }}.</span>@endif @if($shared) <span class="pending" data-pending aria-hidden="true" hidden></span>@endif</p>
    <label data-when="expired" @if($fieldHidden) hidden @endif><span>Current password</span><input type="password" name="confirm_password"@if($shared) id="{{ $shared }}-password"@endif maxlength="200" autocomplete="current-password" @if($expired && ! $shared) required @else disabled @endif></label>
    @if($shared)<p class="held" data-held-row hidden><button type="button" class="go" data-held></button></p>@endif
</div>
