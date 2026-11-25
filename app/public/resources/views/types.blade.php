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
    $plural = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
@endphp
<div class="head"><h1>Setup</h1><div class="controls"><a class="btn" href="#add-type">Add a type</a></div></div>
@include('tabs', ['area' => 'setup', 'current' => 'types', 'space' => $space])
<div class="stats">
    <div class="stat"><b>{{ $types->count() }}</b><span>types</span></div>
    <div class="stat"><b>{{ $types->sum(fn ($t) => count($t->fields)) }}</b><span>fields</span></div>
    <div class="stat"><b>{{ array_sum($usage) }}</b><span>entries</span></div>
</div>
<p class="note">A type lists the fields an entry has. Every field value is kept in the encrypted store, whatever its kind; the kind only changes the input. Agents can see type and field keys, so keep sensitive data out of names.</p>

@if($types->isEmpty())
    <div class="empty"><b>No entry types yet</b>An entry needs a type. <a href="#add-type">Add the first type</a></div>
@else
<h2>Types</h2>
<p class="note">Removed fields keep their values in older versions. A new required field is asked for at the next update of an entry.
    Agent actions need these required fields: browser <code>username</code> + <code>password</code>, API/CLI <code>token</code>, DB <code>role</code>.
    A type used by entries (archived ones too) cannot be removed.</p>
@foreach($types as $type)
    @php $used = $usage[$type->key] ?? 0; @endphp
    <details class="fold" id="type-{{ $type->key }}">
        <summary><span class="fold-name">{{ $type->name }} <code>{{ $type->key }}</code></span> <span class="fold-meta">{{ $plural(count($type->fields), 'field', 'fields') }}</span> <span class="fold-meta">{{ $plural($used, 'entry', 'entries') }}</span></summary>
        <div class="inner">
            <form method="post" action="/workspaces/{{ $space->id }}/types/{{ $type->key }}">@csrf
                @isset($type->revision)<input type="hidden" name="revision" value="{{ $type->revision }}">@endisset
                <label><span>Type name <small>key <code>{{ $type->key }}</code></small></span><input name="name" value="{{ $type->name }}" required maxlength="80"></label>
                <table class="list edit">
                    <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th><th scope="col">Key</th><th scope="col">Remove</th></tr></thead>
                    <tbody id="rows-{{ $type->key }}">
                    @foreach($type->fields as $f)
                        <tr>
                            <td data-label="Field label"><input type="hidden" name="field_key[]" value="{{ $f['key'] }}"><input name="field_label[]" value="{{ $f['label'] }}" required maxlength="80" aria-label="Field label"></td>
                            <td data-label="Kind">{!! $kindSelect($f['kind']) !!}</td>
                            <td data-label="Required">{!! $required((bool) $f['required']) !!}</td>
                            <td data-label="Key"><code>{{ $f['key'] }}</code></td>
                            <td><label class="check"><input type="checkbox" name="field_remove[]" value="{{ $f['key'] }}"> remove</label></td>
                        </tr>
                    @endforeach
                        <tr data-row-template>
                            <td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="new field" aria-label="Field label"></td>
                            <td data-label="Kind">{!! $kindSelect() !!}</td><td data-label="Required">{!! $required(false) !!}</td><td></td><td></td>
                        </tr>
                    </tbody>
                </table>
                <div class="form-foot"><p class="note">Leave the last row empty if you add no field.</p>
                    <div class="controls"><button type="button" data-add-row="rows-{{ $type->key }}">+ Field</button><button aria-label="Save type {{ $type->name }}">Save type</button></div></div>
            </form>
            @if($used === 0)
                <form class="fold-foot" method="post" action="/workspaces/{{ $space->id }}/types/{{ $type->key }}/delete" data-confirm="Remove type “{{ $type->name }}”?">@csrf<button class="stop" aria-label="Remove type {{ $type->name }}">Remove type</button></form>
            @endif
        </div>
    </details>
@endforeach
@endif

<h2>Add a type</h2>
<div class="deck wide">
    <form class="action" id="add-type" method="post" action="/workspaces/{{ $space->id }}/types">@csrf
        <label><span>Type name</span><input name="name" required maxlength="80" placeholder="FTP server"></label>
        <table class="list edit">
            <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th></tr></thead>
            <tbody id="rows-new">
                <tr data-row-template><td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="Server address" aria-label="Field label"></td><td data-label="Kind">{!! $kindSelect() !!}</td><td data-label="Required">{!! $required(true) !!}</td></tr>
            </tbody>
        </table>
        <div class="form-foot"><p class="note">Field keys are made from the labels.</p><div class="controls"><button type="button" data-add-row="rows-new">+ Field</button><button class="go">Create type</button></div></div>
    </form>
</div>
@endsection
