<div class="space-y-5">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="chart-bar" class="w-6 h-6 text-indigo-600" />
            Inventario
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Valorización del stock y control de existencias.
        </p>
    </div>

    {{-- Bloqueo real: sin precios no hay valoración --}}
    @if ($stats['sinPrecio'] > 0)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3.5 flex items-start gap-3">
            <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
            <div class="text-sm text-amber-800 leading-relaxed">
                <p>
                    <span class="font-bold">{{ $stats['sinPrecio'] }} de {{ $stats['productos'] }} productos están sin precio.</span>
                    El sistema viejo nunca les asignó precio, por eso la valoración total
                    {{ $stats['valorConPrecio'] > 0 ? 'solo refleja los que sí lo tienen' : 'aparece en $0' }}.
                </p>
                {{-- wire:navigate es de Livewire 3; el panel corre Livewire 2. --}}
                <a href="{{ url('productos') }}"
                   class="inline-flex items-center gap-1.5 mt-2 font-bold underline underline-offset-2">
                    <x-heroicon name="shopping-bag" class="w-4 h-4" />
                    Asignar precios en Productos
                </a>
            </div>
        </div>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Productos" :valor="$stats['productos']" tono="indigo" icono="shopping-bag"
                      :ayuda="$stats['categorias'].' categorías'" />
        <x-panel.stat etiqueta="Unidades en stock" :valor="number_format($stats['unidades'], 0, ',', '.')" tono="slate" icono="archive-box" />
        <x-panel.stat etiqueta="Valor del inventario" :valor="\App\Support\Money::format($stats['valorCosto'])" tono="emerald" icono="banknotes"
                      :ayuda="$stats['conPrecio'].' de '.$stats['productos'].' con precio'" />
        <x-panel.stat etiqueta="Movimientos" :valor="$stats['movimientos']" tono="indigo" icono="chart-line"
                      :ayuda="'En el ledger'" />
    </div>

    {{-- ================= Valorización por categoría ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100">
            <h3 class="font-bold text-gray-900 text-sm">Valorización por categoría</h3>
            <p class="text-xs text-gray-500 mt-0.5">unidades × precio, ordenado por valor.</p>
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Categoría</th>
                        <th class="px-5 py-3 font-bold text-right">Productos</th>
                        <th class="px-5 py-3 font-bold text-right">Unidades</th>
                        <th class="px-5 py-3 font-bold text-right">Valor</th>
                        <th class="px-5 py-3 font-bold w-56">Peso</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php $mayorValor = collect($porCategoria)->max('valor'); @endphp
                    @forelse ($porCategoria as $c)
                        <tr class="hover:bg-gray-50/70" wire:key="inv-cat-{{ $c['id'] }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 shrink-0 text-xs font-bold">
                                        {{ $c['productos'] }}
                                    </span>
                                    <span class="font-semibold text-gray-900">{{ $c['nombre'] }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-right text-gray-600 tabular-nums">{{ $c['productos'] }}</td>
                            <td class="px-5 py-3 text-right text-gray-600 tabular-nums">
                                {{ number_format($c['unidades'], 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-3 text-right font-bold text-gray-900 tabular-nums">
                                {{ \App\Support\Money::format($c['valor']) }}
                            </td>
                            <td class="px-5 py-3">
                                @if ($mayorValor > 0 && $c['valor'] > 0)
                                    <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full bg-indigo-500"
                                             style="width: {{ max(3, round($c['valor'] / $mayorValor * 100)) }}%"></div>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-14 text-center text-gray-400">Aún no hay categorías.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($porCategoria as $c)
                <div class="p-4" wire:key="inv-cat-m-{{ $c['id'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 truncate">{{ $c['nombre'] }}</p>
                            <p class="text-xs text-gray-400 mt-0.5 tabular-nums">
                                {{ $c['productos'] }} producto(s) · {{ number_format($c['unidades'], 0, ',', '.') }} unidades
                            </p>
                        </div>
                        <p class="shrink-0 font-bold text-gray-900 tabular-nums">
                            {{ \App\Support\Money::format($c['valor']) }}
                        </p>
                    </div>
                    @if ($mayorValor > 0 && $c['valor'] > 0)
                        <div class="mt-2.5 h-2 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full rounded-full bg-indigo-500"
                                 style="width: {{ max(3, round($c['valor'] / $mayorValor * 100)) }}%"></div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin categorías.</p>
            @endforelse
        </div>
    </div>

    {{-- ================= Detalle por producto ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 space-y-3">
            <h3 class="font-bold text-gray-900 text-sm">Detalle por producto</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar producto…"
                       class="rounded-xl border-gray-200 text-sm flex-1 focus:border-indigo-500 focus:ring-indigo-500">

                <div class="flex gap-1.5 p-1 bg-gray-100 rounded-xl overflow-x-auto">
                    @php
                        $estados = [
                            ''           => 'Todos',
                            'agotado'    => 'Agotados',
                            'bajo'       => 'Stock bajo',
                            'negativo'   => 'Negativos',
                            'sin_precio' => 'Sin precio',
                        ];
                    @endphp
                    @foreach ($estados as $valor => $texto)
                        <button type="button" wire:click="$set('filtroEstado', '{{ $valor }}')"
                                class="px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap min-h-[38px] transition"
                                @class([
                                    'bg-white text-indigo-600 shadow-sm' => $filtroEstado === $valor,
                                    'text-gray-500 hover:text-gray-700'   => $filtroEstado !== $valor,
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
                        <th class="px-5 py-3 font-bold">Producto</th>
                        <th class="px-5 py-3 font-bold">Categoría</th>
                        <th class="px-5 py-3 font-bold text-right">Stock</th>
                        <th class="px-5 py-3 font-bold text-right">Precio</th>
                        <th class="px-5 py-3 font-bold text-right">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($productos as $p)
                        <tr class="hover:bg-gray-50/70" wire:key="inv-p-{{ $p['id'] }}">
                            <td class="px-5 py-3 font-semibold text-gray-900">{{ $p['nombre'] }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $p['categoria'] }}</td>
                            <td class="px-5 py-3 text-right font-bold tabular-nums
                                @switch($p['estado'])
                                    @case('ok') text-emerald-600 @break
                                    @case('bajo') text-amber-600 @break
                                    @case('agotado') text-gray-400 @break
                                    @default text-rose-600 @endswitch">{{ $p['stock'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">
                                @if ($p['precio'] > 0)
                                    <span class="text-gray-900">{{ \App\Support\Money::format($p['precio']) }}</span>
                                @else
                                    <span class="text-xs font-semibold text-amber-600">Sin precio</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right font-bold text-gray-900 tabular-nums">
                                {{ \App\Support\Money::format($p['valor']) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-14 text-center text-gray-400">Sin productos para este filtro.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($productos as $p)
                <div class="p-4" wire:key="inv-p-m-{{ $p['id'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 leading-snug">{{ $p['nombre'] }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $p['categoria'] }}</p>
                        </div>
                        <p class="shrink-0 font-bold text-gray-900 tabular-nums">
                            {{ \App\Support\Money::format($p['valor']) }}
                        </p>
                    </div>

                    <div class="mt-2.5 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Stock</p>
                            <p class="text-sm font-bold tabular-nums
                                @switch($p['estado'])
                                    @case('ok') text-emerald-600 @break
                                    @case('bajo') text-amber-600 @break
                                    @case('agotado') text-gray-400 @break
                                    @default text-rose-600 @endswitch">{{ $p['stock'] }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Precio</p>
                            <p class="text-sm font-bold text-gray-900 tabular-nums">
                                {{ $p['precio'] > 0 ? \App\Support\Money::format($p['precio']) : '—' }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Mínimo</p>
                            <p class="text-sm font-bold text-gray-600 tabular-nums">{{ $p['stock_minimo'] }}</p>
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin productos para este filtro.</p>
            @endforelse
        </div>
    </div>
</div>
