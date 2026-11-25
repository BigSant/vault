{{-- Sub-navigation for an area. $area: 'agents' or 'setup'; $current: the active tab key; $space: the workspace.
     The current tab carries id="tab-current": a board that has no h2 of its own (Identities, Grants) is named by it. --}}
@php
    $base = '/workspaces/'.$space->id;
    $sets = [
        'agents' => ['Agents sections', ['requests' => 'Requests', 'agents' => 'Identities', 'grants' => 'Grants']],
        'setup' => ['Setup sections', ['structure' => 'Projects', 'types' => 'Entry types']],
    ];
    [$label, $items] = $sets[$area];
@endphp
<nav class="tabs" aria-label="{{ $label }}">
    @foreach($items as $key => $text)
        <a href="{{ $base }}/{{ $key }}" @if($key === $current) class="on" id="tab-current" aria-current="page" @endif>{{ $text }}</a>
    @endforeach
</nav>
