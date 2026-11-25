@extends('layout')
@section('title', $entry->name)
@section('content')
@php
    $isOwner = $space->role === 'owner';
    $isEditor = $space->role !== 'viewer';
    $canEdit = $isEditor && $entry->state === 'active';
    $showLifecycle = $canEdit || ($entry->state === 'archived' && $isEditor);
    $archiveHeading = $isOwner && $entry->state === 'archived' ? 'Archive and destroy' : 'Archive';
    $label = fn ($key) => $labels[$key] ?? $key;
    $metaNames = ['name' => 'Name', 'alias' => 'Agent alias', 'tags' => 'Tags', 'rotate_at' => 'Rotation reminder'];
    $stateChip = ['active' => 'ok', 'archived' => 'muted', 'destroyed' => 'bad'][$entry->state] ?? 'muted';
    $current = (int) $entry->version;
    $dash = fn ($v) => $v === null || $v === '' ? '—' : $v;
    $shownCurrent = isset($secret) && (! $shownVersion || $shownVersion === $current);
    // An older version is on screen: every text about hiding and revealing again names it.
    $shownOlder = isset($secret) && $shownVersion && $shownVersion !== $current ? (int) $shownVersion : null;
    $hiddenText = $shownOlder ? 'Hidden. Reveal version '.$shownOlder.' again to see it.' : 'Hidden. Reveal again to see it.';
    $hiddenNote = $shownOlder ? 'Version '.$shownOlder.' values are hidden again. Reveal version '.$shownOlder.' again to see them.'
        : 'Current version '.$current.' values are hidden again. Reveal again to see them.';
    $day = fn ($value) => substr(str_replace('T', ' ', (string) $value), 0, 16);
    $waitingCount = isset($waitingCount) ? (int) $waitingCount : ($isOwner
        ? $operations->filter(fn ($o) => in_array($o->state, ['pending', 'requires_user_action'], true) && strtotime((string) $o->expires_at) > time())->count()
        : 0);
    // Versions are listed a page at a time, newest first.
    $vPage = max(1, (int) ($historyPage ?? 1));
    $vMore = isset($historyHasMore) ? (bool) $historyHasMore : ! empty($historyOlder);
    $vUrl = fn (int $p) => '/entries/'.$entry->id.($p > 1 ? '?vpage='.$p : '').'#versions';
@endphp
{{-- Two widths, as everywhere: boards end at --wide; the facts, the cards and the archive forms keep --card. --}}
<div class="entry-page">
<a class="back" href="{{ $backUrl }}">← Entries</a>
<div class="head">
    <div>
        <div class="title-row">
            <h1 class="name">{{ $entry->name }}</h1>
            @if($canEdit)<a class="btn" href="/entries/new?workspace={{ $entry->workspace_id }}&entry={{ $entry->id }}">Edit entry</a>@endif
        </div>
        <p class="sub">{{ $environment->project }} @include('sign', ['name' => $environment->name, 'production' => $environment->production]) <span class="chip {{ $stateChip }}">{{ $entry->state }}</span> <span>{{ $type?->name ?? $entry->type }} · version {{ $current }}</span></p>
    </div>
</div>
@php $hasWaiting = $isOwner && $waitingCount > 0; @endphp
@if($isEditor)
{{-- Jump links right under the title: the sections in page order, each named like its heading. Links, not actions.
     When requests wait for this owner, they are the first link and the first section. --}}
<nav class="subnav" aria-label="On this page">
    {{-- A <details>: app.js keeps it open on wide screens (one line of links); on phones it is a closed drawer. --}}
    <details class="jump" data-wide-open data-wide-static>
        <summary>On this page</summary>
        <div class="jump-links">
            @if($isOwner)<a href="#waiting" data-shown-with="waiting" @if(!$hasWaiting) hidden @endif>Requests waiting for you</a>@endif
            <a href="#details">Details</a><a href="#values">Secret values</a><a href="#versions">Versions</a>
            @if($isOwner)<a href="#access">Agent access</a><a href="#requests">Recent requests</a><a href="#activity">Activity</a>@endif
            @if($showLifecycle)<a href="#archive">{{ $archiveHeading }}</a>@endif
        </div>
    </details>
</nav>
@endif
{{-- Requests that wait for this owner are the first section. Their count is said once, by the section heading.
     While nothing waits the heading row is hidden and the region is empty; a refresh in place fills both. --}}
@if($isOwner)
<div class="waiting-first">
@include('operations', ['mode' => 'waiting', 'quietEmpty' => true, 'workspace' => $entry->workspace_id, 'hideEntry' => true, 'back' => '/entries/'.$entry->id, 'refreshUrl' => '/entries/'.$entry->id])
</div>
@endif

<h2 id="details">Details</h2>
<table class="facts">
    <tr><th scope="row">Agent alias</th><td><code>{{ $entry->alias }}</code> <span class="muted">— the only name agents see</span></td></tr>
    <tr><th scope="row">Tags</th><td>@if($entry->tags){{ $entry->tags }}@else<span class="muted">not set</span>@endif</td></tr>
    <tr><th scope="row">Updated (UTC)</th><td>{{ substr((string) $entry->updated_at, 0, 16) }}</td></tr>
    <tr><th scope="row">Rotation reminder</th><td>@if($entry->rotate_at){{ substr($entry->rotate_at, 0, 10) }}@else<span class="muted">not set</span>@endif @if($entry->rotate_at && strtotime($entry->rotate_at) < time()) <span class="chip warn">rotation due</span>@endif</td></tr>
</table>

<h2 id="values">Secret values</h2>
@if(!isset($secret) && !$canEdit)
    {{-- Nothing can be done here: a plain note, not an action card. --}}
    <p class="note">@if(!$isEditor)Your viewer role sees entry metadata only. Secret values cannot be revealed.@else Secret values cannot be revealed while this entry is {{ $entry->state }}.@endif</p>
@else
{{-- Production shows as the card's own hazard edge, so the warning is exactly as wide as the values. --}}
<div class="card solo{{ $environment->production && $canEdit ? ' danger' : '' }}">
    @if($environment->production && $canEdit)<p class="block-label"><span class="chip warn">Production credentials</span></p>@endif
    @isset($secret)
        @if($shownVersion && $shownVersion !== $current)
            <div class="callout warn"><p>Showing an <b>older version {{ $shownVersion }}</b> (current is {{ $current }}). Fields marked <span class="chip warn">differs</span> are not the same as now.</p></div>
            <p class="note" data-hidden-note="{{ $hiddenNote }}">Version {{ $shownVersion }}. Values hide again after 30 seconds.</p>
        @else
            <p class="note" data-hidden-note="{{ $hiddenNote }}">Current version {{ $current }}. Values hide again after 30 seconds.</p>
        @endif
        @foreach($secret as $field => $value)
            <div class="secret-row">
                <div class="block-label">@if(isset($labels[$field])){{ $labels[$field] }}@else<code class="name">{{ $field }}</code>@endif
                    @if(!isset($labels[$field]))<span class="chip muted">no longer in type</span>@endif
                    @if(in_array($field, $differs, true))<span class="chip warn">differs</span>@endif
                </div>
                <pre data-secret data-hidden-text="{{ $hiddenText }}"@if($shownOlder) data-version="{{ $shownOlder }}"@endif class="secret" id="secret-{{ $loop->index }}">{{ $value }}</pre>
                <div class="controls"><button type="button" data-copy="secret-{{ $loop->index }}" aria-label="Copy {{ $label($field) }}">Copy</button></div>
            </div>
        @endforeach
        @php $onlyCurrent = array_diff($differs, array_keys($secret)); @endphp
        @if($onlyCurrent)<p class="note">The current version also has: {{ implode(', ', array_map($label, $onlyCurrent)) }}.</p>@endif
    @else
        <div class="masked" aria-hidden="true">••••••••••••</div>
        <p class="note">Values are not sent to this page until you reveal them.</p>
    @endisset
    @if($canEdit)
        {{-- One password field for every Reveal on this page (here and per version): an expired confirmation asks
             here, in place, and never sends a reveal to the confirm page. --}}
        @include('confirm-inline', ['verb' => 'reveal', 'shared' => 'confirm-reveal'])
        <div class="form-foot">
            <p class="note">Every reveal goes to the activity log. Older versions are under Versions.</p>
            {{-- Under an older version the way back to the same values comes first; "Reveal current" is the other choice. --}}
            <div class="controls">
                @if($shownOlder)
                <form method="post" action="/entries/{{ $entry->id }}/reveal#values" data-confirm-from="confirm-reveal">@csrf<button aria-label="Reveal current values of {{ $entry->name }}">Reveal current</button></form>
                <form method="post" action="/entries/{{ $entry->id }}/reveal#values" data-confirm-from="confirm-reveal" data-confirm-default>@csrf<input type="hidden" name="version" value="{{ $shownOlder }}">@if($vPage > 1)<input type="hidden" name="vpage" value="{{ $vPage }}">@endif<button class="go" aria-label="Reveal version {{ $shownOlder }} again: values of {{ $entry->name }}">Reveal version {{ $shownOlder }} again</button></form>
                @else
                <form method="post" action="/entries/{{ $entry->id }}/reveal#values" data-confirm-from="confirm-reveal" data-confirm-default>@csrf @if($vPage > 1)<input type="hidden" name="vpage" value="{{ $vPage }}">@endif @if($shownCurrent)<button aria-label="Reveal again: current values of {{ $entry->name }}">Reveal again</button>@else<button class="go" aria-label="Reveal current values of {{ $entry->name }}">Reveal current</button>@endif</form>
                @endif
            </div>
        </div>
    @endif
</div>
@endif

<h2 id="versions">Versions</h2>
<p class="note">@if($isEditor)Every save creates a version. This shows who changed which fields and when; the values themselves only appear when you reveal a version.@else Every save creates a version. This shows who changed which fields and when. Secret values are hidden from the viewer role.@endif</p>
<div class="scroll"><table class="board compact-board versions">
    <thead><tr><th scope="col">Version</th><th scope="col">Saved (UTC)</th><th scope="col">By</th><th scope="col">Changes</th>@if($canEdit)<th scope="col"><span class="sr-only">Actions</span></th>@endif</tr></thead>
    <tbody>
    @forelse($history as $h)
        <tr class="{{ $h['version'] === $current ? 'is-ok' : 'is-idle' }}">
            <td class="full" data-label="Version"><small class="k">Version</small> <b>{{ $h['version'] }}</b> @if($h['version'] === $current)<span class="chip ok">current</span>@endif @unless($h['available'])<span class="chip muted">purged</span>@endunless</td>
            <td class="when" data-label="Saved (UTC)">@if($h['at'])<small class="k">Saved</small> <span class="nobreak">{{ $day($h['at']) }} <small class="k">UTC</small></span>@else<span class="muted">save time unknown</span>@endif</td>
            <td data-label="By">@if($h['user'] ?? null)<small class="k">by</small> {{ $h['user'] }}@else<span class="muted">author unknown</span>@endif</td>
            <td class="changes-cell full" data-label="Changes"><div class="changes">
                @if($h['changes'] === null)
                    <span class="muted">No details (created before the change log existed)</span>
                @else
                    @php
                        $c = $h['changes']; $f = $c['fields'] ?? [];
                        $others = array_filter(['added' => $f['other_added'] ?? 0, 'changed' => $f['other_changed'] ?? 0, 'removed' => $f['other_removed'] ?? 0]);
                    @endphp
                    @if($h['event'] === 'create')
                        <div><b>Added:</b> {{ implode(', ', array_map($label, $f['added'] ?? [])) ?: '—' }}</div>
                    @else
                        @if($h['event'] === 'restore')<div><span class="chip steel">restored</span> from version {{ $c['restored_from'] }}</div>@endif
                        @if(!empty($f['changed']))<div><b>Changed:</b> {{ implode(', ', array_map($label, $f['changed'])) }}</div>@endif
                        @if(!empty($f['added']))<div><b>Added:</b> {{ implode(', ', array_map($label, $f['added'])) }}</div>@endif
                        @if(!empty($f['removed']))<div><b>Removed:</b> {{ implode(', ', array_map($label, $f['removed'])) }}</div>@endif
                        @if(!empty($c['carried']))<div><b>Kept from current:</b> {{ implode(', ', array_map($label, (array) $c['carried'])) }}</div>@endif
                        @foreach($c['meta'] ?? [] as $key => [$before, $after])
                            <div><b>{{ $metaNames[$key] ?? $key }}:</b> “{{ $dash($before) }}” → “{{ $dash($after) }}”</div>
                        @endforeach
                    @endif
                    @foreach($others as $what => $n)<div class="muted">{{ $n }} other field{{ $n === 1 ? '' : 's' }} {{ $what }}</div>@endforeach
                    @if($h['event'] !== 'create' && empty($f['changed']) && empty($f['added']) && empty($f['removed']) && empty($c['meta']) && !$others)<span class="muted">No value changes</span>@endif
                @endif
            </div></td>
            @if($canEdit)
            <td class="act full" data-label="Actions"><div class="controls">
                @if($h['available'])
                    <form method="post" action="/entries/{{ $entry->id }}/reveal#values" data-confirm-from="confirm-reveal">@csrf<input type="hidden" name="version" value="{{ $h['version'] }}">@if($vPage > 1)<input type="hidden" name="vpage" value="{{ $vPage }}">@endif<button aria-label="Reveal version {{ $h['version'] }} values">Reveal</button></form>
                    @if($h['version'] !== $current)
                        <form method="post" action="/entries/{{ $entry->id }}/restore-version" data-confirm="Make version {{ $h['version'] }} the current one? This creates a new version.">@csrf<input type="hidden" name="version" value="{{ $h['version'] }}"><button aria-label="Restore version {{ $h['version'] }}">Restore</button></form>
                    @endif
                @endif
            </div></td>
            @endif
        </tr>
    @empty
        <tr class="is-idle"><td colspan="{{ $canEdit ? 5 : 4 }}" data-label="" class="muted">{{ $vPage > 1 ? 'No older versions.' : 'No versions.' }}</td></tr>
    @endforelse
    </tbody>
</table></div>
{{-- Older versions: the controller passes $historyPage (1 = newest) and $historyHasMore; ?vpage=N opens page N.
     Without $historyPage (a controller that lists only the newest) the old note stays. --}}
@if(isset($historyPage))
    @if($vPage > 1 || $vMore)
    <nav class="pager" aria-label="Version pages"><span>@if($vPage > 1)<a href="{{ $vUrl($vPage - 1) }}">← Newer versions</a>@endif</span><span class="muted">@if($history)Versions {{ $history[array_key_last($history)]['version'] }}–{{ $history[array_key_first($history)]['version'] }} of {{ $current }}@endif</span><span>@if($vMore)<a href="{{ $vUrl($vPage + 1) }}">Older versions →</a>@endif</span></nav>
    @endif
@elseif(!empty($historyOlder))<p class="note">Showing the newest {{ count($history) }} versions. Older ones are kept but not listed.</p>@endif

@if($isOwner)
<h2 id="access">Agent access</h2>
@if($grants->isEmpty())
    <div class="empty">No agent may use this entry. <a href="/workspaces/{{ $entry->workspace_id }}/grants?entry={{ $entry->id }}">Manage grants</a></div>
@else
<div class="scroll"><table class="board compact-board access">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Approval</th><th scope="col">Expires (UTC)</th><th scope="col">State</th></tr></thead>
    <tbody>
    @foreach($grants as $grant)
        {{-- The controller sets state, effective_expires_at (the earlier of the grant's and the agent's end), expiry_reason
             (why it is not the grant's own date) and expires_at (the grant's own date). Shown as on the Grants page. --}}
        @php
            $gState = $grant->state;
            $gEnds = (string) $grant->effective_expires_at;
            // Revoked beats expiry: the date it was revoked (the agent's, when that caused it) replaces both expiry lines.
            $gRevoked = $grant->effective_revoked_at ?? null;
        @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Agent"><b class="nobreak">{{ $grant->machine }}</b></td>
            <td class="end" data-label="Action"><code>{{ $grant->action }}</code></td>
            <td class="full" data-label="Approval">{{ $grant->approval ? 'asks every time' : 'runs without asking' }}</td>
            <td class="when" data-label="Expires (UTC)">@if($gRevoked)Revoked <span class="nobreak">{{ $day($gRevoked) }} UTC</span>@if($grant->expiry_reason) <small class="wrap">{{ $grant->expiry_reason }}</small>@endif @else<small class="k">{{ strtotime($gEnds) <= time() ? 'Expired' : 'Expires' }}</small> <span class="nobreak">{{ $day($gEnds) }} <small class="k">UTC</small></span>@if($grant->expiry_reason) <small class="wrap">{{ $grant->expiry_reason }}</small>@endif @if($gEnds !== (string) $grant->expires_at)<small class="wrap">Grant expiry: <span class="nobreak">{{ $day($grant->expires_at) }} UTC</span></small>@endif @endif</td>
            <td class="state" data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<p class="note">Read-only summary. <a href="/workspaces/{{ $entry->workspace_id }}/grants?entry={{ $entry->id }}">Manage grants</a></p>
@endif
@include('operations', ['mode' => 'history', 'workspace' => $entry->workspace_id, 'refreshUrl' => '/entries/'.$entry->id, 'empty' => 'No finished or running requests for this entry.', 'hideEntry' => true])
<h2 id="activity">Activity</h2>
<p class="note">The last 30 events for this entry and its requests.</p>
@include('activity', ['events' => $events, 'hideSubject' => $entry->id])
@endif

@if($showLifecycle)
<h2 id="archive">{{ $archiveHeading }}</h2>
<div class="deck">
    @if($canEdit)
    <form class="action" method="post" action="/entries/{{ $entry->id }}/archive" data-confirm="Archive this entry? Grants for it are revoked.">@csrf
        <div class="form-foot"><p class="note">Archiving revokes every grant for this entry and hides its values. You can bring it back later.</p><button class="stop">Archive entry</button></div>
    </form>
    @endif
    @if($entry->state === 'archived' && $isEditor)
    <form class="action" method="post" action="/entries/{{ $entry->id }}/unarchive">@csrf
        @if($isOwner)<h3>Restore from archive</h3>@endif
        <div class="form-foot"><p class="note">Makes the entry active again. Grants have to be added again.</p><button>Restore from archive</button></div>
    </form>
    @if($isOwner)
    <form class="action danger" method="post" action="/entries/{{ $entry->id }}/destroy">@csrf
        <h3>Destroy permanently</h3>
        <label><span>Type DELETE to destroy</span><input name="confirmation" required pattern="DELETE" autocomplete="off"></label>
        <div class="form-foot"><p class="note">Removes every version from the live store. Backups keep it until they expire.</p><button class="stop">Destroy all versions</button></div>
    </form>
    @endif
    @endif
</div>
@endif
</div>
@endsection
