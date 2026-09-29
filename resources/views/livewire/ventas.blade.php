<div class="space-y-5"
     wire:key="ventas-root"
     x-data
     @ventas-graficas.window="
        const fmt = (v) => '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v);
        if (window.__chartsVentas) { Object.values(window.__chartsVentas).forEach(c => c.destroy()); }
        window.__chartsVentas = {};

        const dias = document.getElementById('chartVentasDias');
        if (dias && $event.detail.labelsDias.length > 0) {
            window.__chartsVentas.dias = new Chart(dias, {
                data: {
                    labels: $event.detail.labelsDias,
                    datasets: [
                        { type: 'bar', label: 'Monto', data: $event.detail.totalesDias, backgroundColor: 'rgba(16,185,129,0.15)', borderColor: '#10b981', borderWidth: 2, borderRadius: 6, yAxisID: 'yMonto', order: 2 },
                        { type: 'line', label: 'Cantidad', data: $event.detail.cantidadesDias, borderColor: '#a5b4fc', borderWidth: 2, pointBackgroundColor: '#6366f1', pointRadius: 3, tension: 0.4, yAxisID: 'yCant', order: 1 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false }, tooltip: { callbacks: {
                        label: (ctx) => ctx.datasetIndex === 0 ? ' Total: ' + fmt(ctx.raw) : ' Ventas: ' + ctx.raw,
                    } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 10 }, maxRotation: 45, autoSkip: true, maxTicksLimit: 16 } },
                        yMonto: { position: 'left', grid: { color: '#f1f5f9' }, border: { display: false }, ticks: { color: '#9ca3af', font: { size: 10 }, callback: (v) => fmt(v) } },
                        yCant: { position: 'right', grid: { display: false }, border: { display: false }, ticks: { color: '#c7d2fe', font: { size: 10 }, precision: 0 } },
                    },
                },
            });
        }

        const met = document.getElementById('chartVentasMetodos');
        if (met && $event.detail.metodosLabels.length > 0) {
            window.__chartsVentas.metodos = new Chart(met, {
                type: 'doughnut',
                data: { labels: $event.detail.metodosLabels, datasets: [{ data: $event.detail.metodosTotales, backgroundColor: ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'], borderWidth: 2, borderColor: '#fff' }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ' ' + ctx.label + ': ' + fmt(ctx.parsed) } } } },
            });
        }
     }">

    {{-- Toast --}}
    <div x-data="{ visible: false, mensaje: '', tipo: 'ok', t: null }"
         @ventas-toast.window="mensaje = $event.detail.mensaje; tipo = $event.detail.tipo; visible = true; clearTimeout(t); t = setTimeout(() => visible = false, 3500)"
         x-show="visible" x-cloak x-transition.opacity.duration.200ms
         class="fixed bottom-6 right-4 z-[70] max-w-xs">
        <div class="rounded-2xl px-4 py-3 shadow-2xl text-sm font-medium flex items-start gap-2.5 border"
             :class="tipo === 'ok' ? 'bg-emerald-600 border-emerald-500 text-white' : 'bg-rose-600 border-rose-500 text-white'">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-white/20 text-xs shrink-0 font-bold"
                  x-text="tipo === 'ok' ? '✓' : '✕'"></span>
            <span x-text="mensaje"></span>
        </div>
    </div>

    {{-- ================= Header con filtros ================= --}}
    <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <x-heroicon name="chart-line" class="w-6 h-6 text-emerald-600" />
                Ventas
            </h2>
            <p class="text-sm text-gray-500 mt-0.5 tabular-nums">
                {{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }}
                · {{ $kpi['totalRegistros'] }} registros
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach (['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes', 'mes_anterior' => 'Mes anterior'] as $key => $label)
                <button type="button" wire:click="$set('preset', '{{ $key }}')"
                        @class([
                            'rounded-xl px-3.5 py-2 text-xs font-bold min-h-[40px] transition-colors',
                            'bg-gray-900 text-white' => $preset === $key,
                            'bg-white border border-gray-200 text-gray-500 hover:border-gray-400 hover:text-gray-700' => $preset !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach

            <div class="flex items-center gap-1.5 bg-white border border-gray-200 rounded-xl px-2 py-1.5">
                <input type="date" wire:model="desde"
                       class="rounded-lg border-0 text-xs text-gray-700 focus:ring-0 p-0.5 w-[118px]">
                <span class="text-gray-300 text-xs">→</span>
                <input type="date" wire:model="hasta"
                       class="rounded-lg border-0 text-xs text-gray-700 focus:ring-0 p-0.5 w-[118px]">
            </div>
        </div>
    </div>

    {{-- ================= KPIs ================= --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="col-span-2 rounded-2xl bg-gray-900 text-white p-5 relative overflow-hidden">
            <div class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/10 rounded-full"></div>
            <div class="absolute -right-2 -bottom-6 w-32 h-32 bg-emerald-500/5 rounded-full"></div>
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Ingresos del período</p>
            <p class="text-3xl font-extrabold text-emerald-400 tabular-nums mt-1" wire:loading.remove wire:target="preset, desde, hasta">{{ \App\Support\Money::format($kpi['ingresos']) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $kpi['cantidadCompletadas'] }} ventas completadas</p>
            @if ($kpi['hayAnterior'])
                <span class="mt-3 inline-block text-[10px] font-bold px-2 py-0.5 rounded-full {{ $kpi['variacion'] >= 0 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' }}">
                    {{ $kpi['variacion'] >= 0 ? '▲' : '▼' }} {{ abs($kpi['variacion']) }}% vs período anterior
                </span>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 bg-rose-50 rounded-lg flex items-center justify-center">
                    <x-heroicon name="trending-down" class="w-4 h-4 text-rose-400" />
                </span>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Gastos</p>
            </div>
            <p class="text-2xl font-extrabold text-rose-500 tabular-nums">{{ \App\Support\Money::format($kpi['gastos']) }}</p>
            <p class="text-[10px] text-gray-400 mt-1">Egresos de caja</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center {{ $kpi['balance'] >= 0 ? 'bg-indigo-50' : 'bg-rose-50' }}">
                    <x-heroicon name="banknotes" class="w-4 h-4 {{ $kpi['balance'] >= 0 ? 'text-indigo-400' : 'text-rose-400' }}" />
                </span>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Balance</p>
            </div>
            <p class="text-2xl font-extrabold tabular-nums {{ $kpi['balance'] >= 0 ? 'text-indigo-600' : 'text-rose-500' }}">{{ \App\Support\Money::format($kpi['balance']) }}</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
            <div class="flex items-center gap-2 mb-2">
                <span class="w-7 h-7 bg-amber-50 rounded-lg flex items-center justify-center">
                    <x-heroicon name="receipt-percent" class="w-4 h-4 text-amber-400" />
                </span>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Ticket prom.</p>
            </div>
            <p class="text-2xl font-extrabold text-amber-600 tabular-nums">{{ \App\Support\Money::format($kpi['ticketPromedio']) }}</p>
        </div>
    </div>

    {{-- ================= Gráficas ================= --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-gray-900 text-sm">Ventas por día</h3>
                    <p class="text-[10px] text-gray-400">Solo ventas completadas/pagadas</p>
                </div>
                <div class="flex gap-3 text-[10px] text-gray-400 font-medium">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-400 inline-block"></span> Monto</span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-indigo-300 inline-block"></span> Cantidad</span>
                </div>
            </div>
            <div class="relative h-52">
                <canvas id="chartVentasDias" wire:ignore></canvas>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-bold text-gray-900 text-sm">Métodos de pago</h3>
            <p class="text-[10px] text-gray-400 mb-3">Distribución del período</p>
            <div class="relative h-36">
                <canvas id="chartVentasMetodos" wire:ignore></canvas>
            </div>
            <div class="mt-3 space-y-1.5">
                @php $totalMet = $metodos->sum('t'); @endphp
                @forelse ($metodos as $met)
                    <div class="flex items-center justify-between text-xs">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <span class="w-2 h-2 rounded-full shrink-0" style="background: {{ ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'][$loop->index % 6] }}"></span>
                            <span class="text-gray-600 truncate">{{ $met->metodo }}</span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-gray-400">{{ $met->c }} ventas</span>
                            <span class="font-bold text-gray-700 tabular-nums">{{ $totalMet > 0 ? round($met->t / $totalMet * 100) : 0 }}%</span>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 text-center py-3">Sin datos</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ================= Top clientes + Estados ================= --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-bold text-gray-900 text-sm mb-4">🏆 Top clientes</h3>
            @forelse ($topClientes as $tc)
                @php $maxGastado = $topClientes->max('gastado'); $pct = $maxGastado > 0 ? round($tc->gastado / $maxGastado * 100) : 0; @endphp
                <div class="mb-3.5 last:mb-0">
                    <div class="flex items-center justify-between mb-1 gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-sm shrink-0">{{ ['🥇', '🥈', '🥉', '4°', '5°'][$loop->index] }}</span>
                            <span class="text-xs font-semibold text-gray-700 truncate">{{ $tc->nombre }}</span>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="text-xs font-extrabold text-gray-800 tabular-nums">{{ \App\Support\Money::format($tc->gastado) }}</span>
                            <span class="text-[10px] text-gray-400 block">{{ $tc->compras }} compra{{ $tc->compras != 1 ? 's' : '' }}</span>
                        </div>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ $pct }}%; background: {{ ['#10b981', '#6366f1', '#f59e0b', '#94a3b8', '#cbd5e1'][$loop->index] }}"></div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-300 text-sm">Sin datos de clientes en este período</p>
            @endforelse
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-bold text-gray-900 text-sm mb-4">Estado de ventas</h3>
            @forelse ($estados as $est)
                @php
                    $totalEst = $estados->sum('c');
                    $key = strtolower(trim($est->estado));
                    [$badge, $color] = match(true) {
                        in_array($key, ['completada', 'pagada']) => ['bg-emerald-100 text-emerald-700', '#10b981'],
                        $key === 'pendiente' => ['bg-amber-100 text-amber-700', '#f59e0b'],
                        in_array($key, ['cancelada', 'anulada']) => ['bg-rose-100 text-rose-600', '#ef4444'],
                        default => ['bg-gray-100 text-gray-500', '#94a3b8'],
                    };
                    $pctEst = $totalEst > 0 ? round($est->c / $totalEst * 100) : 0;
                @endphp
                <div class="flex items-center gap-3 mb-3 last:mb-0">
                    <span class="{{ $badge }} text-[10px] font-bold px-2 py-0.5 rounded-full w-24 text-center shrink-0 uppercase">{{ $est->estado }}</span>
                    <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ $pctEst }}%; background: {{ $color }}"></div>
                    </div>
                    <div class="text-right shrink-0 w-14">
                        <span class="text-xs font-bold text-gray-700 tabular-nums">{{ $est->c }}</span>
                        <span class="text-[10px] text-gray-400">({{ $pctEst }}%)</span>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-gray-300 text-sm">Sin datos</p>
            @endforelse
        </div>
    </div>

    {{-- ================= Tabla ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="px-4 sm:px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h3 class="font-bold text-gray-900 text-sm">Historial de ventas</h3>
            <input type="search" wire:model.debounce.300ms="search" placeholder="Buscar cliente o #venta…"
                   class="rounded-xl border-gray-200 text-sm sm:w-60 focus:border-indigo-500 focus:ring-indigo-500">
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-200 bg-gray-50/60">
                        <th class="px-5 py-3 font-bold">Ref</th>
                        <th class="px-5 py-3 font-bold">Cliente</th>
                        <th class="px-5 py-3 font-bold text-right">Total</th>
                        <th class="px-5 py-3 font-bold">Fecha</th>
                        <th class="px-5 py-3 font-bold text-center">Estado</th>
                        <th class="px-5 py-3 font-bold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($ventas as $v)
                        @php
                            $estKey = strtolower(trim($v->estado));
                            $badge = in_array($estKey, ['completada', 'pagada']) ? 'bg-emerald-100 text-emerald-700'
                                : ($estKey === 'pendiente' ? 'bg-amber-100 text-amber-700'
                                : (in_array($estKey, ['cancelada', 'anulada']) ? 'bg-rose-100 text-rose-600' : 'bg-gray-100 text-gray-500'));
                        @endphp
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-5 py-3 font-mono text-xs text-gray-400">#{{ $v->id }}</td>
                            <td class="px-5 py-3 font-semibold text-gray-900">{{ $v->cliente_nombre ?: 'Público General' }}</td>
                            <td class="px-5 py-3 text-right font-extrabold text-emerald-600 tabular-nums">{{ \App\Support\Money::format($v->total) }}</td>
                            <td class="px-5 py-3 text-xs text-gray-500 tabular-nums whitespace-nowrap">{{ $v->fecha_venta->format('d/m/Y') }} <span class="text-gray-300">{{ $v->fecha_venta->format('H:i') }}</span></td>
                            <td class="px-5 py-3 text-center">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase {{ $badge }}">{{ $v->estado }}</span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="verVenta({{ $v->id }})" class="text-indigo-600 hover:text-indigo-700 text-sm font-semibold">Ver</button>
                                <span class="text-gray-200 mx-1.5">·</span>
                                <button type="button" wire:click="pedirEliminarVenta({{ $v->id }})" class="text-rose-600 hover:text-rose-700 text-sm font-semibold">Anular</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-14 text-center text-gray-400">No hay ventas en este período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil --}}
        <div class="md:hidden divide-y divide-gray-100">
            @forelse ($ventas as $v)
                @php
                    $estKey = strtolower(trim($v->estado));
                    $badge = in_array($estKey, ['completada', 'pagada']) ? 'bg-emerald-100 text-emerald-700'
                        : ($estKey === 'pendiente' ? 'bg-amber-100 text-amber-700'
                        : (in_array($estKey, ['cancelada', 'anulada']) ? 'bg-rose-100 text-rose-600' : 'bg-gray-100 text-gray-500'));
                @endphp
                <div class="p-4" wire:key="venta-m-{{ $v->id }}">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-semibold text-gray-900 truncate">{{ $v->cliente_nombre ?: 'Público General' }}</span>
                        <span class="font-extrabold text-emerald-600 tabular-nums shrink-0">{{ \App\Support\Money::format($v->total) }}</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs text-gray-400">
                        <span>#{{ $v->id }} · {{ $v->fecha_venta->format('d/m H:i') }}</span>
                        <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase {{ $badge }}">{{ $v->estado }}</span>
                    </div>
                    <div class="mt-2.5 flex gap-2">
                        <button type="button" wire:click="verVenta({{ $v->id }})" class="flex-1 rounded-lg bg-gray-100 py-2.5 text-sm font-semibold text-gray-700 min-h-[44px] active:scale-[0.98] transition">Ver</button>
                        <button type="button" wire:click="pedirEliminarVenta({{ $v->id }})" class="flex-1 rounded-lg bg-rose-50 py-2.5 text-sm font-semibold text-rose-600 min-h-[44px] active:scale-[0.98] transition">Anular</button>
                    </div>
                </div>
            @empty
                <p class="py-14 text-center text-gray-400 text-sm">No hay ventas en este período.</p>
            @endforelse
        </div>

        <div class="px-5 py-3.5 bg-gray-50/60 border-t border-gray-100">
            {{ $ventas->links() }}
        </div>
    </div>

    {{-- ================= Modal: detalle ================= --}}
    @if ($showDetalle && $ventaDetalle)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeDetalle"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl max-h-[88vh] flex flex-col">
                <div class="p-6 pb-4 border-b border-gray-100">
                    <div class="w-10 h-1.5 bg-gray-200 rounded-full mx-auto sm:hidden mb-4"></div>
                    <div class="flex items-start justify-between gap-3">
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

                    <div class="mt-4 rounded-2xl bg-gray-50 p-4 space-y-1.5">
                        <div class="flex justify-between text-sm"><span class="text-gray-500">Motivo</span><span class="font-medium text-gray-900">{{ $ventaDetalle['motivo'] ?: '—' }}</span></div>
                        @if ($ventaDetalle['monto_recibido'] !== null)
                            <div class="flex justify-between text-sm"><span class="text-gray-500">Recibido</span><span class="font-medium tabular-nums">{{ \App\Support\Money::format($ventaDetalle['monto_recibido']) }}</span></div>
                        @endif
                        @if ($ventaDetalle['cambio'] !== null && $ventaDetalle['cambio'] > 0)
                            <div class="flex justify-between text-sm"><span class="text-gray-500">Cambio</span><span class="font-bold text-emerald-600 tabular-nums">{{ \App\Support\Money::format($ventaDetalle['cambio']) }}</span></div>
                        @endif
                        <div class="flex justify-between items-center border-t border-gray-200 pt-2 mt-2">
                            <span class="font-bold text-gray-900">Total</span>
                            <span class="font-bold text-xl tabular-nums">{{ \App\Support\Money::format($ventaDetalle['total']) }}</span>
                        </div>
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
    @if ($ventaAEliminar)
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeDetalle"></div>
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
                    <button type="button" wire:click="closeDetalle" class="rounded-xl bg-gray-100 py-3 font-semibold text-gray-700 min-h-[48px]">Cancelar</button>
                    <button type="button" wire:click="eliminarVentaConfirmada" class="rounded-xl bg-rose-600 py-3 font-bold text-white hover:bg-rose-700 min-h-[48px]">Anular</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Loading --}}
    <div wire:loading wire:target="preset, desde, hasta, eliminarVentaConfirmada" class="fixed top-4 left-1/2 -translate-x-1/2 z-[70]">
        <div class="rounded-full bg-gray-900 text-white text-xs font-semibold px-4 py-2 shadow-xl flex items-center gap-2">
            <span class="inline-block w-3 h-3 rounded-full border-2 border-white/30 border-t-white animate-spin"></span>
            Actualizando…
        </div>
    </div>
</div>
