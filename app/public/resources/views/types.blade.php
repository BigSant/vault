@extends('layout')
@section('title', 'Entry types')
@section('content')
@php
    $kindSelect = function (string $current = 'text') use ($kinds) {
        $html = '<select name="field_kind[]" aria-label="Kind">';
        foreach ($kinds as $value => $name) {
            $html .= '<option value="'.e($value).'"'.($value === $current ? ' selected' : '').'>'.e($name).'</option>';
        }
        return $html.'</select>';
    };
    $required = fn (bool $on) => '<select name="field_required[]" aria-label="Required"><option value="1"'.($on ? ' selected' : '').'>yes</option><option value="0"'.($on ? '' : ' selected').'>no</option></select>';
@endphp
<div class="head"><h1>Setup</h1><div class="controls"><a class="btn go" href="#add-type">+ Add a type</a></div></div>
@include('tabs', ['area' => 'setup', 'current' => 'types', 'space' => $space])
<div class="stats">
    <div class="stat"><b>{{ $types->count() }}</b><span>types</span></div>
    <div class="stat"><b>{{ $types->sum(fn ($t) => count($t->fields)) }}</b><span>fields</span></div>
    <div class="stat"><b>{{ array_sum($usage) }}</b><span>entries</span></div>
</div>
<p class="note">A type lists the fields an entry has. Every field value is stored encrypted in OpenBao, whatever its kind; the kind only changes the input. The type key is visible to agents, so keep names free of sensitive data.</p>

@if($types->isEmpty())
    <div class="empty"><b>No entry types yet</b>An entry needs a type. <a href="#add-type">Add the first type</a></div>
@endif
@foreach($types as $type)
    @php $used = $usage[$type->key] ?? 0; @endphp
    <h2 id="type-{{ $type->key }}">{{ $type->name }}</h2>
    <form class="card" method="post" action="/workspaces/{{ $space->id }}/types/{{ $type->key }}">@csrf
        @isset($type->revision)<input type="hidden" name="revision" value="{{ $type->revision }}">@endisset
        <div class="form-foot"><span class="note">key <code>{{ $type->key }}</code></span><span class="chip {{ $used ? 'ok' : 'muted' }}">{{ $used }} {{ $used === 1 ? 'entry' : 'entries' }}</span></div>
        <label><span>Type name</span><input name="name" value="{{ $type->name }}" required maxlength="80"></label>
        <div class="scroll"><table class="list">
            <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th><th scope="col">Key</th><th scope="col">Remove</th></tr></thead>
            <tbody id="rows-{{ $type->key }}">
            @foreach($type->fields as $f)
                <tr>
                    <td><input type="hidden" name="field_key[]" value="{{ $f['key'] }}"><input name="field_label[]" value="{{ $f['label'] }}" required maxlength="80" aria-label="Field label"></td>
                    <td>{!! $kindSelect($f['kind']) !!}</td>
                    <td>{!! $required((bool) $f['required']) !!}</td>
                    <td><code>{{ $f['key'] }}</code></td>
                    <td><label class="check"><input type="checkbox" name="field_remove[]" value="{{ $f['key'] }}"> remove</label></td>
                </tr>
            @endforeach
                <tr data-row-template>
                    <td><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="new field" aria-label="Field label"></td>
                    <td>{!! $kindSelect() !!}</td><td>{!! $required(false) !!}</td><td></td><td></td>
                </tr>
            </tbody>
        </table></div>
        <div class="form-foot">
            <p class="note">Removed fields keep their values in older versions. A new required field is asked for at the next update. Agent actions need required fields: browser <code>username</code> + <code>password</code>, API/CLI <code>token</code>, DB <code>role</code>.</p>
            <div class="controls"><button type="button" data-add-row="rows-{{ $type->key }}">+ Field</button><button>Save type</button></div>
        </div>
    </form>
    <div class="controls">
    @if($used === 0)
        <form method="post" action="/workspaces/{{ $space->id }}/types/{{ $type->key }}/delete" data-confirm="Remove type “{{ $type->name }}”?">@csrf<button class="stop">Remove type</button></form>
    @else
        <p class="note">Types used by entries (archived ones too) cannot be removed.</p>
    @endif
    </div>
@endforeach

<h2>Add a type</h2>
<div class="deck wide">
    <form class="action" id="add-type" method="post" action="/workspaces/{{ $space->id }}/types">@csrf
        <label><span>Type name</span><input name="name" required maxlength="80" placeholder="FTP server"></label>
        <div class="scroll"><table class="list">
            <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th></tr></thead>
            <tbody id="rows-new">
                <tr data-row-template><td><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="Server address" aria-label="Field label"></td><td>{!! $kindSelect() !!}</td><td>{!! $required(true) !!}</td></tr>
            </tbody>
        </table></div>
        <div class="form-foot"><p class="note">Field keys are made from the labels.</p><div class="controls"><button type="button" data-add-row="rows-new">+ Field</button><button>Create type</button></div></div>
    </form>
</div>
@endsection
