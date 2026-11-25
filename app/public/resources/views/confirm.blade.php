@extends('layout')
@section('title', 'Password confirmation')
@section('quiet-nav', '1')
@section('content')
<h1>Password confirmation</h1>
{{-- The action that was stopped is named once, by the solid button: "Confirm: Reveal <name>" before the password,
     "Reveal <name>" after it. An action without a known name reads "Confirm and continue" / "Continue". The label
     comes from PendingAction: a verb without a trailing colon, the data in .name (its own case), an optional suffix. --}}
@if($pending ?? null)
    @php $label = is_array($pending['label']) ? $pending['label'] : ['verb' => 'Continue']; $named = $label['verb'] !== 'Continue'; @endphp
    @section('pending-label'){{ $label['verb'] }}@if(isset($label['name'])) <span class="name">{{ $label['name'] }}</span>@endif{{ empty($label['suffix']) ? '' : ' '.$label['suffix'] }}
    @endsection
@endif
@if($ready ?? false)
<form class="action" method="post" action="{{ $pending['path'] }}">@csrf
    @foreach($pending['fields'] as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <div class="form-foot"><p class="note" role="status">Password confirmed. Your action is ready.</p><div class="controls"><a class="btn" href="{{ $return }}">Cancel</a><button class="go">{!! trim($__env->yieldContent('pending-label')) !!}</button></div></div>
</form>
@else
<form class="action" method="post" action="/confirm">@csrf
    <input type="hidden" name="return" value="{{ $return }}">
    @if($pending ?? null)
        <input type="hidden" name="pending" value="{{ $pending['nonce'] }}">
    @endif
    @if($failed)
        <div class="callout bad" id="confirm-status" role="alert"><p>That password is not right. Try again.</p></div>
    @elseif($blocked)
        <div class="callout warn" id="confirm-status" role="status"><p>Nothing was changed. {{ isset($pending) ? 'Confirm your password to continue.' : 'Confirm your password, then return to the form.' }}</p></div>
    @endif
    <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password" autofocus @if($failed || $blocked) aria-describedby="confirm-status" @endif @if($failed) aria-invalid="true" @endif></label>
    <div class="form-foot"><p class="note">The confirmation is valid for 5 minutes.</p><div class="controls"><a class="btn" href="{{ $return }}">Cancel</a><button class="go">{!! ($pending ?? null) ? ($named ? 'Confirm: '.trim($__env->yieldContent('pending-label')) : 'Confirm and continue') : 'Confirm' !!}</button></div></div>
</form>
@endif
@endsection
