{{-- Subjects are names resolved by the controller ($event->subject_label, $event->subject_url); a subject that no
     longer resolves reads "removed item", never a raw id.
     $filtered: the caller applied a filter, so an empty list means "nothing matches", not "nothing happened".
     $requestActions (view composer in AppServiceProvider): request id => action, for the events about a request. --}}
@php
    // The one place that names audit events for people. Keys are the stored codes; a code missing here is shown
    // as words made from the code itself. The code follows the name as dim small text on the same line (on phones it is
    // visually hidden: screen readers still get it).
    $eventNames = [
        'entry.create' => 'Added entry', 'entry.rotate' => 'Rotated entry values', 'entry.update' => 'Updated entry', 'entry.reveal' => 'Revealed values',
        'entry.archive' => 'Archived entry', 'entry.unarchive' => 'Restored entry from archive', 'entry.destroy' => 'Destroyed entry',
        'grant.create' => 'Added grant', 'grants.revoke' => 'Revoked grant',
        'machine.create' => 'Added agent', 'machines.revoke' => 'Revoked agent',
        'operation.request' => 'Agent request', 'operation.approve' => 'Agent request allowed', 'operation.deny' => 'Agent request denied',
        'operation.mfa' => 'Site MFA code entered', 'operation.finish' => 'Agent request finished',
        'member.change' => 'Changed member', 'owner.setup' => 'First owner set up',
        'workspace.create' => 'Added workspace', 'project.create' => 'Added project', 'project.rename' => 'Renamed project', 'project.delete' => 'Removed project',
        'environment.create' => 'Added environment', 'environment.update' => 'Updated environment', 'environment.delete' => 'Removed environment',
        'environment.template' => 'Changed environment template', 'type.save' => 'Saved entry type', 'type.delete' => 'Removed entry type',
        'session.login' => 'Signed in', 'session.logout' => 'Signed out', 'user.password' => 'Changed password', 'user.recover' => 'Recovered account',
    ];
    // Codes are shown in the UI vocabulary: request, agent, grant. A new request reads request.open, never request.request.
    $eventCode = fn ($code) => strtr((string) $code, ['operation.request' => 'request.open', 'operation.' => 'request.', 'machines.' => 'agent.', 'machine.' => 'agent.', 'grants.' => 'grant.']);
    $eventName = fn ($code) => $eventNames[$code] ?? ucfirst(str_replace(['.', '_'], ' ', $eventCode($code)));
    $requestActions = $requestActions ?? [];
@endphp
@if($events->isEmpty())
    <div class="empty">{{ empty($filtered) ? 'No activity yet.' : 'No matching activity.' }}</div>
@else
<div class="scroll"><table class="board compact-board activity">
    <thead><tr><th scope="col">When (UTC)</th><th scope="col">Actor</th><th scope="col">Event</th>@empty($hideSubject)<th scope="col">Subject</th>@endempty<th scope="col">Outcome</th></tr></thead>
    <tbody>@foreach($events as $event)
        @php $stripe = match ($event->outcome) {
            'ok', 'succeeded', 'approved', 'active' => 'is-ok',
            'denied', 'failed', 'rejected', 'error', 'refused', 'invalid' => 'is-bad',
            default => 'is-idle',
        }; @endphp
        <tr class="{{ $stripe }}">
        <td data-label="When (UTC)" class="when"><span class="nobreak">{{ substr($event->created_at, 0, 16) }} <small class="k">UTC</small></span></td>
        <td data-label="Actor" class="actor">{{ $event->actor_label }} @if($event->actor_kind === 'agent')<span class="chip muted">agent</span>@endif</td>
        <td data-label="Event" class="event"><span class="event-name">@if($event->event === 'operation.request' && isset($requestActions[$event->subject]))Agent requested <span class="name">{{ $requestActions[$event->subject] }}</span>@else{{ $eventName($event->event) }}@endif</span> <small class="nobreak event-code">{{ $eventCode($event->event) }}</small></td>
        @empty($hideSubject)<td data-label="Subject" class="subject{{ ($event->subject_label ?? '') === '' && ! ($event->subject ?? null) ? ' is-empty' : '' }}">@if(($event->subject_label ?? null) !== null && $event->subject_label !== '')@if($event->subject_url ?? null)<a href="{{ $event->subject_url }}">{{ $event->subject_label }}</a>@else{{ $event->subject_label }}@endif @elseif($event->subject ?? null)<span class="muted">removed item</span>@else<span class="muted">—</span>@endif</td>@endempty
        <td data-label="Outcome" class="outcome">@include('outcome', ['outcome' => $event->outcome])</td>
    </tr>@endforeach</tbody>
</table></div>
@endif
