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
    $day = fn ($value) => substr(str_replace('T', ' ', (string) $value), 0, 16);
    $waitingCount = isset($waitingCount) ? (int) $waitingCount : ($isOwner
        ? $operations->filter(fn ($o) => in_array($o->state, ['pending', 'requires_user_action'], true) && strtotime((string) $o->expires_at) > time())->count()
        : 0);
@endphp
<a class="back" href="/?workspace={{ $entry->workspace_id }}">← Entries</a>
<div class="head">
    <div>
        <div class="title-row">
            <h1 class="name">{{ $entry->name }}</h1>
            @if($canEdit)<a class="btn" href="/entries/new?workspace={{ $entry->workspace_id }}&entry={{ $entry->id }}">Edit entry</a>@endif
        </div>
        <p class="sub">{{ $environment->project }} @include('sign', ['name' => $environment->name, 'production' => $environment->production]) <span class="chip {{ $stateChip }}">{{ $entry->state }}</span> <span>{{ $type?->name ?? $entry->type }} · version {{ $current }}</span>@if($isOwner && $waitingCount > 0) <a class="chip warn" href="#waiting">{{ $waitingCount === 1 ? '1 request waiting for you' : $waitingCount.' requests waiting for you' }}</a>@endif</p>
    </div>
</div>
@if($isEditor)
{{-- Jump links: the sections in page order, each named like its heading. Links, not actions. --}}
<nav class="subnav" aria-label="Entry sections">
    <span class="subnav-label" aria-hidden="true">On this page</span>
    @if($isOwner && $waitingCount > 0)<a class="is-wait" href="#waiting">{{ $waitingCount === 1 ? '1 request waiting for you' : $waitingCount.' requests waiting for you' }}</a>@endif
    <a href="#values">Secret values</a><a href="#versions">Versions</a>
    @if($isOwner)<a href="#access">Agent access</a><a href="#requests">Recent requests</a><a href="#activity">Activity</a>@endif
    @if($showLifecycle)<a href="#archive">{{ $archiveHeading }}</a>@endif
</nav>
@endif

<table class="facts">
    <tr><th scope="row">Agent alias</th><td><code>{{ $entry->alias }}</code> <span class="muted">— the only name agents see</span></td></tr>
    <tr><th scope="row">Tags</th><td>@if($entry->tags){{ $entry->tags }}@else<span class="muted">not set</span>@endif</td></tr>
    <tr><th scope="row">Updated (UTC)</th><td>{{ substr((string) $entry->updated_at, 0, 16) }}</td></tr>
    <tr><th scope="row">Rotation reminder</th><td>@if($entry->rotate_at){{ substr($entry->rotate_at, 0, 10) }}@else<span class="muted">not set</span>@endif @if($entry->rotate_at && strtotime($entry->rotate_at) < time()) <span class="chip warn">rotation due</span>@endif</td></tr>
</table>

@if($isOwner && $waitingCount > 0)
@include('operations', ['mode' => 'waiting', 'workspace' => $entry->workspace_id, 'hideEntry' => true])
@endif

<h2 id="values">Secret values</h2>
@if($environment->production && $canEdit)<div class="tape"><span>Production credentials</span></div>@endif
<div class="card">
    @isset($secret)
        @if($shownVersion && $shownVersion !== $current)
            <div class="callout warn"><p>Showing an <b>older version {{ $shownVersion }}</b> (current is {{ $current }}). Fields marked <span class="chip warn">differs</span> are not the same as now.</p></div>
            <p class="note">Version {{ $shownVersion }}. Values hide again after 30 seconds.</p>
        @else
            <p class="note">Current version {{ $current }}. Values hide again after 30 seconds.</p>
        @endif
        @foreach($secret as $field => $value)
            <div class="secret-row">
                <div class="block-label">@if(isset($labels[$field])){{ $labels[$field] }}@else<code class="name">{{ $field }}</code>@endif
                    @if(!isset($labels[$field]))<span class="chip muted">no longer in type</span>@endif
                    @if(in_array($field, $differs, true))<span class="chip warn">differs</span>@endif
                </div>
                <pre data-secret class="secret" id="secret-{{ $loop->index }}">{{ $value }}</pre>
                <div class="controls"><button type="button" data-copy="secret-{{ $loop->index }}" aria-label="Copy {{ $label($field) }}">Copy</button></div>
            </div>
        @endforeach
        @php $onlyCurrent = array_diff($differs, array_keys($secret)); @endphp
        @if($onlyCurrent)<p class="note">The current version also has: {{ implode(', ', array_map($label, $onlyCurrent)) }}.</p>@endif
    @elseif(!$isEditor)
        <p class="note">Your viewer role sees entry metadata only. Secret values cannot be revealed.</p>
    @elseif(!$canEdit)
        <p class="note">Secret values cannot be revealed while this entry is {{ $entry->state }}.</p>
    @else
        <div class="masked" aria-hidden="true">••••••••••••</div>
        <p class="note">Values are not sent to this page until you reveal them.</p>
    @endisset
    @if($canEdit)
        <div class="form-foot">
            <p class="note">Every reveal goes to the activity log. You may be asked to confirm your password first. Older versions are under Versions.</p>
            <form class="controls" method="post" action="/entries/{{ $entry->id }}/reveal#values">@csrf @if($shownCurrent)<button aria-label="Reveal the current values of {{ $entry->name }} again">Reveal again</button>@else<button class="go" aria-label="Reveal current values of {{ $entry->name }}">Reveal current</button>@endif</form>
        </div>
    @endif
</div>

<h2 id="versions">Versions</h2>
<p class="note">@if($isEditor)Every save creates a version. This shows who changed which fields and when; the values themselves only appear when you reveal a version.@else Every save creates a version. This shows who changed which fields and when. Secret values are hidden from the viewer role.@endif</p>
<div class="scroll"><table class="board compact-board versions">
    <thead><tr><th scope="col">Version</th><th scope="col">Saved (UTC)</th><th scope="col">By</th><th scope="col">Changes</th>@if($canEdit)<th scope="col"><span class="sr-only">Actions</span></th>@endif</tr></thead>
    <tbody>
    @forelse($history as $h)
        <tr class="{{ $h['version'] === $current ? 'is-ok' : 'is-idle' }}">
            <td class="full" data-label="Version"><small class="k">Version</small> <b>{{ $h['version'] }}</b> @if($h['version'] === $current)<span class="chip ok">current</span>@endif @unless($h['available'])<span class="chip muted">purged</span>@endunless</td>
            <td class="when" data-label="Saved (UTC)">@if($h['at'])<small class="k">Saved</small> {{ $day($h['at']) }} <small class="k">UTC</small>@else<span class="muted">save time unknown</span>@endif</td>
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
                        <div><b>Created:</b> {{ implode(', ', array_map($label, $f['added'] ?? [])) ?: '—' }}</div>
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
                    <form method="post" action="/entries/{{ $entry->id }}/reveal#values">@csrf<input type="hidden" name="version" value="{{ $h['version'] }}"><button aria-label="Reveal version {{ $h['version'] }} values">Reveal</button></form>
                    @if($h['version'] !== $current)
                        <form method="post" action="/entries/{{ $entry->id }}/restore-version" data-confirm="Make version {{ $h['version'] }} the current one? This creates a new version.">@csrf<input type="hidden" name="version" value="{{ $h['version'] }}"><button aria-label="Restore version {{ $h['version'] }}">Restore</button></form>
                    @endif
                @endif
            </div></td>
            @endif
        </tr>
    @empty
        <tr class="is-idle"><td colspan="{{ $canEdit ? 5 : 4 }}" data-label="" class="muted">No versions.</td></tr>
    @endforelse
    </tbody>
</table></div>
@if(!empty($historyOlder))<p class="note">Showing the newest {{ count($history) }} versions. Older ones are kept but not listed.</p>@endif

@if($isOwner)
<h2 id="access">Agent access</h2>
@if($grants->isEmpty())
    <div class="empty">No agent may use this entry. <a href="/workspaces/{{ $entry->workspace_id }}/grants?entry={{ $entry->id }}">Manage grants</a></div>
@else
<div class="scroll"><table class="board compact-board access">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Approval</th><th scope="col">Expires (UTC)</th><th scope="col">State</th></tr></thead>
    <tbody>
    @foreach($grants as $grant)
        @php $gState = $grant->revoked_at || $grant->machine_revoked_at ? 'revoked' : (min(strtotime($grant->expires_at), strtotime($grant->machine_expires_at)) <= time() ? 'expired' : 'active'); @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Agent"><b>{{ $grant->machine }}</b></td>
            <td class="end" data-label="Action"><code>{{ $grant->action }}</code></td>
            <td class="full" data-label="Approval">{{ $grant->approval ? 'asks every time' : 'runs without asking' }}</td>
            <td class="when" data-label="Expires (UTC)"><small class="k">{{ strtotime((string) $grant->expires_at) <= time() ? 'Expired' : 'Expires' }}</small> {{ $day($grant->expires_at) }} <small class="k">UTC</small></td>
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
        <div class="form-foot"><p class="note">Makes the entry active again. Grants have to be created again.</p><button>Restore from archive</button></div>
    </form>
    @if($isOwner)
    <form class="action danger" method="post" action="/entries/{{ $entry->id }}/destroy">@csrf
        <h3>Destroy permanently</h3>
        <label><span>Type DELETE</span><input name="confirmation" required pattern="DELETE" autocomplete="off"></label>
        <div class="form-foot"><p class="note">Removes every version from the live store. Backups keep it until they expire.</p><button class="stop">Destroy all versions</button></div>
    </form>
    @endif
    @endif
</div>
@endif
@endsection
