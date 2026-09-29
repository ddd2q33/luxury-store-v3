<div class="space-y-5" @keydown.escape="usarModo('multiple')">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="trending-up" class="w-6 h-6 text-emerald-600" />
            Ingreso de mercancía
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Suma unidades al stock y deja el movimiento registrado en el historial.
        </p>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Ingresos hoy" :valor="$stats['hoy']" tono="emerald" icono="trending-up" />
        <x-panel.stat etiqueta="Unidades hoy" :valor="number_format($stats['unidadesHoy'], 0, ',', '.')" tono="indigo" />
        <x-panel.stat etiqueta="Unidades 7 días" :valor="number_format($stats['semana'], 0, ',', '.')" tono="slate" icono="calendar-days" />
        <x-panel.stat etiqueta="Ingresos totales" :valor="$stats['total']" />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- ================= Formulario ================= --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-gray-100">
                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl max-w-sm">
                    <button type="button" wire:click="usarModo('multiple')"
                            class="flex-1 px-3 py-2.5 rounded-lg text-xs font-bold min-h-[42px] transition"
                            @class([
                                'bg-white text-indigo-600 shadow-sm' => $modo === 'multiple',
                                'text-gray-500 hover:text-gray-700'   => $modo !== 'multiple',
                            ])>
                        Varios productos
                    </button>
                    <button type="button" wire:click="usarModo('simple')"
                            class="flex-1 px-3 py-2.5 rounded-lg text-xs font-bold min-h-[42px] transition"
                            @class([
                                'bg-white text-indigo-600 shadow-sm' => $modo === 'simple',
                                'text-gray-500 hover:text-gray-700'   => $modo !== 'simple',
                            ])>
                        Un solo producto
                    </button>
                </div>
            </div>

            {{-- ---- MODO MÚLTIPLE ---- --}}
            @if ($modo === 'multiple')
                <form wire:submit="registrarMultiple" class="p-4 sm:p-5 space-y-4">
                    <p class="text-sm text-gray-500">
                        Agrega las líneas que entraron de la mercadería. Si algo falla, no se registra nada.
                    </p>

                    <div class="space-y-3">
                        @foreach ($lineas as $index => $linea)
                            <div class="rounded-xl border border-gray-200 p-3.5 space-y-3 bg-gray-50/40"
                                 wire:key="linea-{{ $index }}">

                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                                        Línea {{ $loop->iteration }}
                                    </span>
                                    @if (count($lineas) > 1)
                                        <button type="button" wire:click="quitarLinea({{ $index }})"
                                                class="text-rose-600 text-xs font-bold min-h-[32px] px-2">
                                            Quitar
                                        </button>
                                    @endif
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="sm:col-span-2">
                                        <label for="lin-prod-{{ $index }}"
                                               class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">
                                            Producto *
                                        </label>
                                        <select id="lin-prod-{{ $index }}" wire:model="lineas.{{ $index }}.producto_id"
                                                class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">Selecciona un producto…</option>
                                            @foreach ($catalogo as $cat => $items)
                                                <optgroup label="{{ $cat }}">
                                                    @foreach ($items as $item)
                                                        <option value="{{ $item['id'] }}">
                                                            {{ $item['nombre'] }} (stock: {{ $item['stock'] }})
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                        @error('lineas.'.$index.'.producto_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label for="lin-cant-{{ $index }}"
                                               class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">
                                            Cantidad *
                                        </label>
                                        <input id="lin-cant-{{ $index }}" type="number" inputmode="numeric" min="1" step="1"
                                               wire:model="lineas.{{ $index }}.cantidad"
                                               class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        @error('lineas.'.$index.'.cantidad') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                @if (! empty($linea['producto_id']) && isset($precios[$linea['producto_id']]) && $precios[$linea['producto_id']] > 0)
                                    <p class="text-xs text-gray-400 tabular-nums">
                                        Precio actual: {{ \App\Support\Money::format((float) $precios[$linea['producto_id']]) }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('lineas') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label for="ing-fecha" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha del ingreso *</label>
                            <input id="ing-fecha" type="date" wire:model="fecha"
                                   class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('fecha') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="ing-obs" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Observaciones</label>
                            <input id="ing-obs" type="text" wire:model="observaciones"
                                   class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="Factura, proveedor, nota…" autocomplete="off">
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 pt-1">
                        <button type="button" wire:click="agregarLinea"
                                class="rounded-xl bg-gray-100 py-3 px-5 font-bold text-gray-700 min-h-[48px] active:scale-[0.98] transition">
                            + Agregar línea
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="registrarMultiple"
                                class="flex-1 rounded-xl bg-emerald-600 py-3 font-bold text-white hover:bg-emerald-700 min-h-[48px] active:scale-[0.98] transition shadow-lg shadow-emerald-600/20">
                            <span wire:loading.remove wire:target="registrarMultiple">Registrar ingreso</span>
                            <span wire:loading wire:target="registrarMultiple">Registrando…</span>
                        </button>
                    </div>
                </form>
            @else
                {{-- ---- MODO SIMPLE ---- --}}
                <form wire:submit="registrarSimple" class="p-4 sm:p-5 space-y-4">
                    <div>
                        <label for="s-prod" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Producto *</label>
                        <select id="s-prod" wire:model="producto_id"
                                class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Selecciona un producto…</option>
                            @foreach ($catalogo as $cat => $items)
                                <optgroup label="{{ $cat }}">
                                    @foreach ($items as $item)
                                        <option value="{{ $item['id'] }}">
                                            {{ $item['nombre'] }} (stock: {{ $item['stock'] }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('producto_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label for="s-cant" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Cantidad *</label>
                            <input id="s-cant" type="number" inputmode="numeric" min="1" step="1" wire:model="cantidad"
                                   class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('cantidad') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="s-fecha" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Fecha del ingreso *</label>
                            <input id="s-fecha" type="date" wire:model="fecha"
                                   class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @error('fecha') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="s-obs" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1.5 block">Observaciones</label>
                        <input id="s-obs" type="text" wire:model="observaciones"
                               class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="Factura, proveedor, nota…" autocomplete="off">
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="registrarSimple"
                            class="w-full rounded-xl bg-emerald-600 py-3 font-bold text-white hover:bg-emerald-700 min-h-[48px] active:scale-[0.98] transition shadow-lg shadow-emerald-600/20">
                        <span wire:loading.remove wire:target="registrarSimple">Registrar ingreso</span>
                        <span wire:loading wire:target="registrarSimple">Registrando…</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- ================= Ingresos recientes ================= --}}
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden self-start">
            <div class="p-4 sm:p-5 border-b border-gray-100">
                <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                    <x-heroicon name="clock" class="w-4 h-4 text-gray-400" />
                    Ingresos recientes
                </h3>
            </div>

            <ul class="divide-y divide-gray-100">
                @forelse ($ultimos as $mov)
                    <li class="px-4 sm:px-5 py-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 truncate">
                                    {{ $mov->producto?->nombre ?? 'Producto #'.$mov->producto_id }}
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5 tabular-nums">
                                    {{ $mov->fecha?->format('d/m/Y') }}
                                    @if ($mov->observaciones)
                                        · <span class="truncate">{{ $mov->observaciones }}</span>
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 tabular-nums">
                                +{{ $mov->cantidad }}
                            </span>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-12 text-center text-gray-400 text-sm">
                        Aún no hay ingresos registrados.
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
