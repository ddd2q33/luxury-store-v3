<div class="space-y-5" @keydown.escape="cerrarFactura">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="receipt-percent" class="w-6 h-6 text-indigo-600" />
            Facturación
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Historial de ventas y factura imprimible
        </p>
    </div>

    {{-- Aviso: venta_detalles vacía --}}
    @php $sinLineas = \App\Models\VentaDetalle::count() === 0; @endphp
    @if ($sinLineas)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
            <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
            <p class="text-sm text-amber-800 leading-relaxed">
                <span class="font-bold">Las facturas no muestran productos.</span>
                La tabla <code class="px-1 py-0.5 rounded bg-amber-100 text-xs font-bold">venta_detalles</code>
                está vacía en la base de datos: ninguna de las {{ $stats['cantidad'] + $stats['anuladas'] }} ventas
                tiene líneas de detalle guardadas. La factura muestra cliente, método de pago y total, que sí existen.
                Esto viene del sistema anterior, no se puede recuperar.
            </p>
        </div>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Total facturado" :valor="\App\Support\Money::cents($stats['total'])" tono="emerald" icono="currency-dollar" />
        <x-panel.stat etiqueta="Ventas en el filtro" :valor="$stats['cantidad']" tono="indigo" icono="shopping-bag" />
        <x-panel.stat etiqueta="Ticket promedio" :valor="\App\Support\Money::cents($stats['promedio'])" tono="sky" icono="chart-line" />
        <x-panel.stat etiqueta="Ventas anuladas" :valor="$stats['anuladas']" tono="rose" icono="exclamation-triangle" />
    </div>

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Facturas emitidas</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar cliente o № de factura…"
                       class="rounded-xl border-gray-200 text-sm sm:w-56 focus:border-indigo-500 focus:ring-indigo-500">

                <select wire:model.live="estado"
                        class="rounded-xl border-gray-200 text-sm sm:w-36 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="todas">Todo estado</option>
                    @foreach (\App\Http\Livewire\Facturacion::ESTADOS as $e)
                        <option value="{{ $e }}">{{ $e }}</option>
                    @endforeach
                </select>

                <select wire:model.live="metodo"
                        class="rounded-xl border-gray-200 text-sm sm:w-36 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="todos">Todo método</option>
                    @foreach ($metodos as $m)
                        <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select>

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @foreach (['hoy' => 'Hoy', 'mes' => 'Mes', 'anio' => 'Año', 'todo' => 'Todo', 'custom' => 'Rango'] as $valor => $texto)
                        <button type="button" wire:click="$set('rango', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-indigo-600 shadow-sm' => $rango === $valor,
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
                    <label for="fa-desde" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Desde</label>
                    <input id="fa-desde" type="date" wire:model.live="desde"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="fa-hasta" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Hasta</label>
                    <input id="fa-hasta" type="date" wire:model.live="hasta"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>
        @endif

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Factura</th>
                        <th class="px-5 py-3 font-bold">Fecha</th>
                        <th class="px-5 py-3 font-bold">Cliente</th>
                        <th class="px-5 py-3 font-bold">Método</th>
                        <th class="px-5 py-3 font-bold text-right">Total</th>
                        <th class="px-5 py-3 font-bold text-right">Estado</th>
                        <th class="px-5 py-3 font-bold text-right">Factura</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($ventas as $v)
                        <tr class="hover:bg-gray-50/70 {{ $v->estado === 'Anulada' ? 'opacity-60' : '' }}" wire:key="fa-{{ $v->id }}">
                            <td class="px-5 py-3 font-bold text-indigo-600 tabular-nums whitespace-nowrap">#{{ $v->id }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ $v->fecha_venta->format('d/m/Y') }}</td>
                            <td class="px-5 py-3 text-gray-900 font-medium max-w-xs">
                                <span class="line-clamp-1">{{ $v->cliente_nombre }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $v->metodo_pago }}</td>
                            <td class="px-5 py-3 text-right font-bold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ \App\Support\Money::cents($v->total) }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                @if ($v->devoluciones_count > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-bold text-orange-700">
                                        {{ $v->devoluciones_count }} devolución(es)
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold
                                        {{ $v->estado === 'Anulada' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                                        {{ $v->estado }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="verFactura({{ $v->id }})"
                                        class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Ver</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' || $estado !== 'todas' || $metodo !== 'todos' ? 'Sin facturas para este filtro.' : 'No hay ventas en este periodo.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($ventas as $v)
                <div class="p-4 {{ $v->estado === 'Anulada' ? 'opacity-60' : '' }}" wire:key="fa-m-{{ $v->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-indigo-600 tabular-nums text-xs">Factura #{{ $v->id }}</p>
                            <p class="font-semibold text-gray-900 truncate mt-0.5">{{ $v->cliente_nombre }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ $v->fecha_venta->format('d/m/Y') }} · {{ $v->metodo_pago }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::cents($v->total) }}</p>
                            @if ($v->devoluciones_count > 0)
                                <p class="text-[10px] font-bold text-orange-600">{{ $v->devoluciones_count }} devolución(es)</p>
                            @else
                                <p class="text-[10px] font-bold {{ $v->estado === 'Anulada' ? 'text-rose-600' : 'text-emerald-600' }}">
                                    {{ $v->estado }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <button type="button" wire:click="verFactura({{ $v->id }})"
                            class="mt-3 w-full rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">
                        Ver factura
                    </button>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin ventas.</p>
            @endforelse
        </div>

        @if ($ventas->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $ventas->links() }}
            </div>
        @endif
    </div>

    {{-- ================= Modal: factura ================= --}}
    @if ($factura)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarFactura"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-5 sm:p-6 pb-4 flex items-start justify-between gap-3 border-b border-gray-100">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Factura #{{ $factura->id }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ $factura->fecha_venta->format('d/m/Y h:i A') }}
                        </p>
                    </div>
                    <button type="button" wire:click="cerrarFactura"
                            class="shrink-0 w-10 h-10 rounded-xl bg-gray-100 text-gray-500 flex items-center justify-center min-h-[44px] min-w-[44px]">
                        <x-heroicon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="px-5 sm:px-6 py-5 overflow-y-auto flex-1 space-y-4">
                    <div class="rounded-2xl bg-gray-50 p-4">
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Cliente</p>
                        <p class="font-bold text-gray-900">{{ $factura->cliente_nombre }}</p>
                    </div>

                    @if ($factura->detalles->isNotEmpty())
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Productos</p>
                            <div class="space-y-2">
                                @foreach ($factura->detalles as $d)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-3 py-2">
                                        <span class="text-sm text-gray-700">{{ $d->producto_nombre }}</span>
                                        <span class="text-xs text-gray-400 tabular-nums shrink-0">
                                            {{ $d->cantidad }} × {{ \App\Support\Money::cents($d->precio_unitario) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="rounded-xl border border-dashed border-amber-300 bg-amber-50/60 px-4 py-3">
                            <p class="text-xs text-amber-800 leading-relaxed">
                                <span class="font-bold">Sin detalle de productos.</span>
                                Esta venta no guardó líneas en <code class="text-[10px]">venta_detalles</code>,
                                así que solo se puede mostrar el encabezado.
                            </p>
                        </div>
                    @endif

                    <div class="rounded-2xl border border-gray-200 divide-y divide-gray-100">
                        <div class="flex items-center justify-between px-4 py-2.5">
                            <span class="text-sm text-gray-500">Método de pago</span>
                            <span class="text-sm font-semibold text-gray-900">{{ $factura->metodo_pago }}</span>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5">
                            <span class="text-sm text-gray-500">Recibido</span>
                            <span class="text-sm font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::cents($factura->monto_recibido) }}</span>
                        </div>
                        <div class="flex items-center justify-between px-4 py-2.5">
                            <span class="text-sm text-gray-500">Cambio</span>
                            <span class="text-sm font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::cents($factura->cambio) }}</span>
                        </div>
                        <div class="flex items-center justify-between px-4 py-3 bg-indigo-50/60">
                            <span class="text-sm font-bold text-indigo-900">Total</span>
                            <span class="text-lg font-bold text-indigo-700 tabular-nums">{{ \App\Support\Money::cents($factura->total) }}</span>
                        </div>
                    </div>

                    @if ($factura->estado === 'Anulada' && $factura->motivo)
                        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                            <p class="text-xs font-bold text-rose-800 uppercase tracking-wider mb-1">Venta anulada</p>
                            <p class="text-sm text-rose-700">{{ $factura->motivo }}</p>
                        </div>
                    @endif

                    @if ($factura->devoluciones->isNotEmpty())
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Devoluciones</p>
                            @foreach ($factura->devoluciones as $d)
                                <div class="rounded-xl border border-orange-200 bg-orange-50/60 px-4 py-3 mb-2">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-semibold text-orange-800">
                                            −{{ \App\Support\Money::cents($d->total_devuelto) }}
                                        </span>
                                        <span class="text-xs text-orange-600">{{ $d->fecha->format('d/m/Y') }}</span>
                                    </div>
                                    <p class="text-xs text-orange-700 mt-1">{{ $d->motivo }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="px-5 sm:px-6 pb-6 pt-2 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="cerrarFactura"
                            class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cerrar</button>
                    <button type="button" onclick="window.print()"
                            class="rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                        Imprimir
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
