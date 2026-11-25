@php
    $o = (string) $outcome;
    $kind = in_array($o, ['ok', 'succeeded', 'approved', 'active'], true) ? 'ok'
        : (in_array($o, ['denied', 'failed', 'rejected', 'error', 'refused', 'invalid'], true) ? 'bad'
        : (in_array($o, ['pending', 'requires_user_action'], true) ? 'warn' : (in_array($o, ['running'], true) ? 'run' : 'muted')));
@endphp<span class="chip {{ $kind }}">{{ ['pending' => 'waiting for approval', 'requires_user_action' => 'needs MFA code'][$o] ?? str_replace('_', ' ', $o) }}</span>
