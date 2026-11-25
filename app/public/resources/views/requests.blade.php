@extends('layout')
@section('title', 'Requests')
@section('content')
<h1>Agents</h1>
@include('tabs', ['area' => 'agents', 'current' => 'requests', 'space' => $space])
@include('operations', ['heading' => 'Waiting for you', 'operations' => $waiting, 'workspace' => $space->id, 'empty' => 'No requests need your attention.'])
@include('operations', ['heading' => 'Recent history', 'sectionId' => 'history', 'historyOnly' => true, 'operations' => $history, 'workspace' => $space->id, 'empty' => 'No recent requests.', 'refreshUrl' => '/workspaces/'.$space->id.'/requests'])
@endsection
