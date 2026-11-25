@extends('layout')
@section('title', 'New workspace')
@section('content')
<h1>New workspace</h1>
<p class="note">A workspace keeps its own projects, entries, members and agents. Nothing is shared between workspaces.</p>
<form class="action narrow" method="post" action="/workspaces">@csrf
        <label><span>Name</span><input name="name" required maxlength="100" placeholder="Client work" autofocus></label>
        <div class="form-foot"><p class="note">You become its owner. Add projects under Setup and people under Members.</p><div class="controls"><a class="btn" href="{{ $navSpaceId ? '/?workspace='.$navSpaceId : '/' }}">Cancel</a><button class="go">Create workspace</button></div></div>
</form>
@endsection
