@extends('layout')
@section('title', $entry ? 'Edit entry' : 'Add an entry')
@section('content')
@php
    $oldType = !$entry && is_string(old('type')) && $types->contains('key', old('type')) ? old('type') : null;
    $initialType = $entry?->type ?? $oldType ?? ($types->count() === 1 ? $types->first()->key : null);
@endphp
@if($entry)<a class="back" href="/entries/{{ $entry->id }}">← {{ $entry->name }}</a>@else<a class="back" href="/?workspace={{ $workspace }}">← Entries</a>@endif
<h1>{{ $entry ? 'Edit entry' : 'Add an entry' }}</h1>
<p class="note">Values go straight to the encrypted store.@if($entry) Existing values are never shown in this form. An empty field keeps its current value, and every save creates a new version.@endif</p>
@if(!$entry && ($environments->isEmpty() || $types->isEmpty()))
<div class="empty capped">
    @if($environments->isEmpty())<p>No environments in this workspace yet. <a href="/workspaces/{{ $workspace }}/structure">Add a project in Setup</a> first.</p>@endif
    @if($types->isEmpty())<p>No entry types in this workspace yet. <a href="/workspaces/{{ $workspace }}/types">Add an entry type in Setup</a> first.</p>@endif
</div>
@else
<form class="action" data-editing="{{ $entry ? '1' : '0' }}" method="post" action="{{ $entry ? '/entries/'.$entry->id : '/entries' }}">@csrf
    <fieldset>
        <legend>Details</legend>
        <label><span>Name</span><input name="name" value="{{ old('name', $entry?->name) }}" required maxlength="160"></label>
        <label><span>Agent alias <small>visible to agents</small></span><input name="alias" value="{{ old('alias', $entry?->alias) }}" required maxlength="100" placeholder="shop-stage-admin"></label>
        @if($entry)
            @php
                $currentEnv = $environments->firstWhere('id', $entry->environment_id);
                $currentType = $types->firstWhere('key', $entry->type);
            @endphp
            <input type="hidden" name="environment_id" value="{{ $entry->environment_id }}">
            <input type="hidden" name="type" id="entry-type" value="{{ $entry->type }}">
            <div class="field field-static">
                <span class="field-key">Project / environment</span>
                <span class="field-val">@if($currentEnv){{ $currentEnv->project }} @include('sign', ['name' => $currentEnv->name, 'production' => $currentEnv->production])@else{{ $entry->environment_id }}@endif</span>
            </div>
            <div class="field field-static">
                <span class="field-key">Type</span>
                <span class="field-val">{{ $currentType?->name ?? $entry->type }}</span>
            </div>
        @else
            @php $selectedEnv = old('environment_id'); @endphp
            <label><span>Project / environment</span><select name="environment_id" required>
                <option value="">Choose an environment</option>
                @foreach($environments as $e)
                    <option value="{{ $e->id }}" @selected($selectedEnv === $e->id)>{{ $e->project }} / {{ $e->name }}{{ $e->production ? ' (production)' : '' }}</option>
                @endforeach
            </select></label>
            <label><span>Type</span><select name="type" id="entry-type" required>
                <option value="">Choose a type</option>
                @foreach($types as $t)<option value="{{ $t->key }}" @selected($initialType === $t->key)>{{ $t->name }}</option>@endforeach
            </select></label>
            <p class="note">Missing a type or a field? <a href="/workspaces/{{ $workspace }}/types">Edit entry types</a></p>
        @endif
    </fieldset>

    <fieldset>
        <legend>Secret values</legend>
        @unless($entry)<p class="note" data-types="" @if($initialType !== null) hidden @endif>Choose a type above to see its fields.</p>@endunless
        @foreach($types as $t)
            @php $activeType = $t->key === $initialType; @endphp
            @foreach($t->fields as $f)
                @php
                    $id = 'f-'.$t->key.'-'.$f['key'];
                    $optional = $f['required'] ? '' : 'data-optional';
                    $req = $activeType && !$entry && $f['required'];
                @endphp
                @if($f['kind'] === 'secret')
                    <div class="field" data-types="{{ $t->key }}" @unless($activeType) hidden @endunless>
                        <label for="{{ $id }}"><span>{{ $f['label'] }}@unless($f['required']) <small>optional</small>@endunless</span></label>
                        <span class="with-button"><input id="{{ $id }}" type="password" name="secret_fields[{{ $f['key'] }}]" autocomplete="new-password" {{ $optional }} @if($req) required @endif @if($entry) placeholder="Leave blank to keep" @endif @disabled(!$activeType)>@if(!empty($f['generate']))<button type="button" data-generate="{{ $id }}" aria-label="Generate {{ $f['label'] }}">Generate</button>@endif</span>
                    </div>
                @else
                    <label data-types="{{ $t->key }}" for="{{ $id }}" @unless($activeType) hidden @endunless><span>{{ $f['label'] }}@unless($f['required']) <small>optional</small>@endunless</span>
                        @if($f['kind'] === 'multiline')
                            <textarea id="{{ $id }}" name="secret_fields[{{ $f['key'] }}]" rows="6" spellcheck="false" autocomplete="off" {{ $optional }} @if($req) required @endif @if($entry) placeholder="Leave blank to keep" @endif @disabled(!$activeType)></textarea>
                        @else
                            <input id="{{ $id }}" name="secret_fields[{{ $f['key'] }}]" autocomplete="off" {{ $optional }} @if($req) required @endif @if($entry) placeholder="Leave blank to keep" @endif @disabled(!$activeType)>
                        @endif
                    </label>
                @endif
                @if($entry && !$f['required'])<label class="check indent" data-types="{{ $t->key }}" @unless($activeType) hidden @endunless><input type="checkbox" name="clear_fields[]" value="{{ $f['key'] }}" data-optional @disabled(!$activeType)> clear the current “{{ $f['label'] }}” value</label>@endif
            @endforeach
        @endforeach
    </fieldset>

    <fieldset>
        <legend>Tags and rotation</legend>
        <label><span>Tags</span><input name="tags" value="{{ old('tags', $entry?->tags) }}" maxlength="200" placeholder="billing, admin"></label>
        <label><span>Rotation reminder</span><input type="date" name="rotate_at" value="{{ old('rotate_at', $entry?->rotate_at ? substr($entry->rotate_at, 0, 10) : '') }}"></label>
    </fieldset>
    @include('confirm-inline', ['verb' => 'save'])
    <div class="form-foot">
        <p class="note">{{ $entry ? 'Saving cancels agent requests that are waiting for this entry.' : 'Agents need a grant to use this entry.' }}</p>
        <div class="controls"><a class="btn" href="{{ $entry ? '/entries/'.$entry->id : '/?workspace='.$workspace }}">Cancel</a><button class="go">{{ $entry ? 'Save new version' : 'Add entry' }}</button></div>
    </div>
</form>
@endif
@endsection
