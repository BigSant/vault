@extends('layout')
@section('title', 'Activity')
@section('content')
<h1>Activity</h1>
@php $filterCount = (int) request()->filled('group') + (int) request()->filled('actor'); @endphp
<details class="filters" data-wide-open>
    <summary>Filters @if($filterCount)<span class="chip muted">{{ $filterCount }}<span class="sr-only"> active</span></span>@endif</summary>
    <form class="toolbar" method="get">
        <label>Event group <select name="group"><option value="">All groups</option>@foreach(array_keys($groups) as $group)<option value="{{ $group }}" @selected(request('group') === $group)>{{ ucfirst($group) }}</option>@endforeach</select></label>
        <label>Actor <select name="actor"><option value="">All actors</option>@foreach($actorOptions as $key => $label)<option value="{{ $key }}" @selected(request('actor') === $key)>{{ $label }}{{ str_starts_with($key, 'machine:') ? ' · agent' : '' }}</option>@endforeach</select></label>
        <button>Apply</button>
    </form>
</details>
@if($total !== null)
<p class="filter-status">Showing {{ $events->total() }} of {{ $total }}
    @if(request('group'))<span class="chip filter">Event group: {{ ucfirst(request('group')) }}</span>@endif
    @if(request('actor'))<span class="chip filter">Actor: {{ $actorOptions[request('actor')] ?? request('actor') }}</span>@endif
    <a href="/workspaces/{{ $space->id }}/audit">Clear filters</a>
</p>
@endif
@include('activity', ['events' => $events])
@if($events->hasPages())
<div class="pager"><span>@if($events->previousPageUrl())<a href="{{ $events->previousPageUrl() }}">← Newer</a>@endif</span><span class="muted">Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</span><span>@if($events->hasMorePages())<a href="{{ $events->nextPageUrl() }}">Older →</a>@endif</span></div>
@endif
@endsection
