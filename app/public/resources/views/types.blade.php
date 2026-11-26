@extends('layout')
@section('title', 'Entry types')
@section('content')
@php
    $kindSelect = function (int $index, string $current = 'text', string $for = 'the new field') use ($kinds) {
        $html = '<select name="field_kind['.$index.']" aria-label="Kind for '.e($for).'">';
        foreach ($kinds as $value => $name) {
            $html .= '<option value="'.e($value).'"'.($value === $current ? ' selected' : '').'>'.e($name).'</option>';
        }
        return $html.'</select>';
    };
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
            $newRows[] = ['label' => $lbl, 'kind' => $kind, 'required' => ($oldReq[$i] ?? '0') === '1'];
        }
    }
@endphp
<div class="head"><h1>Setup</h1><a class="btn" href="#add-type">+ Add an entry type</a></div>
@include('tabs', ['area' => 'setup', 'current' => 'types', 'space' => $space])
<p class="note">An entry type defines an entry's encrypted fields. Agents see type and field keys, so keep sensitive data out of names.</p>

{{-- One password block for the page, above the entry types: every form below names it (data-confirm-from), so an
     expired confirmation asks here and holds the action under its name ("Save entry type Login"). The add form
     only points to it. With no entry types there is nothing above the add form, which then has its own field. --}}
@unless($types->isEmpty())
@include('confirm-inline', ['verb' => 'change entry types', 'ask' => 'Enter your current password, then save, add or remove below.', 'shared' => 'confirm-types'])
@endunless
@if($types->isEmpty())
    <div class="empty capped"><b>No entry types yet.</b>An entry needs an entry type. <a href="#add-type">Add the first entry type</a>.</div>
@else
@foreach($types as $type)
    @php
        $used = $usage[$type->key] ?? 0;
        $typePath = $addTypePath.'/'.$type->key;
        $oldTypeEdit = $errorForm === $typePath;
        $oldTypeDelete = $errorForm === $typePath.'/delete';
        $storedLabels = array_column($type->fields, 'label', 'key');
        $editRows = array_values(array_map(fn ($f) => $f + ['remove' => false], $type->fields));
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
        $nextEditIdx = count($editRows);
    @endphp
    <details class="fold type-fold capped" id="type-{{ $type->key }}"@if($oldTypeEdit || $oldTypeDelete) open @endif>
        <summary><span class="fold-name" id="type-name-{{ $type->key }}">{{ $type->name }} <code>{{ $type->key }}</code></span> <span class="fold-meta">{{ $plural(count($type->fields), 'field', 'fields') }} · {{ $plural($used, 'entry', 'entries') }}</span> <span class="fold-act"><span class="when-closed">Edit</span><span class="when-open">Close</span><span class="sr-only"> entry type {{ $type->name }}</span></span></summary>
        <div class="inner">
            <form method="post" action="{{ $typePath }}" data-confirm-from="confirm-types">@csrf
                @isset($type->revision)<input type="hidden" name="revision" value="{{ $type->revision }}">@endisset
                <label><span>Type name <small>key <code>{{ $type->key }}</code></small></span><input name="name" value="{{ $oldTypeEdit && is_string(old('name')) ? old('name') : $type->name }}" required maxlength="80"></label>
                <div class="scroll"><table class="list edit" aria-labelledby="type-name-{{ $type->key }}">
                    <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th><th scope="col">Key</th><th scope="col">Remove</th></tr></thead>
                    <tbody id="rows-{{ $type->key }}">
                    @foreach($editRows as $i => $f)
                        @php $for = $f['label'] !== '' ? $f['label'] : ($f['key'] !== '' ? ($storedLabels[$f['key']] ?? $f['key']) : 'row '.$loop->iteration); @endphp
                        <tr>
                            <td class="field-label" data-label="Field label"><input type="hidden" name="field_key[{{ $i }}]" value="{{ $f['key'] }}"><input name="field_label[{{ $i }}]" value="{{ $f['label'] }}"@if($f['key'] !== '') required @endif maxlength="80" aria-label="Label for {{ $for }}"></td>
                            <td class="kind" data-label="Kind">{!! $kindSelect($i, $f['kind'], $for) !!}</td>
                            <td class="req" data-label=""><label class="check"><input type="checkbox" name="field_required[{{ $i }}]" value="1" @checked((bool) $f['required']) aria-label="Required for {{ $for }}"> <span class="phone-only">Required</span></label></td>
                            <td class="key" data-label="Key">@if($f['key'] !== '')<code>{{ $f['key'] }}</code>@endif</td>
                            <td class="remove" data-label="">@if($f['key'] !== '')<label class="check stop"><input type="checkbox" name="field_remove[]" value="{{ $f['key'] }}" aria-label="Remove field {{ $for }}" @checked($f['remove'])> <span class="phone-only">Remove field</span></label>@endif</td>
                        </tr>
                    @endforeach
                        <tr data-row-template>
                            <td class="field-label" data-label="Field label"><input type="hidden" name="field_key[{{ $nextEditIdx }}]" value=""><input name="field_label[{{ $nextEditIdx }}]" maxlength="80" aria-label="Label for the new field"></td>
                            <td class="kind" data-label="Kind">{!! $kindSelect($nextEditIdx, 'text', 'the new field') !!}</td><td class="req" data-label=""><label class="check"><input type="checkbox" name="field_required[{{ $nextEditIdx }}]" value="1" aria-label="Required for the new field"> <span class="phone-only">Required</span></label></td><td class="key" data-label=""></td><td class="remove" data-label=""></td>
                        </tr>
                    </tbody>
                </table></div>
                <div class="form-foot"><p class="note">An empty last row is ignored.</p>
                    <div class="controls"><button type="button" data-add-row="rows-{{ $type->key }}" aria-label="Add a field to {{ $type->name }}">+ Add a field</button><button aria-label="Save entry type {{ $type->name }}" data-held-name="Save entry type {{ $type->name }}" data-held-lead="Save entry type " data-held-data="{{ $type->name }}">Save entry type</button></div></div>
            </form>
            @if($used === 0)
                <form class="fold-foot" method="post" action="{{ $typePath }}/delete" data-confirm="Remove entry type {{ $type->name }}?" data-confirm-from="confirm-types">@csrf<button class="stop" aria-label="Remove entry type {{ $type->name }}" data-held-name="Remove entry type {{ $type->name }}" data-held-lead="Remove entry type " data-held-data="{{ $type->name }}">Remove entry type</button></form>
            @else
                {{-- As on Projects: why there is no Remove. --}}
                <p class="note fold-foot">Used by {{ $plural($used, 'entry', 'entries') }}, so it cannot be removed.</p>
            @endif
        </div>
    </details>
@endforeach
<details class="more field-rules capped">
    <summary>Rules for changing fields</summary>
    <div class="inner">
        <ul class="note">
            <li>Older versions keep removed fields. Entries need new required fields on their next update.</li>
            <li>Browser actions require <code>username</code> and <code>password</code>. API and CLI actions require <code>token</code>. Database actions require <code>role</code>.</li>
            <li>Removing an entry type requires zero entries, including archived entries.</li>
        </ul>
    </div>
</details>
@endif

<h2 id="add-type">Add an entry type</h2>

    <form class="action" method="post" action="{{ $addTypePath }}"@unless($types->isEmpty()) data-confirm-from="confirm-types"@endunless>@csrf
        <label><span>Type name</span><input name="name" value="{{ $oldAddType && is_string(old('name')) ? old('name') : '' }}" required maxlength="80" placeholder="e.g. FTP server"></label>
        @php $nextNewIdx = count($newRows); @endphp
        <table class="list edit type-new" aria-labelledby="add-type">
            <thead><tr><th scope="col">Field label</th><th scope="col">Kind</th><th scope="col">Required</th></tr></thead>
            <tbody id="rows-new">
            @foreach($newRows as $i => $f)
                @php $for = $f['label'] !== '' ? $f['label'] : 'row '.$loop->iteration; @endphp
                <tr><td data-label="Field label"><input type="hidden" name="field_key[{{ $i }}]" value=""><input name="field_label[{{ $i }}]" value="{{ $f['label'] }}" maxlength="80" aria-label="Label for {{ $for }}"></td><td data-label="Kind">{!! $kindSelect($i, $f['kind'], $for) !!}</td><td data-label=""><label class="check"><input type="checkbox" name="field_required[{{ $i }}]" value="1" @checked((bool) $f['required']) aria-label="Required for {{ $for }}"> <span class="phone-only">Required</span></label></td></tr>
            @endforeach
                <tr data-row-template><td data-label="Field label"><input type="hidden" name="field_key[{{ $nextNewIdx }}]" value=""><input name="field_label[{{ $nextNewIdx }}]" maxlength="80" aria-label="Label for the new field"></td><td data-label="Kind">{!! $kindSelect($nextNewIdx, 'text', 'the new field') !!}</td><td data-label=""><label class="check"><input type="checkbox" name="field_required[{{ $nextNewIdx }}]" value="1" aria-label="Required for the new field"> <span class="phone-only">Required</span></label></td></tr>
            </tbody>
        </table>
        @if($types->isEmpty())
            @include('confirm-inline', ['verb' => 'add this entry type'])
        @else
            @include('confirm-inline', ['verb' => 'add this entry type', 'shared' => 'confirm-add-type', 'primary' => 'confirm-types', 'primaryShown' => true, 'fallback' => true])
        @endif
        <div class="form-foot"><p class="note">Field keys are made from the labels.</p><div class="controls"><button type="button" data-add-row="rows-new" aria-label="Add a field to the new entry type">+ Add a field</button><button class="go" data-held-name="Add entry type " data-held-field="name">Add entry type</button></div></div>
    </form>
@endsection
