{{-- Sub-navigation for an area. $area: 'agents' or 'setup'; $current: the active tab key; $space: the workspace. --}}
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
        <a href="{{ $base }}/{{ $key }}" @if($key === $current) class="on" aria-current="page" @endif>{{ $text }}</a>
    @endforeach
</nav>
