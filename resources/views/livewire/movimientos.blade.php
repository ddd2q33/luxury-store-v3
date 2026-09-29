<div class="space-y-5">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="trending-up" class="w-6 h-6 text-indigo-600" />
            Movimientos de caja
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Histórico global de entradas y salidas, de todos los turnos
        </p>
    </div>

    {{-- Aviso: este módulo es de solo lectura --}}
    <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 flex items-start gap-3">
        <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-sky-500 shrink-0 mt-0.5" />
        <p class="text-sm text-sky-800 leading-relaxed">
            <span class="font-bold">Consulta de solo lectura.</span>
            Los movimientos se crean desde
            <a href="{{ route('caja') }}" class="font-bold underline">Caja</a> para que el turno cuadre.
            Aquí solo se filtran y revisan los {{ number_format(\App\Models\MovimientoCaja::count(), 0, ',', '.') }}
            movimientos ya registrados.
        </p>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-panel.stat etiqueta="Entradas" :valor="'+'.\App\Support\Money::cents($stats['entradas'])" tono="emerald" icono="trending-up" />
        <x-panel.stat etiqueta="Salidas" :valor="'−'.\App\Support\Money::cents($stats['salidas'])" tono="rose" icono="trending-down" />
        <x-panel.stat etiqueta="Balance" :valor="\App\Support\Money::cents($stats['balance'])" :tono="$stats['balance'] >= 0 ? 'indigo' : 'rose'" icono="chart-line" />
        <x-panel.stat etiqueta="Balance de hoy" :valor="\App\Support\Money::cents($stats['hoy'])" :tono="$stats['hoy'] >= 0 ? 'emerald' : 'rose'" icono="clock" />
    </div>

    {{-- Filtros + tabla --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Histórico completo</h3>

            <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center">
                <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar descripción…"
                       class="rounded-xl border-gray-200 text-sm sm:w-48 focus:border-indigo-500 focus:ring-indigo-500">

                <select wire:model.live="tipo"
                        class="rounded-xl border-gray-200 text-sm sm:w-40 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="todos">Todos los tipos</option>
                    @foreach (\App\Http\Livewire\Movimientos::TIPOS as $valor => $texto)
                        <option value="{{ $valor }}">{{ $texto }}</option>
                    @endforeach
                </select>

                <select wire:model.live="metodo"
                        class="rounded-xl border-gray-200 text-sm sm:w-40 focus:border-indigo-500 focus:ring-indigo-500">
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
                    <label for="mo-desde" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Desde</label>
                    <input id="mo-desde" type="date" wire:model.live="desde"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="mo-hasta" class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 block">Hasta</label>
                    <input id="mo-hasta" type="date" wire:model.live="hasta"
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>
        @endif

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Fecha</th>
                        <th class="px-5 py-3 font-bold">Tipo</th>
                        <th class="px-5 py-3 font-bold">Descripción</th>
                        <th class="px-5 py-3 font-bold">Método</th>
                        <th class="px-5 py-3 font-bold text-right">Monto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($movimientos as $m)
                        @php
                            $esSalida = in_array($m->tipo, ['egreso', 'devolucion'], true);
                            $etiqueta = \App\Http\Livewire\Movimientos::TIPOS[$m->tipo] ?? $m->tipo;
                        @endphp
                        <tr class="hover:bg-gray-50/70" wire:key="mov-{{ $m->id }}">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <span class="text-gray-600">{{ $m->fecha->format('d/m/Y') }}</span>
                                <span class="block text-xs text-gray-400 tabular-nums">{{ $m->fecha->format('h:i A') }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold
                                    @if ($m->tipo === 'venta') bg-emerald-50 text-emerald-700
                                    @elseif ($m->tipo === 'ingreso') bg-sky-50 text-sky-700
                                    @elseif ($m->tipo === 'devolucion') bg-orange-50 text-orange-700
                                    @else bg-rose-50 text-rose-700 @endif">
                                    @if ($m->tipo === 'venta')
                                        <x-heroicon name="shopping-cart" class="w-3.5 h-3.5" />
                                    @elseif ($m->tipo === 'ingreso')
                                        <x-heroicon name="trending-up" class="w-3.5 h-3.5" />
                                    @elseif ($m->tipo === 'devolucion')
                                        <x-heroicon name="trending-down" class="w-3.5 h-3.5" />
                                    @else
                                        <x-heroicon name="trending-down" class="w-3.5 h-3.5" />
                                    @endif
                                    {{ $etiqueta }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-700 max-w-sm">
                                <span class="line-clamp-1">{{ $m->descripcion ?: '—' }}</span>
                                @if ($m->venta_id)
                                    <a href="{{ route('facturacion') }}" class="text-xs text-indigo-600 font-semibold">Venta #{{ $m->venta_id }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $m->metodo_pago ?: '—' }}</td>
                            <td class="px-5 py-3 text-right font-bold tabular-nums whitespace-nowrap {{ $esSalida ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ $esSalida ? '−' : '+' }}{{ \App\Support\Money::cents($m->monto) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-14 text-center text-gray-400">
                            {{ $search !== '' || $tipo !== 'todos' || $metodo !== 'todos' ? 'Sin movimientos para este filtro.' : 'No hay movimientos en este periodo.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($movimientos as $m)
                @php $esSalida = in_array($m->tipo, ['egreso', 'devolucion'], true); @endphp
                <div class="p-4" wire:key="mov-m-{{ $m->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-bold mb-1.5
                                @if ($m->tipo === 'venta') bg-emerald-50 text-emerald-700
                                @elseif ($m->tipo === 'ingreso') bg-sky-50 text-sky-700
                                @elseif ($m->tipo === 'devolucion') bg-orange-50 text-orange-700
                                @else bg-rose-50 text-rose-700 @endif">
                                {{ \App\Http\Livewire\Movimientos::TIPOS[$m->tipo] ?? $m->tipo }}
                            </span>
                            <p class="text-sm text-gray-700 leading-snug">{{ $m->descripcion ?: '—' }}</p>
                            <p class="text-xs text-gray-400 mt-1">
                                {{ $m->fecha->format('d/m/Y h:i A') }}
                                @if ($m->metodo_pago) · {{ $m->metodo_pago }} @endif
                            </p>
                        </div>
                        <p class="font-bold tabular-nums whitespace-nowrap shrink-0 {{ $esSalida ? 'text-rose-600' : 'text-emerald-600' }}">
                            {{ $esSalida ? '−' : '+' }}{{ \App\Support\Money::cents($m->monto) }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="py-14 px-4 text-center text-gray-400 text-sm">Sin movimientos.</p>
            @endforelse
        </div>

        @if ($movimientos->hasPages())
            <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
                {{ $movimientos->links() }}
            </div>
        @endif
    </div>
</div>
