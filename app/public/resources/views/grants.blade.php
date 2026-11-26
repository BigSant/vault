@extends('layout')
@section('title', 'Grants')
@section('content')
<div class="head"><h1>Agents</h1><a class="btn" href="#add-grant">+ Add a grant</a></div>
@include('tabs', ['area' => 'agents', 'current' => 'grants', 'space' => $space])
@include('notice')

@php $filterCount = (int) (bool) $agent + (int) (bool) $entry; @endphp
{{-- Revoke never goes to the confirm page while script runs: one shared password block, as on Members. It stands
     above the filter bar, so the bar, its status line and the board stay together. "Add a grant" names it too and
     only points to it when the confirmation has expired. --}}
@unless($grants->isEmpty())
@include('confirm-inline', ['verb' => 'change grants', 'ask' => 'Enter your current password, then revoke or add a grant below.', 'shared' => 'confirm-grants'])
@endunless
<details class="filters" data-wide-open>
    <summary>Filters @if($filterCount)<span class="chip muted">{{ $filterCount }}<span class="sr-only"> active</span></span>@endif</summary>
    <form class="toolbar" method="get">
        <label>Agent <select name="agent"><option value="">All agents</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected($m->id === $agent?->id)>{{ $m->name }}</option>@endforeach</select></label>
        <label>Entry <select name="entry"><option value="">All entries</option>@foreach($entries as $e)<option value="{{ $e->id }}" @selected($e->id === $entry?->id)>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }}{{ $e->production ? ' (production)' : '' }}</option>@endforeach</select></label>
        <button>Apply</button>
    </form>
</details>
@if($agent || $entry)
<p class="filter-status"><span class="filter-count">Showing {{ $grants->count() }} of {{ $total }} {{ Str::plural('grant', $total) }} <a href="/workspaces/{{ $space->id }}/grants">Clear filters</a></span>
    @if($agent)<span class="chip filter">Agent: {{ $agent->name }}</span>@endif
    @if($entry)<span class="chip filter">Entry: {{ $entry->name }} — {{ $entry->project }} / {{ $entry->environment }}</span>@endif
</p>
@endif
@php
    $activeMachines = $machines->whereNull('revoked_at')->filter(fn ($m) => strtotime($m->expires_at) > time());
    $activeEntries = $entries->where('state', 'active');
@endphp
@if($grants->isEmpty())
    <div class="empty">{{ $filterCount ? 'No matching grants.' : 'No grants yet.' }}
        @unless($filterCount)
            @if($activeMachines->isEmpty())<a href="/workspaces/{{ $space->id }}/agents">Add an agent in Identities</a>.
            @elseif($activeEntries->isNotEmpty() && $actions)<a href="#add-grant">Add a grant</a>.
            @endif
        @endunless
    </div>
@else
<div class="scroll"><table class="board compact-board grants" aria-labelledby="tab-current">
    <thead><tr><th scope="col">Agent</th><th scope="col">Action</th><th scope="col">Entry</th><th scope="col">Approval</th><th scope="col">End date (UTC)</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @foreach($grants as $g)
        @php
            $machine = $machines[$g->machine_id];
            $target = $entries[$g->entry_id];
            $revoke = "Revoke grant {$g->action} for {$machine->name} on {$target->name} — {$target->project} / {$target->environment}";
            $gState = $g->state;
        @endphp
        <tr class="{{ $gState === 'active' ? (\App\Vault\Access::endsSoon($g->effective_revoked_at, $g->effective_expires_at) ? 'is-wait' : 'is-ok') : 'is-idle' }}">
            <td class="who" data-label="Agent"><b>{{ $machine->name }}</b></td>
            <td class="action" data-label="Action"><code>{!! str_replace('.', '.<wbr>', e($g->action)) !!}</code></td>
            <td class="name entry full" data-label="Entry"><a href="/entries/{{ $g->entry_id }}">{{ $target->name }}</a><span class="k" aria-hidden="true">&nbsp;·</span> <small>{{ $target->project }}&nbsp;/ @include('sign', ['name' => $target->environment, 'production' => $target->production])</small></td>
            {{-- onetimesecret.link asks every time whatever the row says (an older grant may say otherwise). --}}
            @php $asks = $g->approval || $g->action === \App\Vault\Links::ACTION; @endphp
            <td class="approval" data-label="Approval">{{ $gState === 'active' ? ($asks ? 'asks every time' : 'runs without asking') : ($asks ? 'previously asked every time' : 'previously ran without asking') }}</td>
            <td class="when" data-label="End date (UTC)">@include('end-date', ['revoked' => $g->effective_revoked_at, 'ends' => $g->effective_expires_at, 'reason' => $g->expiry_reason, 'own' => $g->expires_at])</td>
            <td class="state" data-label="State"><span class="chip {{ $gState === 'active' ? 'ok' : 'muted' }}">{{ $gState }}</span></td>
            <td data-label="">@if($gState === 'active')<form class="controls" method="post" action="/workspaces/{{ $space->id }}/revoke/grants/{{ $g->id }}" data-confirm="{{ $revoke }}?" data-confirm-from="confirm-grants">@csrf<button class="stop" aria-label="{{ $revoke }}" data-held-name="Revoke grant: {{ $g->action }} for {{ $machine->name }} on {{ $target->name }}">Revoke</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif

<h2 id="add-grant">Add a grant</h2>

@if($activeMachines->isEmpty() || $activeEntries->isEmpty() || !$actions)
    <div class="empty capped">
        @if($activeMachines->isEmpty())<p>No active agents. <a href="/workspaces/{{ $space->id }}/agents">Add an agent in Identities</a>.</p>@endif
        @if($activeEntries->isEmpty())<p>No active entries. <a href="/entries/new?workspace={{ $space->id }}">Add an entry</a>.</p>@endif
        @unless($actions)<p>No actions are configured. Ask the operator to register the allowed target systems.</p>@endunless
    </div>
@else
    <form class="action" method="post" action="/workspaces/{{ $space->id }}/grants"@unless($grants->isEmpty()) data-confirm-from="confirm-grants"@endunless>@csrf
        <label><span>Agent</span><select name="machine_id" required><option value="">Choose an agent</option>@foreach($activeMachines as $m)<option value="{{ $m->id }}"{{ $m->id === old('machine_id', $agent?->id) ? ' selected' : '' }}>{{ $m->name }}</option>@endforeach</select></label>
        <label><span>Entry</span><select name="entry_id" required><option value="">Choose an entry</option>@foreach($activeEntries as $e)<option data-fields="{{ implode(',', $typeFields[$e->type] ?? []) }}" data-entry="{{ $e->name }}" data-project="{{ $e->project }}" data-env="{{ $e->environment }}" data-sign="{{ $e->production ? 'prod' : (str_starts_with(mb_strtolower($e->environment), 'stag') ? 'stage' : '') }}" value="{{ $e->id }}"{{ $e->id === old('entry_id', $entry?->id) ? ' selected' : '' }}>{{ $e->name }} — {{ $e->project }} / {{ $e->environment }}{{ $e->production ? ' (production)' : '' }} · {{ $typeNames[$e->type] ?? $e->type }}</option>@endforeach</select></label>
        <label><span>Action</span><select name="action" required><option value="">Choose an action</option>@foreach($actions as $id => $a)@php $required = \App\Vault\EntryTypes::actionFields($a['kind']); @endphp<option value="{{ $id }}" data-fields="{{ implode(',', $required) }}" @selected(old('action') === $id)>{{ $id }}</option>@endforeach</select></label>
        <label><span>Valid for (days)</span><input type="number" name="days" min="1" max="30" value="{{ old('days', 1) }}" required></label>
        <label class="check"><input type="checkbox" name="approval" value="1" @checked(old('approval', !session()->hasOldInput()))> Require owner approval for every run</label>
        @if($grants->isEmpty())
            @include('confirm-inline', ['verb' => 'grant this action'])
        @else
            @include('confirm-inline', ['verb' => 'grant this action', 'shared' => 'confirm-add-grant', 'primary' => 'confirm-grants', 'primaryShown' => true, 'fallback' => true])
        @endif
        {{-- What a held "Add grant" is about: app.js fills the marked parts from the form and shows the line beside
             the held button in the password block, as its description (like a held request decision). --}}
        <template data-held-summary><b class="name" data-held-value="machine_id"></b> may run <code data-held-value="action"></code>&nbsp;<span class="sep" aria-hidden="true">·</span> <span class="name" data-held-value="entry_id" data-held-attr="entry"></span>&nbsp;<span class="sep" aria-hidden="true">·</span> <span class="nobreak"><span class="name" data-held-value="entry_id" data-held-attr="project"></span> / <span data-held-sign="entry_id"></span></span>&nbsp;<span class="sep" aria-hidden="true">·</span> <span class="nobreak">valid for <span data-held-value="days" data-held-unit="day"></span></span>&nbsp;<span class="sep" aria-hidden="true">·</span> <span class="nobreak" data-held-check="approval" data-on="asks every time" data-off="runs without asking"></span></template>
        <div class="form-foot"><p class="note" data-action-note>The entry's type must have the fields the action reads.</p><button class="go" data-held-name="Add grant: " data-held-field="action">Add grant</button></div>
    </form>
@endif
@endsection
