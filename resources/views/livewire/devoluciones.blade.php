<div class="space-y-5" @keydown.escape="$set('showForm', false); $wire.porAnular = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="trending-down" class="w-6 h-6 text-orange-600" />
                Devoluciones
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Dinero que vuelve al cliente por ventas ya realizadas
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="rounded-xl bg-orange-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-orange-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-orange-600/20">
            + Nueva devolución
        </button>
    </div>

    {{-- Aviso: no se puede reponer stock --}}
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
        <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
        <p class="text-sm text-amber-800 leading-relaxed">
            <span class="font-bold">La devolución no repone inventario.</span>
            Las ventas de esta base de datos no guardaron qué productos se vendieron
            (<code class="px-1 py-0.5 rounded bg-amber-100 text-xs font-bold">venta_detalles</code> está vacía),
            así que no se sabe qué reingresar al stock. El movimiento queda registrado
            <em>económicamente</em> y en caja; si la mercancía vuelve al almacén, usa
            <a href="{{ route('ingreso') }}" class="font-bold underline">Ingreso</a> con la Mercancía devuelta.
        </p>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Devuelto en el filtro" :valor="\App\Support\Money::cents($stats['total'])" tono="orange" icono="trending-down" />
        <x-panel.stat etiqueta="Devoluciones" :valor="$stats['cantidad']" tono="indigo" icono="receipt-percent" />
        <x-panel.stat etiqueta="Devuelto hoy" :valor="\App\Support\Money::cents($stats['hoy'])" tono="amber" icono="clock" />
        <x-panel.stat etiqueta="Ventas con devolución" :valor="$stats['ventasDevueltas']" tono="rose" icono="exclamation-triangle" />
    </div>

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Historial de devoluciones</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar cliente o motivo…"
                       class="rounded-xl border-gray-200 text-sm sm:w-52 focus:border-orange-500 focus:ring-orange-500">

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @foreach (['hoy' => 'Hoy', 'mes' => 'Mes', 'anio' => 'Año', 'todo' => 'Todo', 'custom' => 'Rango'] as $valor => $texto)
                        <button type="button" wire:click="$set('rango', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-orange-600 shadow-sm' => $rango === $valor,
                                    'text-gray-500 hover:text-gray-700'   => $rango !== $valor,
                                ])>
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($rango === 'custom')
            <div class="px-4 sm:px-5 py-3 bg-gray-50/60 border-b border-gray-100 grid grid-cols-2 gap-3">
                <div>
                    <label for="dv-desde" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Desde</label>
                    <input id="dv-desde" type="date" wire:model.live="desde"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-orange-500 focus:ring-orange-500">
                </div>
                <div>
                    <label for="dv-hasta" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Hasta</label>
                    <input id="dv-hasta" type="date" wire:model.live="hasta"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-orange-500 focus:ring-orange-500">
                </div>
            </div>
        @endif

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Fecha</th>
                        <th class="px-5 py-3 font-bold">Venta</th>
                        <th class="px-5 py-3 font-bold">Cliente</th>
                        <th class="px-5 py-3 font-bold">Motivo</th>
                        <th class="px-5 py-3 font-bold text-right">Devuelto</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($devoluciones as $d)
                        <tr class="hover:bg-gray-50/70" wire:key="dv-{{ $d->id }}">
                            <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ $d->fecha->format('d/m/Y') }}</td>
                            <td class="px-5 py-3 font-bold text-indigo-600 tabular-nums whitespace-nowrap">
                                #{{ $d->venta_id }}
                            </td>
                            <td class="px-5 py-3 text-gray-900 font-medium max-w-xs">
                                <span class="line-clamp-1">{{ $d->cliente_nombre }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-500 max-w-xs">
                                <span class="line-clamp-1">{{ $d->motivo }}</span>
                            </td>
                            <td class="px-5 py-3 text-right font-bold text-orange-600 tabular-nums whitespace-nowrap">
                                −{{ \App\Support\Money::cents($d->total_devuelto) }}
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="pedirAnular({{ $d->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Anular</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' ? 'Sin devoluciones para esta búsqueda.' : 'No hay devoluciones en este periodo.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($devoluciones as $d)
                <div class="p-4" wire:key="dv-m-{{ $d->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-indigo-600 tabular-nums">Venta #{{ $d->venta_id }}</p>
                            <p class="font-semibold text-gray-900 truncate mt-0.5">{{ $d->cliente_nombre }}</p>
                            <p class="text-xs text-gray-500 mt-1 leading-snug">{{ $d->motivo }}</p>
                            <p class="text-xs text-gray-400 mt-1">{{ $d->fecha->format('d/m/Y') }}</p>
                        </div>
                        <p class="font-bold text-orange-600 tabular-nums whitespace-nowrap shrink-0">
                            −{{ \App\Support\Money::cents($d->total_devuelto) }}
                        </p>
                    </div>

                    <button type="button" wire:click="pedirAnular({{ $d->id }})"
                            class="mt-3 w-full rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">
                        Anular devolución
                    </button>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin devoluciones.</p>
            @endforelse
        </div>

        @if ($devoluciones->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $devoluciones->links() }}
            </div>
        @endif
    </div>

    {{-- ================= Modal: registrar devolución ================= --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('showForm', false)"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">Registrar devolución</h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="dv-venta" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Venta *</label>
                        <select id="dv-venta" wire:model="ventaId"
                                class="w-full rounded-xl border-gray-200 focus:border-orange-500 focus:ring-orange-500">
                            <option value="">Selecciona la venta…</option>
                            @foreach (\App\Models\Venta::devolubles()->orderByDesc('fecha_venta')->limit(300)->get() as $v)
                                @php $saldo = $this->saldoDevoluble($v->id); @endphp
                                <option value="{{ $v->id }}" @disabled($saldo <= 0)>
                                    #{{ $v->id }} · {{ $v->cliente_nombre }} · {{ \App\Support\Money::cents($v->total) }}
                                    @if ($saldo > 0) (devolver {{ \App\Support\Money::cents($saldo) }})
                                    @else (devuelta) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('ventaId') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    @if ($ventaId !== '')
                        @php $devoluble = $this->saldoDevoluble((int) $ventaId); @endphp
                        <div class="rounded-2xl px-4 py-3 border {{ $devoluble > 0 ? 'bg-amber-50 border-amber-200' : 'bg-rose-50 border-rose-200' }}">
                            <p class="text-xs font-bold uppercase tracking-wider {{ $devoluble > 0 ? 'text-amber-700' : 'text-rose-700' }}">
                                {{ $devoluble > 0 ? 'Disponible para devolver' : 'Esta venta ya fue devuelta por completo' }}
                            </p>
                            <p class="text-xl font-bold tabular-nums {{ $devoluble > 0 ? 'text-amber-800' : 'text-rose-800' }} mt-0.5">
                                {{ \App\Support\Money::cents($devoluble) }}
                            </p>
                        </div>

                        <div>
                            <label for="dv-monto" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Monto a devolver *</label>
                            <input id="dv-monto" type="number" step="0.01" min="0" inputmode="decimal" wire:model="totalDevuelto"
                                   class="w-full rounded-xl border-gray-200 focus:border-orange-500 focus:ring-orange-500"
                                   placeholder="0.00" max="{{ $devoluble }}" @disabled($devoluble <= 0)>
                            @error('totalDevuelto') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label for="dv-motivo" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Motivo *</label>
                        <input id="dv-motivo" type="text" wire:model="motivo"
                               class="w-full rounded-xl border-gray-200 focus:border-orange-500 focus:ring-orange-500"
                               placeholder="Producto con defecto, talla incorrecta, arrepentimiento…">
                        @error('motivo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="dv-fecha" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha *</label>
                        <input id="dv-fecha" type="date" wire:model="fecha"
                               class="w-full rounded-xl border-gray-200 focus:border-orange-500 focus:ring-orange-500">
                        @error('fecha') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl bg-gray-50 p-4 cursor-pointer">
                        <input type="checkbox" wire:model="registrarEnCaja" class="rounded border-gray-300 text-orange-600 focus:ring-orange-500 mt-0.5 w-5 h-5 shrink-0">
                        <span class="text-sm">
                            <span class="font-bold text-gray-900 block">Descontar de la caja abierta</span>
                            <span class="text-gray-500 text-xs leading-relaxed">
                                Registra un movimiento tipo Devolución para que el arqueo del turno cuadre.
                            </span>
                        </span>
                    </label>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="$set('showForm', false)"
                                class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="rounded-xl bg-orange-600 py-3 font-bold text-white hover:bg-orange-700 min-h-[48px] active:scale-[0.98] transition">
                            <span wire:loading.remove wire:target="guardar">Registrar devolución</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($porAnular)
        <x-panel.confirmar
            titulo="Anular devolución"
            descripcion="Se borra la devolución y se elimina el movimiento de caja que generó, devolviendo el dinero al turno."
            confirmar="anularConfirmado"
            wire="porAnular" />
    @endif
</div>
