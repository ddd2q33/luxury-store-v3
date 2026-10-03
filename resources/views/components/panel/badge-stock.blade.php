@props(['estado', 'stock' => null])

@php
    // Paleta cerrada, igual que `x-panel.stat`: cada módulo no debe inventar
    // colores. Los cuatro estados son los de `Producto::estadoStock()`.
    $estilos = [
        'ok'       => ['fondo' => 'bg-emerald-50', 'texto' => 'text-emerald-700', 'etiqueta' => 'Normal'],
        'bajo'     => ['fondo' => 'bg-amber-50',   'texto' => 'text-amber-700',   'etiqueta' => 'Bajo'],
        'agotado'  => ['fondo' => 'bg-gray-100',    'texto' => 'text-gray-500',    'etiqueta' => 'Agotado'],
        'negativo' => ['fondo' => 'bg-rose-50',    'texto' => 'text-rose-700',    'etiqueta' => 'Negativo'],
    ];
    $e = $estilos[$estado] ?? $estilos['ok'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold whitespace-nowrap {$e['fondo']} {$e['texto']}"]) }}>
    {{ $e['etiqueta'] }}
    @if ($stock !== null)
        <span class="tabular-nums opacity-70">{{ (int) $stock }}</span>
    @endif
</span>
