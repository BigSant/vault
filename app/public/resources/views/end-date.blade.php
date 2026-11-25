{{-- When an agent or a grant ends, the same on Identities, Grants and an entry's Agent access.
     $revoked  the time it was revoked (wins over the expiry), or null
     $ends     the expiry that counts
     $reason   optional: why that is not the grant's own date ("agent expired")
     $own      optional: the grant's own expiry, shown as a second line when it differs from $ends
     The label is small.k: "Expires" is named by the column head, so it shows on phone cards only; "Revoked" and
     "Expired" are named by no head (.keep) and show everywhere. A date and its UTC never break apart. --}}
@php
    $edDay = fn ($value) => substr(str_replace('T', ' ', (string) $value), 0, 16);
    $edRevoked = $revoked ?? null;
    $edEnds = (string) ($ends ?? '');
    $edOwn = (string) ($own ?? '');
    $edPast = strtotime($edEnds) <= time();
@endphp
@if($edRevoked)<small class="k keep">Revoked</small> <span class="nobreak">{{ $edDay($edRevoked) }} <small class="k">UTC</small></span>@else<small class="k{{ $edPast ? ' keep' : '' }}">{{ $edPast ? 'Expired' : 'Expires' }}</small> <span class="nobreak">{{ $edDay($edEnds) }} <small class="k">UTC</small></span>@endif
@if($reason ?? null) <small class="wrap">{{ $reason }}</small>@endif
@if(! $edRevoked && $edOwn !== '' && $edOwn !== $edEnds) <small class="wrap">Grant expiry: <span class="nobreak">{{ $edDay($edOwn) }} UTC</span></small>@endif
