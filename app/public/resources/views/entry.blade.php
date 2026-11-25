@extends('layout')
@section('title', $entry->name)
@section('content')
@php
    $isOwner = $space->role === 'owner';
    $isEditor = $space->role !== 'viewer';
    $canEdit = $isEditor && $entry->state === 'active';
    $showLifecycle = $canEdit || ($entry->state === 'archived' && $isEditor);
    $label = fn ($key) => $labels[$key] ?? $key;
    $metaNames = ['name' => 'Name', 'alias' => 'Agent alias', 'tags' => 'Tags', 'rotate_at' => 'Rotation reminder'];
    $stateChip = ['active' => 'ok', 'archived' => 'muted', 'destroyed' => 'bad'][$entry->state] ?? 'muted';
    $current = (int) $entry->version;
    $dash = fn ($v) => $v === null || $v === '' ? '—' : $v;
@endphp
<a class="back" href="/?workspace={{ $entry->workspace_id }}">← Entries</a>
<div class="head">
    <div>
        <h1 class="name">{{ $entry->name }}</h1>
        <p class="sub">{{ $environment->project }} @include('sign', ['name' => $environment->name, 'production' => $environment->production]) <span class="chip {{ $stateChip }}">{{ $entry->state }}</span> <span>{{ $type?->name ?? $entry->type }} · version {{ $current }}</span></p>
    </div>
    @if($canEdit)<a class="btn" href="/entries/new?workspace={{ $entry->workspace_id }}&entry={{ $entry->id }}">Update</a>@endif
</div>
<nav class="subnav"><a class="btn" href="#data">Values</a><a class="btn" href="#versions">Versions</a>@if($showLifecycle)<a class="btn" href="#lifecycle">Lifecycle</a>@endif @if($isOwner)<a class="btn" href="#access">Agent access</a><a class="btn" href="#activity">Activity</a>@endif</nav>

<table class="facts">
    <tr><th>Agent alias</th><td><code>{{ $entry->alias }}</code> <span class="muted">— the only name agents see</span></td></tr>
    <tr><th>Tags</th><td>{{ $entry->tags ?: '—' }}</td></tr>
    <tr><th>Updated</th><td>{{ $entry->updated_at }}</td></tr>
    <tr><th>Rotation reminder</th><td>{{ $entry->rotate_at ? substr($entry->rotate_at, 0, 10) : 'not set' }}@if($entry->rotate_at && strtotime($entry->rotate_at) < time()) <span class="chip warn">due</span>@endif</td></tr>
</table>

<h2 id="data">Secret values</h2>
@if($environment->production)<div class="tape"><span>Production credentials</span></div>@endif
<div class="card">
    @isset($secret)
        @if($shownVersion && $shownVersion !== $current)
            <div class="callout warn"><p>Showing an <b>older version {{ $shownVersion }}</b> (current is {{ $current }}). Fields marked <span class="chip warn">differs</span> are not the same as now.</p></div>
        @else
            <p class="note">Current version {{ $current }}. Values hide again after 30 seconds.</p>
        @endif
        @foreach($secret as $field => $value)
            <div class="secret-row">
                <div class="block-label">{{ $label($field) }}
                    @if(!isset($labels[$field]))<span class="chip muted">no longer in type</span>@endif
                    @if(in_array($field, $differs, true))<span class="chip warn">differs</span>@endif
                </div>
                <pre data-secret class="secret" id="secret-{{ $loop->index }}">{{ $value }}</pre>
                <div class="controls"><button type="button" data-copy="secret-{{ $loop->index }}">Copy</button></div>
            </div>
        @endforeach
        @php $onlyCurrent = array_diff($differs, array_keys($secret)); @endphp
        @if($onlyCurrent)<p class="note">The current version also has: {{ implode(', ', array_map($label, $onlyCurrent)) }}.</p>@endif
    @else
        <div class="masked" aria-hidden="true">••••••••••••</div>
        <p class="note">Values are not sent to this page until you reveal them.</p>
    @endisset
    @if($canEdit)
        <div class="form-foot">
            <p class="note">Every reveal goes to the activity log. You may be asked to confirm your password first. Older versions are under Versions.</p>
            <form class="controls" method="post" action="/entries/{{ $entry->id }}/reveal">@csrf<button class="go">Reveal current</button></form>
        </div>
    @endif
</div>

<h2 id="versions">Versions</h2>
<p class="note">Every save creates a version. This shows who changed which fields and when; the values themselves only appear when you reveal a version.</p>
<div class="scroll"><table class="board versions">
    <thead><tr><th scope="col">Version</th><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">Changes</th>@if($canEdit)<th scope="col"></th>@endif</tr></thead>
    <tbody>
    @forelse($history as $h)
        <tr class="{{ $h['version'] === $current ? 'is-ok' : 'is-idle' }}">
            <td data-label="Version"><b>{{ $h['version'] }}</b> @if($h['version'] === $current)<span class="chip ok">current</span>@endif @unless($h['available'])<span class="chip muted">purged</span>@endunless</td>
            <td class="when" data-label="When (UTC)">{{ $h['at'] ? substr(str_replace('T', ' ', $h['at']), 0, 19) : '—' }}</td>
            <td data-label="Who">{{ $h['user'] ?? '—' }}</td>
            <td data-label="Changes"><div class="changes">
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
            <td class="act" data-label=""><div class="controls">
                @if($h['available'])
                    <form method="post" action="/entries/{{ $entry->id }}/reveal">@csrf<input type="hidden" name="version" value="{{ $h['version'] }}"><button>View</button></form>
                    @if($h['version'] !== $current)
                        <form method="post" action="/entries/{{ $entry->id }}/restore-version" data-confirm="Make version {{ $h['version'] }} the current one? This creates a new version.">@csrf<input type="hidden" name="version" value="{{ $h['version'] }}"><button>Restore</button></form>
                    @endif
                @endif
            </div></td>
            @endif
        </tr>
    @empty
        <tr class="is-idle"><td colspan="5" data-label="" class="muted">No versions.</td></tr>
    @endforelse
    </tbody>
</table></div>
@if(!empty($historyOlder))<p class="note">Showing the newest {{ count($history) }} versions. Older ones are kept but not listed.</p>@endif

@if($showLifecycle)
<h2 id="lifecycle">Lifecycle</h2>
<div class="deck">
    @if($canEdit)
    <form class="action" method="post" action="/entries/{{ $entry->id }}/archive" data-confirm="Archive this entry? Agent permissions for it are revoked.">@csrf
        <h3>Archive</h3>
        <div class="form-foot"><p class="note">Revokes every agent permission for this entry. You can bring it back later.</p><button class="stop">Archive</button></div>
    </form>
    @endif
    @if($entry->state === 'archived' && $isEditor)
    <form class="action" method="post" action="/entries/{{ $entry->id }}/unarchive">@csrf
        <h3>Restore from archive</h3>
        <div class="form-foot"><p class="note">Agent permissions have to be granted again.</p><button>Restore</button></div>
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

@if($isOwner)
<h2 id="access">Agent access</h2>
@if($grants->isEmpty())
    <div class="empty">No agent may use this entry. <a href="/workspaces/{{ $entry->workspace_id }}/grants?entry={{ $entry->id }}">Manage grants</a></div>
@else
<div class="scroll"><table class="board">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Approval</th><th scope="col">Expires</th><th scope="col">State</th></tr></thead>
    <tbody>
    @foreach($grants as $grant)
        @php $gState = $grant->revoked_at || $grant->machine_revoked_at ? 'revoked' : (min(strtotime($grant->expires_at), strtotime($grant->machine_expires_at)) <= time() ? 'expired' : 'active'); @endphp
        <tr class="{{ $gState === 'active' ? 'is-ok' : 'is-idle' }}">
            <td data-label="Agent"><b>{{ $grant->machine }}</b></td>
            <td data-label="Action"><code>{{ $grant->action }}</code></td>
            <td data-label="Approval">{{ $grant->approval ? 'every time' : 'not asked' }}</td>
            <td class="when" data-label="Expires">{{ $grant->expires_at }}</td>
            <td data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<p class="note">Read-only summary. <a href="/workspaces/{{ $entry->workspace_id }}/grants?entry={{ $entry->id }}">Manage grants</a></p>
@endif
@include('operations', ['workspace' => $entry->workspace_id, 'refreshUrl' => '/entries/'.$entry->id])
<h2 id="activity">Activity</h2>
<p class="note">The last 30 events for this entry and its operations.</p>
<div class="scroll"><table class="list">
    <thead><tr><th scope="col">When (UTC)</th><th scope="col">Event</th><th scope="col">Actor</th><th scope="col">Outcome</th></tr></thead>
    <tbody>@forelse($events as $event)<tr><td class="nobreak">{{ $event->created_at }}</td><td><code>{{ $event->event }}</code></td><td>{{ $event->actor }}</td><td>@include('outcome', ['outcome' => $event->outcome])</td></tr>@empty<tr><td colspan="4" class="muted">No events yet.</td></tr>@endforelse</tbody>
</table></div>
@endif
@endsection
