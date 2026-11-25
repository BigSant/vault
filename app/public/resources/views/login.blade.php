@extends('layout')
@section('title', $setup ? 'Create the first owner' : 'Sign in')
@section('bare', '1')
@section('content')
<form class="action" method="post" action="{{ $setup ? '/setup' : '/login' }}">@csrf
    <h1>{{ $setup ? 'Create the first owner' : 'Sign in' }}</h1>
    <p class="note">{{ $setup ? 'The next step sets up your authenticator code.' : 'Then confirm with a code from your authenticator app.' }}</p>
    @if($setup)
        <label><span>Activation code</span><input type="password" name="bootstrap_code" required autocomplete="off"></label>
        <p class="note">The code is in the private <code>bootstrap.code</code> file created during install. Type it here only, never into a chat.</p>
        <label><span>Name</span><input name="name" required maxlength="100" autocomplete="name"></label>
    @endif
    <label><span>Email</span><input type="email" name="email" required autocomplete="username"></label>
    <label><span>Password</span><input type="password" name="password" required minlength="{{ $setup ? 14 : 1 }}" maxlength="200" autocomplete="{{ $setup ? 'new-password' : 'current-password' }}"></label>
    <div class="form-foot"><p class="note">{{ $setup ? 'At least 14 characters.' : '' }}</p><button class="go">{{ $setup ? 'Create account' : 'Continue' }}</button></div>
</form>
@endsection
