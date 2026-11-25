@extends('layout')
@php
    $title = [401 => 'Sign-in failed', 403 => 'Not allowed', 404 => 'Not found', 409 => 'Changed meanwhile', 419 => 'Session expired',
        422 => 'Check the form', 429 => 'Too many attempts'][$status] ?? 'Not available';
    $signIn = in_array($status, [401, 419], true);
@endphp
@section('title', $title)
@section('bare', '1')
@section('content')
<div class="card">
    <h1>{{ $title }} <span class="chip bad">{{ $status }}</span></h1>
    <div class="callout bad">
        <p>{{ match($status) {
            401 => 'Check your sign-in details or the authenticator code.',
            403 => 'Your role in this workspace does not allow it, or the code you entered was wrong.',
            404 => 'This page does not exist, or is not available to you.',
            409 => 'The entry or grant changed meanwhile. Reload the page and try again.',
            419 => 'The session expired. Sign in again.',
            422 => 'Check required fields, value formats and password length.',
            429 => 'Wait a minute, then try again.',
            default => 'The service is not available right now. Try again.',
        } }}</p>
        @if(!empty($messages))<ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    </div>
    @if(in_array($status, [409, 419, 422], true))<p class="note">Go back to correct the form. Secret fields are deliberately not refilled.</p>@endif
    <div class="controls"><a class="btn" href="{{ $signIn ? '/login' : '/' }}">{{ $signIn ? 'Sign in' : 'Back to entries' }}</a></div>
</div>
@endsection
