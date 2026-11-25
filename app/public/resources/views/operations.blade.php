{{-- Agent requests as a board.
     $operations  rows from Workspace::requestRows(); waiting and other rows may be mixed, the partial splits them
     $mode        'waiting' (rows that need a decision or a site MFA code, with the controls),
                  'history' (everything else: finished, running, expired), or
                  'both'    (two h2 sections: waiting first, then history).
                  Without $mode: 'history' when $historyOnly is set, else 'waiting' (the round-3 calls).
     $workspace   workspace id for the decision forms
     $hideEntry   leave out the Entry column (the entry page)
     $heading, $sectionId, $empty   override the h2 text, its id and the empty text in the single modes
     $refreshUrl  the page these requests are on: the address app.js fetches every 30 seconds while the page is
                  visible (also while nothing waits). Each section then sits in a region app.js replaces in place,
                  never the page: <div data-refresh="waiting|history" data-refresh-url="…">, and the waiting heading
                  is a region of its own ("waiting-title"). Beside that heading, outside every region so that it
                  keeps the focus through a refresh, sit "Refresh requests" (a plain link without script) and
                  "Queue checked HH:MM UTC". The waiting heading is always "Requests waiting for you"; the number of waiting
                  rows is a chip beside it (.chip.warn, as in the header nav), which app.js keeps equal to the rows shown.
     $quietEmpty  the waiting section shows nothing while nothing waits (the entry page): its heading row is hidden
                  and its region is empty, so new requests have a place to appear. data-shown-with="waiting" lets
                  app.js show the row again when the region fills.
     $confirmAsk  the sentence of the shared password block when it serves more than the decisions (the entry page:
                  "…then choose a request action or reveal values below.")
     $back        sent with the decision and MFA forms as a hidden `back`, so the controller returns to that page
                  (the entry page sends its own path, /entries/{id}); without it the controller returns to Requests
     State shown: "denied" (the owner said no), "expired" (the deadline passed) or "cancelled" (stored as revoked
     without an owner decision: the agent withdrew it, or its grant or agent was revoked). The controller's
     `decision_label` says which; without it, revoked with approved_by reads "denied".
     An expired row shows its deadline ("Expired …") where a finished one shows "Finished …".
     Waiting rows carry data-expires, data-what ("<action> on <entry>", for the expiry announcement) and the table
     data-now (Unix seconds): app.js shows the time left, switches a
     row off at its deadline and refreshes the regions. Every row carries data-request (its id) and data-state, so a
     refresh can say what changed ("Request expired"). Timestamp labels are <small class="k">; .keep marks the two
     that no column head replaces. A date and its time never break apart (.nobreak).
     Columns: Agent · Action · Entry. Card (below 1180 px): "<agent> requests <action>" ("requested" in the history), then "<entry> · <project> /
     <environment>", then the short request id ("Request 1a2b3c4d") with the state at the right, then "Requested …",
     "Expires …" or "Finished …" each on a line of its own, and the controls last. The id belongs to the request's
     identity: it comes after what is asked for and before the decision controls at every width (in the table it
     sits under the action), and it ends each decision control's accessible name, so two requests that are
     otherwise the same can be told apart.
     Decisions never go to the confirm page while script runs: one shared password block (confirm-inline, id
     "confirm-decide") stands above the waiting board, outside the refreshed region so a typed password survives a
     refresh; every decision form names it with data-confirm-from. A decision pressed without the password is held:
     the block then shows a button that names it (data-held-name: "Allow once: <action>, request <id>") and, beside
     it, the request it belongs to (each decision cell carries <template data-held-summary>: agent, action, entry,
     project / environment, request id), so the held decision keeps its context. --}}
@php
    $mode = $mode ?? (isset($historyOnly) ? 'history' : 'waiting');
    $isWaiting = fn ($o) => in_array($o->state, ['pending', 'requires_user_action'], true) && strtotime((string) $o->expires_at) > time();
    $waitingRows = $operations->filter($isWaiting)->values();
    $historyRows = $operations->reject($isWaiting)->values();
    $n = $waitingRows->count();
    $waitingHeading = 'Requests waiting for you';
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
    $reload = $refreshUrl ?? null;
    $quietEmpty = ! empty($quietEmpty);
@endphp
@foreach($sections as [$finished, $rows, $id, $title, $none])
@if($finished)
@if($reload)<div data-refresh="history" data-refresh-url="{{ $reload }}">@endif
<h2 id="{{ $id }}">{{ $title }}</h2>
@else
<div class="section-head"@if($quietEmpty) data-shown-with="waiting"@if($rows->isEmpty()) hidden @endif @endif>
    <h2 id="{{ $id }}"@if($reload) data-refresh="waiting-title"@endif>{{ $title }}@if($n > 0 && $title === $waitingHeading) <span class="chip warn" data-waiting-count>{{ $n }}<span class="sr-only"> waiting</span></span>@endif</h2>
    @if($reload)<p class="refresh-row"><a class="btn" href="{{ $reload }}" data-refresh-now>Refresh requests</a> <span class="note refresh-status" data-refresh-status data-now="{{ time() }}">Queue checked {{ gmdate('H:i') }} UTC</span></p>@endif
</div>
@include('confirm-inline', ['verb' => 'decide', 'ask' => $confirmAsk ?? 'Enter your current password, then choose a request action below.', 'shared' => 'confirm-decide', 'shownWith' => 'waiting', 'shownHidden' => $rows->isEmpty()])
@if($reload)<div data-refresh="waiting" data-refresh-url="{{ $reload }}">@endif
@endif
@if($rows->isEmpty())
@unless($quietEmpty && ! $finished)<div class="empty">{{ $none }}</div>@endunless
@else
<div class="scroll"><table class="board compact-board requests"@unless($finished) data-now="{{ time() }}"@endunless>
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
            $rid = substr($o->id, 0, 8);
            $what = $o->agent.' runs '.$o->action.' on '.$o->entry.' — '.$o->project.' / '.$o->environment.', request '.$rid;
        @endphp
        <tr class="{{ $stripes[$state] ?? 'is-idle' }}" data-request="{{ $o->id }}" data-state="{{ $state }}"@unless($finished) data-what="{{ $o->action }} on {{ $o->entry }} (request {{ $rid }})" data-expires="{{ strtotime((string) $o->expires_at) }}"@endunless>
            <td class="who" data-label="Agent"><b class="nobreak">{{ $o->agent }}</b></td>
            <td class="name action" data-label="Action"><small class="k">{{ $finished ? 'requested' : 'requests' }}</small> <code>{{ $o->action }}</code><small class="rid" title="Request {{ $o->id }}">Request {{ $rid }}</small></td>
            @empty($hideEntry)<td class="name entry" data-label="Entry"><a href="/entries/{{ $o->entry_id }}">{{ $o->entry }}</a><small><span class="k">· </span>{{ $o->project }} / @include('sign', ['production' => $o->production, 'name' => $o->environment])</small></td>@endempty
            {{-- Both waiting states carry the amber lamp; a running request pulses. --}}
            <td class="state" data-label="State">@if(in_array($state, ['pending', 'running', 'requires_user_action'], true))<span class="lamp {{ $state === 'running' ? 'run' : 'warn' }}" aria-hidden="true"></span> @endif @include('outcome', ['outcome' => $state])</td>
            <td class="when" data-label="Requested (UTC)">@if($o->created_at ?? null)<small class="k">Requested</small> <span class="nobreak">{{ $time($o->created_at) }} <small class="k">UTC</small></span>@else<span class="muted">request time unknown</span>@endif @unless($finished)<span class="line expires"><small class="k keep">Expires</small> <span class="nobreak">{{ $time($o->expires_at) }} UTC</span> <span class="left" hidden></span></span>@endunless</td>
            @if($finished)
            <td class="when" data-label="Finished (UTC)">@if($state === 'expired' && ($o->expires_at ?? null))<small class="k keep">Expired</small> <span class="nobreak">{{ $time($o->expires_at) }} <small class="k">UTC</small></span>@elseif(in_array($state, ['approved', 'running'], true) || ! ($o->updated_at ?? null))<span class="muted">not finished yet</span>@else<small class="k">Finished</small> <span class="nobreak">{{ $time($o->updated_at) }} <small class="k">UTC</small></span>@endif</td>
            @else
            <td class="decision" data-label="Decision">
                {{-- What a held decision is about: app.js shows it beside the held button in the password block. --}}
                <template data-held-summary><b class="name">{{ $o->agent }}</b> requests <code>{{ $o->action }}</code> <span class="sep" aria-hidden="true">·</span> <span class="name">{{ $o->entry }}</span> <span class="sep" aria-hidden="true">·</span> <span class="nobreak">{{ $o->project }} / @include('sign', ['production' => $o->production, 'name' => $o->environment])</span> <span class="sep" aria-hidden="true">·</span> <span class="nobreak">Request {{ $rid }}</span></template>
                @if($o->state === 'pending')
                    <form class="controls" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/decision" data-confirm-from="confirm-decide">@csrf @isset($back)<input type="hidden" name="back" value="{{ $back }}">@endisset<button class="ok" name="decision" value="approve" aria-label="Allow once: {{ $what }}" data-held-name="Allow once: {{ $o->action }}, request {{ $rid }}">Allow once</button><button class="stop" name="decision" value="deny" aria-label="Deny: {{ $what }}" data-held-name="Deny: {{ $o->action }}, request {{ $rid }}">Deny</button></form>
                @else
                    <form class="inline mfa" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/mfa" data-confirm-from="confirm-decide">@csrf @isset($back)<input type="hidden" name="back" value="{{ $back }}">@endisset<label class="stack"><span>Site MFA code (6–8 digits)</span><input name="code" pattern="[0-9]{6,8}" inputmode="numeric" autocomplete="one-time-code" required maxlength="8" aria-label="Site MFA code (6–8 digits): {{ $what }}"></label><button class="ok" aria-label="Continue sign-in: {{ $what }}" data-held-name="Continue sign-in: {{ $o->action }}, request {{ $rid }}">Continue sign-in</button></form>
                @endif
            </td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
@if($reload)</div>@endif
@endforeach
