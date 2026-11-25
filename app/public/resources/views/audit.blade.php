@extends('layout')
@section('title', 'Activity')
@section('content')
<h1>Activity</h1>
<form class="toolbar" method="get">
    <label>Event group <select name="group"><option value="">All groups</option>@foreach(array_keys($groups) as $group)<option value="{{ $group }}" @selected(request('group') === $group)>{{ ucfirst($group) }}</option>@endforeach</select></label>
    <label>Actor <select name="actor"><option value="">All actors</option>@foreach($actorOptions as $key => $label)<option value="{{ $key }}" @selected(request('actor') === $key)>{{ $label }}{{ str_starts_with($key, 'machine:') ? ' · agent' : '' }}</option>@endforeach</select></label>
    <button>Filter</button>
</form>
@if($events->isEmpty())
    <div class="empty">No matching activity.</div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">When (UTC)</th><th scope="col">Actor</th><th scope="col">Event</th><th scope="col">Subject</th><th scope="col">Outcome</th></tr></thead>
    <tbody>@foreach($events as $event)<tr>
        <td data-label="When (UTC)" class="when">{{ substr($event->created_at, 0, 16) }}</td>
        <td data-label="Actor">{{ $event->actor_label }} @if($event->actor_kind === 'agent')<span class="chip muted">agent</span>@endif</td>
        <td data-label="Event"><code class="nobreak">{{ strtr($event->event, ['operation.' => 'request.', 'machines.' => 'agent.', 'machine.' => 'agent.', 'grants.' => 'grant.']) }}</code></td>
        <td data-label="Subject">@if($event->subject_url)<a href="{{ $event->subject_url }}">{{ $event->subject_label }}</a>@else{{ $event->subject_label ?? $event->subject ?? '—' }}@endif</td>
        <td data-label="Outcome">@include('outcome', ['outcome' => $event->outcome])</td>
    </tr>@endforeach</tbody>
</table></div>
@endif
<div class="pager"><span>@if($events->previousPageUrl())<a href="{{ $events->previousPageUrl() }}">← Newer</a>@endif</span><span>Page {{ $events->currentPage() }}</span><span>@if($events->hasMorePages())<a href="{{ $events->nextPageUrl() }}">Older →</a>@endif</span></div>
@endsection
