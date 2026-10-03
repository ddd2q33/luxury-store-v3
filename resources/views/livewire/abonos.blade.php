<div class="space-y-5" @keydown.escape="$set('showForm', false); $wire.porAnular = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="banknotes" class="w-6 h-6 text-emerald-600" />
                Abonos a proveedores
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                Pagos que bajan la deuda · cada abono descuenta el saldo del proveedor
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-emerald-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-emerald-600/20">
            <x-heroicon name="plus" class="w-5 h-5" />
            Registrar abono
        </button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Abonado en el filtro" :valor="\App\Support\Money::cents($stats['totalAbonado'])" tono="emerald" icono="banknotes" />
        <x-panel.stat etiqueta="Deuda pendiente" :valor="\App\Support\Money::cents($stats['deudaTotal'])" tono="rose" icono="currency-dollar" />
        <x-panel.stat etiqueta="Proveedores con deuda" :valor="$stats['proveedoresConDeuda']" tono="amber" icono="exclamation-triangle" />
        <x-panel.stat etiqueta="Abonos en el filtro" :valor="$stats['cantidad']" tono="indigo" icono="receipt-percent" />
    </div>

    @if ($stats['deudaTotal'] > 0)
        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 flex items-start gap-3">
            <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
            <p class="text-sm text-rose-800 leading-relaxed">
                <span class="font-bold">Quedan {{ \App\Support\Money::cents($stats['deudaTotal']) }} por pagar.</span>
                No se puede abonar más que el saldo del proveedor: el formulario te avisa si te pasas.
            </p>
        </div>
    @endif

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Historial de abonos</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar proveedor o referencia…"
                       class="rounded-xl border-gray-200 text-sm sm:w-52 focus:border-emerald-500 focus:ring-emerald-500">

                <select wire:model.live="proveedorId"
                        class="rounded-xl border-gray-200 text-sm sm:w-44 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">Todos los proveedores</option>
                    @foreach ($proveedores as $p)
                        <option value="{{ $p->id }}">
                            {{ $p->nombre }}{{ (float) $p->saldo_deuda > 0 ? ' ('.\App\Support\Money::cents($p->saldo_deuda).')' : '' }}
                        </option>
                    @endforeach
                </select>

                <select wire:model.live="rango"
                        class="rounded-xl border-gray-200 text-sm sm:w-36 focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="todo">Todo el historial</option>
                    <option value="mes">Este mes</option>
                    <option value="anio">Este año</option>
                    <option value="custom">Rango…</option>
                </select>
            </div>
        </div>

        @if ($rango === 'custom')
            <div class="px-4 sm:px-5 py-3 bg-gray-50/60 border-b border-gray-100 grid grid-cols-2 gap-3">
                <div>
                    <label for="ab-desde" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Desde</label>
                    <input id="ab-desde" type="date" wire:model.live="desde"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <div>
                    <label for="ab-hasta" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Hasta</label>
                    <input id="ab-hasta" type="date" wire:model.live="hasta"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
            </div>
        @endif

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Fecha</th>
                        <th class="px-5 py-3 font-bold">Proveedor</th>
                        <th class="px-5 py-3 font-bold">Referencia</th>
                        <th class="px-5 py-3 font-bold text-right">Monto</th>
                        <th class="px-5 py-3 font-bold text-right">Deuda</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($abonos as $abono)
                        <tr class="hover:bg-gray-50/70" wire:key="ab-{{ $abono->id }}">
                            <td class="px-5 py-3 whitespace-nowrap text-gray-600">
                                {{ \Illuminate\Support\Carbon::parse($abono->fecha_abono)->format('d/m/Y') }}
                            </td>
                            <td class="px-5 py-3 font-semibold text-gray-900">
                                {{ $abono->proveedor?->nombre ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-gray-500 max-w-xs">
                                <span class="line-clamp-1">{{ $abono->referencia ?: '—' }}</span>
                            </td>
                            <td class="px-5 py-3 text-right font-bold text-emerald-600 tabular-nums whitespace-nowrap">
                                {{ \App\Support\Money::cents($abono->monto) }}
                            </td>
                            <td class="px-5 py-3 text-right text-xs tabular-nums whitespace-nowrap">
                                <span class="text-gray-400">{{ \App\Support\Money::cents($abono->deuda_anterior) }} →</span>
                                <span class="font-bold {{ (float) $abono->deuda_nueva > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                    {{ \App\Support\Money::cents($abono->deuda_nueva) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="pedirAnular({{ $abono->id }})"
                                        class="inline-flex items-center gap-1 text-rose-600 hover:text-rose-700 text-sm font-semibold">
                                    <x-heroicon name="archive-box-x-mark" class="w-4 h-4" /> Anular
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' || $proveedorId !== '' || $rango !== 'todo' ? 'Sin abonos para este filtro.' : 'Aún no hay abonos registrados.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($abonos as $abono)
                <div class="p-4" wire:key="ab-m-{{ $abono->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">{{ $abono->proveedor?->nombre ?? '—' }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ \Illuminate\Support\Carbon::parse($abono->fecha_abono)->format('d/m/Y') }}
                                @if ($abono->referencia) · {{ $abono->referencia }} @endif
                            </p>
                        </div>
                        <p class="font-bold text-emerald-600 tabular-nums whitespace-nowrap shrink-0">
                            {{ \App\Support\Money::cents($abono->monto) }}
                        </p>
                    </div>

                    <div class="mt-2 flex items-center justify-between text-xs tabular-nums">
                        <span class="text-gray-400">Deuda {{ \App\Support\Money::cents($abono->deuda_anterior) }} →</span>
                        <span class="font-bold {{ (float) $abono->deuda_nueva > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            {{ \App\Support\Money::cents($abono->deuda_nueva) }}
                        </span>
                    </div>

                    <button type="button" wire:click="pedirAnular({{ $abono->id }})"
                            class="mt-3 w-full inline-flex items-center justify-center gap-1.5 rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">
                        <x-heroicon name="archive-box-x-mark" class="w-4 h-4" />
                        Anular abono
                    </button>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin abonos.</p>
            @endforelse
        </div>

        @if ($abonos->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $abonos->links() }}
            </div>
        @endif
    </div>

    {{-- ================= Modal: registrar abono ================= --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('showForm', false)"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">Registrar abono</h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="ab-prov" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Proveedor *</label>
                        <select id="ab-prov" wire:model="abonoProveedorId"
                                class="w-full rounded-xl border-gray-200 focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">Selecciona un proveedor…</option>
                            @foreach ($proveedores as $p)
                                <option value="{{ $p->id }}">{{ $p->nombre }}</option>
                            @endforeach
                        </select>
                        @error('abonoProveedorId') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    @if ($abonoProveedorId)
                        @php $saldo = $this->saldoDelProveedor(); @endphp
                        <div class="rounded-2xl px-4 py-3 border {{ $saldo > 0 ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200' }}">
                            <p class="text-xs font-bold uppercase tracking-wider {{ $saldo > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                                Deuda actual
                            </p>
                            <p class="text-xl font-bold tabular-nums {{ $saldo > 0 ? 'text-amber-800' : 'text-emerald-800' }} mt-0.5">
                                {{ \App\Support\Money::cents($saldo) }}
                            </p>
                            @if ($saldo <= 0)
                                <p class="text-xs text-emerald-700 mt-1">Este proveedor está al día.</p>
                            @endif
                        </div>

                        <div>
                            <label for="ab-monto" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Monto del abono *</label>
                            <input id="ab-monto" type="number" step="0.01" min="0"
                                   inputmode="decimal" wire:model="monto"
                                   class="w-full rounded-xl border-gray-200 focus:border-emerald-500 focus:ring-emerald-500"
                                   placeholder="0.00" @disabled($saldo <= 0)>
                            @error('monto') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                            @if ($saldo > 0)
                                <button type="button" wire:click="$set('monto', '{{ (string) $saldo }}')"
                                        class="mt-1.5 text-xs font-bold text-emerald-700 underline min-h-[36px]">
                                    Pagar todo ({{ \App\Support\Money::cents($saldo) }})
                                </button>
                            @endif
                        </div>
                    @endif

                    <div>
                        <label for="ab-ref" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Referencia</label>
                        <input id="ab-ref" type="text" wire:model="referencia"
                               class="w-full rounded-xl border-gray-200 focus:border-emerald-500 focus:ring-emerald-500"
                               placeholder="Neto, transferencia, cheque…">
                    </div>

                    <div>
                        <label for="ab-fecha" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha *</label>
                        <input id="ab-fecha" type="date" wire:model="fecha"
                               class="w-full rounded-xl border-gray-200 focus:border-emerald-500 focus:ring-emerald-500">
                        @error('fecha') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="$set('showForm', false)"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="x-mark" class="w-4 h-4" /> Cancelar
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 py-3 font-bold text-white hover:bg-emerald-700 min-h-[48px] active:scale-[0.98] transition">
                            <x-heroicon name="check-circle" class="w-5 h-5" wire:loading.remove wire:target="guardar" />
                            <span wire:loading.remove wire:target="guardar">Registrar abono</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($porAnular)
        <x-panel.confirmar
            titulo="Anular abono"
            descripcion="El abono se borra y el monto vuelve a la deuda del proveedor. Queda el saldo anterior guardado en el historial."
            confirmar="anularConfirmado"
            iconoConfirmar="archive-box-x-mark"
            wire="porAnular" />
    @endif
</div>
