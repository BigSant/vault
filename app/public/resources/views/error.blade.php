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
    // 409 and 422 for a signed-in person: the controller may pass $returnPath, a safe same-origin GET path to the
    // page the action came from. The button then leads there; without it the button and the instruction say "entries".
    $returnPath = $shell && in_array($status, [409, 422], true) && isset($returnPath) && is_string($returnPath) && $returnPath !== '' ? $returnPath : null;
@endphp
@section('title', $title)
@section('quiet-nav', '1')
@unless($shell)
@section('bare', '1')
@endunless
@section('content')
<div class="{{ $shell ? 'error-page' : 'card' }}">
    <h1>{{ $title }}</h1>
    {{-- The status code is plain dim text: the callout below is the page's one red mark. --}}
    <p class="error-status">Error {{ $status }}</p>
    <div class="callout bad">
        <p>{{ match($status) {
            401 => 'Check your sign-in details or the authenticator code.',
            403 => 'Your role in this workspace does not allow it, or the code you entered was wrong.',
            404 => 'This page does not exist, or is not available to you.',
            409 => 'It changed meanwhile, or it is still in use. Reload and try again.',
            419 => 'The session expired. Nothing was saved.',
            422 => 'Check required fields, value formats and password length.',
            429 => 'Wait a minute, then try again.',
            default => 'The service is not available right now. Try again.',
        } }}</p>
        @if(!empty($messages))<ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    </div>
    {{-- One recovery instruction, in the order it is done; its first step is the button below. --}}
    @if($status === 419)<p class="note">Sign in again, then open the form and fill it in again. Secret fields are not refilled.</p>
    @elseif($status === 422)<p class="note">{{ $returnPath ? 'Go back to the previous page to correct the form.' : ($signIn ? 'Sign in, then open the form again to correct it.' : 'Go back to entries, then open the form again to correct it.') }} Secret fields are not refilled.</p>
    @elseif($status === 409)<p class="note">{{ $returnPath ? 'Go back to the previous page to see the current state.' : ($signIn ? 'Sign in to see the current state.' : 'Go back to entries to see the current state.') }} Nothing was changed.</p>@endif
    <div class="controls"><a class="btn" href="{{ $signIn ? '/login' : ($returnPath ?? $home) }}">{{ $signIn ? 'Sign in' : ($returnPath ? 'Back to previous page' : 'Back to entries') }}</a></div>
</div>
@endsection
