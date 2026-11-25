@if($events->isEmpty())
    <div class="empty">No matching activity.</div>
@else
<div class="scroll"><table class="board compact-board activity">
    <thead><tr><th scope="col">When (UTC)</th><th scope="col">Actor</th><th scope="col">Event</th><th scope="col">Subject</th><th scope="col">Outcome</th></tr></thead>
    <tbody>@foreach($events as $event)
        @php $stripe = match ($event->outcome) {
            'ok', 'succeeded', 'approved', 'active' => 'is-ok',
            'denied', 'failed', 'rejected', 'error', 'refused', 'invalid' => 'is-bad',
            default => 'is-idle',
        }; @endphp
        <tr class="{{ $stripe }}">
        <td data-label="When (UTC)" class="when">{{ substr($event->created_at, 0, 16) }}</td>
        <td data-label="Actor">{{ $event->actor_label }} @if($event->actor_kind === 'agent')<span class="chip muted">agent</span>@endif</td>
        <td data-label="Event"><code class="nobreak">{{ strtr($event->event, ['operation.' => 'request.', 'machines.' => 'agent.', 'machine.' => 'agent.', 'grants.' => 'grant.']) }}</code></td>
        <td data-label="Subject">@if($event->subject_url && !(isset($hideSubject) && ($hideSubject === $event->subject || $event->subject_url === '/entries/'.$hideSubject)))<a href="{{ $event->subject_url }}">{{ $event->subject_label }}</a>@else{{ $event->subject_label ?? $event->subject ?? '—' }}@endif</td>
        <td data-label="Outcome">@include('outcome', ['outcome' => $event->outcome])</td>
    </tr>@endforeach</tbody>
</table></div>
@endif
