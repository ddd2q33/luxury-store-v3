<div class="space-y-5" @keydown.escape="cerrarForm(); cerrarHistorial(); $wire.porEliminar = null">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="user-circle" class="w-6 h-6 text-indigo-600" />
                Proveedores
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">
                {{ $stats['total'] }} proveedores · {{ $stats['compras'] }} compras registradas
            </p>
        </div>
        <button type="button" wire:click="nuevo"
                class="rounded-xl bg-indigo-600 text-white px-5 py-2.5 text-sm font-bold hover:bg-indigo-700 min-h-[44px] active:scale-[0.98] transition shadow-lg shadow-indigo-600/20">
            + Nuevo proveedor
        </button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Total proveedores" :valor="$stats['total']" tono="indigo" icono="user-circle" />
        <x-panel.stat etiqueta="Con deuda" :valor="$stats['conDeuda']" tono="amber" icono="exclamation-triangle" />
        <x-panel.stat etiqueta="Total adeudado" :valor="\App\Support\Money::cents($stats['totalDeuda'])" tono="rose" icono="currency-dollar" />
        <x-panel.stat etiqueta="Compras" :valor="$stats['compras']" tono="emerald" icono="shopping-cart" />
    </div>

    @if ($stats['totalDeuda'] > 0)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
            <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
            <p class="text-sm text-amber-800 leading-relaxed">
                <span class="font-bold">Debes {{ \App\Support\Money::cents($stats['totalDeuda']) }} a {{ $stats['conDeuda'] }} proveedor(es).</span>
                Registra los pagos en <a href="{{ route('abonos') }}" class="font-bold underline">Abonos</a>
                para que el saldo baja solo.
            </p>
        </div>
    @endif

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Directorio de proveedores</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar nombre, contacto, correo…"
                       class="rounded-xl border-gray-200 text-sm sm:w-60 focus:border-indigo-500 focus:ring-indigo-500">

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @foreach (['todos' => 'Todos', 'con_deuda' => 'Con deuda', 'sin_deuda' => 'Sin deuda'] as $valor => $texto)
                        <button type="button" wire:click="$set('filtro', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-indigo-600 shadow-sm' => $filtro === $valor,
                                    'text-gray-500 hover:text-gray-700'   => $filtro !== $valor,
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
                        <th class="px-5 py-3 font-bold">Proveedor</th>
                        <th class="px-5 py-3 font-bold">Contacto</th>
                        <th class="px-5 py-3 font-bold text-right">Deuda</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($proveedores as $p)
                        <tr class="hover:bg-gray-50/70" wire:key="prov-{{ $p->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 shrink-0">
                                        <x-heroicon name="user-circle" class="w-5 h-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900 truncate">{{ $p->nombre }}</p>
                                        @if ($p->descripcion_deuda_inicial)
                                            <p class="text-xs text-gray-400 truncate" title="{{ $p->descripcion_deuda_inicial }}">
                                                {{ $p->descripcion_deuda_inicial }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-gray-500">
                                @if ($p->contacto || $p->telefono || $p->correo)
                                    <p class="text-gray-700">{{ $p->contacto ?: '—' }}</p>
                                    <p class="text-xs text-gray-400">{{ collect([$p->telefono, $p->correo])->filter()->join(' · ') ?: '—' }}</p>
                                @else
                                    <span class="text-gray-400">Sin datos</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                @if ((float) $p->saldo_deuda > 0)
                                    <span class="font-bold text-rose-600 tabular-nums">{{ \App\Support\Money::cents($p->saldo_deuda) }}</span>
                                @else
                                    <span class="text-xs font-semibold text-emerald-600">Al día</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="abrirAjuste({{ $p->id }})" class="text-amber-600 hover:text-amber-700 text-sm font-semibold">Deuda</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="verHistorial({{ $p->id }})" class="text-gray-500 hover:text-gray-700 text-sm font-semibold">Historial</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="editar({{ $p->id }})" class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Editar</button>
                                <span class="text-gray-200 mx-1">·</span>
                                <button type="button" wire:click="pedirEliminar({{ $p->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Eliminar</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' || $filtro !== 'todos' ? 'Sin resultados para este filtro.' : 'Aún no hay proveedores.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($proveedores as $p)
                <div class="p-4" wire:key="prov-m-{{ $p->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">{{ $p->nombre }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ collect([$p->contacto, $p->telefono, $p->correo])->filter()->join(' · ') ?: 'Sin datos de contacto' }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            @if ((float) $p->saldo_deuda > 0)
                                <p class="font-bold text-rose-600 tabular-nums">{{ \App\Support\Money::cents($p->saldo_deuda) }}</p>
                                <p class="text-[10px] uppercase font-bold text-rose-400">deuda</p>
                            @else
                                <p class="text-xs font-bold text-emerald-600">Al día</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="abrirAjuste({{ $p->id }})"
                                class="rounded-lg bg-amber-50 py-2.5 text-xs font-bold text-amber-700 min-h-[42px] active:scale-[0.98] transition">Ajustar deuda</button>
                        <button type="button" wire:click="editar({{ $p->id }})"
                                class="rounded-lg bg-indigo-50 py-2.5 text-xs font-bold text-indigo-700 min-h-[42px] active:scale-[0.98] transition">Editar</button>
                        <button type="button" wire:click="verHistorial({{ $p->id }})"
                                class="rounded-lg bg-gray-100 py-2.5 text-xs font-bold text-gray-600 min-h-[42px] active:scale-[0.98] transition">Historial de deuda</button>
                        <button type="button" wire:click="pedirEliminar({{ $p->id }})"
                                class="rounded-lg bg-rose-50 py-2.5 text-xs font-bold text-rose-600 min-h-[42px] active:scale-[0.98] transition">Eliminar</button>
                    </div>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin proveedores.</p>
            @endforelse
        </div>

        @if ($proveedores->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $proveedores->links() }}
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
                        {{ $editandoId ? 'Editar proveedor' : 'Nuevo proveedor' }}
                    </h3>
                </div>

                <form wire:submit="guardar" class="px-6 overflow-y-auto flex-1 space-y-3.5">
                    <div>
                        <label for="pr-nombre" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nombre o razón social *</label>
                        <input id="pr-nombre" type="text" wire:model="nombre"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Calzados del Norte S.A.S." autocomplete="organization">
                        @error('nombre') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="pr-contacto" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Contacto</label>
                            <input id="pr-contacto" type="text" wire:model="contacto"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Nombre del asesor">
                        </div>
                        <div>
                            <label for="pr-telefono" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Teléfono</label>
                            <input id="pr-telefono" type="tel" inputmode="tel" wire:model="telefono"
                                   class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="300 000 0000">
                        </div>
                    </div>

                    <div>
                        <label for="pr-correo" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Correo</label>
                        <input id="pr-correo" type="email" inputmode="email" wire:model="correo"
                               class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="ventas@proveedor.com" autocomplete="email">
                        @error('correo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="pr-direccion" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Dirección</label>
                        <textarea id="pr-direccion" wire:model="direccion" rows="2"
                                  class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="Calle, número, ciudad"></textarea>
                    </div>

                    <div class="rounded-2xl bg-amber-50/70 border border-amber-200 p-4 space-y-3">
                        <p class="text-xs font-bold text-amber-800 uppercase tracking-wider flex items-center gap-1.5">
                            <x-heroicon name="currency-dollar" class="w-4 h-4" /> Deuda
                        </p>

                        <div>
                            <label for="pr-desc-deuda" class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5 block">Descripción de la deuda</label>
                            <input id="pr-desc-deuda" type="text" wire:model="descripcionDeudaInicial"
                                   class="w-full rounded-xl border-amber-200 bg-white focus:border-amber-500 focus:ring-amber-500"
                                   placeholder="Mercancía de la factura 1234, pendiente">
                        </div>

                        @if ($editandoId)
                            <p class="text-xs text-amber-800 leading-relaxed flex items-start gap-1.5">
                                <x-heroicon name="exclamation-triangle" class="w-4 h-4 shrink-0 mt-px" />
                                <span>El saldo no se edita aquí. Usa <strong>Deuda</strong> para ajustarlo
                                (queda auditado) o <a href="{{ route('abonos') }}" class="underline">Abonos</a> para pagar.</span>
                            </p>
                        @else
                            <div>
                                <label for="pr-deuda-inicial" class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5 block">Deuda inicial</label>
                                <input id="pr-deuda-inicial" type="number" step="0.01" min="0" inputmode="decimal" wire:model="deudaInicial"
                                       class="w-full rounded-xl border-amber-200 bg-white focus:border-amber-500 focus:ring-amber-500"
                                       placeholder="0.00">
                                @error('deudaInicial') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 pb-6">
                        <button type="button" wire:click="cerrarForm"
                                class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="guardar"
                                class="rounded-xl bg-indigo-600 py-3 font-bold text-white hover:bg-indigo-700 min-h-[48px] active:scale-[0.98] transition">
                            <span wire:loading.remove wire:target="guardar">{{ $editandoId ? 'Guardar cambios' : 'Crear proveedor' }}</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ================= Modal: ajuste de deuda ================= --}}
    @if ($showAjuste)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="$set('showAjuste', false)"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl">
                <div class="p-6 pb-4">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <h3 class="text-lg font-bold text-gray-900">Ajustar deuda</h3>
                </div>

                <form wire:submit="confirmarAjuste" class="px-6 pb-6 space-y-3.5">
                    <p class="text-sm text-gray-500 leading-relaxed">
                        Corrige el saldo a mano cuando la deuda no viene de una compra registrada.
                        El ajuste queda guardado en el historial; para pagos usa
                        <a href="{{ route('abonos') }}" class="font-bold text-indigo-600 underline">Abonos</a>.
                    </p>

                    <div>
                        <label for="aj-saldo" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Nuevo saldo *</label>
                        <input id="aj-saldo" type="number" step="0.01" min="0" inputmode="decimal" wire:model="nuevoSaldo"
                               class="w-full rounded-xl border-gray-200 focus:border-amber-500 focus:ring-amber-500">
                        @error('nuevoSaldo') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="aj-razon" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Motivo *</label>
                        <input id="aj-razon" type="text" wire:model="razonAjuste"
                               class="w-full rounded-xl border-gray-200 focus:border-amber-500 focus:ring-amber-500"
                               placeholder="Corrección tras revisar facturas">
                        @error('razonAjuste') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-1">
                        <button type="button" wire:click="$set('showAjuste', false)"
                                class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px] active:scale-[0.98] transition">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="confirmarAjuste"
                                class="rounded-xl bg-amber-500 py-3 font-bold text-white hover:bg-amber-600 min-h-[48px] active:scale-[0.98] transition">
                            <span wire:loading.remove wire:target="confirmarAjuste">Aplicar ajuste</span>
                            <span wire:loading wire:target="confirmarAjuste">Guardando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ================= Modal: historial de deuda ================= --}}
    @if ($historialId)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="cerrarHistorial"></div>
            <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[85vh] flex flex-col">
                <div class="p-6 pb-4 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Historial de deuda</h3>
                        <p class="text-sm text-gray-500 mt-0.5">
                            {{ $proveedorHistorial?->nombre }} ·
                            saldo actual
                            <span class="font-bold {{ ((float) ($proveedorHistorial?->saldo_deuda ?? 0)) > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ \App\Support\Money::cents($proveedorHistorial?->saldo_deuda ?? 0) }}
                            </span>
                        </p>
                    </div>
                    <button type="button" wire:click="cerrarHistorial"
                            class="shrink-0 w-10 h-10 rounded-xl bg-gray-100 text-gray-500 flex items-center justify-center min-h-[44px] min-w-[44px]">
                        <x-heroicon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="px-6 pb-6 overflow-y-auto">
                    @if ($historial && $historial->isNotEmpty())
                        <ol class="space-y-3">
                            @foreach ($historial as $h)
                                <li class="rounded-2xl border border-gray-200 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider mb-1.5
                                                {{ $h->tipo_movimiento === 'saldo_inicial' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700' }}">
                                                {{ $h->tipo_movimiento === 'saldo_inicial' ? 'Saldo inicial' : 'Ajuste' }}
                                            </span>
                                            <p class="text-sm text-gray-700 leading-relaxed">{{ $h->razon }}</p>
                                            <p class="text-xs text-gray-400 mt-1">
                                                {{ \Illuminate\Support\Carbon::parse($h->fecha_registro)->format('d/m/Y h:i A') }}
                                            </p>
                                        </div>
                                        <div class="text-right shrink-0 tabular-nums">
                                            <p class="text-xs text-gray-400">
                                                {{ \App\Support\Money::cents($h->monto_anterior) }} →
                                            </p>
                                            <p class="font-bold {{ $h->diferencia > 0 ? 'text-rose-600' : ($h->diferencia < 0 ? 'text-emerald-600' : 'text-gray-500') }}">
                                                {{ \App\Support\Money::cents($h->monto_nuevo) }}
                                            </p>
                                            <p class="text-[10px] font-bold {{ $h->diferencia > 0 ? 'text-rose-500' : 'text-emerald-500' }}">
                                                {{ $h->diferencia > 0 ? '+' : '' }}{{ \App\Support\Money::cents($h->diferencia) }}
                                            </p>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <div class="py-12 text-center">
                            <p class="text-sm text-gray-400">Sin movimientos de deuda registrados.</p>
                            <p class="text-xs text-gray-400 mt-1">Los abonos aparecen en <a href="{{ route('abonos') }}" class="underline">Abonos</a>.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($porEliminar)
        <x-panel.confirmar
            titulo="Eliminar proveedor"
            descripcion="Solo se permite si no tiene abonos, compras ni deuda pendiente."
            confirmar="eliminarConfirmado"
            wire="porEliminar" />
    @endif
</div>
