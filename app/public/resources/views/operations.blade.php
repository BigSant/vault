<h2>{{ $heading ?? 'Requests' }}</h2>
@if($operations->isEmpty())
    <div class="empty">{{ $empty ?? 'No requests yet.' }}</div>
@else
@php
    $sections = isset($historyOnly) ? [(int) $historyOnly => $operations] : $operations->groupBy(
        fn ($request) => (int) !(in_array($request->state, ['pending', 'requires_user_action'], true) && strtotime($request->expires_at) > time())
    )->sortKeys();
@endphp
@foreach($sections as $finished => $rows)
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Agent</th><th scope="col">Entry</th><th scope="col">Action</th><th scope="col">State</th><th scope="col">Requested (UTC)</th>@if($finished)<th scope="col">Finished (UTC)</th>@else<th scope="col">Decision</th>@endif</tr></thead>
    <tbody>
    @foreach($rows as $o)
        @php $row = ['succeeded' => 'is-ok', 'failed' => 'is-bad', 'expired' => 'is-idle', 'revoked' => 'is-idle'][$o->state] ?? ''; @endphp
        <tr class="{{ $row }}">
            <td data-label="Agent">{{ $o->agent }}</td>
            <td class="name" data-label="Entry"><a href="/entries/{{ $o->entry_id }}">{{ $o->entry }}</a><small>{{ $o->project }} / @include('sign', ['production' => $o->production, 'name' => $o->environment])</small></td>
            <td class="name" data-label="Action"><code>{{ $o->action }}</code><small class="muted">{{ $o->id }}</small></td>
            <td data-label="State">@if(in_array($o->state, ['running', 'requires_user_action'], true))<span class="lamp {{ $o->state === 'running' ? 'run' : 'warn' }}"></span> @endif @include('outcome', ['outcome' => in_array($o->state, ['pending', 'requires_user_action']) && strtotime($o->expires_at) <= time() ? 'expired' : $o->state])</td>
            <td class="when" data-label="Requested (UTC)">{{ substr($o->created_at ?? '', 0, 16) ?: '—' }}</td>
            @if($finished)
            <td class="when" data-label="Finished (UTC)">{{ in_array($o->state, ['approved', 'running'], true) ? '—' : (substr($o->updated_at ?? '', 0, 16) ?: '—') }}</td>
            @else
            <td data-label="Decision">
                @if($o->state === 'pending' && strtotime($o->expires_at) > time())
                    <form class="controls" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/decision">@csrf<button class="ok" name="decision" value="approve">Allow once</button><button class="stop" name="decision" value="deny">Deny</button></form>
                @elseif($o->state === 'requires_user_action' && strtotime($o->expires_at) > time())
                    <form class="inline" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/mfa">@csrf<input name="code" pattern="[0-9]{6,8}" inputmode="numeric" autocomplete="one-time-code" required placeholder="site MFA code" aria-label="Site MFA code"><button class="ok">Continue sign-in</button></form>
                @else
                    <span class="muted">—</span>
                @endif
            </td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table></div>
@endforeach
@endif
@isset($refreshUrl)
<p class="note"><a href="{{ $refreshUrl }}">Refresh states</a></p>
@endisset
