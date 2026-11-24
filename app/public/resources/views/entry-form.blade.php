@extends('layout')
@section('title', $entry ? 'Update entry' : 'Add an entry')
@section('content')
@if($entry)<a class="back" href="/entries/{{ $entry->id }}">← {{ $entry->name }}</a>@else<a class="back" href="/?workspace={{ $workspace }}">← Entries</a>@endif
<h1>{{ $entry ? 'Update entry' : 'Add an entry' }}</h1>
<p class="note">Values go straight to the encrypted store. Existing values are never shown in this form.@if($entry) An empty field keeps its current value, and every save creates a new version.@endif</p>
@if($environments->isEmpty())<div class="callout warn"><p>Create a project first: <a href="/workspaces/{{ $workspace }}/structure">Projects</a>.</p></div>@endif
<form class="action" data-editing="{{ $entry ? '1' : '0' }}" method="post" action="{{ $entry ? '/entries/'.$entry->id : '/entries' }}">@csrf
    <h3>Where and what</h3>
    <label><span>Name</span><input name="name" value="{{ $entry?->name }}" required maxlength="160"></label>
    <label><span>Agent alias <small>visible to agents</small></span><input name="alias" value="{{ $entry?->alias }}" required maxlength="100" placeholder="shop-stage-admin"></label>
    <label><span>Project / environment</span><select name="environment_id">
        @foreach($environments as $e)
            @if(!$entry || $entry->environment_id === $e->id)<option value="{{ $e->id }}" @selected($entry?->environment_id === $e->id)>{{ $e->project }} / {{ $e->name }}{{ $e->production ? ' (production)' : '' }}</option>@endif
        @endforeach
    </select></label>
    <label><span>Type</span><select name="type" id="entry-type">
        @foreach($types as $t)<option value="{{ $t->key }}" @selected($entry?->type === $t->key)>{{ $t->name }}</option>@endforeach
    </select></label>
    @unless($entry)<p class="note">Missing a type or a field? <a href="/workspaces/{{ $workspace }}/types">Edit entry types</a></p>@endunless

    <h3 class="gap">Secret values</h3>
    @foreach($types as $t)
        @foreach($t->fields as $f)
            @php $id = 'f-'.$t->key.'-'.$f['key']; $optional = $f['required'] ? '' : 'data-optional'; @endphp
            <label data-types="{{ $t->key }}" for="{{ $id }}"><span>{{ $f['label'] }}@unless($f['required']) <small>optional</small>@endunless</span>
                @if($f['kind'] === 'multiline')
                    <textarea id="{{ $id }}" name="secret_fields[{{ $f['key'] }}]" rows="6" spellcheck="false" autocomplete="off" {{ $optional }}></textarea>
                @elseif($f['kind'] === 'secret')
                    <span class="with-button"><input id="{{ $id }}" type="password" name="secret_fields[{{ $f['key'] }}]" autocomplete="new-password" {{ $optional }}><button type="button" data-generate="{{ $id }}">Generate</button></span>
                @else
                    <input id="{{ $id }}" name="secret_fields[{{ $f['key'] }}]" autocomplete="off" {{ $optional }}>
                @endif
            </label>
            @if($entry && !$f['required'])<label class="check indent" data-types="{{ $t->key }}"><input type="checkbox" name="clear_fields[]" value="{{ $f['key'] }}" data-optional> clear the current “{{ $f['label'] }}” value</label>@endif
        @endforeach
    @endforeach

    <h3 class="gap">Notes</h3>
    <label><span>Tags</span><input name="tags" value="{{ $entry?->tags }}" maxlength="200" placeholder="billing, admin"></label>
    <label><span>Rotation reminder</span><input type="date" name="rotate_at" value="{{ $entry?->rotate_at ? substr($entry->rotate_at, 0, 10) : '' }}"></label>
    <div class="form-foot">
        <p class="note">{{ $entry ? 'Saving revokes pending agent operations for this entry.' : 'Agents get nothing until you grant them an action.' }}</p>
        <div class="controls"><a class="btn" href="{{ $entry ? '/entries/'.$entry->id : '/?workspace='.$workspace }}">Cancel</a><button class="go">{{ $entry ? 'Save new version' : 'Save entry' }}</button></div>
    </div>
</form>
@endsection
