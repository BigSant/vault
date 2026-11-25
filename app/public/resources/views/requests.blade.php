@extends('layout')
@section('title', 'Requests')
@section('content')
<div class="head"><h1>Agents</h1></div>
@include('tabs', ['area' => 'agents', 'current' => 'requests', 'space' => $space])
@include('operations', ['mode' => 'both', 'operations' => $waiting->concat($history), 'workspace' => $space->id, 'refreshUrl' => '/workspaces/'.$space->id.'/requests'])
<p class="note"><a class="more-link" href="/workspaces/{{ $space->id }}/audit?group=request">All request activity →</a></p>
@endsection
