<div class="space-y-5"
     @keydown.window.escape="['showForm', 'showDetalle'].forEach(p => { if ($wire[p]) $wire[p] = false }); $wire.porEliminar = null">

    {{-- Toast --}}
    <div x-data="{ visible: false, mensaje: '', tipo: 'ok', t: null }"
         @clientes-toast.window="mensaje = $event.detail.mensaje; tipo = $event.detail.tipo; visible = true; clearTimeout(t); t = setTimeout(() => visible = false, 3500)"
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
                <x-heroicon name="users" class="w-6 h-6 text-indigo-600" />
                Clientes
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">{{ $stats['total'] }} registrados · {{ $stats['nuevosMes'] }} nuevos este mes</p>
        </div>
        <button type="button" wire:click="nuevo"
                class="rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
            + Nuevo cliente
        </button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Total clientes</p>
            <p class="text-2xl font-extrabold text-gray-900 mt-1 tabular-nums">{{ $stats['total'] }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Nuevos este mes</p>
            <p class="text-2xl font-extrabold text-emerald-600 mt-1 tabular-nums">{{ $stats['nuevosMes'] }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Con teléfono</p>
            <p class="text-2xl font-extrabold text-indigo-600 mt-1 tabular-nums">{{ $stats['conTelefono'] }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Con correo</p>
            <p class="text-2xl font-extrabold text-amber-600 mt-1 tabular-nums">{{ $stats['conCorreo'] }}</p>
        </div>
    </div>

    {{-- Búsqueda + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Lista de clientes</h3>
            <input type="search" wire:model.debounce.300ms="search" placeholder="Nombre, teléfono, correo, ciudad…"
                   class="rounded-xl border-gray-200 text-sm sm:w-64 focus:border-indigo-500 focus:ring-indigo-500">
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Cliente</th>
                        <th class="px-5 py-3 font-bold">Teléfono</th>
                        <th class="px-5 py-3 font-bold">Correo</th>
                        <th class="px-5 py-3 font-bold">Ciudad</th>
                        <th class="px-5 py-3 font-bold text-right">Compras</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($clientes as $cliente)
                        <tr class="hover:bg-gray-50/70" wire:key="cli-{{ $cliente->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex items-center justify-center w-9 h-9 rounded-full bg-indigo-50 text-indigo-600 font-bold text-sm shrink-0">
                                        {{ mb_substr($cliente->nombre, 0, 1) }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900 truncate">{{ $cliente->nombre }}</p>
                                        @if ($cliente->direccion)
                                            <p class="text-xs text-gray-400 truncate">{{ $cliente->direccion }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-gray-600 tabular-nums">{{ $cliente->telefono ?: '—' }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $cliente->correo ?: '—' }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $cliente->ciudad ?: '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <span class="font-bold text-gray-900 tabular-nums">{{ $comprasPorCliente[$cliente->id] ?? 0 }}</span>
                                @if (isset($gastosPorCliente[$cliente->id]))
                                    <span class="block text-xs text-emerald-600 font-semibold tabular-nums">{{ \App\Support\Money::format($gastosPorCliente[$cliente->id]) }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="verDetalle({{ $cliente->id }})" class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Ver</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="editar({{ $cliente->id }})" class="text-gray-600 hover:text-gray-800 text-sm font-semibold">Editar</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="pedirEliminar({{ $cliente->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Eliminar</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' ? 'Sin resultados para "'.$search.'".' : 'Aún no hay clientes registrados.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($clientes as $cliente)
                <div class="p-4" wire:key="cli-m-{{ $cliente->id }}">
                    <div class="flex items-center gap-3">
                        <span class="flex items-center justify-center w-10 h-10 rounded-full bg-indigo-50 text-indigo-600 font-bold shrink-0">
                            {{ mb_substr($cliente->nombre, 0, 1) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 truncate">{{ $cliente->nombre }}</p>
                            <p class="text-xs text-gray-400 tabular-nums">{{ $cliente->telefono ?: 'sin teléfono' }} · {{ $comprasPorCliente[$cliente->id] ?? 0 }} compras</p>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <button type="button" wire:click="verDetalle({{ $cliente->id }})" class="rounded-lg bg-gray-100 py-2.5 text-xs font-bold text-gray-700 min-h-[42px] active:scale-[0.98] transition">Ver</button>
                        <button type="button" wire:click="editar({{ $cliente->id }})" class="rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">Editar</button>
                        <button type="button" wire:click="pedirEliminar({{ $cliente->id }})" class="rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">Eliminar</button>
                    </div>
                </div>
            @empty
                <p class="py-14 text-center text-gray-400 text-sm">Sin clientes.</p>
            @endforelse
        </div>

        <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
            {{ $clientes->links() }}
        </div>
    </div>

    {{-- ================= Modal: formulario ================= --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeForm"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">{{ $editandoId ? 'Editar cliente' : 'Nuevo cliente' }}</h3>
                </div>
                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nombre *</label>
                        <input type="text" wire:model="nombre" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                        @error('nombre') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Teléfono</label>
                            <input type="tel" wire:model="telefono" inputmode="tel" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Ciudad</label>
                            <input type="text" wire:model="ciudad" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Correo</label>
                        <input type="email" wire:model="correo" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                        @error('correo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Dirección</label>
                        <input type="text" wire:model="direccion" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" autocomplete="off">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Notas</label>
                        <textarea wire:model="notas" rows="2" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Preferencias, tallas, observaciones…"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="closeForm" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px]">
                            {{ $editandoId ? 'Guardar cambios' : 'Crear cliente' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ================= Modal: detalle con historial ================= --}}
    @if ($showDetalle && $clienteDetalle)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeDetalle"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4 border-b border-gray-100">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <div class="flex items-center gap-3.5">
                        <span class="flex items-center justify-center w-12 h-12 rounded-2xl bg-indigo-600 text-white font-bold text-lg shrink-0">
                            {{ mb_substr($clienteDetalle['nombre'], 0, 1) }}
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-lg font-bold text-gray-900 truncate">{{ $clienteDetalle['nombre'] }}</h3>
                            <p class="text-xs text-gray-400">
                                Cliente desde {{ $clienteDetalle['creado'] ?? '—' }}
                                @if ($clienteDetalle['totalGastado'] > 0)
                                    · <span class="text-emerald-600 font-bold">{{ \App\Support\Money::format($clienteDetalle['totalGastado']) }} en compras</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                        @if ($clienteDetalle['telefono'])<span>📱 {{ $clienteDetalle['telefono'] }}</span>@endif
                        @if ($clienteDetalle['correo'])<span>✉️ {{ $clienteDetalle['correo'] }}</span>@endif
                        @if ($clienteDetalle['ciudad'])<span>📍 {{ $clienteDetalle['ciudad'] }}</span>@endif
                        @if ($clienteDetalle['direccion'])<span>🏠 {{ $clienteDetalle['direccion'] }}</span>@endif
                    </div>
                    @if ($clienteDetalle['notas'])
                        <p class="mt-3 rounded-xl bg-amber-50 border border-amber-100 px-3.5 py-2.5 text-sm text-amber-800">📝 {{ $clienteDetalle['notas'] }}</p>
                    @endif
                </div>
                <div class="p-6 overflow-y-auto flex-1 space-y-5">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Últimas compras</p>
                        @if (count($clienteDetalle['ventas']) > 0)
                            <ul class="divide-y divide-gray-100 text-sm">
                                @foreach ($clienteDetalle['ventas'] as $v)
                                    <li class="py-2.5 flex items-center justify-between gap-3">
                                        <div>
                                            <span class="text-gray-900 font-medium">#{{ $v['id'] }}</span>
                                            <span class="text-gray-400 text-xs ml-1.5">{{ $v['fecha'] }} · {{ $v['metodo'] }}</span>
                                        </div>
                                        <span class="font-bold tabular-nums">{{ \App\Support\Money::format($v['total']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-gray-400 py-3">Sin compras registradas.</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Pedidos</p>
                        @if (count($clienteDetalle['pedidos']) > 0)
                            <ul class="divide-y divide-gray-100 text-sm">
                                @foreach ($clienteDetalle['pedidos'] as $p)
                                    <li class="py-2.5 flex items-center justify-between gap-3">
                                        <div>
                                            <span class="text-gray-900 font-medium">{{ $p['numero'] ?: '#'.$p['id'] }}</span>
                                            <span class="text-gray-400 text-xs ml-1.5">{{ $p['fecha'] }}</span>
                                        </div>
                                        <div class="text-right">
                                            <span class="font-bold tabular-nums">{{ \App\Support\Money::format($p['total']) }}</span>
                                            <span class="block text-[10px] font-bold uppercase text-gray-400">{{ $p['estado'] }}</span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-gray-400 py-3">Sin pedidos.</p>
                        @endif
                    </div>
                </div>
                <div class="p-6 pt-4 grid grid-cols-2 gap-3 border-t border-gray-100">
                    <button type="button" wire:click="editar({{ $clienteDetalle['id'] }})" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Editar</button>
                    <button type="button" wire:click="closeDetalle" class="rounded-xl bg-gray-900 py-3 font-bold text-white min-h-[48px]">Cerrar</button>
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
                    <h3 class="text-lg font-bold text-gray-900">Eliminar cliente</h3>
                </div>
                <p class="mt-3 text-sm text-gray-600">Se eliminará el cliente del sistema. Su historial de ventas permanecerá intacto.</p>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="$set('porEliminar', null)" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Cancelar</button>
                    <button type="button" wire:click="eliminarConfirmado" class="rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px]">Eliminar</button>
                </div>
            </div>
        </div>
    @endif

    <div wire:loading wire:target="guardar, eliminarConfirmado" class="fixed top-4 left-1/2 -translate-x-1/2 z-[70]">
        <div class="rounded-full bg-gray-900 text-white text-xs font-semibold px-4 py-2 shadow-xl flex items-center gap-2">
            <span class="inline-block w-3 h-3 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
            Procesando…
        </div>
    </div>
</div>
