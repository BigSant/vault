@extends('layout')
@section('title', 'Password confirmation')
@section('quiet-nav', '1')
@section('content')
<h1>Password confirmation</h1>
@if($pending ?? null)
    @php $label = is_array($pending['label']) ? $pending['label'] : ['verb' => 'Continue']; @endphp
    @section('pending-label'){{ $label['verb'] }}@if(isset($label['name'])) <span class="name">{{ $label['name'] }}</span>@endif{{ empty($label['suffix']) ? '' : ' '.$label['suffix'] }}
    @endsection
    <p>Action to confirm<br><strong>@if($label['verb'] === 'Continue')the action you started
        @else
            @yield('pending-label')
        @endif</strong></p>
@endif
@if($ready ?? false)
<form class="action" method="post" action="{{ $pending['path'] }}">@csrf
    @foreach($pending['fields'] as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <p role="status">Password confirmed. Your action is ready.</p>
    <div class="controls"><a class="btn" href="{{ $return }}">Cancel</a><button class="go">@yield('pending-label')</button></div>
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
    <p class="note">Enter your current password. The confirmation is valid for 5 minutes: it is needed to reveal values and to change entries, access or setup.</p>
    <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password" autofocus @if($failed || $blocked) aria-describedby="confirm-status" @endif @if($failed) aria-invalid="true" @endif></label>
    <div class="form-foot"><p class="note">Reveals and access changes are written to the activity log.</p><div class="controls"><a class="btn" href="{{ $return }}">Cancel</a><button class="go">Confirm</button></div></div>
</form>
@endif
@endsection
