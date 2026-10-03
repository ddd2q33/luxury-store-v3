<div class="space-y-5"
     @keydown.window.escape="['showDetalle'].forEach(p => { if ($wire[p]) $wire[p] = false }); $wire.porEliminar = null; $wire.porCambiarEstado = null">

    {{-- Toast --}}
    <div x-data="{ visible: false, mensaje: '', tipo: 'ok', t: null }"
         @pedidos-toast.window="mensaje = $event.detail.mensaje; tipo = $event.detail.tipo; visible = true; clearTimeout(t); t = setTimeout(() => visible = false, 3500)"
         x-show="visible" x-cloak x-transition.opacity.duration.200ms
         class="fixed bottom-6 right-4 z-[70] max-w-xs">
        <div class="rounded-2xl px-4 py-3 shadow-2xl text-sm font-medium flex items-start gap-2.5 border"
             :class="tipo === 'ok' ? 'bg-emerald-600 border-emerald-500 text-white' : 'bg-rose-600 border-rose-500 text-white'">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-white/20 text-xs shrink-0 font-bold"
                  x-text="tipo === 'ok' ? '✓' : '✕'"></span>
            <span x-text="mensaje"></span>
        </div>
    </div>

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="archive-box" class="w-6 h-6 text-indigo-600" />
                Pedidos
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">{{ $stats['pendientes'] }} pendientes · {{ $stats['preparando'] }} en preparación</p>
        </div>
        @if ($tab === 'lista')
            <button type="button" wire:click="irNuevo"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
                <x-heroicon name="plus" class="w-5 h-5" />
                Nuevo pedido
            </button>
        @else
            <button type="button" wire:click="$set('tab', 'lista')"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-700 px-5 py-2.5 text-sm font-bold hover:bg-gray-200 min-h-[44px]">
                <x-heroicon name="chevron-double-left" class="w-4 h-4" />
                Volver a la lista
            </button>
        @endif
    </div>

    @if ($tab === 'lista')
        {{-- Stats --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Pendientes</p>
                <p class="text-2xl font-extrabold text-amber-600 mt-1 tabular-nums">{{ $stats['pendientes'] }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">En preparación</p>
                <p class="text-2xl font-extrabold text-indigo-600 mt-1 tabular-nums">{{ $stats['preparando'] }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Listos</p>
                <p class="text-2xl font-extrabold text-emerald-600 mt-1 tabular-nums">{{ $stats['listos'] }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Entregados (mes)</p>
                <p class="text-2xl font-extrabold text-gray-900 mt-1 tabular-nums">{{ $stats['entregadosMes'] }}</p>
            </div>
        </div>

        {{-- Filtros + lista --}}
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-gray-100 space-y-3">
                <div class="flex gap-1.5 overflow-x-auto pb-1 -mx-1 px-1">
                    @foreach (['todos' => 'Todos', 'pendiente' => 'Pendientes', 'confirmado' => 'Confirmados', 'preparando' => 'Preparando', 'listo' => 'Listos', 'entregado' => 'Entregados', 'cancelado' => 'Cancelados'] as $key => $label)
                        <button type="button" wire:click="$set('filtroEstado', '{{ $key }}')"
                                @class([
                                    'rounded-full px-3.5 py-2 text-xs font-bold whitespace-nowrap min-h-[38px] transition-colors',
                                    'bg-gray-900 text-white' => $filtroEstado === $key,
                                    'bg-gray-100 text-gray-600 hover:bg-gray-200' => $filtroEstado !== $key,
                                ])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar #pedido, cliente o teléfono…"
                       class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            {{-- Desktop --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                            <th class="px-5 py-3 font-bold">Pedido</th>
                            <th class="px-5 py-3 font-bold">Cliente</th>
                            <th class="px-5 py-3 font-bold">Entrega</th>
                            <th class="px-5 py-3 font-bold text-right">Total</th>
                            <th class="px-5 py-3 font-bold text-center">Estado</th>
                            <th class="px-5 py-3 font-bold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($pedidos as $pedido)
                            @php $badge = match($pedido->estado) { 'pendiente' => 'bg-amber-100 text-amber-700', 'confirmado' => 'bg-sky-100 text-sky-700', 'preparando' => 'bg-indigo-100 text-indigo-700', 'listo' => 'bg-emerald-100 text-emerald-700', 'entregado' => 'bg-gray-100 text-gray-600', default => 'bg-rose-100 text-rose-600' }; @endphp
                            <tr class="hover:bg-gray-50/70" wire:key="ped-{{ $pedido->id }}">
                                <td class="px-5 py-3">
                                    <span class="font-mono text-xs font-bold text-gray-900">{{ $pedido->numero_pedido ?: '#'.$pedido->id }}</span>
                                    <span class="block text-[10px] text-gray-400">{{ $pedido->fecha_pedido?->format('d/m H:i') }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="font-semibold text-gray-900">{{ $pedido->cliente_nombre }}</span>
                                    @if ($pedido->cliente_telefono)<span class="block text-xs text-gray-400 tabular-nums">{{ $pedido->cliente_telefono }}</span>@endif
                                </td>
                                <td class="px-5 py-3 text-gray-600 tabular-nums">{{ $pedido->fecha_entrega?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-5 py-3 text-right font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($pedido->total) }}</td>
                                <td class="px-5 py-3 text-center">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase {{ $badge }}">{{ $pedido->estado }}</span>
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="verPedido({{ $pedido->id }})"
                                            class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-700 text-sm font-semibold">
                                        <x-heroicon name="eye" class="w-4 h-4" /> Ver
                                    </button>
                                    <span class="text-gray-200 mx-1">·</span>
                                    <button type="button" wire:click="pedirEliminar({{ $pedido->id }})"
                                            class="inline-flex items-center gap-1 text-rose-600 hover:text-rose-700 text-sm font-semibold">
                                        <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-14 text-center text-gray-400">No hay pedidos con este filtro.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Móvil --}}
            <div class="md:hidden divide-y divide-gray-100">
                @forelse ($pedidos as $pedido)
                    @php $badge = match($pedido->estado) { 'pendiente' => 'bg-amber-100 text-amber-700', 'confirmado' => 'bg-sky-100 text-sky-700', 'preparando' => 'bg-indigo-100 text-indigo-700', 'listo' => 'bg-emerald-100 text-emerald-700', 'entregado' => 'bg-gray-100 text-gray-600', default => 'bg-rose-100 text-rose-600' }; @endphp
                    <div class="p-4" wire:key="ped-m-{{ $pedido->id }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs font-bold text-gray-900">{{ $pedido->numero_pedido ?: '#'.$pedido->id }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase {{ $badge }}">{{ $pedido->estado }}</span>
                        </div>
                        <div class="mt-1.5 flex items-center justify-between gap-3">
                            <span class="font-semibold text-gray-900 truncate">{{ $pedido->cliente_nombre }}</span>
                            <span class="font-bold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::format($pedido->total) }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-400">Entrega: {{ $pedido->fecha_entrega?->format('d/m/Y') ?? '—' }}</p>
                        <div class="mt-2.5 flex gap-2">
                            <button type="button" wire:click="verPedido({{ $pedido->id }})" class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg bg-gray-100 py-2.5 text-xs font-bold text-gray-700 min-h-[42px]">
                                <x-heroicon name="eye" class="w-4 h-4" /> Ver
                            </button>
                            <button type="button" wire:click="pedirEliminar({{ $pedido->id }})" class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px]">
                                <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="py-14 text-center text-gray-400 text-sm">No hay pedidos con este filtro.</p>
                @endforelse
            </div>

            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $pedidos->links() }}
            </div>
        </div>

    @else
        {{-- ================= Nuevo pedido ================= --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5 space-y-4">
                    <h3 class="font-bold text-gray-900">Datos del cliente</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Cliente *</label>
                            <input type="text" wire:model="clienteNombre" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                            @error('clienteNombre') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Teléfono</label>
                            <input type="tel" wire:model="clienteTelefono" inputmode="tel" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha de entrega *</label>
                            <input type="date" wire:model="fechaEntrega" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                            @error('fechaEntrega') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Método de pago</label>
                            <select wire:model="metodoPago" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach (['Pendiente', 'Efectivo', 'Transferencia/QR', 'Datafono', 'Nequi'] as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Observaciones</label>
                        <textarea wire:model="observaciones" rows="2" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="Detalles del pedido, tallas, colores…"></textarea>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
                    <h3 class="font-bold text-gray-900 mb-3">Productos</h3>
                    <div class="relative">
                        <input type="text" wire:model.debounce.300ms="searchProducto" placeholder="Buscar producto para agregar…"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                        @if (count($productosSugeridos) > 0)
                            <div class="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
                                @foreach ($productosSugeridos as $r)
                                    <button type="button" wire:click="agregarProducto({{ $r['id'] }})"
                                            class="w-full text-left px-4 py-3 hover:bg-indigo-50 flex items-center justify-between gap-3 border-b border-gray-50 last:border-0">
                                        <span class="text-sm text-gray-900 truncate font-medium">{{ $r['nombre'] }}</span>
                                        <span class="text-xs shrink-0 tabular-nums {{ $r['stock'] > 0 ? 'text-gray-500' : 'text-rose-600 font-semibold' }}">{{ $r['stock'] }} und · {{ \App\Support\Money::format($r['precio']) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if (count($items) === 0)
                        <div class="py-10 text-center">
                            <span class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gray-100 mb-3">
                                <x-heroicon name="archive-box" class="w-7 h-7 text-gray-300" />
                            </span>
                            <p class="text-sm font-medium text-gray-500">Sin productos</p>
                            <p class="text-xs text-gray-400 mt-1">Busca y agrega los productos del pedido</p>
                        </div>
                    @else
                        <ul class="mt-4 divide-y divide-gray-100">
                            @foreach ($items as $i => $item)
                                <li class="py-3 flex items-center gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-gray-900 truncate">{{ $item['nombre'] }}</p>
                                        <p class="text-xs text-gray-500 tabular-nums">{{ \App\Support\Money::format($item['precio']) }} c/u · stock {{ $item['stock'] }}</p>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button" wire:click="cambiarCantidad({{ $i }}, -1)" class="w-9 h-9 rounded-lg bg-gray-100 font-bold text-gray-700 hover:bg-gray-200">−</button>
                                        <span class="w-9 text-center text-sm font-bold tabular-nums">{{ $item['cantidad'] }}</span>
                                        <button type="button" wire:click="cambiarCantidad({{ $i }}, 1)" class="w-9 h-9 rounded-lg bg-gray-100 font-bold text-gray-700 hover:bg-gray-200">+</button>
                                    </div>
                                    <span class="w-24 text-right text-sm font-bold tabular-nums shrink-0 hidden sm:block">{{ \App\Support\Money::format($item['cantidad'] * $item['precio']) }}</span>
                                    <button type="button" wire:click="quitarItem({{ $i }})" class="w-9 h-9 rounded-lg text-rose-400 hover:bg-rose-50 font-bold shrink-0">✕</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Resumen --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5 space-y-4 lg:sticky lg:top-20">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-widest">Total del pedido</p>
                    <p class="text-3xl font-bold text-gray-900 tabular-nums mt-1">{{ \App\Support\Money::format($totalNuevo) }}</p>
                </div>
                <div class="rounded-xl bg-indigo-50 px-3.5 py-3 text-xs text-indigo-700 leading-relaxed">
                    El pedido se crea como <strong>pendiente</strong>. El stock se reserva al crear y se devuelve si se cancela o elimina.
                </div>
                <button type="button" wire:click="guardarPedido" wire:loading.attr="disabled" wire:target="guardarPedido"
                        @class([
                            'w-full rounded-2xl py-3.5 font-bold text-white min-h-[52px] transition-all shadow-lg',
                            'bg-indigo-600 hover:bg-indigo-700 active:scale-[0.99] shadow-indigo-600/25' => count($items) > 0,
                            'bg-gray-300 cursor-not-allowed' => count($items) === 0,
                        ])>
                    <span wire:loading.remove wire:target="guardarPedido">Crear pedido</span>
                    <span wire:loading wire:target="guardarPedido">Guardando…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ================= Modal: detalle ================= --}}
    @if ($showDetalle && $pedidoDetalle)
        @php
            $badgeDetalle = match($pedidoDetalle['estado']) {
                'pendiente' => 'bg-amber-100 text-amber-700',
                'confirmado' => 'bg-sky-100 text-sky-700',
                'preparando' => 'bg-indigo-100 text-indigo-700',
                'listo' => 'bg-emerald-100 text-emerald-700',
                'entregado' => 'bg-gray-100 text-gray-600',
                default => 'bg-rose-100 text-rose-600',
            };
        @endphp
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeDetalle"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4 border-b border-gray-100">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-lg font-bold text-gray-900">{{ $pedidoDetalle['numero'] ?: 'Pedido #'.$pedidoDetalle['id'] }}</h3>
                            <p class="text-sm text-gray-500 mt-0.5">{{ $pedidoDetalle['cliente'] }} @if($pedidoDetalle['telefono']) · {{ $pedidoDetalle['telefono'] }} @endif</p>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase {{ $badgeDetalle }} shrink-0">{{ $pedidoDetalle['estado'] }}</span>
                    </div>
                </div>

                <div class="p-6 overflow-y-auto flex-1">
                    <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                        <div class="rounded-xl bg-gray-50 p-3">
                            <p class="text-[10px] font-bold text-gray-400 uppercase">Pedido</p>
                            <p class="text-gray-900 font-medium">{{ $pedidoDetalle['fechaPedido'] }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3">
                            <p class="text-[10px] font-bold text-gray-400 uppercase">Entrega</p>
                            <p class="text-gray-900 font-medium">{{ $pedidoDetalle['fechaEntrega'] ?? '—' }}</p>
                        </div>
                    </div>

                    @if (count($pedidoDetalle['detalles']) > 0)
                        <ul class="divide-y divide-gray-100 text-sm">
                            @foreach ($pedidoDetalle['detalles'] as $d)
                                <li class="py-3 flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-gray-900 font-medium truncate">{{ $d['nombre'] }}</p>
                                        <p class="text-xs text-gray-400 tabular-nums">{{ $d['cantidad'] }} × {{ \App\Support\Money::format($d['precio']) }}</p>
                                    </div>
                                    <span class="font-bold tabular-nums shrink-0">{{ \App\Support\Money::format($d['subtotal']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($pedidoDetalle['observaciones'])
                        <p class="mt-4 rounded-xl bg-amber-50 border border-amber-100 px-3.5 py-2.5 text-sm text-amber-800">📝 {{ $pedidoDetalle['observaciones'] }}</p>
                    @endif

                    <div class="mt-4 rounded-2xl bg-gray-50 p-4 flex justify-between items-center">
                        <span class="font-bold text-gray-900">Total ({{ $pedidoDetalle['metodoPago'] }})</span>
                        <span class="font-bold text-xl tabular-nums">{{ \App\Support\Money::format($pedidoDetalle['total']) }}</span>
                    </div>
                </div>

                {{-- Cambio de estado rápido --}}
                <div class="px-6 pb-2">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Cambiar estado</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (\App\Models\Pedido::ESTADOS as $estado)
                            @if ($estado !== $pedidoDetalle['estado'])
                                <button type="button" wire:click="pedirCambiarEstado({{ $pedidoDetalle['id'] }}, '{{ $estado }}')"
                                        class="rounded-full px-3 py-2 text-xs font-bold bg-gray-100 text-gray-600 hover:bg-gray-200 min-h-[38px] capitalize active:scale-[0.97] transition">
                                    {{ $estado }}
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="p-6 pt-3 grid grid-cols-2 gap-3 border-t border-gray-100 mt-3">
                    <button type="button" wire:click="closeDetalle" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">
                        <x-heroicon name="x-mark" class="w-4 h-4" /> Cerrar
                    </button>
                    <button type="button" wire:click="pedirEliminar({{ $pedidoDetalle['id'] }})" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-50 border border-rose-200 py-3 font-semibold text-rose-700 min-h-[48px]">
                        <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Confirmar cambio de estado ================= --}}
    @if ($porCambiarEstado)
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('porCambiarEstado', null)"></div>
            <div class="relative w-full sm:max-w-sm bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
                <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                <h3 class="text-lg font-bold text-gray-900 capitalize">Cambiar a "{{ $nuevoEstado }}"</h3>
                @if ($nuevoEstado === 'cancelado')
                    <p class="mt-3 text-sm text-gray-600">Al cancelar, <strong>el stock reservado será devuelto</strong> al inventario.</p>
                @elseif ($nuevoEstado === 'entregado')
                    <p class="mt-3 text-sm text-gray-600">Confirma que el pedido fue entregado al cliente.</p>
                @endif
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="$set('porCambiarEstado', null)" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">
                        <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                    </button>
                    <button type="button" wire:click="cambiarEstadoConfirmado" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-900 py-3 font-bold text-white min-h-[48px]">
                        <x-heroicon name="check-circle" class="w-4 h-4" /> Confirmar
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Confirmar eliminación ================= --}}
    @if ($porEliminar)
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('porEliminar', null)"></div>
            <div class="relative w-full sm:max-w-sm bg-white rounded-t-3xl sm:rounded-3xl p-6 shadow-2xl">
                <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                <div class="flex items-center gap-3">
                    <span class="flex items-center justify-center w-11 h-11 rounded-2xl bg-rose-50 shrink-0">
                        <x-heroicon name="exclamation-triangle" class="w-6 h-6 text-rose-500" />
                    </span>
                    <h3 class="text-lg font-bold text-gray-900">Eliminar pedido</h3>
                </div>
                <p class="mt-3 text-sm text-gray-600">Se eliminará el pedido y sus detalles. Si estaba reservando stock, <strong>este será devuelto</strong>.</p>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="$set('porEliminar', null)" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">
                        <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                    </button>
                    <button type="button" wire:click="eliminarConfirmado" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px]">
                        <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div wire:loading wire:target="guardarPedido, cambiarEstadoConfirmado, eliminarConfirmado" class="fixed top-4 left-1/2 -translate-x-1/2 z-[70]">
        <div class="rounded-full bg-gray-900 text-white text-xs font-semibold px-4 py-2 shadow-xl flex items-center gap-2">
            <span class="inline-block w-3 h-3 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
            Procesando…
        </div>
    </div>
</div>
