@extends('layout')
@section('title', $setup ? 'Create the first owner' : 'Sign in')
@section('bare', '1')
@section('content')
<form class="action" method="post" action="{{ $setup ? '/setup' : '/login' }}">@csrf
    <h1>{{ $setup ? 'Create the first owner' : 'Sign in' }}</h1>
    <p class="note">{{ $setup ? 'The next step sets up your authenticator code.' : 'Then confirm with a code from your authenticator app.' }}</p>
    @if($setup)
        {{-- A .field, not a <label> around the input: the note under it is the field's description. --}}
        <div class="field"><label for="bootstrap-code"><span>Activation code</span></label><input id="bootstrap-code" type="password" name="bootstrap_code" required autocomplete="off" aria-describedby="bootstrap-code-hint"></div>
        <p class="note" id="bootstrap-code-hint">The code is in the private <code>bootstrap.code</code> file created during install. Type it here only, never into a chat.</p>
        <label><span>Name</span><input name="name" required maxlength="100" autocomplete="name"></label>
    @endif
    <label><span>Email</span><input type="email" name="email" required autocomplete="username"></label>
    @if($setup)
        {{-- A new password can be checked before it is sent (app.js adds Reveal/Hide). --}}
        <div class="field"><label for="setup-password"><span>Password</span></label><span class="with-button"><input id="setup-password" type="password" name="password" required minlength="14" maxlength="200" autocomplete="new-password"></span></div>
    @else
    <label><span>Password</span><input type="password" name="password" required minlength="1" maxlength="200" autocomplete="current-password"></label>
    @endif
    <div class="form-foot"><p class="note">{{ $setup ? 'At least 14 characters.' : '' }}</p><button class="go">{{ $setup ? 'Create account' : 'Continue' }}</button></div>
</form>
@endsection
