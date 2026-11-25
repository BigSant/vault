@extends('layout')
@section('title', 'Activity')
@section('content')
<h1>Activity</h1>
<form class="toolbar" method="get">
    <label>Event group <select name="group"><option value="">All groups</option>@foreach(array_keys($groups) as $group)<option value="{{ $group }}" @selected(request('group') === $group)>{{ ucfirst($group) }}</option>@endforeach</select></label>
    <label>Actor <select name="actor"><option value="">All actors</option>@foreach($actorOptions as $key => $label)<option value="{{ $key }}" @selected(request('actor') === $key)>{{ $label }}{{ str_starts_with($key, 'machine:') ? ' · agent' : '' }}</option>@endforeach</select></label>
    <button>Filter</button>
    @if(request()->filled('group') || request()->filled('actor'))<a href="/workspaces/{{ $space->id }}/audit">Clear filters</a>@endif
</form>
@include('activity', ['events' => $events])
@if($events->hasPages())
<div class="pager"><span>@if($events->previousPageUrl())<a href="{{ $events->previousPageUrl() }}">← Newer</a>@endif</span><span>Page {{ $events->currentPage() }}</span><span>@if($events->hasMorePages())<a href="{{ $events->nextPageUrl() }}">Older →</a>@endif</span></div>
@endif
@endsection
