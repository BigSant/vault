@extends('layout')
@section('title', 'Activity')
@section('content')
<h1>Activity</h1>
@php $filterCount = count(array_filter(request()->only(['group', 'actor', 'outcome', 'from', 'to']))); @endphp
<details class="filters" data-wide-open>
    <summary>Filters @if($filterCount)<span class="chip muted">{{ $filterCount }}<span class="sr-only"> active</span></span>@endif</summary>
    <form class="toolbar" method="get">
        <label>Event group <select name="group"><option value="">All groups</option>@foreach(array_keys($groups) as $group)<option value="{{ $group }}" @selected(request('group') === $group)>{{ ucfirst($group) }}</option>@endforeach</select></label>
        <label>Actor <select name="actor"><option value="">All actors</option>@foreach($actorOptions as $key => $label)<option value="{{ $key }}" @selected(request('actor') === $key)>{{ $label }}{{ str_starts_with($key, 'machine:') ? ' · agent' : '' }}</option>@endforeach</select></label>
        @php $outcomeLabels = ['ok' => 'OK', 'denied' => 'Denied', 'failed' => 'Failed']; @endphp
        <label>Outcome<select name="outcome"><option value="">All outcomes</option>@foreach($outcomeLabels as $outcome => $outcomeLabel)<option value="{{ $outcome }}" @selected(request('outcome') === $outcome)>{{ $outcomeLabel }}</option>@endforeach</select></label>
        <label>From (UTC)<input type="date" name="from" value="{{ request('from') }}"></label>
        <label>To (UTC)<input type="date" name="to" value="{{ request('to') }}"></label>
        <button>Apply</button>
    </form>
</details>
<p class="filter-status">Showing {{ $events->total() }} of {{ $total ?? $events->total() }} {{ Str::plural('event', $total ?? $events->total()) }}
    @if($total !== null)
    @if(request('group'))<span class="chip filter">Event group: {{ ucfirst(request('group')) }}</span>@endif
    @if(request('actor'))<span class="chip filter">Actor: {{ $actorOptions[request('actor')] ?? request('actor') }}</span>@endif
    @foreach(['outcome' => 'Outcome', 'from' => 'From', 'to' => 'To'] as $key => $label)
        @if(request($key))<span class="chip filter">{{ $label }}: {{ $key === 'outcome' ? ($outcomeLabels[request($key)] ?? request($key)) : request($key) }}</span>@endif
    @endforeach
    <a href="/workspaces/{{ $space->id }}/audit">Clear filters</a>
    @endif
</p>
@include('activity', ['events' => $events, 'filtered' => $filterCount > 0])
@if(!$filterCount || $events->hasPages())
<div class="pager"><span>@if($events->previousPageUrl())<a href="{{ $events->previousPageUrl() }}">← Newer</a>@endif</span><span class="muted">Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</span><span>@if($events->hasMorePages())<a href="{{ $events->nextPageUrl() }}">Older →</a>@endif</span></div>
@endif
@endsection
