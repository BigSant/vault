@extends('layout')
@section('title', 'Entry types')
@section('content')
@php
    $kindSelect = function (string $current = 'text', string $for = 'the new field') use ($kinds) {
        $html = '<select name="field_kind[]" aria-label="Kind for '.e($for).'">';
        foreach ($kinds as $value => $name) {
            $html .= '<option value="'.e($value).'"'.($value === $current ? ' selected' : '').'>'.e($name).'</option>';
        }
        return $html.'</select>';
    };
    $required = fn (bool $on, string $for = 'the new field') => '<select name="field_required[]" aria-label="Required for '.e($for).'"><option value="1"'.($on ? ' selected' : '').'>yes</option><option value="0"'.($on ? '' : ' selected').'>no</option></select>';
    $plural = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
    $errorForm = session('error_form');
    $addTypePath = '/workspaces/'.$space->id.'/types';
    $oldAddType = $errorForm === $addTypePath || ($errorForm === null && old('revision') === null && (old('name') !== null || is_array(old('field_label'))));
    $oldLabels = is_array(old('field_label')) ? old('field_label') : null;
    $oldKeys = is_array(old('field_key')) ? old('field_key') : [];
    $oldKinds = is_array(old('field_kind')) ? old('field_kind') : [];
    $oldReq = is_array(old('field_required')) ? old('field_required') : [];
    $oldRemove = is_array(old('field_remove')) ? array_filter(old('field_remove'), 'is_string') : [];
    $newRows = [];
    if ($oldAddType && $oldLabels !== null) {
        foreach ($oldLabels as $i => $lbl) {
            $lbl = is_string($lbl) ? $lbl : '';
            if ($lbl === '') {
                continue;
            }
            $kind = isset($oldKinds[$i]) && is_string($oldKinds[$i]) && isset($kinds[$oldKinds[$i]]) ? $oldKinds[$i] : 'text';
            $newRows[] = ['label' => $lbl, 'kind' => $kind, 'required' => ($oldReq[$i] ?? '1') === '1'];
        }
    }
@endphp
<h1>Setup</h1>
@include('tabs', ['area' => 'setup', 'current' => 'types', 'space' => $space])
<p class="note">A type defines an entry’s encrypted fields. Agents see type and field keys, so keep sensitive data out of names.</p>

@if($types->isEmpty())
    <div class="empty"><b>No entry types yet</b>An entry needs a type. <a href="#add-type">Add the first type</a></div>
@else
@foreach($types as $type)
    @php
        $used = $usage[$type->key] ?? 0;
        $typePath = $addTypePath.'/'.$type->key;
        $oldTypeEdit = $errorForm === $typePath;
        $oldTypeDelete = $errorForm === $typePath.'/delete';
        $storedLabels = array_column($type->fields, 'label', 'key');
        $editRows = array_map(fn ($f) => $f + ['remove' => false], $type->fields);
        if ($oldTypeEdit && $oldLabels !== null) {
            $editRows = [];
            $lastIdx = array_key_last($oldLabels);
            foreach ($oldLabels as $i => $lbl) {
                $lbl = is_string($lbl) ? $lbl : '';
                $k = isset($oldKeys[$i]) && is_string($oldKeys[$i]) ? $oldKeys[$i] : '';
                if ($k === '' && $lbl === '' && $i === $lastIdx) {
                    continue;
                }
                $knownKey = $k !== '' && array_key_exists($k, $storedLabels) ? $k : '';
                $kind = isset($oldKinds[$i]) && is_string($oldKinds[$i]) && isset($kinds[$oldKinds[$i]]) ? $oldKinds[$i] : 'text';
                $editRows[] = [
                    'key' => $knownKey,
                    'label' => $lbl,
                    'kind' => $kind,
                    'required' => ($oldReq[$i] ?? '0') === '1',
                    'remove' => $knownKey !== '' && in_array($knownKey, $oldRemove, true),
                ];
            }
        }
    @endphp
    <details class="fold" id="type-{{ $type->key }}"@if($oldTypeEdit || $oldTypeDelete) open @endif>
        <summary><span class="fold-name">{{ $type->name }} <code>{{ $type->key }}</code></span> <span class="fold-meta">{{ $plural(count($type->fields), 'field', 'fields') }}</span> <span class="fold-meta">{{ $plural($used, 'entry', 'entries') }}</span> <span class="fold-act">Edit<span class="sr-only"> type {{ $type->name }}</span></span></summary>
        <div class="inner">
            <form method="post" action="{{ $typePath }}">@csrf
                @isset($type->revision)<input type="hidden" name="revision" value="{{ $type->revision }}">@endisset
                <label><span>Type name <small>key <code>{{ $type->key }}</code></small></span><input name="name" value="{{ $oldTypeEdit && is_string(old('name')) ? old('name') : $type->name }}" required maxlength="80"></label>
                <table class="list edit">
                    <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th><th scope="col">Key</th><th scope="col">Remove</th></tr></thead>
                    <tbody id="rows-{{ $type->key }}">
                    @foreach($editRows as $f)
                        @php $for = $f['label'] !== '' ? $f['label'] : ($f['key'] !== '' ? ($storedLabels[$f['key']] ?? $f['key']) : 'row '.$loop->iteration); @endphp
                        <tr>
                            <td data-label="Field label"><input type="hidden" name="field_key[]" value="{{ $f['key'] }}"><input name="field_label[]" value="{{ $f['label'] }}"@if($f['key'] !== '') required @endif maxlength="80" aria-label="Label for {{ $for }}"></td>
                            <td data-label="Kind">{!! $kindSelect($f['kind'], $for) !!}</td>
                            <td data-label="Required">{!! $required((bool) $f['required'], $for) !!}</td>
                            <td data-label="Key">@if($f['key'] !== '')<code>{{ $f['key'] }}</code>@endif</td>
                            <td data-label="">@if($f['key'] !== '')<label class="check"><input type="checkbox" name="field_remove[]" value="{{ $f['key'] }}" aria-label="Remove field {{ $for }}" @checked($f['remove'])> remove</label>@endif</td>
                        </tr>
                    @endforeach
                        <tr data-row-template>
                            <td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="Field label" aria-label="Label for the new field"></td>
                            <td data-label="Kind">{!! $kindSelect('text', 'the new field') !!}</td><td data-label="Required">{!! $required(false, 'the new field') !!}</td><td data-label=""></td><td data-label=""></td>
                        </tr>
                    </tbody>
                </table>
                @include('confirm-inline', ['verb' => 'save this type'])
                <div class="form-foot"><p class="note">Leave the last row empty if you add no field.</p>
                    <div class="controls"><button type="button" data-add-row="rows-{{ $type->key }}" aria-label="Add a field to {{ $type->name }}">+ Field</button><button aria-label="Save type {{ $type->name }}">Save type</button></div></div>
            </form>
            @if($used === 0)
                <form class="fold-foot" method="post" action="{{ $typePath }}/delete" data-confirm="Remove type “{{ $type->name }}”?">@csrf<button class="stop" aria-label="Remove type {{ $type->name }}">Remove type</button></form>
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

    <form class="action" method="post" action="{{ $addTypePath }}">@csrf
        <label><span>Type name</span><input name="name" value="{{ $oldAddType && is_string(old('name')) ? old('name') : '' }}" required maxlength="80" placeholder="FTP server"></label>
        <table class="list edit">
            <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th></tr></thead>
            <tbody id="rows-new">
            @foreach($newRows as $f)
                @php $for = $f['label'] !== '' ? $f['label'] : 'row '.$loop->iteration; @endphp
                <tr><td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" value="{{ $f['label'] }}" maxlength="80" aria-label="Label for {{ $for }}"></td><td data-label="Kind">{!! $kindSelect($f['kind'], $for) !!}</td><td data-label="Required">{!! $required($f['required'], $for) !!}</td></tr>
            @endforeach
                <tr data-row-template><td data-label="Field label"><input type="hidden" name="field_key[]" value=""><input name="field_label[]" maxlength="80" placeholder="{{ $newRows ? 'Field label' : 'Server address' }}" aria-label="Label for the new field"></td><td data-label="Kind">{!! $kindSelect('text', 'the new field') !!}</td><td data-label="Required">{!! $required(true, 'the new field') !!}</td></tr>
            </tbody>
        </table>
        @include('confirm-inline', ['verb' => 'add this type'])
        <div class="form-foot"><p class="note">Field keys are made from the labels.</p><div class="controls"><button type="button" data-add-row="rows-new" aria-label="Add a field to the new type">+ Field</button><button class="go">Add type</button></div></div>
    </form>
@endsection
