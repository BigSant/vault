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
<h1>Setup</h1>
@include('tabs', ['area' => 'setup', 'current' => 'types', 'space' => $space])
<nav class="subnav" aria-label="On this page">
    <span class="subnav-label" aria-hidden="true">On this page</span>
    <a href="#add-type">Add a type</a>
</nav>

<h2 class="setup-first">Types</h2>
<p class="note">A type defines an entry’s encrypted fields. Agents see type and field keys, so keep sensitive data out of names.</p>

@if($types->isEmpty())
    <div class="empty"><b>No entry types yet</b>An entry needs a type. <a href="#add-type">Add the first type</a></div>
@else
@foreach($types as $type)
    @php $used = $usage[$type->key] ?? 0; @endphp
    <details class="fold" id="type-{{ $type->key }}">
        <summary><span class="fold-name">{{ $type->name }} <code>{{ $type->key }}</code></span> <span class="fold-meta">{{ $plural(count($type->fields), 'field', 'fields') }}</span> <span class="fold-meta">{{ $plural($used, 'entry', 'entries') }}</span> <span class="fold-act">Edit<span class="sr-only"> type {{ $type->name }}</span></span></summary>
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
                            <td data-label=""><label class="check"><input type="checkbox" name="field_remove[]" value="{{ $f['key'] }}" aria-label="Remove field {{ $f['label'] }}"> remove</label></td>
                        </tr>
                    @endforeach
                        <tr data-row-template>
                            <td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="Field label" aria-label="Field label"></td>
                            <td data-label="Kind">{!! $kindSelect() !!}</td><td data-label="Required">{!! $required(false) !!}</td><td data-label=""></td><td data-label=""></td>
                        </tr>
                    </tbody>
                </table>
                @include('confirm-inline', ['verb' => 'save this type'])
                <div class="form-foot"><p class="note">Leave the last row empty if you add no field.</p>
                    <div class="controls"><button type="button" data-add-row="rows-{{ $type->key }}" aria-label="Add a field to {{ $type->name }}">+ Field</button><button aria-label="Save type {{ $type->name }}">Save type</button></div></div>
            </form>
            @if($used === 0)
                <form class="fold-foot" method="post" action="/workspaces/{{ $space->id }}/types/{{ $type->key }}/delete" data-confirm="Remove type “{{ $type->name }}”?">@csrf<button class="stop" aria-label="Remove type {{ $type->name }}">Remove type</button></form>
            @endif
        </div>
    </details>
@endforeach
<details class="more field-rules">
    <summary>Rules for changing fields</summary>
    <div class="inner">
        <ul class="note">
            <li>Older versions keep removed fields. Entries need new required fields on their next update.</li>
            <li>Browser actions require <code>username</code> and <code>password</code>. API and CLI actions require <code>token</code>. Database actions require <code>role</code>.</li>
            <li>Removing a type requires zero entries, including archived entries.</li>
        </ul>
    </div>
</details>
@endif

<h2 id="add-type">Add a type</h2>

    <form class="action" method="post" action="/workspaces/{{ $space->id }}/types">@csrf
        <label><span>Type name</span><input name="name" value="{{ old('revision') === null && (session('error_form') === null || session('error_form') === '/workspaces/'.$space->id.'/types') ? old('name') : '' }}" required maxlength="80" placeholder="FTP server"></label>
        <table class="list edit">
            <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th></tr></thead>
            <tbody id="rows-new">
                <tr data-row-template><td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="Server address" aria-label="Field label"></td><td data-label="Kind">{!! $kindSelect() !!}</td><td data-label="Required">{!! $required(true) !!}</td></tr>
            </tbody>
        </table>
        @include('confirm-inline', ['verb' => 'add this type'])
        <div class="form-foot"><p class="note">Field keys are made from the labels.</p><div class="controls"><button type="button" data-add-row="rows-new" aria-label="Add a field to the new type">+ Field</button><button class="go">Add type</button></div></div>
    </form>
@endsection
