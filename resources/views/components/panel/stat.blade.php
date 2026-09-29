@props([
    'etiqueta',
    'valor',
    'tono' => 'slate',
    'icono' => null,
    'ayuda' => null,
])

@php
    // Paleta cerrada: evita que cada módulo invente colores y rompe la consistencia.
    $tonos = [
        'slate'    => ['texto' => 'text-gray-900',   'glifo' => 'text-gray-400',   'fondo' => 'bg-gray-100'],
        'indigo'   => ['texto' => 'text-indigo-600', 'glifo' => 'text-indigo-500', 'fondo' => 'bg-indigo-50'],
        'emerald'  => ['texto' => 'text-emerald-600','glifo' => 'text-emerald-500','fondo' => 'bg-emerald-50'],
        'rose'     => ['texto' => 'text-rose-600',   'glifo' => 'text-rose-500',   'fondo' => 'bg-rose-50'],
        'amber'    => ['texto' => 'text-amber-600',  'glifo' => 'text-amber-500',  'fondo' => 'bg-amber-50'],
    ];
    $t = $tonos[$tono] ?? $tonos['slate'];
@endphp

<div class="bg-white rounded-2xl border border-gray-200 p-4 flex items-start gap-3">
    @if ($icono)
        <span class="flex items-center justify-center w-9 h-9 rounded-xl shrink-0 {{ $t['fondo'] }}">
            <x-heroicon :name="$icono" class="w-5 h-5 {{ $t['glifo'] }}" />
        </span>
    @endif
    <div class="min-w-0 flex-1">
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider leading-tight">
            {{ $etiqueta }}
        </p>
        <p class="text-2xl font-extrabold mt-1 tabular-nums truncate {{ $t['texto'] }}">
            {{ $valor }}
        </p>
        @if ($ayuda)
            <p class="text-[11px] text-gray-400 mt-0.5 leading-tight">{{ $ayuda }}</p>
        @endif
    </div>
</div>
