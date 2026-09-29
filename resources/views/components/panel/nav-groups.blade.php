@php
    $grupos = \App\Support\PanelMenu::gruposVisibles();
@endphp

{{--
    Rejilla de 4 columnas, como el legacy (`app_nav.php:165`).
    NO es acordeón ni lista vertical: los grupos siempre están abiertos y
    separados por un <hr>, igual que allí. En 256px de ancho, 4 columnas de
    44px mínimo leaves el área táctil cumplida (AGENTS.md).

    `$compacto` usa las etiquetas cortas ("Prov", "Mov", "Dev"): en la rejilla
    no caben las largas. El drawer móvil usa las largas.
--}}
<nav class="flex-1 overflow-y-auto px-2 py-3 space-y-1.5 luxury-scroll"
     aria-label="Módulos del panel">
    @forelse ($grupos as $gi => $grupo)
        <div wire:key="nav-grupo-{{ $gi }}-{{ $grupo['titulo'] }}">
            <h3 class="text-[9px] font-black uppercase tracking-[2px] text-gray-500 px-3 mb-1 mt-1">
                {{ $grupo['titulo'] }}
            </h3>
            <div class="grid grid-cols-4 gap-1.5 px-1">
                @foreach ($grupo['items'] as $item)
                    @php $activo = request()->is($item['uri']) || request()->is($item['uri'].'/*'); @endphp
                    <a href="{{ url($item['uri']) }}"
                       wire:key="nav-{{ $item['uri'] }}"
                       @click="open = false"
                       @class([
                           'panel-nav-tile flex flex-col items-center p-2 rounded-xl min-h-[44px] justify-center',
                           'activo' => $activo,
                           'text-gray-400 hover:bg-gray-800 hover:text-white' => ! $activo,
                       ])
                       @if ($activo) aria-current="page" @endif
                       title="{{ $item['label'] }}">
                        <x-heroicon :name="$item['icon']" class="w-4 h-4 shrink-0" />
                        <span class="text-[8px] font-bold mt-1.5 text-center leading-tight break-words">
                            {{ $item['corto'] }}
                        </span>
                        <span class="sr-only">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
        <hr class="border-gray-800/40 my-1.5 mx-3">
    @empty
        <p class="px-3 text-sm text-gray-500">Sin módulos migrados aún.</p>
    @endforelse
</nav>
