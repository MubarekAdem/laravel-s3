@props(['bytes' => 0])
@php
    $value = max(0, (float) $bytes);
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $unit = 0;
    while ($value >= 1024 && $unit < 4) { $value /= 1024; $unit++; }
@endphp
<span {{ $attributes }} title="{{ number_format((float) $bytes) }} bytes">{{ number_format($value, $unit === 0 || floor($value) === $value ? 0 : 1) }} <span class="unit">{{ $units[$unit] }}</span></span>
