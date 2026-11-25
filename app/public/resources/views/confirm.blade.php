@extends('layout')
@section('title', 'Confirm it is you')
@section('bare', '1')
@section('content')
<form class="action" method="post" action="/confirm">@csrf
    <input type="hidden" name="return" value="{{ $return }}">
    <h1>Confirm it is you</h1>
    @if($failed)
        <div class="callout bad"><p>That password is not right. Try again.</p></div>
    @elseif($blocked)
        <div class="callout warn"><p>Nothing was changed. Confirm your password, then repeat the action.</p></div>
    @endif
    <p class="note">Revealing values and changing entries, access or structure needs your password again. A confirmation lasts 5 minutes.</p>
    <label><span>Current password</span><input type="password" name="current_password" required maxlength="200" autocomplete="current-password" autofocus></label>
    <div class="form-foot"><p class="note">Reveals and access changes are written to the activity log.</p><div class="controls"><a class="btn" href="{{ $return }}">Cancel</a><button class="go">Confirm</button></div></div>
</form>
@endsection
