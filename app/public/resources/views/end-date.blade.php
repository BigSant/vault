{{-- When an agent or a grant ends, the same on Identities, Grants and an entry's Agent access.
     $revoked  the time it was revoked (wins over the expiry), or null
     $ends     the expiry that counts
     $reason   optional: why that is not the grant's own date ("agent expired")
     $own      optional: the grant's own expiry, shown as a second line when it differs from $ends
     The label is small.k: "Expires" is named by the column head, so it shows on phone cards only; "Revoked" and
     "Expired" are named by no head (.keep) and show everywhere. The label, its date and the UTC are one piece
     (.nobreak): "Revoked" never stands on a line above its date. --}}
@php
    $edDay = fn ($value) => substr(str_replace('T', ' ', (string) $value), 0, 16);
    $edRevoked = $revoked ?? null;
    $edEnds = (string) ($ends ?? '');
    $edOwn = (string) ($own ?? '');
    $edPast = strtotime($edEnds) <= time();
@endphp
@if($edRevoked)<span class="nobreak"><small class="k keep">Revoked</small> {{ $edDay($edRevoked) }} <small class="k">UTC</small></span>@else<span class="nobreak"><small class="k{{ $edPast ? ' keep' : '' }}">{{ $edPast ? 'Expired' : 'Expires' }}</small> {{ $edDay($edEnds) }} <small class="k">UTC</small></span>@endif
@if($reason ?? null) <small class="wrap">{{ $reason }}</small>@endif
@if(! $edRevoked && $edOwn !== '' && $edOwn !== $edEnds) <small class="wrap">Grant expiry: <span class="nobreak">{{ $edDay($edOwn) }} UTC</span></small>@endif
