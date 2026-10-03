<div class="space-y-5" @keydown.escape="cerrarPegar()">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="banknotes" class="w-6 h-6 text-indigo-600" />
                Cargar precios
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Los precios del catálogo. Lo que quede vacío se muestra como «Sin precio».
            </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-2.5">
            <button type="button" wire:click="abrirPegar()"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border border-gray-200 text-gray-700 px-5 py-2.5 text-sm font-bold hover:bg-gray-50 min-h-[44px] active:scale-[0.98] transition">
                <x-heroicon name="clipboard" class="w-5 h-5" />
                Pegar de Excel
            </button>

            <button type="button" wire:click="guardar()"
                    @disabled($tocados === [])
                    class="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold min-h-[44px] active:scale-[0.98] transition shadow-lg
                        {{ $tocados === [] ? 'bg-gray-200 text-gray-400 cursor-not-allowed shadow-none' : 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-indigo-600/20' }}">
                <x-heroicon name="check" class="w-5 h-5" />
                Guardar
                @if ($tocados !== [])
                    <span class="rounded-full bg-white/25 px-1.5 py-0.5 text-xs tabular-nums">{{ count($tocados) }}</span>
                @endif
            </button>
        </div>
    </div>

    {{-- Aviso: por qué esta pantalla existe --}}
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
        <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
        <div class="text-sm text-amber-900 min-w-0">
            <p class="font-bold">Casi ningún producto tiene precio.</p>
            <p class="mt-0.5 leading-relaxed">
                Mientras el precio esté en cero, el valor del inventario, los márgenes y los reportes
                por categoría dan $0. No se puede recuperar del historial de ventas (el legacy guardaba
                el total del carrito, no el precio unitario), así que hay que cargarlos aquí.
            </p>
        </div>
    </div>

    {{-- ================= Progreso ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <p class="text-sm font-bold text-gray-900">
                {{ $stats['conPrecio'] }} de {{ $stats['total'] }} con precio
            </p>
            <p class="text-sm font-bold tabular-nums
                {{ $stats['avance'] === 100 ? 'text-emerald-600' : ($stats['avance'] >= 50 ? 'text-amber-600' : 'text-rose-600') }}">
                {{ $stats['avance'] }}%
            </p>
        </div>

        <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden" role="progressbar"
             aria-valuenow="{{ $stats['avance'] }}" aria-valuemin="0" aria-valuemax="100"
             aria-label="Avance de la carga de precios">
            <div class="h-full rounded-full transition-all duration-500
                {{ $stats['avance'] === 100 ? 'bg-emerald-500' : ($stats['avance'] >= 50 ? 'bg-amber-500' : 'bg-rose-500') }}"
                 style="width: {{ max($stats['avance'], 2) }}%"></div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
            <x-panel.stat etiqueta="Con precio" :valor="$stats['conPrecio']" tono="emerald" icono="check-circle" />
            <x-panel.stat etiqueta="Sin precio" :valor="$stats['sinPrecio']" tono="amber" icono="exclamation-triangle" />
            <x-panel.stat etiqueta="Valor inventario" :valor="\App\Support\Money::format($stats['valorInventario'])" tono="indigo" icono="banknotes" />
            <x-panel.stat etiqueta="Pendiente" :valor="$stats['sinPrecio'] > 0 ? 'Cargar' : 'Listo'" tono="slate" icono="flag" />
        </div>
    </div>

    {{-- ================= Filtros ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-4 flex flex-col lg:flex-row gap-3">
        <div class="relative flex-1">
            <x-heroicon name="magnifying-glass" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar producto…"
                   aria-label="Buscar producto"
                   class="w-full rounded-xl border-gray-200 text-sm pl-10 min-h-[44px] focus:border-indigo-500 focus:ring-indigo-500">
        </div>

        <select wire:model="filtroCategoria" aria-label="Filtrar por categoría"
                class="rounded-xl border-gray-200 text-sm min-h-[44px] lg:w-52 focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">Todas las categorías</option>
            @foreach ($categorias as $id => $nombre)
                <option value="{{ $id }}">{{ $nombre }}</option>
            @endforeach
        </select>

        <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl" role="group" aria-label="Filtrar por estado del precio">
            @foreach ([
                ''            => 'Todos',
                'sin_precio'  => 'Sin precio',
                'con_precio'  => 'Con precio',
            ] as $valor => $etiqueta)
                <button type="button" wire:click="$set('filtroEstado', '{{ $valor }}')"
                        @class([
                            'flex-1 px-3 rounded-lg text-xs font-bold min-h-[38px] transition whitespace-nowrap',
                            'bg-white text-indigo-600 shadow-sm' => $filtroEstado === $valor,
                            'text-gray-500 hover:text-gray-700'   => $filtroEstado !== $valor,
                        ])>
                    {{ $etiqueta }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ================= Listado ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        @php $hayFiltros = $search !== '' || $filtroCategoria !== '' || $filtroEstado !== ''; @endphp

        {{-- Escritorio: tabla --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-5 py-3 text-left font-bold text-gray-500 text-xs uppercase tracking-wider">Producto</th>
                        <th class="px-5 py-3 text-left font-bold text-gray-500 text-xs uppercase tracking-wider">Categoría</th>
                        <th class="px-5 py-3 text-left font-bold text-gray-500 text-xs uppercase tracking-wider w-32">Stock</th>
                        <th class="px-5 py-3 text-left font-bold text-gray-500 text-xs uppercase tracking-wider w-48">Precio</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($productos as $p)
                        @php $actual = $p->precio; @endphp
                        <tr wire:key="precio-{{ $p->id }}"
                            @class([
                                'transition-colors',
                                'bg-indigo-50/40' => isset($tocados[$p->id]),
                                'hover:bg-gray-50/70' => ! isset($tocados[$p->id]),
                            ])>
                            <td class="px-5 py-3">
                                <p class="font-semibold text-gray-900 truncate max-w-md">{{ $p->nombre }}</p>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-500">{{ $p->categoria?->nombre ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <x-panel.badge-stock :estado="$p->estadoStock()" />
                            </td>
                            <td class="px-5 py-3">
                                {{-- El input NO se escribe con wire:model: 106 filas
                                     Actualizando la BD en cada tecla sería 106 viajes
                                     por segundo. Se envía el valor al hacer blur. --}}
                                <input type="text" inputmode="numeric"
                                       wire:blur="editarPrecio({{ $p->id }}, $event.target.value)"
                                       @if (array_key_exists($p->id, $precios)) value="{{ $precios[$p->id] }}" @else value="{{ $actual > 0 ? rtrim(rtrim(number_format((float) $actual, 2, '.', ''), '0'), '.') : '' }}" @endif
                                       placeholder="Sin precio"
                                       aria-label="Precio de {{ $p->nombre }}"
                                       @class([
                                           'w-full rounded-xl border-gray-200 text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500',
                                           'border-amber-300 bg-amber-50/50 placeholder:text-amber-400' => $actual <= 0,
                                           'border-indigo-300 bg-white ring-2 ring-indigo-100' => isset($tocados[$p->id]),
                                       ])>
                                @error('precios.'.$p->id)
                                    <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>
                                @enderror
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-14 text-center text-gray-400">
                            {{ $hayFiltros ? 'Ningún producto coincide con el filtro.' : 'No hay productos en el catálogo.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil: tarjetas apiladas (NO tabla con scroll) --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($productos as $p)
                @php $actual = $p->precio; @endphp
                <div class="p-4" wire:key="precio-m-{{ $p->id }}"
                     @class(['-mx-1 px-4', 'bg-indigo-50/40' => isset($tocados[$p->id])])>
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 leading-snug">{{ $p->nombre }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ $p->categoria?->nombre ?? 'Sin categoría' }}
                            </p>
                        </div>
                        <x-panel.badge-stock :estado="$p->estadoStock()" />
                    </div>

                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5"
                           for="precio-m-{{ $p->id }}">
                        Precio de venta
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-bold pointer-events-none">$</span>
                        <input id="precio-m-{{ $p->id }}" type="text" inputmode="numeric"
                               wire:blur="editarPrecio({{ $p->id }}, $event.target.value)"
                               @if (array_key_exists($p->id, $precios)) value="{{ $precios[$p->id] }}" @else value="{{ $actual > 0 ? rtrim(rtrim(number_format((float) $actual, 2, '.', ''), '0'), '.') : '' }}" @endif
                               placeholder="Sin precio"
                               @class([
                                   'w-full rounded-xl border-gray-200 text-sm tabular-nums pl-8 min-h-[48px] focus:border-indigo-500 focus:ring-indigo-500',
                                   'border-amber-300 bg-amber-50/50 placeholder:text-amber-400' => $actual <= 0,
                                   'border-indigo-300 bg-white ring-2 ring-indigo-100' => isset($tocados[$p->id]),
                               ])>
                    </div>
                    @error('precios.'.$p->id)
                        <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            @empty
                <div class="px-4 py-14 text-center text-gray-400">
                    {{ $hayFiltros ? 'Ningún producto coincide con el filtro.' : 'No hay productos en el catálogo.' }}
                </div>
            @endforelse
        </div>
    </div>

    {{-- Barra fija de guardado: en móvil el botón del header se va con el scroll --}}
    @if ($tocados !== [])
        <div class="sticky bottom-0 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-white/95 backdrop-blur border-t border-gray-200 z-30">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm font-bold text-gray-900 min-w-0 truncate">
                    {{ count($tocados) }} {{ count($tocados) === 1 ? 'precio sin guardar' : 'precios sin guardar' }}
                </p>
                <button type="button" wire:click="guardar()"
                        class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
                    <x-heroicon name="check" class="w-5 h-5" />
                    Guardar
                </button>
            </div>
        </div>
    @endif

    {{-- ================= Modal: pegar desde Excel ================= --}}
    @if ($showPegar)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarPegar()"></div>

            <div class="relative w-full sm:max-w-3xl bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                        <h3 class="text-lg font-bold text-gray-900">Pegar precios desde Excel</h3>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Una línea por producto: <span class="font-semibold">nombre, precio</span>.
                            Se puede separar por coma o tabulador.
                        </p>
                    </div>
                    <button type="button" wire:click="cerrarPegar"
                            aria-label="Cerrar"
                            class="shrink-0 flex items-center justify-center w-11 h-11 rounded-xl text-gray-400 hover:bg-gray-100 active:bg-gray-200 transition">
                        <x-heroicon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4">
                    <div class="rounded-xl bg-gray-50 border border-gray-200 p-3">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5">Ejemplo</p>
                        <pre class="text-xs text-gray-600 font-mono whitespace-pre-wrap leading-relaxed">ACSIS ROSADO, 150000
bota af1	270.000
"vomero morado", 120000,50</pre>
                    </div>

                    <div>
                        <label for="pegar-texto" class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5">
                            Pega aquí (Ctrl+V)
                        </label>
                        <textarea id="pegar-texto" rows="7" wire:model="textoPegado"
                                  placeholder="ACSIS ROSADO, 150000&#10;bota af1&#9;270.000"
                                  class="w-full rounded-xl border-gray-200 text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                        @error('textoPegado')
                            <p class="mt-1 text-sm font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($showPegarErrores)
                        {{-- Propuestas: se muestran ANTES de aplicar, para revisar --}}
                        @if ($propuestas !== [])
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 overflow-hidden">
                                <div class="px-4 py-3 border-b border-emerald-200 flex items-center justify-between gap-3">
                                    <p class="text-sm font-bold text-emerald-900">
                                        {{ count($propuestas) }} {{ count($propuestas) === 1 ? 'precio reconocido' : 'precios reconocidos' }}
                                    </p>
                                    <button type="button" wire:click="aplicarPegado()"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-2 text-xs font-bold min-h-[38px] hover:bg-emerald-700 active:scale-[0.98] transition">
                                        <x-heroicon name="check" class="w-4 h-4" />
                                        Aplicar
                                    </button>
                                </div>

                                {{-- Escritorio --}}
                                <div class="hidden sm:block max-h-56 overflow-y-auto divide-y divide-emerald-100">
                                    @foreach ($propuestas as $propuesta)
                                        <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                                            <span class="text-sm font-medium text-gray-900 truncate">{{ $propuesta['nombre'] }}</span>
                                            <span class="text-sm tabular-nums shrink-0">
                                                <span class="text-gray-400 line-through">{{ $propuesta['anterior'] }}</span>
                                                <span class="mx-1 text-gray-300">→</span>
                                                <span class="font-bold text-emerald-700">{{ $propuesta['nuevo'] }}</span>
                                            </span>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- Móvil --}}
                                <div class="sm:hidden divide-y divide-emerald-100 max-h-56 overflow-y-auto">
                                    @foreach ($propuestas as $propuesta)
                                        <div class="px-4 py-3">
                                            <p class="text-sm font-medium text-gray-900">{{ $propuesta['nombre'] }}</p>
                                            <p class="text-sm tabular-nums mt-0.5">
                                                <span class="text-gray-400 line-through">{{ $propuesta['anterior'] }}</span>
                                                <span class="mx-1 text-gray-300">→</span>
                                                <span class="font-bold text-emerald-700">{{ $propuesta['nuevo'] }}</span>
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Lo que NO se pudo asociar: nunca se descarta en silencio --}}
                        @if ($sinAsociar !== [])
                            <div class="rounded-2xl border border-amber-200 bg-amber-50/60 overflow-hidden">
                                <div class="px-4 py-3 border-b border-amber-200">
                                    <p class="text-sm font-bold text-amber-900">
                                        {{ count($sinAsociar) }} {{ count($sinAsociar) === 1 ? 'línea no se pudo aplicar' : 'líneas no se pudieron aplicar' }}
                                    </p>
                                    <p class="text-xs text-amber-800 mt-0.5">
                                        No se van a guardar: revísalas y cárgalas a mano en la lista.
                                    </p>
                                </div>

                                <div class="max-h-40 overflow-y-auto divide-y divide-amber-100">
                                    @foreach ($sinAsociar as $fallo)
                                        <div class="px-4 py-2.5">
                                            <p class="text-xs font-bold text-amber-900">
                                                Línea {{ $fallo['linea'] }}
                                            </p>
                                            <p class="text-xs text-gray-600 font-mono truncate">{{ $fallo['texto'] }}</p>
                                            <p class="text-xs text-amber-800 mt-0.5">{{ $fallo['motivo'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="px-6 pb-6 pt-3 border-t border-gray-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                    <button type="button" wire:click="cerrarPegar"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-700 px-5 py-3 font-semibold min-h-[48px] active:scale-[0.98] transition">
                        Cancelar
                    </button>
                    <button type="button" wire:click="analizarPegado"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 text-white px-5 py-3 font-bold min-h-[48px] hover:bg-indigo-700 active:scale-[0.98] transition">
                        <x-heroicon name="magnifying-glass" class="w-5 h-5" />
                        Revisar lista
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
