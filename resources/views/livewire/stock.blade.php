<div class="space-y-5" @keydown.escape="cerrarAjuste()">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="cube" class="w-6 h-6 text-indigo-600" />
                Stock
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Movimientos de inventario y control de existencias.
            </p>
        </div>
        <button type="button" wire:click="abrirAjuste()"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
            <x-heroicon name="arrows-right-left" class="w-5 h-5" />
            Ajustar inventario
        </button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Movimientos hoy" :valor="$stats['hoy']" tono="indigo" icono="clock" />
        <x-panel.stat etiqueta="Unidades ingresadas" :valor="number_format($stats['entradas'], 0, ',', '.')" tono="emerald" icono="trending-up" />
        <x-panel.stat etiqueta="Unidades salidas" :valor="number_format($stats['salidas'], 0, ',', '.')" tono="rose" icono="trending-down" />
        <x-panel.stat etiqueta="Requieren atención" :valor="$stats['alertas']" tono="amber" icono="exclamation-triangle" />
    </div>

    {{-- ================= Tabs ================= --}}
    <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl max-w-md">
        <button type="button" wire:click="irA('movimientos')"
                class="flex-1 px-3 py-2.5 rounded-lg text-xs font-bold min-h-[42px] transition"
                @class([
                    'bg-white text-indigo-600 shadow-sm' => $vista === 'movimientos',
                    'text-gray-500 hover:text-gray-700'   => $vista !== 'movimientos',
                ])>
            Movimientos
        </button>
        <button type="button" wire:click="irA('alertas')"
                class="flex-1 px-3 py-2.5 rounded-lg text-xs font-bold min-h-[42px] transition"
                @class([
                    'bg-white text-indigo-600 shadow-sm' => $vista === 'alertas',
                    'text-gray-500 hover:text-gray-700'   => $vista !== 'alertas',
                ])>
            Alertas
            @if ($stats['alertas'] > 0)
                <span class="ml-1 rounded-full bg-amber-500 text-white text-[10px] font-bold px-1.5 py-0.5 tabular-nums">
                    {{ $stats['alertas'] }}
                </span>
            @endif
        </button>
    </div>

    {{-- ================= Vista: MOVIMIENTOS ================= --}}
    @if ($vista === 'movimientos')
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <h3 class="font-bold text-gray-900 text-sm">Historial de movimientos</h3>

                <div class="flex flex-col sm:flex-row gap-2.5">
                    <input type="search" wire:model.debounce.300ms="search" placeholder="Producto, observación…"
                           class="rounded-xl border-gray-200 text-sm sm:w-56 focus:border-indigo-500 focus:ring-indigo-500">

                    <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                        @php
                            $tipos = [
                                ''        => 'Todos',
                                'Entrada' => 'Entradas',
                                'Salida'  => 'Salidas',
                                'Ajuste'  => 'Ajustes',
                            ];
                        @endphp
                        @foreach ($tipos as $valor => $texto)
                            <button type="button" wire:click="$set('filtroTipo', '{{ $valor }}')"
                                    class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                    @class([
                                        'bg-white text-indigo-600 shadow-sm' => $filtroTipo === $valor,
                                        'text-gray-500 hover:text-gray-700'   => $filtroTipo !== $valor,
                                    ])>
                                {{ $texto }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Desktop --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                            <th class="px-5 py-3 font-bold">Fecha</th>
                            <th class="px-5 py-3 font-bold">Producto</th>
                            <th class="px-5 py-3 font-bold">Tipo</th>
                            <th class="px-5 py-3 font-bold text-right">Cantidad</th>
                            <th class="px-5 py-3 font-bold">Observaciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($movimientos as $mov)
                            <tr class="hover:bg-gray-50/70" wire:key="mov-{{ $mov->id }}">
                                <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                    {{ $mov->fecha?->format('d/m/Y') }}
                                </td>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-gray-900 truncate">
                                        {{ $mov->producto?->nombre ?? 'Producto #'.$mov->producto_id }}
                                    </p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold
                                        @switch($mov->tipo)
                                            @case('Entrada') bg-emerald-50 text-emerald-700 @break
                                            @case('Salida')  bg-rose-50 text-rose-700 @break
                                            @default bg-indigo-50 text-indigo-700 @endswitch">
                                        {{ $mov->tipo }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right font-bold tabular-nums
                                    @switch($mov->tipo)
                                        @case('Entrada') text-emerald-600 @break
                                        @case('Salida')  text-rose-600 @break
                                        @default text-indigo-600 @endswitch">
                                    {{ $mov->tipo === 'Salida' ? '−' : '+' }}{{ $mov->cantidad }}
                                </td>
                                <td class="px-5 py-3 text-gray-500 max-w-xs">
                                    <span class="line-clamp-1">{{ $mov->observaciones ?: '—' }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-14 text-center text-gray-400">
                                {{ $search !== '' || $filtroTipo !== '' ? 'Sin movimientos para este filtro.' : 'Aún no hay movimientos de inventario.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Móvil --}}
            <div class="md:hidden divide-y divide-gray-100">
                @forelse ($movimientos as $mov)
                    <div class="p-4" wire:key="mov-m-{{ $mov->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900 truncate leading-snug">
                                    {{ $mov->producto?->nombre ?? 'Producto #'.$mov->producto_id }}
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5 tabular-nums">{{ $mov->fecha?->format('d/m/Y') }}</p>
                            </div>
                            <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold
                                @switch($mov->tipo)
                                    @case('Entrada') bg-emerald-50 text-emerald-700 @break
                                    @case('Salida')  bg-rose-50 text-rose-700 @break
                                    @default bg-indigo-50 text-indigo-700 @endswitch">
                                {{ $mov->tipo }}
                            </span>
                        </div>

                        <div class="mt-2.5 flex items-center justify-between gap-3">
                            <p class="text-lg font-extrabold tabular-nums
                                @switch($mov->tipo)
                                    @case('Entrada') text-emerald-600 @break
                                    @case('Salida')  text-rose-600 @break
                                    @default text-indigo-600 @endswitch">
                                {{ $mov->tipo === 'Salida' ? '−' : '+' }}{{ $mov->cantidad }}
                            </p>
                            @if ($mov->observaciones)
                                <p class="text-xs text-gray-500 text-right line-clamp-2 flex-1">{{ $mov->observaciones }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin movimientos.</p>
                @endforelse
            </div>

            @if ($movimientos->hasPages())
                <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                    {{ $movimientos->links() }}
                </div>
            @endif
        </div>
    @else
        {{-- ================= Vista: ALERTAS ================= --}}
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-gray-100">
                <h3 class="font-bold text-gray-900 text-sm">Productos que requieren reposición</h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    Agotados, por debajo del mínimo o con saldos negativos heredados.
                </p>
            </div>

            {{-- Desktop --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                            <th class="px-5 py-3 font-bold">Producto</th>
                            <th class="px-5 py-3 font-bold">Categoría</th>
                            <th class="px-5 py-3 font-bold text-right">Stock</th>
                            <th class="px-5 py-3 font-bold text-right">Mínimo</th>
                            <th class="px-5 py-3 font-bold">Estado</th>
                            <th class="px-5 py-3 font-bold text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($alertas as $p)
                            @php $estado = $p->estadoStock(); @endphp
                            <tr class="hover:bg-gray-50/70" wire:key="al-{{ $p->id }}">
                                <td class="px-5 py-3 font-semibold text-gray-900">{{ $p->nombre }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $p->categoria?->nombre ?? '—' }}</td>
                                <td class="px-5 py-3 text-right font-bold tabular-nums
                                    @switch($estado)
                                        @case('ok') text-emerald-600 @break
                                        @case('bajo') text-amber-600 @break
                                        @case('agotado') text-gray-400 @break
                                        @default text-rose-600 @endswitch">{{ $p->stock }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    @if ($p->stock_minimo > 0)
                                        {{ $p->stock_minimo }}
                                    @else
                                        <span class="text-gray-400 italic">sin definir</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold
                                        @switch($estado)
                                            @case('bajo') bg-amber-50 text-amber-700 @break
                                            @case('agotado') bg-gray-100 text-gray-600 @break
                                            @default bg-rose-50 text-rose-700 @endswitch">
                                        {{ \App\Models\Producto::etiquetaEstadoStock($estado) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-4">
                                        <button type="button" wire:click="abrirMinimo({{ $p->id }})"
                                                class="inline-flex items-center gap-1 text-gray-500 hover:text-indigo-600 text-sm font-semibold">
                                            <x-heroicon name="scale" class="w-4 h-4" /> Mínimo
                                        </button>
                                        <button type="button" wire:click="abrirAjuste({{ $p->id }})"
                                                class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-700 text-sm font-semibold">
                                            <x-heroicon name="arrows-right-left" class="w-4 h-4" /> Reponer
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-14 text-center text-emerald-500">
                                Todo el inventario está por encima del mínimo.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Móvil --}}
            <div class="md:hidden divide-y divide-gray-100">
                @forelse ($alertas as $p)
                    @php $estado = $p->estadoStock(); @endphp
                    <div class="p-4" wire:key="al-m-{{ $p->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900 leading-snug">{{ $p->nombre }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ $p->categoria?->nombre ?? 'Sin categoría' }} ·
                                    @if ($p->stock_minimo > 0)
                                        mínimo {{ $p->stock_minimo }}
                                    @else
                                        <span class="text-amber-600 font-semibold">mínimo sin definir</span>
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold
                                @switch($estado)
                                    @case('bajo') bg-amber-50 text-amber-700 @break
                                    @case('agotado') bg-gray-100 text-gray-600 @break
                                    @default bg-rose-50 text-rose-700 @endswitch">
                                {{ \App\Models\Producto::etiquetaEstadoStock($estado) }}
                            </span>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-gray-50 py-2.5 text-center">
                                <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Stock actual</p>
                                <p class="text-lg font-extrabold tabular-nums
                                    @switch($estado)
                                        @case('bajo') text-amber-600 @break
                                        @case('agotado') text-gray-400 @break
                                        @default text-rose-600 @endswitch">{{ $p->stock }}</p>
                            </div>
                            <button type="button" wire:click="abrirAjuste({{ $p->id }})"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-indigo-600 py-2.5 text-xs font-bold text-white min-h-[44px] active:scale-[0.98] transition">
                                <x-heroicon name="arrows-right-left" class="w-4 h-4" />
                                Reponer stock
                            </button>
                        </div>
                        <button type="button" wire:click="abrirMinimo({{ $p->id }})"
                                class="mt-2 w-full inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-200 py-2.5 text-xs font-bold text-gray-600 min-h-[44px] active:scale-[0.98] transition">
                            <x-heroicon name="scale" class="w-4 h-4" />
                            {{ $p->stock_minimo > 0 ? 'Cambiar mínimo' : 'Definir mínimo' }}
                        </button>
                    </div>
                @empty
                    <p class="py-14 px-4 text-center text-emerald-500 text-sm">Todo el inventario está por encima del mínimo.</p>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ================= Modal: stock mínimo ================= --}}
    @if ($minimoProducto > 0)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarMinimo"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Stock mínimo de reposición</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Avísate cuando el stock llegue a este número. Con
                                <span class="font-semibold">0</span> el producto no puede marcarse como bajo.
                            </p>
                        </div>
                        <button type="button" wire:click="cerrarMinimo" aria-label="Cerrar"
                                class="shrink-0 w-11 h-11 flex items-center justify-center rounded-full text-gray-400 hover:bg-gray-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <form wire:submit.prevent="guardarMinimo" class="px-6 pb-6 overflow-y-auto">
                    <label for="minimo-stock" class="block text-sm font-semibold text-gray-700">Mínimo de unidades</label>
                    <input id="minimo-stock" type="number" wire:model="minimo" inputmode="numeric" min="0" max="99999" step="1" required
                           class="mt-1 block w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                           placeholder="Ej: 5">
                    @error('minimo')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs text-gray-500 leading-relaxed">
                        No descuenta unidades: solo define el nivel a partir del cual aparece la alerta.
                    </p>

                    <div class="mt-5 flex flex-col-reverse sm:flex-row justify-end gap-2">
                        <button type="button" wire:click="cerrarMinimo"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 min-h-[44px] px-5 rounded-xl border border-gray-300 font-semibold text-gray-700">
                            <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                        </button>
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 min-h-[44px] px-5 rounded-xl bg-indigo-600 font-semibold text-white">
                            <x-heroicon name="check-circle" class="w-4 h-4" /> Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ================= Modal: ajuste ================= --}}
    @if ($showAjuste)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarAjuste"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">Ajustar inventario</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Todo ajuste queda registrado con el stock anterior y el nuevo.
                    </p>
                </div>

                <form wire:submit="guardarAjuste" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="aj-prod" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Producto *</label>
                        <select id="aj-prod" wire:model.live="ajusteProducto"
                                class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Selecciona un producto…</option>
                            @foreach ($ajustesDisponibles as $op)
                                <option value="{{ $op->id }}">
                                    {{ $op->nombre }} — stock {{ $op->stock }}
                                </option>
                            @endforeach
                        </select>
                        @error('ajusteProducto') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Contexto del producto seleccionado --}}
                    @if ($productoAjuste)
                        <div class="rounded-xl bg-gray-50 border border-gray-100 px-3.5 py-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Stock actual</span>
                                <span class="font-bold text-gray-900 tabular-nums">{{ $productoAjuste->stock }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm mt-1.5">
                                <span class="text-gray-500">Mínimo configurado</span>
                                <span class="font-bold text-gray-600 tabular-nums">{{ $productoAjuste->stock_minimo }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm mt-1.5">
                                <span class="text-gray-500">Estado</span>
                                <span class="font-bold tabular-nums
                                    @switch($productoAjuste->estadoStock())
                                        @case('ok') text-emerald-600 @break
                                        @case('bajo') text-amber-600 @break
                                        @case('agotado') text-gray-500 @break
                                        @default text-rose-600 @endswitch">
                                    {{ \App\Models\Producto::etiquetaEstadoStock($productoAjuste->estadoStock()) }}
                                </span>
                            </div>
                        </div>
                    @endif

                    <div>
                        <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Tipo de movimiento</span>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" wire:click="$set('ajusteTipo', 'Ajuste')"
                                    class="rounded-xl py-3 text-xs font-bold min-h-[48px] border-2 transition"
                                    @class([
                                        'border-indigo-500 bg-indigo-50 text-indigo-700' => $ajusteTipo === 'Ajuste',
                                        'border-gray-200 text-gray-500 hover:border-gray-300' => $ajusteTipo !== 'Ajuste',
                                    ])>
                                Conteo físico
                            </button>
                            <button type="button" wire:click="$set('ajusteTipo', 'Salida')"
                                    class="rounded-xl py-3 text-xs font-bold min-h-[48px] border-2 transition"
                                    @class([
                                        'border-rose-400 bg-rose-50 text-rose-700' => $ajusteTipo === 'Salida',
                                        'border-gray-200 text-gray-500 hover:border-gray-300' => $ajusteTipo !== 'Salida',
                                    ])>
                                Baja / merma
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="aj-cant" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">
                            {{ $ajusteTipo === 'Ajuste' ? 'Cantidad contada en bodega *' : 'Unidades a dar de baja *' }}
                        </label>
                        <input id="aj-cant" type="number" inputmode="numeric" min="0" step="1" wire:model="ajusteCantidad"
                               class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <p class="mt-1 text-xs text-gray-400">
                            {{ $ajusteTipo === 'Ajuste'
                                ? 'Escribe el total real que hay en bodega. El stock quedará en ese número.'
                                : 'Unidades que se pierden, se rompen o se devuelven al proveedor.' }}
                        </p>
                        @error('ajusteCantidad') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="aj-obs" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Observaciones</label>
                        <input id="aj-obs" type="text" wire:model="ajusteObservaciones"
                               class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Inventario físico, producto roto, devolución…" autocomplete="off">
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarAjuste"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardarAjuste"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="check-circle" class="w-5 h-5" wire:loading.remove wire:target="guardarAjuste" />
                            <span wire:loading.remove wire:target="guardarAjuste">Registrar</span>
                            <span wire:loading wire:target="guardarAjuste">Registrando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
