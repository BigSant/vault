<h2>{{ $heading ?? 'Operations' }}</h2>
@if($operations->isEmpty())
    <div class="empty">{{ $empty ?? 'No operations yet.' }}</div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Action · operation</th><th scope="col">State</th><th scope="col">Expires</th><th scope="col">Decision</th></tr></thead>
    <tbody>
    @foreach($operations as $o)
        @php $row = ['succeeded' => 'is-ok', 'failed' => 'is-bad', 'expired' => 'is-idle', 'revoked' => 'is-idle'][$o->state] ?? ''; @endphp
        <tr class="{{ $row }}">
            <td class="name" data-label="Action · operation"><code>{{ $o->action }}</code><small>{{ $o->id }}</small></td>
            <td data-label="State">@if(in_array($o->state, ['running', 'requires_user_action'], true))<span class="lamp {{ $o->state === 'running' ? 'run' : 'warn' }}"></span> @endif @include('outcome', ['outcome' => $o->state])</td>
            <td class="when" data-label="Expires">{{ $o->expires_at }}</td>
            <td data-label="Decision">
                @if($o->state === 'pending' && strtotime($o->expires_at) > time())
                    <form class="controls" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/decision">@csrf<button class="ok" name="decision" value="approve">Allow once</button><button class="stop" name="decision" value="deny">Deny</button></form>
                @elseif($o->state === 'requires_user_action' && strtotime($o->expires_at) > time())
                    <form class="inline" method="post" action="/workspaces/{{ $workspace }}/operations/{{ $o->id }}/mfa">@csrf<input name="code" pattern="[0-9]{6,8}" inputmode="numeric" autocomplete="one-time-code" required placeholder="site MFA code" aria-label="Site MFA code"><button class="ok">Continue sign-in</button></form>
                @else
                    <span class="muted">—</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
@isset($refreshUrl)
<p class="note"><a href="{{ $refreshUrl }}">Refresh states</a></p>
@endisset
