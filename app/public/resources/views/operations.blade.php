{{-- Agent requests as a board.
     $operations  rows from Workspace::requestRows(); waiting and other rows may be mixed, the partial splits them
     $mode        'waiting' (rows that need a decision or a site MFA code, with the controls),
                  'history' (everything else: finished, running, expired), or
                  'both'    (two h2 sections: waiting first, then history).
                  Without $mode: 'history' when $historyOnly is set, else 'waiting' (the round-3 calls).
     $workspace   workspace id for the decision forms
     $hideEntry   leave out the Entry column (the entry page)
     $heading, $sectionId, $empty   override the h2 text, its id and the empty text in the single modes
     $refreshUrl  adds a "Refresh states" link under the last table --}}
@php
    $mode = $mode ?? (isset($historyOnly) ? 'history' : 'waiting');
    $isWaiting = fn ($o) => in_array($o->state, ['pending', 'requires_user_action'], true) && strtotime((string) $o->expires_at) > time();
    $waitingRows = $operations->filter($isWaiting)->values();
    $historyRows = $operations->reject($isWaiting)->values();
    $n = $waitingRows->count();
    $waitingHeading = $n === 0 ? 'Requests waiting for you' : ($n === 1 ? '1 request waiting for you' : $n.' requests waiting for you');
    $single = $mode !== 'both';
    $sections = [];
    if ($mode !== 'history') {
        $sections[] = [false, $waitingRows, $single ? ($sectionId ?? 'waiting') : 'waiting', $single ? ($heading ?? $waitingHeading) : $waitingHeading,
            $single ? ($empty ?? 'No requests need your attention.') : 'No requests need your attention.'];
    }
    if ($mode !== 'waiting') {
        $sections[] = [true, $historyRows, $single ? ($sectionId ?? 'requests') : 'history', $single ? ($heading ?? 'Recent requests') : 'Recent requests',
            $single ? ($empty ?? 'No recent requests.') : 'No recent requests.'];
    }
    $stripes = ['succeeded' => 'is-ok', 'approved' => 'is-ok', 'failed' => 'is-bad', 'denied' => 'is-bad', 'pending' => 'is-wait', 'requires_user_action' => 'is-wait', 'running' => 'is-wait'];
    $time = fn ($value) => substr(str_replace('T', ' ', (string) $value), 0, 16);
@endphp
@foreach($sections as [$finished, $rows, $id, $title, $none])
<h2 id="{{ $id }}">{{ $title }}</h2>
@if($rows->isEmpty())
    <div class="empty">{{ $none }}</div>
@else
<div class="scroll"><table class="board compact-board requests">
    <thead><tr><th scope="col">Agent</th>@empty($hideEntry)<th scope="col">Entry</th>@endempty<th scope="col">Action</th><th scope="col">State</th><th scope="col">Requested (UTC)</th>@if($finished)<th scope="col">Finished (UTC)</th>@else<th scope="col">Decision</th>@endif</tr></thead>
    <tbody>
    @foreach($rows as $o)
        @php
            $state = in_array($o->state, ['pending', 'requires_user_action'], true) && ! $isWaiting($o) ? 'expired' : $o->state;
            $what = $o->agent.' runs '.$o->action.' on '.$o->entry;
        @endphp
        <tr class="{{ $stripes[$state] ?? 'is-idle' }}">
            <td class="who" data-label="Agent"><b class="nobreak">{{ $o->agent }}</b></td>
            @empty($hideEntry)<td class="name entry" data-label="Entry"><a href="/entries/{{ $o->entry_id }}">{{ $o->entry }}</a><small>{{ $o->project }} / @include('sign', ['production' => $o->production, 'name' => $o->environment])</small></td>@endempty
            <td class="name action" data-label="Action"><code>{{ $o->action }}</code><small class="muted" title="Request {{ $o->id }}">{{ substr($o->id, 0, 8) }}</small></td>
            <td class="state" data-label="State">@if(in_array($state, ['running', 'requires_user_action'], true))<span class="lamp {{ $state === 'running' ? 'run' : 'warn' }}"></span> @endif @include('outcome', ['outcome' => $state])</td>
            <td class="when" data-label="Requested (UTC)">@if($o->created_at ?? null)<small class="k">Requested</small> {{ $time($o->created_at) }} <small class="k">UTC</small>@else<span class="muted">request time unknown</span>@endif @unless($finished)<span class="line expires">Expires {{ $time($o->expires_at) }} UTC</span>@endunless</td>
            @if($finished)
            <td class="when" data-label="Finished (UTC)">@if(in_array($state, ['approved', 'running'], true) || ! ($o->updated_at ?? null))<span class="muted">not finished yet</span>@else<small class="k">Finished</small> {{ $time($o->updated_at) }} <small class="k">UTC</small>@endif</td>
            @else
            <td class="decision" data-label="Decision">
                @if($o->state === 'pending')
                    <form class="controls" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/decision">@csrf<button class="ok" name="decision" value="approve" aria-label="Allow once: {{ $what }}">Allow once</button><button class="stop" name="decision" value="deny" aria-label="Deny: {{ $what }}">Deny</button></form>
                @else
                    <form class="inline" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/mfa">@csrf<input name="code" pattern="[0-9]{6,8}" inputmode="numeric" autocomplete="one-time-code" required placeholder="site MFA code" aria-label="Site MFA code: {{ $what }}"><button class="ok" aria-label="Continue sign-in: {{ $what }}">Continue sign-in</button></form>
                @endif
            </td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
@endforeach
@isset($refreshUrl)
<p class="note"><a href="{{ $refreshUrl }}">Refresh states</a></p>
@endisset
