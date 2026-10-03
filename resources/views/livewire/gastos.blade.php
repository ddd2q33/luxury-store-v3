<div class="space-y-5" @keydown.escape="cerrarForm(); $wire.porEliminar = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="credit-card" class="w-6 h-6 text-rose-600" />
                Gastos
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Salidas de dinero por consumo operativo
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-rose-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-rose-600/20">
            <x-heroicon name="plus" class="w-5 h-5" />
            Registrar gasto
        </button>
    </div>

    {{-- Aviso: gastos no tocan la caja --}}
    <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 flex items-start gap-3">
        <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-sky-500 shrink-0 mt-0.5" />
        <p class="text-sm text-sky-800 leading-relaxed">
            <span class="font-bold">Este registro no mueve la caja.</span>
            Para que el dinero salga del turno hay que registrarlo también en
            <a href="{{ route('caja') }}" class="font-bold underline">Caja → Movimientos</a> como egreso.
            Así el arqueo de cada turno cuadra con lo que realmente salió.
        </p>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Total general" :valor="\App\Support\Money::cents($stats['totalGeneral'])" tono="rose" icono="currency-dollar" />
        <x-panel.stat etiqueta="Este mes" :valor="\App\Support\Money::cents($stats['totalMes'])" tono="amber" icono="calendar-days" />
        <x-panel.stat etiqueta="Hoy" :valor="\App\Support\Money::cents($stats['totalHoy'])" tono="indigo" icono="clock" />
        <x-panel.stat etiqueta="En el filtro" :valor="\App\Support\Money::cents($totalRango)" tono="emerald" icono="chart-line" />
    </div>

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">
                {{ $stats['cantidad'] }} gasto(s) · promedio
                <span class="text-gray-400 font-normal">{{ \App\Support\Money::cents($stats['promedio']) }}</span>
            </h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar descripción…"
                       class="rounded-xl border-gray-200 text-sm sm:w-52 focus:border-rose-500 focus:ring-rose-500">

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @foreach (['hoy' => 'Hoy', 'mes' => 'Este mes', 'anio' => 'Este año', 'todo' => 'Todo', 'custom' => 'Rango'] as $valor => $texto)
                        <button type="button" wire:click="$set('rango', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-rose-600 shadow-sm' => $rango === $valor,
                                    'text-gray-500 hover:text-gray-700'  => $rango !== $valor,
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
                    <label for="g-desde" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Desde</label>
                    <input id="g-desde" type="date" wire:model.live="desde"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-rose-500 focus:ring-rose-500">
                </div>
                <div>
                    <label for="g-hasta" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Hasta</label>
                    <input id="g-hasta" type="date" wire:model.live="hasta"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-rose-500 focus:ring-rose-500">
                </div>
            </div>
        @endif

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Fecha</th>
                        <th class="px-5 py-3 font-bold">Descripción</th>
                        <th class="px-5 py-3 font-bold text-right">Monto</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($gastos as $gasto)
                        <tr class="hover:bg-gray-50/70" wire:key="gas-{{ $gasto->id }}">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <span class="text-gray-600">{{ \Illuminate\Support\Carbon::parse($gasto->fecha)->format('d/m/Y') }}</span>
                                <span class="block text-xs text-gray-400 tabular-nums">{{ substr((string) $gasto->hora, 0, 5) }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-900 font-medium max-w-md">
                                <span class="line-clamp-1">{{ $gasto->descripcion }}</span>
                            </td>
                            <td class="px-5 py-3 text-right font-bold text-rose-600 tabular-nums whitespace-nowrap">
                                −{{ \App\Support\Money::cents($gasto->monto) }}
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="editar({{ $gasto->id }})"
                                        class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-700 text-sm font-semibold">
                                    <x-heroicon name="pencil-square" class="w-4 h-4" /> Editar
                                </button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="pedirEliminar({{ $gasto->id }})"
                                        class="inline-flex items-center gap-1 text-rose-600 hover:text-rose-700 text-sm font-semibold">
                                    <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' ? 'Sin resultados para esta búsqueda.' : 'No hay gastos registrados en este periodo.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($gastos as $gasto)
                <div class="p-4" wire:key="gas-m-{{ $gasto->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">{{ $gasto->descripcion }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ \Illuminate\Support\Carbon::parse($gasto->fecha)->format('d/m/Y') }}
                                @if ($gasto->hora) · {{ substr((string) $gasto->hora, 0, 5) }} @endif
                            </p>
                        </div>
                        <p class="font-bold text-rose-600 tabular-nums whitespace-nowrap shrink-0">
                            −{{ \App\Support\Money::cents($gasto->monto) }}
                        </p>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="editar({{ $gasto->id }})"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">
                            <x-heroicon name="pencil-square" class="w-4 h-4" /> Editar
                        </button>
                        <button type="button" wire:click="pedirEliminar({{ $gasto->id }})"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">
                            <x-heroicon name="trash" class="w-4 h-4" /> Eliminar
                        </button>
                    </div>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin gastos en este periodo.</p>
            @endforelse
        </div>

        @if ($gastos->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $gastos->links() }}
            </div>
        @endif
    </div>

    {{-- ================= Modal: formulario ================= --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarForm"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ $editandoId ? 'Editar gasto' : 'Registrar gasto' }}
                    </h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="g-desc" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Descripción *</label>
                        <input id="g-desc" type="text" wire:model="descripcion"
                               class="w-full rounded-xl border-gray-200 focus:border-rose-500 focus:ring-rose-500"
                               placeholder="Arriendo, transporte, publicidad…" autocomplete="off">
                        @error('descripcion') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="g-monto" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Monto *</label>
                        <input id="g-monto" type="number" step="0.01" min="0" inputmode="decimal" wire:model="monto"
                               class="w-full rounded-xl border-gray-200 focus:border-rose-500 focus:ring-rose-500"
                               placeholder="0.00">
                        @error('monto') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="g-fecha" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha *</label>
                            <input id="g-fecha" type="date" wire:model="fecha"
                                   class="w-full rounded-xl border-gray-200 focus:border-rose-500 focus:ring-rose-500">
                            @error('fecha') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="g-hora" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Hora</label>
                            <input id="g-hora" type="time" wire:model="hora"
                                   class="w-full rounded-xl border-gray-200 focus:border-rose-500 focus:ring-rose-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarForm"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="check-circle" class="w-5 h-5" wire:loading.remove wire:target="guardar" />
                            <span wire:loading.remove wire:target="guardar">{{ $editandoId ? 'Guardar cambios' : 'Registrar gasto' }}</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($porEliminar)
        <x-panel.confirmar
            titulo="Eliminar gasto"
            descripcion="Se borra del registro. Si el dinero salió de la caja, el egreso correspondiente en Caja debe anularse aparte."
            confirmar="eliminarConfirmado"
            wire="porEliminar" />
    @endif
</div>
