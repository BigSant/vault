@extends('layout')
@section('title', 'Requests')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'requests', 'space' => $space])
@include('operations', ['mode' => 'both', 'operations' => $waiting->concat($history), 'workspace' => $space->id, 'refreshUrl' => '/workspaces/'.$space->id.'/requests'])
@endsection
