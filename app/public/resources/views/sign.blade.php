@php $signClass = !empty($production) ? 'prod' : (mb_strtolower($name) === 'stage' ? 'stage' : ''); @endphp<span class="sign {{ $signClass }}">{{ $name }}</span>
