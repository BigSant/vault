@extends('layout')
@php
    $title = [401 => 'Sign-in failed', 403 => 'Not allowed', 404 => 'Not found', 409 => 'Not possible now', 419 => 'Session expired',
        422 => 'Check the form', 429 => 'Too many attempts'][$status] ?? 'Not available';
    // $shell: a signed-in person sees the error under the normal header. Guests and a failing service get the bare page.
    $shell = ! empty($shell);
    // The bare page has no header to go anywhere from, and "entries" means nothing to a guest: it always offers Sign in.
    $signIn = ! $shell || in_array($status, [401, 419], true);
    // Back to the workspace last opened in this session; the entries page checks membership again.
    $last = $shell ? session('last_workspace') : null;
    $home = is_string($last) && $last !== '' ? '/?workspace='.rawurlencode($last) : '/';
@endphp
@section('title', $title)
@section('quiet-nav', '1')
@unless($shell)
@section('bare', '1')
@endunless
@section('content')
<div class="{{ $shell ? 'error-page' : 'card' }}">
    <h1>{{ $title }} <span class="chip bad">{{ $status }}</span></h1>
    <div class="callout bad">
        <p>{{ match($status) {
            401 => 'Check your sign-in details or the authenticator code.',
            403 => 'Your role in this workspace does not allow it, or the code you entered was wrong.',
            404 => 'This page does not exist, or is not available to you.',
            409 => 'It changed meanwhile, or it is still in use. Reload and try again.',
            419 => 'The session expired. Sign in again.',
            422 => 'Check required fields, value formats and password length.',
            429 => 'Wait a minute, then try again.',
            default => 'The service is not available right now. Try again.',
        } }}</p>
        @if(!empty($messages))<ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    </div>
    @if(in_array($status, [419, 422], true))<p class="note">Go back to correct the form. Secret fields are not refilled.</p>
    @elseif($status === 409)<p class="note">Go back and reload the page to see the current state. Nothing was changed.</p>@endif
    <div class="controls"><a class="btn" href="{{ $signIn ? '/login' : $home }}">{{ $signIn ? 'Sign in' : 'Back to entries' }}</a></div>
</div>
@endsection
