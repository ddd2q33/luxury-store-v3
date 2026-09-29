<div class="space-y-5"
     @keydown.window.escape="['showApertura','showCerrar','showMovimiento','showCliente','showTicket','showDetalle','showEliminar'].forEach(p => { if ($wire[p]) $wire[p] = false })"
     x-data>

    {{-- Toasts --}}
    <div x-data="{ visible: false, mensaje: '', tipo: 'ok', t: null }"
         @caja-toast.window="mensaje = $event.detail.mensaje; tipo = $event.detail.tipo; visible = true; clearTimeout(t); t = setTimeout(() => visible = false, 3500)"
         x-show="visible" x-cloak x-transition.opacity.duration.200ms
         class="fixed bottom-20 lg:bottom-6 right-4 z-[70] max-w-xs">
        <div class="rounded-2xl px-4 py-3 shadow-2xl text-sm font-medium flex items-start gap-2.5 border"
             :class="tipo === 'ok' ? 'bg-emerald-600 border-emerald-500 text-white' : 'bg-rose-600 border-rose-500 text-white'">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-white/20 text-xs shrink-0 font-bold"
                  x-text="tipo === 'ok' ? '✓' : '✕'"></span>
            <span x-text="mensaje"></span>
        </div>
    </div>

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="banknotes" class="w-6 h-6 text-indigo-600" />
            Caja
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Apertura, cierre de turno y movimientos de efectivo.
        </p>
    </div>

    {{-- ================= Barra de turno ================= --}}
    <div class="rounded-2xl bg-gray-900 text-white p-4 sm:p-5">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <span class="flex items-center justify-center w-11 h-11 rounded-2xl bg-white/10 shrink-0">
                    <x-heroicon name="banknotes" class="w-6 h-6 text-emerald-400" />
                </span>
                <div class="min-w-0">
                    @if ($caja)
                        <div class="flex items-center gap-2">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-400"></span>
                            </span>
                            <p class="font-bold truncate">Turno abierto · {{ $turno }}</p>
                        </div>
                        <p class="text-xs text-gray-400 mt-0.5">Desde {{ $caja->fecha_apertura?->format('d/m/Y H:i') }} · <span class="capitalize">{{ $fechaHoy }}</span></p>
                    @else
                        <p class="font-bold">Caja cerrada</p>
                        <p class="text-xs text-gray-400 mt-0.5 capitalize">{{ $fechaHoy }}</p>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-4 sm:gap-6">
                <div class="text-right">
                    <p class="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Saldo actual</p>
                    <p class="text-2xl font-bold tabular-nums text-emerald-400">{{ \App\Support\Money::format($totales['saldo'] ?? 0) }}</p>
                </div>
                @if ($caja)
                    <button type="button" wire:click="confirmarCerrar"
                            class="rounded-xl bg-rose-500/15 border border-rose-500/40 text-rose-300 px-4 py-2.5 text-sm font-semibold hover:bg-rose-500/25 transition-colors min-h-[44px]">
                        Cerrar turno
                    </button>
                @endif
            </div>
        </div>

        @if ($caja)
            <div class="grid grid-cols-3 gap-2 sm:gap-4 mt-4 pt-4 border-t border-white/10">
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Ventas</p>
                    <p class="font-bold tabular-nums text-sm sm:text-base text-emerald-300">{{ \App\Support\Money::format($totales['ventas']) }}</p>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Ingresos</p>
                    <p class="font-bold tabular-nums text-sm sm:text-base text-indigo-300">{{ \App\Support\Money::format($totales['ingresos']) }}</p>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Egresos</p>
                    <p class="font-bold tabular-nums text-sm sm:text-base text-rose-300">{{ \App\Support\Money::format($totales['egresos']) }}</p>
                </div>
            </div>
        @endif
    </div>

    @if ($caja)
        {{-- ================= Tabs ================= --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-1.5 flex gap-1">
            @foreach (['venta' => 'Venta', 'movimientos' => 'Movimientos', 'historial' => 'Historial'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                        @class([
                            'flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold min-h-[44px] transition-all',
                            'bg-gray-900 text-white shadow' => $tab === $key,
                            'text-gray-500 hover:bg-gray-100 hover:text-gray-900' => $tab !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'venta')
            {{-- ================= POS ================= --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start">
                {{-- Izquierda: scanner + carrito --}}
                <div class="lg:col-span-3 space-y-4">
                    <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                    <x-heroicon name="archive-box" class="w-4 h-4" /> Escáner
                                </label>
                                <input type="text" wire:model="codigoBarras" wire:keydown.enter="agregarPorCodigo"
                                       id="input-scanner" inputmode="search" autocomplete="off"
                                       placeholder="Escanea · Enter…"
                                       class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 font-mono">
                            </div>
                            <div class="relative">
                                <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                    <x-heroicon name="shopping-cart" class="w-4 h-4" /> Buscar producto
                                </label>
                                <input type="text" wire:model.debounce.300ms="search" autocomplete="off"
                                       placeholder="Nombre…"
                                       class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                                @if (count($resultados) > 0)
                                    <div class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
                                        @foreach ($resultados as $r)
                                            <button type="button" wire:click="agregarProducto({{ $r['id'] }})"
                                                    class="w-full text-left px-4 py-3 hover:bg-indigo-50 flex items-center justify-between gap-3 border-b border-gray-50 last:border-0">
                                                <span class="text-sm text-gray-900 truncate font-medium">{{ $r['nombre'] }}</span>
                                                <span class="text-xs shrink-0 tabular-nums {{ $r['stock'] > 0 ? 'text-gray-500' : 'text-rose-600 font-semibold' }}">{{ $r['stock'] }} und · {{ \App\Support\Money::format($r['precio']) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Carrito --}}
                    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                        <div class="flex items-center justify-between px-4 sm:px-5 py-3.5 border-b border-gray-100">
                            <h3 class="font-bold text-gray-900">
                                Carrito
                                @if (count($carrito) > 0)
                                    <span class="ml-1.5 inline-flex items-center justify-center rounded-full bg-indigo-600 text-white text-xs font-bold min-w-[20px] h-5 px-1.5">{{ count($carrito) }}</span>
                                @endif
                            </h3>
                            <div class="flex items-center gap-3">
                                <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
                                    <input type="checkbox" wire:model="ventaManual" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    Total manual
                                </label>
                                @if (count($carrito) > 0 && ! $ventaManual)
                                    <button type="button" wire:click="limpiarCarrito" wire:loading.attr="disabled"
                                            class="text-xs font-semibold text-rose-600 hover:text-rose-700">
                                        Vaciar
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($ventaManual)
                            <div class="p-4 sm:p-5">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total de la venta</p>
                                        <p class="text-3xl font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($subtotal) }}</p>
                                        <p class="mt-1 text-xs text-gray-400">Sin inventario — para ventas rápidas o costos varios.</p>
                                    </div>
                                </div>

                                {{-- Numpad --}}
                                <div class="mt-4 grid grid-cols-4 gap-2 max-w-xs">
                                    @foreach (['1', '2', '3', '←', '4', '5', '6', 'C', '7', '8', '9', '000'] as $tecla)
                                        <button type="button" wire:click="numpad('{{ $tecla }}')"
                                                class="rounded-xl bg-gray-100 hover:bg-gray-200 active:scale-95 transition text-gray-800 font-bold py-3 min-h-[48px] text-sm tabular-nums">
                                            {{ $tecla === '←' ? '⌫' : $tecla }}
                                        </button>
                                    @endforeach
                                    <button type="button" wire:click="numpad('0')" class="col-span-2 rounded-xl bg-gray-100 hover:bg-gray-200 active:scale-95 transition text-gray-800 font-bold py-3 min-h-[48px] tabular-nums">0</button>
                                    <button type="button" wire:click="numpadSumar(1000)" class="col-span-2 rounded-xl bg-indigo-50 text-indigo-700 hover:bg-indigo-100 active:scale-95 transition font-bold py-3 min-h-[48px] text-sm">+1.000</button>
                                </div>
                            </div>
                        @elseif (count($carrito) === 0)
                            <div class="py-12 text-center px-4">
                                <span class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gray-100 mb-3">
                                    <x-heroicon name="shopping-cart" class="w-7 h-7 text-gray-300" />
                                </span>
                                <p class="text-sm font-medium text-gray-500">Carrito vacío</p>
                                <p class="text-xs text-gray-400 mt-1">Escanea un código o busca un producto para agregarlo</p>
                            </div>
                        @else
                            <ul class="divide-y divide-gray-100">
                                @foreach ($carrito as $i => $item)
                                    <li class="py-3 px-4 sm:px-5 flex items-center gap-3 {{ $item['stock'] <= 0 ? 'bg-rose-50/50' : '' }}">
                                        <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 text-xs font-bold shrink-0">{{ $i + 1 }}</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-gray-900 truncate">{{ $item['nombre'] }}</p>
                                            <p class="text-xs text-gray-500 tabular-nums">{{ \App\Support\Money::format($item['precio']) }} c/u · stock {{ $item['stock'] }}</p>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button type="button" wire:click="cambiarCantidad({{ $i }}, -1)" aria-label="Restar"
                                                    class="w-9 h-9 rounded-lg bg-gray-100 text-gray-700 font-bold hover:bg-gray-200 active:scale-95 transition">−</button>
                                            <span class="w-9 text-center text-sm font-bold tabular-nums" wire:loading.remove wire:target="cambiarCantidad">{{ $item['cantidad'] }}</span>
                                            <button type="button" wire:click="cambiarCantidad({{ $i }}, 1)" aria-label="Sumar"
                                                    class="w-9 h-9 rounded-lg bg-gray-100 text-gray-700 font-bold hover:bg-gray-200 active:scale-95 transition">+</button>
                                        </div>
                                        <span class="w-24 text-right text-sm font-bold text-gray-900 tabular-nums shrink-0 hidden sm:block">
                                            {{ \App\Support\Money::format($item['cantidad'] * $item['precio']) }}
                                        </span>
                                        <button type="button" wire:click="quitarProducto({{ $i }})" aria-label="Quitar"
                                                class="w-9 h-9 rounded-lg text-rose-400 hover:bg-rose-50 hover:text-rose-600 active:scale-95 transition shrink-0 font-bold">✕</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Derecha: cobro --}}
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5 space-y-4 lg:sticky lg:top-20">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-widest">Total a cobrar</p>
                            <p class="text-4xl font-bold text-gray-900 tabular-nums mt-1" wire:loading.remove wire:target="numpad, numpadSumar">{{ \App\Support\Money::format($subtotal) }}</p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Método de pago</p>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (['Efectivo' => 'banknotes', 'Transferencia/QR' => 'credit-card', 'Datafono' => 'credit-card', 'Nequi' => 'currency-dollar'] as $metodo => $icono)
                                    <button type="button" wire:click="$set('metodoPago', '{{ $metodo }}')"
                                            @class([
                                                'flex items-center gap-2 rounded-xl border px-3 py-2.5 text-sm font-semibold min-h-[44px] transition-all text-left',
                                                'border-indigo-600 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600' => $metodoPago === $metodo,
                                                'border-gray-200 text-gray-600 hover:border-gray-300 hover:bg-gray-50' => $metodoPago !== $metodo,
                                            ])>
                                        <x-heroicon :name="$icono" class="w-4.5 h-4.5 w-[18px] h-[18px] shrink-0" />
                                        <span class="truncate">{{ $metodo }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @if ($metodoPago === 'Efectivo' && ! $ventaManual && count($carrito) > 0)
                            @if (count($montosRapidos) > 0)
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Billete recibido</p>
                                    <div class="grid grid-cols-4 gap-2">
                                        @foreach ($montosRapidos as $monto)
                                            <button type="button" wire:click="$set('montoRecibido', {{ $monto }})"
                                                    @class([
                                                        'rounded-xl py-2.5 text-xs font-bold tabular-nums min-h-[44px] transition-all active:scale-95',
                                                        'bg-indigo-600 text-white' => (int) $montoRecibido === (int) $monto,
                                                        'bg-gray-100 text-gray-700 hover:bg-gray-200' => (int) $montoRecibido !== (int) $monto,
                                                    ])>
                                                {{ number_format($monto / 1000, 0) }}k
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif

                        @if ($metodoPago === 'Efectivo')
                            <div>
                                <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5 block">Monto recibido</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2.5 text-gray-400 font-semibold">$</span>
                                    <input type="number" wire:model.debounce.300ms="montoRecibido" inputmode="numeric" min="0"
                                           class="w-full rounded-xl border-gray-200 pl-7 pr-3 py-2.5 focus:border-indigo-500 focus:ring-indigo-500 font-semibold"
                                           placeholder="0">
                                </div>
                                <div class="mt-2 flex items-center justify-between rounded-xl bg-emerald-50 px-3.5 py-2.5">
                                    <span class="text-sm font-medium text-emerald-700">Cambio</span>
                                    <span class="text-lg font-bold text-emerald-700 tabular-nums">{{ \App\Support\Money::format($cambio) }}</span>
                                </div>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 gap-2.5">
                            <button type="button" wire:click="$set('showCliente', true)"
                                    class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-left text-sm hover:border-indigo-300 min-h-[44px] flex items-center justify-between">
                                <span class="flex items-center gap-2 min-w-0">
                                    <x-heroicon name="user-circle" class="w-5 h-5 text-gray-400 shrink-0" />
                                    <span class="truncate font-medium text-gray-900">{{ $clienteNombre }}</span>
                                </span>
                                <span class="text-gray-400 text-xs shrink-0 ml-2">cambiar</span>
                            </button>

                            <input type="text" wire:model.debounce.300ms="motivo" aria-label="Motivo"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                   placeholder="Motivo (Venta general)">
                        </div>

                        <button type="button" wire:click="registrarVenta" wire:loading.attr="disabled" wire:target="registrarVenta"
                                @class([
                                    'w-full rounded-2xl py-4 font-bold text-white transition-all min-h-[52px] shadow-lg',
                                    'bg-emerald-600 hover:bg-emerald-700 active:scale-[0.99] shadow-emerald-600/25' => $puedeCobrar,
                                    'bg-gray-300 cursor-not-allowed' => ! $puedeCobrar,
                                ])>
                            <span wire:loading.remove wire:target="registrarVenta">
                                Cobrar {{ \App\Support\Money::format($subtotal) }}
                            </span>
                            <span wire:loading wire:target="registrarVenta">Procesando…</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Barra de cobro fija móvil --}}
            <div class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur border-t border-gray-200 px-4 py-3 flex items-center gap-3 pb-[max(12px,env(safe-area-inset-bottom))]">
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Total</p>
                    <p class="font-bold text-gray-900 tabular-nums leading-tight">{{ \App\Support\Money::format($subtotal) }}</p>
                </div>
                <button type="button" wire:click="registrarVenta" wire:loading.attr="disabled" wire:target="registrarVenta"
                        @class([
                            'flex-1 rounded-xl py-3 font-bold text-white min-h-[48px] transition-colors',
                            'bg-emerald-600 active:bg-emerald-700' => $puedeCobrar,
                            'bg-gray-300 cursor-not-allowed' => ! $puedeCobrar,
                        ])>
                    {{ $puedeCobrar ? 'Cobrar' : 'Carrito vacío' }}
                </button>
            </div>
            <div class="lg:hidden h-20"></div>

        @elseif ($tab === 'movimientos')
            {{-- ================= Movimientos ================= --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                    <h3 class="font-bold text-gray-900">Movimientos del turno</h3>
                    <button type="button" wire:click="$set('showMovimiento', true)"
                            class="rounded-xl bg-gray-900 text-white px-4 py-2.5 text-sm font-semibold hover:bg-gray-800 min-h-[44px] active:scale-[0.98] transition">
                        + Nuevo movimiento
                    </button>
                </div>

                {{-- Filtros --}}
                <div class="flex gap-1.5 mb-4 overflow-x-auto pb-1 -mx-1 px-1">
                    @foreach (['todos' => 'Todos', 'venta' => 'Ventas', 'ingreso' => 'Ingresos', 'egreso' => 'Egresos'] as $key => $label)
                        <button type="button" wire:click="$set('filtroMov', '{{ $key }}')"
                                @class([
                                    'rounded-full px-3.5 py-2 text-xs font-bold whitespace-nowrap min-h-[38px] transition-colors',
                                    'bg-gray-900 text-white' => $filtroMov === $key,
                                    'bg-gray-100 text-gray-600 hover:bg-gray-200' => $filtroMov !== $key,
                                ])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- Tabla desktop --}}
                <div class="hidden md:block">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200">
                                <th class="py-2.5 pr-4 font-bold">Hora</th>
                                <th class="py-2.5 pr-4 font-bold">Tipo</th>
                                <th class="py-2.5 pr-4 font-bold">Descripción</th>
                                <th class="py-2.5 pr-4 font-bold">Método</th>
                                <th class="py-2.5 font-bold text-right">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($movimientos as $mov)
                                <tr class="hover:bg-gray-50/70">
                                    <td class="py-3 pr-4 text-gray-500 tabular-nums whitespace-nowrap">{{ $mov->fecha->format('H:i') }}</td>
                                    <td class="py-3 pr-4">
                                        @php $badge = match($mov->tipo) { 'venta' => 'bg-emerald-100 text-emerald-700', 'ingreso' => 'bg-indigo-100 text-indigo-700', 'devolucion' => 'bg-amber-100 text-amber-700', default => 'bg-rose-100 text-rose-700' }; @endphp
                                        <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-bold capitalize {{ $badge }}">{{ $mov->tipo }}</span>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-900">{{ $mov->descripcion }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $mov->metodo_pago }}</td>
                                    <td class="py-3 text-right font-bold text-gray-900 tabular-nums">{{ ($mov->tipo === 'egreso' || $mov->tipo === 'devolucion') ? '−' : '' }}{{ \App\Support\Money::format($mov->monto) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-10 text-center text-gray-400">Sin movimientos en este filtro.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Tarjetas móvil --}}
                <div class="md:hidden space-y-2">
                    @forelse ($movimientos as $mov)
                        <div class="rounded-xl border border-gray-200 p-3.5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-bold capitalize {{ match($mov->tipo) { 'venta' => 'bg-emerald-100 text-emerald-700', 'ingreso' => 'bg-indigo-100 text-indigo-700', 'devolucion' => 'bg-amber-100 text-amber-700', default => 'bg-rose-100 text-rose-700' } }}">{{ $mov->tipo }}</span>
                                <span class="font-bold text-gray-900 tabular-nums {{ in_array($mov->tipo, ['egreso', 'devolucion']) ? 'text-rose-600' : '' }}">{{ in_array($mov->tipo, ['egreso', 'devolucion']) ? '−' : '' }}{{ \App\Support\Money::format($mov->monto) }}</span>
                            </div>
                            <p class="mt-1.5 text-sm text-gray-900">{{ $mov->descripcion }}</p>
                            <p class="mt-0.5 text-xs text-gray-400">{{ $mov->fecha->format('d/m H:i') }} · {{ $mov->metodo_pago }}</p>
                        </div>
                    @empty
                        <p class="py-10 text-center text-gray-400">Sin movimientos en este filtro.</p>
                    @endforelse
                </div>

                <div class="mt-4">{{ $movimientos->links() }}</div>
            </div>

        @else
            {{-- ================= Historial ================= --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                    <h3 class="font-bold text-gray-900">Ventas recientes</h3>
                    <input type="search" wire:model.debounce.300ms="searchHistorial" placeholder="Cliente o #venta…"
                           class="rounded-xl border-gray-200 text-sm sm:w-56 focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="hidden md:block">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200">
                                <th class="py-2.5 pr-4 font-bold">#</th>
                                <th class="py-2.5 pr-4 font-bold">Cliente</th>
                                <th class="py-2.5 pr-4 font-bold">Fecha</th>
                                <th class="py-2.5 pr-4 font-bold">Método</th>
                                <th class="py-2.5 pr-4 font-bold text-right">Total</th>
                                <th class="py-2.5 font-bold text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($historial as $venta)
                                <tr class="hover:bg-gray-50/70">
                                    <td class="py-3 pr-4 text-gray-400 tabular-nums">{{ $venta->id }}</td>
                                    <td class="py-3 pr-4 font-semibold text-gray-900">{{ $venta->cliente_nombre ?: 'Consumidor Final' }}</td>
                                    <td class="py-3 pr-4 text-gray-500 whitespace-nowrap tabular-nums">{{ $venta->fecha_venta->format('d/m/Y H:i') }}</td>
                                    <td class="py-3 pr-4"><span class="text-xs rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{{ $venta->metodo_pago }}</span></td>
                                    <td class="py-3 pr-4 text-right font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($venta->total) }}</td>
                                    <td class="py-3 text-right whitespace-nowrap">
                                        <button type="button" wire:click="verVenta({{ $venta->id }})" class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Ver</button>
                                        <span class="text-gray-200 mx-1.5">·</span>
                                        <button type="button" wire:click="pedirEliminarVenta({{ $venta->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Anular</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-10 text-center text-gray-400">Sin resultados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="md:hidden space-y-2">
                    @forelse ($historial as $venta)
                        <div class="rounded-xl border border-gray-200 p-3.5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-semibold text-gray-900 truncate">{{ $venta->cliente_nombre ?: 'Consumidor Final' }}</span>
                                <span class="font-bold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::format($venta->total) }}</span>
                            </div>
                            <div class="mt-1 flex items-center justify-between text-xs text-gray-400">
                                <span>#{{ $venta->id }} · {{ $venta->metodo_pago }}</span>
                                <span class="tabular-nums">{{ $venta->fecha_venta->format('d/m H:i') }}</span>
                            </div>
                            <div class="mt-2.5 flex gap-2">
                                <button type="button" wire:click="verVenta({{ $venta->id }})" class="flex-1 rounded-lg bg-gray-100 py-2.5 text-sm font-semibold text-gray-700 min-h-[44px] active:scale-[0.98] transition">Ver</button>
                                <button type="button" wire:click="pedirEliminarVenta({{ $venta->id }})" class="flex-1 rounded-lg bg-rose-50 py-2.5 text-sm font-semibold text-rose-600 min-h-[44px] active:scale-[0.98] transition">Anular</button>
                            </div>
                        </div>
                    @empty
                        <p class="py-10 text-center text-gray-400">Sin resultados.</p>
                    @endforelse
                </div>
            </div>
        @endif
    @else
        {{-- ================= Sin caja abierta ================= --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 flex flex-col">
                <span class="flex items-center justify-center w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 mb-4">
                    <x-heroicon name="banknotes" class="w-6 h-6" />
                </span>
                <h3 class="text-lg font-bold text-gray-900">Abrir caja</h3>
                <p class="text-sm text-gray-500 mt-1 mb-5">Define el efectivo con el que inicias el turno.</p>
                <div class="relative">
                    <span class="absolute left-3.5 top-3 text-gray-400 font-bold">$</span>
                    <input type="number" wire:model="saldoInicial" inputmode="numeric" min="0"
                           class="w-full rounded-xl border-gray-200 pl-8 py-3 text-lg font-bold focus:border-emerald-500 focus:ring-emerald-500"
                           placeholder="0">
                </div>
                @error('saldoInicial') <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="flex gap-2 mt-3">
                    @foreach ([0, 50000, 100000, 200000] as $monto)
                        <button type="button" wire:click="$set('saldoInicial', {{ $monto }})"
                                class="flex-1 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-bold py-2.5 min-h-[40px] tabular-nums">
                            {{ $monto === 0 ? '$0' : number_format($monto / 1000, 0) . 'k' }}
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="abrirCaja" wire:loading.attr="disabled" wire:target="abrirCaja"
                        class="mt-4 w-full rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 min-h-[52px] shadow-lg shadow-emerald-600/25 active:scale-[0.99] transition">
                    <span wire:loading.remove wire:target="abrirCaja">Abrir caja</span>
                    <span wire:loading wire:target="abrirCaja">Abriendo…</span>
                </button>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 flex flex-col">
                <h3 class="text-lg font-bold text-gray-900">Resumen de hoy</h3>
                <div class="grid grid-cols-2 gap-3 mt-4 flex-1">
                    <div class="rounded-2xl bg-emerald-50 p-4 flex flex-col justify-center">
                        <p class="text-xs font-semibold text-emerald-600 uppercase tracking-wide">Ventas de hoy</p>
                        <p class="text-2xl font-bold text-emerald-700 tabular-nums mt-1">{{ \App\Support\Money::format($ventasHoy['total']) }}</p>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 flex flex-col justify-center">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Transacciones</p>
                        <p class="text-2xl font-bold text-gray-900 tabular-nums mt-1">{{ $ventasHoy['cantidad'] }}</p>
                    </div>
                </div>
                <button type="button" wire:click="reabrirCaja"
                        class="mt-4 w-full rounded-2xl bg-amber-50 border border-amber-200 text-amber-700 font-semibold py-3.5 min-h-[52px] hover:bg-amber-100 active:scale-[0.99] transition">
                    Reabrir última caja cerrada
                </button>
            </div>
        </div>
    @endif

    {{-- ================= Modal: cierre ================= --}}
    @if ($showCerrar && $totalesCierre)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" x-data x-show="true">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('showCerrar', false)"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
                <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                <h3 class="text-lg font-bold text-gray-900">Cerrar turno</h3>
                <p class="text-sm text-gray-500 mt-1">Se guardará el saldo esperado del turno.</p>
                <div class="mt-4 rounded-2xl bg-gray-50 p-4 space-y-2.5 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Saldo inicial</span><span class="font-semibold tabular-nums">{{ \App\Support\Money::format($caja->saldo_inicial) }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Ventas</span><span class="font-semibold text-emerald-700 tabular-nums">+ {{ \App\Support\Money::format($totalesCierre['ventas']) }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Ingresos</span><span class="font-semibold text-indigo-700 tabular-nums">+ {{ \App\Support\Money::format($totalesCierre['ingresos']) }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Egresos</span><span class="font-semibold text-rose-700 tabular-nums">− {{ \App\Support\Money::format($totalesCierre['egresos']) }}</span></div>
                    <div class="flex justify-between border-t border-gray-200 pt-2.5 mt-2.5"><span class="font-bold text-gray-900">Saldo esperado</span><span class="font-bold text-xl tabular-nums">{{ \App\Support\Money::format($totalesCierre['saldo']) }}</span></div>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="$set('showCerrar', false)" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                    <button type="button" wire:click="cerrarCaja" class="rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px] active:scale-[0.98] transition">Cerrar turno</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Modal: movimiento manual ================= --}}
    @if ($showMovimiento)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('showMovimiento', false)"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
                <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                <h3 class="text-lg font-bold text-gray-900">Nuevo movimiento</h3>
                <div class="mt-4 space-y-3.5">
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['ingreso' => 'Ingreso', 'egreso' => 'Egreso', 'devolucion' => 'Devolución'] as $key => $label)
                            <button type="button" wire:click="$set('movTipo', '{{ $key }}')"
                                    @class([
                                        'rounded-xl py-2.5 text-sm font-semibold min-h-[44px] transition-all',
                                        'bg-gray-900 text-white' => $movTipo === $key,
                                        'bg-gray-100 text-gray-600' => $movTipo !== $key,
                                    ])>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <div class="relative">
                        <span class="absolute left-3.5 top-2.5 text-gray-400 font-bold">$</span>
                        <input type="number" wire:model="movMonto" inputmode="numeric" min="0"
                               class="w-full rounded-xl border-gray-200 pl-8 py-2.5 text-lg font-bold" placeholder="0">
                        @error('movMonto') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5 block">Método</label>
                        <select wire:model="movMetodo" class="w-full rounded-xl border-gray-200">
                            @foreach (['Efectivo', 'Transferencia/QR', 'Datafono', 'Nequi'] as $m)
                                <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5 block">Motivo</label>
                        <input type="text" wire:model="movMotivo" class="w-full rounded-xl border-gray-200"
                               placeholder="Ej: base, domo, pago proveedor…">
                    </div>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="$set('showMovimiento', false)" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Cancelar</button>
                    <button type="button" wire:click="registrarMovimiento" class="rounded-xl bg-gray-900 py-3 font-bold text-white min-h-[48px]">Registrar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Modal: cliente ================= --}}
    @if ($showCliente)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="clienteFinal"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
                <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                <h3 class="text-lg font-bold text-gray-900">Cliente</h3>
                <button type="button" wire:click="clienteFinal"
                        class="mt-3 w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 min-h-[48px] flex items-center gap-2.5">
                    <x-heroicon name="user-circle" class="w-5 h-5 text-gray-400" />
                    Consumidor Final (sin datos)
                </button>
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2.5">Cliente nuevo</p>
                    <input type="text" wire:model="nuevoClienteNombre" placeholder="Nombre completo"
                           class="w-full rounded-xl border-gray-200 mb-2" autocomplete="off">
                    @error('nuevoClienteNombre') <p class="mb-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    <input type="tel" wire:model="nuevoClienteTelefono" placeholder="Teléfono (opcional)" inputmode="tel"
                           class="w-full rounded-xl border-gray-200 mb-3" autocomplete="off">
                    <button type="button" wire:click="crearCliente"
                            class="w-full rounded-xl bg-indigo-600 text-white py-3 font-bold hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                        Crear y usar
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Modal: ticket recibo ================= --}}
    @if ($showTicket && $ticket)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeTicket"></div>
            <div class="relative w-full sm:max-w-sm bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl overflow-hidden">
                <div class="bg-emerald-600 text-white text-center pt-7 pb-6 px-6">
                    <span class="mx-auto flex items-center justify-center w-14 h-14 rounded-full bg-white/20 text-2xl mb-2">✓</span>
                    <h3 class="text-lg font-bold">Venta registrada</h3>
                    <p class="text-sm text-emerald-100">Venta #{{ $ticket['id'] }} · {{ $ticket['fecha'] }}</p>
                </div>
                <div class="p-6 text-center">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Total</p>
                    <p class="text-4xl font-bold text-gray-900 tabular-nums mt-0.5">{{ \App\Support\Money::format($ticket['total']) }}</p>
                    <div class="mt-3 rounded-xl bg-gray-50 py-2.5 px-4 flex items-center justify-between text-sm">
                        <span class="text-gray-500">{{ $ticket['metodo_pago'] }} · {{ $ticket['cliente'] }}</span>
                        @if ($ticket['metodo_pago'] === 'Efectivo')
                            <span class="font-bold text-emerald-600 tabular-nums">Cambio {{ \App\Support\Money::format($ticket['cambio']) }}</span>
                        @endif
                    </div>
                    <button type="button" wire:click="closeTicket"
                            class="mt-5 w-full rounded-2xl bg-gray-900 text-white py-3.5 font-bold min-h-[52px] active:scale-[0.99] transition">
                        Nueva venta
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Modal: detalle de venta ================= --}}
    @if ($showDetalle && $ventaDetalle)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeDetalle"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[88vh] flex flex-col">
                <div class="p-6 pb-4 border-b border-gray-100">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Venta #{{ $ventaDetalle['id'] }}</h3>
                            <p class="text-sm text-gray-500 mt-0.5">{{ $ventaDetalle['fecha'] }} · {{ $ventaDetalle['cliente'] }}</p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-bold text-gray-600 shrink-0">{{ $ventaDetalle['metodo_pago'] }}</span>
                    </div>
                </div>
                <div class="p-6 overflow-y-auto flex-1">
                    @if (count($ventaDetalle['detalles']) > 0)
                        <ul class="divide-y divide-gray-100 text-sm">
                            @foreach ($ventaDetalle['detalles'] as $d)
                                <li class="py-3 flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-gray-900 font-medium truncate">{{ $d['nombre'] }}</p>
                                        <p class="text-xs text-gray-400 tabular-nums">{{ $d['cantidad'] }} × {{ \App\Support\Money::format($d['precio']) }}</p>
                                    </div>
                                    <span class="font-bold tabular-nums shrink-0">{{ \App\Support\Money::format($d['subtotal']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-400 text-center py-6">Venta sin productos (total manual).</p>
                    @endif
                    <div class="mt-4 rounded-2xl bg-gray-50 p-4 flex justify-between items-center">
                        <span class="font-bold text-gray-900">Total</span>
                        <span class="font-bold text-xl tabular-nums">{{ \App\Support\Money::format($ventaDetalle['total']) }}</span>
                    </div>
                </div>
                <div class="p-6 pt-4 grid grid-cols-2 gap-3 border-t border-gray-100">
                    <button type="button" wire:click="closeDetalle" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Cerrar</button>
                    <button type="button" wire:click="pedirEliminarVenta({{ $ventaDetalle['id'] }})" class="rounded-xl bg-rose-50 border border-rose-200 py-3 font-semibold text-rose-700 min-h-[48px]">Anular venta</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Modal: confirmar anulación ================= --}}
    @if ($showEliminar && $ventaAEliminar)
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('showEliminar', false)"></div>
            <div class="relative w-full sm:max-w-sm bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
                <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                <div class="flex items-center gap-3">
                    <span class="flex items-center justify-center w-11 h-11 rounded-2xl bg-amber-50 shrink-0">
                        <x-heroicon name="exclamation-triangle" class="w-6 h-6 text-amber-500" />
                    </span>
                    <h3 class="text-lg font-bold text-gray-900">Anular venta #{{ $ventaAEliminar }}</h3>
                </div>
                <p class="mt-3 text-sm text-gray-600 leading-relaxed">Se eliminará la venta, su movimiento en caja y <strong>el stock será repuesto</strong>. Esta acción no se puede deshacer.</p>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="$set('showEliminar', false)" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Cancelar</button>
                    <button type="button" wire:click="eliminarVentaConfirmada" class="rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px]">Anular</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Loading overlay --}}
    <div wire:loading wire:target="abrirCaja, cerrarCaja, registrarVenta, eliminarVentaConfirmada" class="fixed top-4 left-1/2 -translate-x-1/2 z-[70]">
        <div class="rounded-full bg-gray-900 text-white text-xs font-semibold px-4 py-2 shadow-xl flex items-center gap-2">
            <span class="inline-block w-3 h-3 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
            Procesando…
        </div>
    </div>
</div>

<script>
    // Devolver el foco al escáner tras agregar/cobrar (flujo de mostrador).
    document.addEventListener('scanner-focus', () => {
        const input = document.getElementById('input-scanner');
        if (input) input.focus();
    });
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('input-scanner')?.focus();
    });
</script>
