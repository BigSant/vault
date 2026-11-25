{{-- Agent requests as a board.
     $operations  rows from Workspace::requestRows(); waiting and other rows may be mixed, the partial splits them
     $mode        'waiting' (rows that need a decision or a site MFA code, with the controls),
                  'history' (everything else: finished, running, expired), or
                  'both'    (two h2 sections: waiting first, then history).
                  Without $mode: 'history' when $historyOnly is set, else 'waiting' (the round-3 calls).
     $workspace   workspace id for the decision forms
     $hideEntry   leave out the Entry column (the entry page)
     $heading, $sectionId, $empty   override the h2 text, its id and the empty text in the single modes
     $refreshUrl  adds a "Refresh" link under the last table; it is also the address app.js reloads every 30 seconds
                  while requests wait (without it: $back)
     $back        sent with the decision and MFA forms as a hidden `back`, so the controller returns to that page
                  (the entry page sends its own path, /entries/{id}); without it the controller returns to Requests
     State shown: "denied" (the owner said no), "expired" (the deadline passed) or "cancelled" (stored as revoked
     without an owner decision: the agent withdrew it, or its grant or agent was revoked). The controller's
     `decision_label` says which; without it, revoked with approved_by reads "denied".
     An expired row shows its deadline ("Expired …") where a finished one shows "Finished …".
     Waiting rows carry data-expires and the table data-now (Unix seconds): app.js shows the time left, switches a
     row off at its deadline and refreshes the page. Timestamp labels are <small class="k">; .keep marks the two
     that no column head replaces. A date and its time never break apart (.nobreak).
     Columns: Agent · Action · Entry. Phone card: "<agent> requests <action>", then "<entry> · <project> /
     <environment>", the state, then "Requested …", "Expires …" or "Finished …" each on a line of its own, the
     controls, and the request id as the last, quiet line (on wide screens it sits under the action). --}}
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
    $reload = $refreshUrl ?? $back ?? null;
@endphp
@foreach($sections as [$finished, $rows, $id, $title, $none])
<h2 id="{{ $id }}">{{ $title }}</h2>
@if($rows->isEmpty())
    <div class="empty">{{ $none }}</div>
@else
<div class="scroll"><table class="board compact-board requests"@unless($finished) data-now="{{ time() }}"@if($reload) data-refresh="{{ $reload }}"@endif @endunless>
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th>@empty($hideEntry)<th scope="col">Entry</th>@endempty<th scope="col">State</th><th scope="col">Requested (UTC)</th>@if($finished)<th scope="col">Finished (UTC)</th>@else<th scope="col">Decision</th>@endif</tr></thead>
    <tbody>
    @foreach($rows as $o)
        @php
            $state = in_array($o->state, ['pending', 'requires_user_action'], true) && ! $isWaiting($o) ? 'expired' : $o->state;
            if ($finished) {
                $state = ($o->decision_label ?? null) ?: ($state === 'revoked' && ($o->approved_by ?? null) ? 'denied' : $state);
                // Revoked without an owner decision: the agent cancelled it, or its grant or agent was revoked.
                $state = $state === 'revoked' ? 'cancelled' : $state;
            }
            $what = $o->agent.' runs '.$o->action.' on '.$o->entry.' — '.$o->project.' / '.$o->environment;
        @endphp
        <tr class="{{ $stripes[$state] ?? 'is-idle' }}"@unless($finished) data-expires="{{ strtotime((string) $o->expires_at) }}"@endunless>
            <td class="who" data-label="Agent"><b class="nobreak">{{ $o->agent }}</b></td>
            <td class="name action" data-label="Action"><small class="k">requests</small> <code>{{ $o->action }}</code><small class="muted rid" title="Request {{ $o->id }}">{{ substr($o->id, 0, 8) }}</small></td>
            @empty($hideEntry)<td class="name entry" data-label="Entry"><a href="/entries/{{ $o->entry_id }}">{{ $o->entry }}</a><small><span class="k">· </span>{{ $o->project }} / @include('sign', ['production' => $o->production, 'name' => $o->environment])</small></td>@endempty
            {{-- Both waiting states carry the amber lamp; a running request pulses. --}}
            <td class="state" data-label="State">@if(in_array($state, ['pending', 'running', 'requires_user_action'], true))<span class="lamp {{ $state === 'running' ? 'run' : 'warn' }}" aria-hidden="true"></span> @endif @include('outcome', ['outcome' => $state])</td>
            <td class="when" data-label="Requested (UTC)">@if($o->created_at ?? null)<small class="k">Requested</small> <span class="nobreak">{{ $time($o->created_at) }} <small class="k">UTC</small></span>@else<span class="muted">request time unknown</span>@endif @unless($finished)<span class="line expires"><small class="k keep">Expires</small> <span class="nobreak">{{ $time($o->expires_at) }} UTC</span> <span class="left" hidden></span></span>@endunless</td>
            @if($finished)
            <td class="when" data-label="Finished (UTC)">@if($state === 'expired' && ($o->expires_at ?? null))<small class="k keep">Expired</small> <span class="nobreak">{{ $time($o->expires_at) }} <small class="k">UTC</small></span>@elseif(in_array($state, ['approved', 'running'], true) || ! ($o->updated_at ?? null))<span class="muted">not finished yet</span>@else<small class="k">Finished</small> <span class="nobreak">{{ $time($o->updated_at) }} <small class="k">UTC</small></span>@endif <small class="k rid" title="Request {{ $o->id }}">Request {{ substr($o->id, 0, 8) }}</small></td>
            @else
            <td class="decision" data-label="Decision">
                @if($o->state === 'pending')
                    <form class="controls" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/decision">@csrf @isset($back)<input type="hidden" name="back" value="{{ $back }}">@endisset<button class="ok" name="decision" value="approve" aria-label="Allow once: {{ $what }}">Allow once</button><button class="stop" name="decision" value="deny" aria-label="Deny: {{ $what }}">Deny</button></form>
                @else
                    <form class="inline mfa" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/mfa">@csrf @isset($back)<input type="hidden" name="back" value="{{ $back }}">@endisset<label class="stack"><span>Site MFA code (6–8 digits)</span><input name="code" pattern="[0-9]{6,8}" inputmode="numeric" autocomplete="one-time-code" required maxlength="8" aria-label="Site MFA code (6–8 digits): {{ $what }}"></label><button class="ok" aria-label="Continue sign-in: {{ $what }}">Continue sign-in</button></form>
                @endif
                <small class="k rid" title="Request {{ $o->id }}">Request {{ substr($o->id, 0, 8) }}</small>
            </td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
@endforeach
@isset($refreshUrl)
<p class="note"><a href="{{ $refreshUrl }}">Refresh</a></p>
@endisset
