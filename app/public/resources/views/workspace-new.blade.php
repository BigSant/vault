@extends('layout')
@section('title', 'Add a workspace')
@section('content')
<h1>Add a workspace</h1>
<p class="note">A workspace keeps its own projects, entries, members and agents. Nothing is shared between workspaces.</p>
<form class="action" method="post" action="/workspaces">@csrf
    <label><span>Name</span><input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Client work" autofocus></label>
    <div class="form-foot"><p class="note">You become its owner. Add projects under Setup and people under Members.</p><div class="controls"><a class="btn" href="{{ $navSpaceId ? '/?workspace='.$navSpaceId : '/' }}">Cancel</a><button class="go">Add workspace</button></div></div>
</form>
@endsection
