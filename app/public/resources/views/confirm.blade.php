@extends('layout')
@section('title', 'Confirm your identity')
@section('quiet-nav', '1')
@section('content')
<h1>Confirm your identity</h1>
<form class="action" method="post" action="/confirm">@csrf
    <input type="hidden" name="return" value="{{ $return }}">
    @if($failed)
        <div class="callout bad" id="confirm-status" role="alert"><p>That password is not right. Try again.</p></div>
    @elseif($blocked)
        <div class="callout warn" id="confirm-status" role="status"><p>Nothing was changed. Confirm your password, then repeat the action.</p></div>
    @endif
    <p class="note">Revealing values and changing entries, access or setup needs your password again. A confirmation lasts 5 minutes.</p>
    <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password" autofocus @if($failed || $blocked) aria-describedby="confirm-status" @endif @if($failed) aria-invalid="true" @endif></label>
    <div class="form-foot"><p class="note">Reveals and access changes are written to the activity log.</p><div class="controls"><a class="btn" href="{{ $return }}">Cancel</a><button class="go">Confirm</button></div></div>
</form>
@endsection
