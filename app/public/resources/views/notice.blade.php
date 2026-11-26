{{-- What the last action did, said once on the page it led back to (session flash "notice"). Status, not alert: the page was just loaded. --}}
@if(is_string(session('notice')))<div class="callout notice" role="status"><p>{{ session('notice') }}</p></div>@endif
