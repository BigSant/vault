<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Vault')@if(($space ?? null) && ! $__env->hasSection('quiet-nav') && ($titleSpace = $navSpaces->firstWhere('id', $navSpaceId))) · {{ $titleSpace->name }}@endif · Vault</title><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head><body>
<a class="skip" href="#content">Skip to content</a>
@hasSection('bare')
<main class="login" id="content" tabindex="-1"><a class="mark" href="/">VAULT<small>credential store</small></a>@yield('content')</main>
@else
@php
    $navSpace = $navSpaces->firstWhere('id', $navSpaceId);
    $base = $navSpace ? '/workspaces/'.$navSpace->id : null;
    $path = request()->path();
    // The page's section inside a workspace, e.g. "grants" for /workspaces/{id}/grants.
    $section = preg_match('#^workspaces/[^/]+/([a-z]+)$#D', $path, $m) ? $m[1] : null;
    $sectionRoles = ['requests' => ['owner'], 'agents' => ['owner'], 'grants' => ['owner'], 'members' => ['owner'], 'audit' => ['owner'],
        'structure' => ['owner', 'editor'], 'types' => ['owner', 'editor'], 'import' => ['owner', 'editor']];
    $switchTo = fn ($s) => in_array($s->role, $sectionRoles[$section] ?? [], true) ? '/workspaces/'.$s->id.'/'.$section : '/?workspace='.$s->id;
    // The confirm page and error pages stand in for another page: no nav item is the current one.
    $quiet = $__env->hasSection('quiet-nav');
    // The last value is the item's aria-current: "page" when the item is the page itself; "location" when the page
    // is inside the item's area (an entry, the import, a tab of Agents or Setup: there the tab is the "page").
    $items = [['Entries', $navSpace ? '/?workspace='.$navSpace->id : '/', $path === '/' || str_starts_with($path, 'entries') || $section === 'import', 0, $path === '/' ? 'page' : 'location']];
    if ($navRole === 'owner') {
        $items[] = ['Agents', $base.'/requests', in_array($section, ['requests', 'agents', 'grants', 'machines'], true), $navWaiting, 'location'];
        $items[] = ['Members', $base.'/members', $section === 'members', 0, 'page'];
    }
    if (in_array($navRole, ['owner', 'editor'], true)) {
        $items[] = ['Setup', $base.'/structure', in_array($section, ['structure', 'types'], true), 0, 'location'];
    }
    if ($navRole === 'owner') {
        $items[] = ['Activity', $base.'/audit', $section === 'audit', 0, 'page'];
    }
    // The footer line is about secret values and agents: only the entry list and the Agents area carry it
    // ("machines" is the page that shows a new agent's token).
    $footer = ! $quiet && ($path === '/' || in_array($section, ['requests', 'agents', 'grants', 'machines'], true));
    if ($quiet) {
        $items = array_map(fn ($item) => [$item[0], $item[1], false, $item[3], $item[4]], $items);
    }
@endphp
<header class="top">
    <a class="mark" href="{{ $navSpace ? '/?workspace='.$navSpace->id : '/' }}">VAULT</a>
    @auth
    <details class="menu space">
        <summary aria-label="Workspace: {{ $navSpace->name ?? 'none chosen' }}"><span class="menu-name">{{ $navSpace->name ?? 'Workspace' }}</span></summary>
        <div class="menu-list">
            <p class="menu-head">Workspaces</p>
            @foreach($navSpaces as $s)
                <a href="{{ $switchTo($s) }}"{!! $navSpace && $s->id === $navSpace->id ? ' class="on" aria-current="true"' : '' !!}><span class="menu-name">{{ $s->name }}</span><span class="chip role">{{ $s->role }}</span></a>
            @endforeach
            <a class="menu-foot" href="/workspaces/new">+ Add a workspace</a>
        </div>
    </details>
    @endauth
    {{-- A role with one place to go (a viewer: Entries) gets no nav row; the VAULT mark already leads there.
         A page without a workspace (role unknown) keeps its Entries link.
         data-refresh: app.js keeps the waiting count current on the pages that poll for requests.
         data-waiting-url (owners): on every other page app.js asks that address for the count once a minute. --}}
    @if(count($items) > 1 || ! $navRole)
    <nav class="nav" aria-label="Main" data-refresh="nav"@if($navRole === 'owner') data-waiting-url="{{ $base }}/waiting"@endif>
        @foreach($items as [$text, $href, $active, $count, $current])
            <a class="btn{{ $active ? ' on' : '' }}" href="{{ $href }}"{!! $active ? ' aria-current="'.$current.'"' : '' !!}>{{ $text }}@if($count) <span class="chip warn" title="Requests waiting for you">{{ $count }}<span class="sr-only"> {{ $count === 1 ? 'request' : 'requests' }} waiting</span></span>@endif</a>
        @endforeach
    </nav>
    @endif
    <span class="spacer"></span>
    @auth
    <details class="menu account">
        <summary aria-label="Account: {{ auth()->user()->name }}"><span class="menu-name">{{ auth()->user()->name }}</span></summary>
        <div class="menu-list">
            <a href="/account"{!! $path === 'account' ? ' class="on" aria-current="page"' : '' !!}>Account</a>
            <form action="/logout" method="post">@csrf<button>Sign out</button></form>
        </div>
    </details>
    @endauth
</header>
<main id="content" tabindex="-1">
{{-- A form that failed validation comes back here (bootstrap/app.php flashes form_errors: field name => messages). The summary is rendered right under the
     page head (h1, tabs, jump links), never above the h1; app.js then moves it to the form that was sent, focuses it and links each message to its field. No role="alert": the focus announces it once.
     Messages name fields and rules, never values. --}}
@php
    $formErrors = array_filter((array) session('form_errors'), 'is_array');
    $page = $__env->yieldContent('content');
    $headEnd = $formErrors ? \App\Providers\AppServiceProvider::headEnd($page) : 0;
    // The short non-secret values that were sent (ids such as project_id): app.js uses them to tell apart forms that
    // post to the same address. Old input never holds secret fields (bootstrap/app.php drops them).
    $sent = $formErrors ? array_filter((array) session()->getOldInput(), fn ($value, $key) => is_string($value) && strlen($value) <= 100 && $key !== '_token', ARRAY_FILTER_USE_BOTH) : [];
@endphp
{!! substr($page, 0, $headEnd) !!}
@if($formErrors)
<div class="callout bad error-summary" id="form-errors" tabindex="-1" data-form="{{ session('error_form') }}" data-sent="{{ json_encode((object) $sent) }}"@if(session('error_unfilled')) data-unfilled @endif>
    <p><b>Check the form.</b> Nothing was saved.@if(session('error_unfilled')) Secret fields are not refilled.@endif</p>
    <ul>@foreach($formErrors as $field => $messages)@foreach(array_slice($messages, 0, 2) as $message)<li data-field="{{ $field }}">{{ $message }}</li>@endforeach @endforeach</ul>
</div>
@endif
{!! substr($page, $headEnd) !!}
@auth @if($footer)<p class="foot">Secret values stay in the vault. Agents only get action results.</p>@endif @endauth
</main>
@endif
<p id="live" class="sr-only" aria-live="polite"></p>
</body></html>
