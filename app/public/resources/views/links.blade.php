@extends('layout')
@section('title', 'Links')
@section('content')
{{-- One-time links of the workspace (owner, editor): the list first, then "Add a link". The content of a link is never
     on this page after it was sent; a form that failed comes back with what was typed in the same response
     ($draft), never through the session. --}}
@php
    $draft = $draft ?? [];
    $stateNames = ['reserved' => 'waiting for approval', 'pending' => 'waiting for approval', 'sending' => 'sending', 'active' => 'not opened', 'previewed' => 'previewed', 'revealed' => 'read',
        'burned' => 'burned', 'expired' => 'expired', 'cancelled' => 'cancelled', 'failed' => 'failed'];
    $stateChips = ['reserved' => 'warn', 'pending' => 'warn', 'sending' => 'warn', 'active' => 'ok', 'previewed' => 'warn', 'revealed' => 'muted', 'burned' => 'muted', 'expired' => 'muted', 'cancelled' => 'muted', 'failed' => 'bad'];
    $stripes = ['reserved' => 'is-wait', 'pending' => 'is-wait', 'sending' => 'is-wait', 'active' => 'is-ok', 'previewed' => 'is-wait', 'failed' => 'is-bad'];
    $isOwner = $space->role === 'owner';
    $fieldKeys = $type ? array_column($type->fields, 'key') : [];
    $text = $draft['text'] ?? ($entry ? \App\Vault\Links::template($type) : '');
    // Braces as entities: Blade would read two braces in a string here as an echo.
    $fieldCodes = implode(', ', array_map(fn ($k) => '<code>&#123;&#123;'.e($k).'&#125;&#125;</code>', $fieldKeys));
@endphp
<div class="head"><h1 id="page-title">Links</h1>@if($settings)<a class="btn" href="#add-link">+ Add a link</a>@endif</div>
<p class="note">One-time links made with Onetime Secret. A link opens once and is then gone. Vault keeps who made it, for which entry, and its state.</p>
@include('notice')

@unless($settings)
    <div class="empty capped"><b>Onetime Secret is not set up.</b>
        @if($canSetUp)<a href="/workspaces/{{ $space->id }}/onetimesecret">Set up the account in Setup</a>.@else An owner sets up the account in Setup.@endif
    </div>
@endunless

@if($links->isEmpty())
    @if($settings)<div class="empty">No links yet. <a href="#add-link">Add a link</a>.</div>@endif
@else
<div class="links-check">
    <form method="post" action="/workspaces/{{ $space->id }}/links/check">@csrf<button aria-label="Check now for link states">Check now</button></form>
    <p class="note">States come from Onetime Secret: "previewed" means the page was loaded without reading the content (a link scanner can do that), "read" that it was opened.</p>
</div>
<div class="scroll"><table class="board compact-board links" aria-labelledby="page-title">
    <thead><tr><th scope="col">Link</th><th scope="col">Made by</th><th scope="col">Made (UTC)</th><th scope="col">End date (UTC)</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($links as $l)
        @php
            $title = $l->note ?? ($l->entry ?? 'Text only');
            $fields = json_decode((string) $l->fields, true) ?: [];
            $open = in_array($l->state, ['reserved', 'pending', 'active', 'previewed'], true);
            $canBurn = $open && ($isOwner || $l->user_id === auth()->id());
            $burnName = in_array($l->state, ['reserved', 'pending'], true) ? 'Cancel link '.$title : 'Burn link '.$title;
        @endphp
        <tr class="{{ $stripes[$l->state] ?? 'is-idle' }}">
            {{-- Line 1: the note, else the entry (a link to it), else "Text only". Line 2: the entry when the note took
                 line 1, the field names the content used, and whether a passphrase guards it. --}}
            <td class="name" data-label="Link">@if($l->entry_id && $l->entry && ! $l->note)<a href="/entries/{{ $l->entry_id }}"><b>{{ $l->entry }}</b></a>@else<b>{{ $title }}</b>@endif
                <small class="link-what">@if($l->entry_id && $l->note)@if($l->entry)<a href="/entries/{{ $l->entry_id }}">{{ $l->entry }}</a>@else<span class="muted">removed entry</span>@endif&nbsp;<span class="sep" aria-hidden="true">·</span> @endif{{ $l->entry_id ? ($fields ? 'fields '.implode(', ', $fields) : 'no fields') : 'text only' }}@if($l->passphrase) <span class="chip muted">passphrase</span>@endif</small></td>
            <td class="who" data-label="Made by">@if($l->machine_id){{ $l->agent ?? 'removed agent' }} <span class="chip muted">agent</span>@else{{ $l->person ?? 'removed member' }}@endif</td>
            <td class="when" data-label="Made (UTC)"><span class="nobreak"><small class="k">Made </small>{{ substr($l->created_at, 0, 16) }} <small class="k">UTC</small></span></td>
            {{-- Open: when the lifetime ends. Ended: when, as far as Vault knows (the lifetime's end for an expired link, else the check that found it ended). --}}
            @php [$endWord, $endAt] = match (true) {
                in_array($l->state, ['reserved', 'pending', 'sending'], true) => [null, null],
                $open => ['Expires', $l->expires_at],
                $l->state === 'expired' && $l->expires_at => ['Expired', min($l->expires_at, $l->updated_at)],
                default => ['Ended', $l->updated_at],
            }; @endphp
            <td class="when" data-label="End date (UTC)">@if($endWord)<span class="nobreak">{{ $endWord }} {{ substr($endAt, 0, 16) }} <small class="k">UTC</small></span>@else<span class="muted">{{ \App\Vault\OneTimeSecret::LIFETIMES[$l->lifetime] ?? $l->lifetime.' s' }} {{ $l->state === 'sending' ? 'once sent' : 'once allowed' }}</span>@endif</td>
            {{-- "read" is the news when an agent's link held an entry's values: its own colour, not the muted end states. --}}
            @php $chip = $l->state === 'revealed' && $l->machine_id && $l->entry_id ? 'warn' : ($stateChips[$l->state] ?? 'muted'); @endphp
            <td class="state" data-label="State"><span class="chip {{ $chip }}">{{ $stateNames[$l->state] ?? $l->state }}</span></td>
            <td data-label="">@if($canBurn)<form class="controls" method="post" action="/workspaces/{{ $space->id }}/links/{{ $l->id }}/burn" data-confirm="{{ in_array($l->state, ['reserved', 'pending'], true) ? 'Cancel this request? The link is never sent.' : 'Burn this link? It can no longer be opened.' }}">@csrf<button class="stop" aria-label="{{ $burnName }}">{{ in_array($l->state, ['reserved', 'pending'], true) ? 'Cancel' : 'Burn' }}</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@if($links->lastPage() > 1)<nav class="pager" aria-label="Link pages"><span>@if($links->previousPageUrl())<a href="{{ $links->previousPageUrl() }}">← Newer</a>@endif</span><span class="muted">Page {{ $links->currentPage() }} of {{ $links->lastPage() }}</span><span>@if($links->hasMorePages())<a href="{{ $links->nextPageUrl() }}">Older →</a>@endif</span></nav>@endif
@endif

@if($settings)
<h2 id="add-link">Add a link</h2>
<form class="action" method="post" action="/workspaces/{{ $space->id }}/links">@csrf
    @if($entry)
        <input type="hidden" name="entry_id" value="{{ $entry->id }}">
        <p class="link-entry"><span class="k">Entry</span> <a href="/entries/{{ $entry->id }}"><b>{{ $entry->name }}</b></a>&nbsp;<span class="sep" aria-hidden="true">·</span> <span class="nobreak">{{ $entry->place->project }}&nbsp;/ @include('sign', ['name' => $entry->place->environment, 'production' => $entry->place->production])</span> <a class="more-link" href="/workspaces/{{ $space->id }}/links#add-link">Text only instead</a></p>
    @endif
    <div class="field"><label for="link-text"><span>Content @unless($entry)<small aria-hidden="true">required</small>@endunless</span></label>
        <textarea id="link-text" name="text" rows="{{ $entry ? max(3, min(10, substr_count($text, "\n") + 2)) : 6 }}" maxlength="{{ \App\Vault\Links::MAX_TEXT }}" spellcheck="false" autocomplete="off" aria-describedby="link-text-hint"@unless($entry) required @endunless>{{ $text }}</textarea>
        <small class="hint" id="link-text-hint">@if($entry)<code>&#123;&#123;field&#125;&#125;</code> puts the entry's current value there: {!! $fieldCodes !!}. Left empty, the link holds every field, one per line.@else Plain text, up to 10,000 characters. To put an entry's values in a link, open the entry and choose Share as a one-time link.@endif</small></div>
    <label><span>Lifetime</span><select name="lifetime">@foreach($lifetimes as $seconds => $name)<option value="{{ $seconds }}" @selected((int) ($draft['lifetime'] ?? $settings->default_lifetime) === $seconds)>{{ $name }}</option>@endforeach</select></label>
    <div class="field"><label for="link-passphrase"><span>Passphrase <small aria-hidden="true">optional</small></span></label><span class="with-button"><input id="link-passphrase" name="passphrase" type="password" maxlength="200" autocomplete="new-password" aria-describedby="link-passphrase-hint"><button type="button" data-generate="link-passphrase" aria-label="Generate passphrase">Generate</button></span><small class="hint" id="link-passphrase-hint">The recipient needs it to open the link. Send it another way than the link.</small></div>
    <div class="field"><label for="link-note"><span>Note <small aria-hidden="true">optional</small></span></label><input id="link-note" name="note" maxlength="120" value="{{ $draft['note'] ?? '' }}" placeholder="e.g. access for the new contractor" aria-describedby="link-note-hint"><small class="hint" id="link-note-hint">Shown in the list above. Never put a secret here.</small></div>
    @include('confirm-inline', ['verb' => 'add this link'])
    <div class="form-foot"><p class="note">The link is shown once. Anyone who has it can open it once.</p><button class="go">Add link</button></div>
</form>
@endif
@endsection
