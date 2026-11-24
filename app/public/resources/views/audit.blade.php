@extends('layout')
@section('title', 'Activity')
@section('content')
<h1>Activity</h1>
<p class="note">Who did what and when. Never passwords, codes or the content of external responses.</p>
@if($events->isEmpty())
    <div class="empty">No activity yet.</div>
@else
<div class="scroll"><table class="list">
    <thead><tr><th scope="col">When (UTC)</th><th scope="col">Event · subject</th><th scope="col">Actor</th><th scope="col">Outcome</th></tr></thead>
    <tbody>@foreach($events as $event)<tr><td class="nobreak">{{ $event->created_at }}</td><td><code>{{ $event->event }}</code>@if($event->subject)<small>{{ $event->subject }}</small>@endif</td><td>{{ $event->actor }}</td><td>@include('outcome', ['outcome' => $event->outcome])</td></tr>@endforeach</tbody>
</table></div>
<div class="pager"><span>@if($events->previousPageUrl())<a href="{{ $events->previousPageUrl() }}">← Newer</a>@endif</span><span>@if($events->hasMorePages())<a href="{{ $events->nextPageUrl() }}">Older →</a>@endif</span></div>
@endif
@endsection
