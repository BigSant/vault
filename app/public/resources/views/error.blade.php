@extends('layout')
@section('title', 'Not completed')
@section('bare', '1')
@section('content')
<div class="card">
    <h1>Not completed <span class="chip bad">{{ $status }}</span></h1>
    <div class="callout bad">
        <p>{{ match($status) {
            401 => 'Check your sign-in details or the authenticator code.',
            403 => 'This needs the right role and a fresh password confirmation.',
            404 => 'Not found, or not available to you.',
            409 => 'The entry or permission changed meanwhile. Reload the page and try again.',
            419 => 'The session expired. Sign in again.',
            422 => 'Check required fields, value formats and password length.',
            429 => 'Too many attempts. Wait a minute.',
            default => 'The service is not available right now. Try again.',
        } }}</p>
        @if(!empty($messages))<ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    </div>
    <p class="note">Secret fields are deliberately not refilled on an error page.</p>
    <div class="controls"><a class="btn" href="/">Back to entries</a></div>
</div>
@endsection
