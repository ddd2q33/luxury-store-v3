<div class="space-y-5">

    <x-panel.toast />

    {{-- ================= Header ================= --}}
    <div>
        <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <x-heroicon name="presentation-chart-bar" class="w-6 h-6 text-indigo-600" />
            Reportes
        </h2>
        <p class="text-sm text-gray-500 mt-0.5">
            Ventas, márgenes e inventario por período. Solo lectura.
        </p>
    </div>

    {{-- ================= Filtro de período ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-4">
        <div class="flex flex-col sm:flex-row sm:items-end gap-3">
            <div class="flex-1">
                <label class="block text-xs font-semibold text-gray-600 mb-1.5" for="rep-desde">Desde</label>
                <input id="rep-desde"
                       type="date"
                       wire:model.live="desde"
                       class="w-full min-h-[44px] rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div class="flex-1">
                <label class="block text-xs font-semibold text-gray-600 mb-1.5" for="rep-hasta">Hasta</label>
                <input id="rep-hasta"
                       type="date"
                       wire:model.live="hasta"
                       class="w-full min-h-[44px] rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
        </div>

        {{-- Presets: botones de 44px, sin depender de hover --}}
        <div class="flex flex-wrap gap-2 mt-3">
            @foreach (['hoy' => 'Hoy', '7' => '7 días', '30' => '30 días', '90' => '90 días', 'mes' => 'Este mes', 'anio' => 'Este año', 'todo' => 'Todo'] as $clave => $etiqueta)
                <button type="button"
                        wire:click="aplicarPreset('{{ $clave }}')"
                        class="min-h-[44px] px-3.5 rounded-xl text-sm font-semibold border transition
                               {{ $preset === $clave
                                   ? 'bg-indigo-600 text-white border-indigo-600'
                                   : 'bg-white text-gray-700 border-gray-300' }}">
                    {{ $etiqueta }}
                </button>
            @endforeach
        </div>

        <p class="mt-2 text-xs text-gray-500">
            Período analizado:
            <span class="font-semibold text-gray-900">
                {{ $ini ? $ini->translatedFormat('d M Y') : 'inicio' }}
                →
                {{ $fin ? $fin->translatedFormat('d M Y') : 'hoy' }}
            </span>
            @if ($preset === 'custom')
                <span class="text-amber-600 font-semibold">· rango personalizado</span>
            @endif
        </p>
    </div>

    {{-- ================= Avisos: lo que NO se puede calcular con esta BD ================= --}}
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
        <x-heroicon name="information-circle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
        <div class="text-sm text-amber-800 leading-relaxed space-y-1">
            <p>
                <strong>Tres reportes del sistema anterior no se pueden calcular con los datos actuales:</strong>
            </p>
            <ul class="list-disc pl-5 space-y-0.5">
                <li>
                    <strong>Ventas por categoría y por producto</strong> (importe): la tabla
                    <code class="text-[11px]">venta_detalles</code> está vacía, así que no existe el vínculo
                    venta → producto.
                </li>
                <li>
                    <strong>Ventas por vendedor</strong>: <code class="text-[11px]">ventas</code> no guarda
                    el usuario que la hizo.
                </li>
                <li>
                    <strong>Margen real por venta</strong>: los {{ $kpi['sinPrecio'] }} productos están en
                    precio 0.00.
                </li>
            </ul>
            <p>
                Es un hueco de datos del sistema viejo, no un error de estas pantallas: se dicen aquí
                en vez de mostrar gráficas en cero que parecen ventas perdidas.
            </p>
        </div>
    </div>

    {{-- ================= KPIs ================= --}}
    <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <x-panel.stat etiqueta="Ventas" valor="\App\Support\Money::format($kpi['total'])" tono="indigo" icono="currency-dollar" />
        <x-panel.stat etiqueta="Cantidad" valor="\App\Support\Money::number($kpi['cantidad'])" tono="slate" icono="shopping-cart" />
        <x-panel.stat etiqueta="Ticket medio" valor="\App\Support\Money::format($kpi['ticket'])" tono="emerald" icono="receipt-percent" />
        <x-panel.stat etiqueta="Venta mayor" valor="\App\Support\Money::format($kpi['maxVenta'])" tono="slate" icono="arrow-trending-up" />
        <x-panel.stat etiqueta="Egresos" valor="\App\Support\Money::format($kpi['egresos'])" tono="rose" icono="arrow-trending-down" />
        <x-panel.stat etiqueta="Flujo neto" valor="\App\Support\Money::format($kpi['flujoNeto'])" :tono="$kpi['flujoNeto'] >= 0 ? 'emerald' : 'rose'" icono="scale" />
    </div>

    {{-- ================= Serie diaria ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-1 gap-3">
            <h3 class="font-semibold text-gray-900">Ventas y egresos por día</h3>
            <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full shrink-0">{{ count($serieDiaria['etiquetas']) }} días</span>
        </div>
        <p class="text-xs text-gray-500 mb-4">Los días sin movimiento aparecen en cero, no como huecos.</p>

        @if (count($serieDiaria['etiquetas']) > 0)
            <div class="h-64">
                <canvas id="repDiario"
                        data-etiquetas='@json($serieDiaria['etiquetas'])'
                        data-ventas='@json($serieDiaria['ventas'])'
                        data-egresos='@json($serieDiaria['egresos'])'></canvas>
            </div>
        @else
            <p class="py-8 text-center text-sm text-gray-500">No hay ventas en el período seleccionado.</p>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Ticket promedio por día --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-1">Ticket promedio por día</h3>
            <p class="text-xs text-gray-500 mb-4">Cuánto se facturó en promedio por venta, cada día</p>
            @if (count($serieDiaria['etiquetas']) > 0)
                <div class="h-56">
                    <canvas id="repTicket" data-etiquetas='@json($serieDiaria['etiquetas'])' data-valores='@json($serieDiaria['tickets'])'></canvas>
                </div>
            @else
                <p class="py-6 text-center text-sm text-gray-500">Sin datos.</p>
            @endif
        </div>

        {{-- Ventas por día de la semana --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-1">Día de la semana</h3>
            <p class="text-xs text-gray-500 mb-4">Qué día conviene tener más gente en el local</p>
            <div class="h-56">
                <canvas id="repDiaSemana"
                        data-etiquetas='@json(collect($porDiaSemana)->pluck('dia')->all())'
                        data-valores='@json(collect($porDiaSemana)->pluck('total')->map(fn ($v) => (float) $v)->all())'
                        data-ventas='@json(collect($porDiaSemana)->pluck('ventas')->all())'></canvas>
            </div>
        </div>
    </div>

    {{-- ================= Métodos de pago ================= --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-4">Métodos de pago</h3>
            @if ($porMetodo->isNotEmpty())
                <div class="h-56">
                    <canvas id="repMetodos"
                            data-etiquetas='@json($porMetodo->pluck('metodo')->all())'
                            data-valores='@json($porMetodo->pluck('total_gastado')->map(fn ($v) => (float) $v)->all())'></canvas>
                </div>
            @else
                <p class="py-6 text-center text-sm text-gray-500">Sin ventas en el período.</p>
            @endif
        </div>

        {{-- Estado de inventario (independiente del período) --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4 gap-3">
                <h3 class="font-semibold text-gray-900">Estado del inventario</h3>
                <span class="text-xs text-gray-400 shrink-0">hoy, no del período</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-emerald-50 p-3 text-center">
                    <p class="text-lg font-bold text-emerald-700">{{ $estadoStock['normal'] }}</p>
                    <p class="text-xs text-emerald-600">Normal</p>
                </div>
                <div class="rounded-xl bg-amber-50 p-3 text-center">
                    <p class="text-lg font-bold text-amber-700">{{ $estadoStock['bajo'] }}</p>
                    <p class="text-xs text-amber-600">Bajo mínimo</p>
                </div>
                <div class="rounded-xl bg-rose-50 p-3 text-center">
                    <p class="text-lg font-bold text-rose-700">{{ $estadoStock['agotado'] }}</p>
                    <p class="text-xs text-rose-600">Agotado (stock 0)</p>
                </div>
                <div class="rounded-xl bg-slate-100 p-3 text-center">
                    <p class="text-lg font-bold text-slate-700">{{ $estadoStock['negativo'] }}</p>
                    <p class="text-xs text-slate-600">Stock negativo</p>
                </div>
            </div>
            @if ($estadoStock['bajo'] == 0)
                <p class="mt-3 text-xs text-gray-500 leading-relaxed">
                    <strong>Ningún producto está en "bajo mínimo" porque ninguno tiene
                    <code class="text-[11px]">stock_minimo</code> definido.</strong> Esa alerta preventiva
                    no puede dispararse hasta que se configure en Stock.
                </p>
            @endif
        </div>
    </div>

    {{-- ================= Top clientes ================= --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4 gap-3">
            <h3 class="font-semibold text-gray-900">Mejores clientes</h3>
            <span class="text-xs text-gray-400 shrink-0">excluye ventas de mostrador</span>
        </div>

        {{-- Escritorio: tabla --}}
        <div class="hidden md:block overflow-hidden">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
                        <th class="py-2.5 pr-4 font-semibold">Cliente</th>
                        <th class="py-2.5 pr-4 font-semibold text-right">Compras</th>
                        <th class="py-2.5 pr-4 font-semibold text-right">Total</th>
                        <th class="py-2.5 font-semibold text-right">Última</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($topClientes as $c)
                        <tr>
                            <td class="py-3 pr-4 font-medium text-gray-900">{{ $c->cliente_nombre }}</td>
                            <td class="py-3 pr-4 text-right text-gray-500 tabular-nums">{{ $c->compras }}</td>
                            <td class="py-3 pr-4 text-right font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::format($c->total_gastado) }}</td>
                            <td class="py-3 text-right text-gray-500">{{ \Carbon\Carbon::parse($c->ultima)->translatedFormat('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">Sin ventas a clientes identificados en el período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Móvil: tarjetas --}}
        <div class="md:hidden space-y-2">
            @forelse ($topClientes as $c)
                <div class="rounded-xl border border-gray-200 p-3.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-medium text-gray-900 truncate">{{ $c->cliente_nombre }}</span>
                        <span class="font-bold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::format($c->total_gastado) }}</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
                        <span>{{ $c->compras }} {{ $c->compras == 1 ? 'compra' : 'compras' }}</span>
                        <span>{{ \Carbon\Carbon::parse($c->ultima)->translatedFormat('d/m/Y') }}</span>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-gray-500">Sin ventas a clientes identificados.</p>
            @endforelse
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Productos con más salidas de bodega --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-1">Productos con más salidas de bodega</h3>
            <p class="text-xs text-gray-500 mb-4">Movimientos de inventario, no ventas</p>
            @forelse ($topProductos as $p)
                <div class="flex items-center justify-between py-2 border-t first:border-t-0 border-gray-100 gap-3">
                    <span class="text-sm text-gray-700 truncate">{{ $p->nombre }}</span>
                    <span class="text-sm font-semibold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::number($p->unidades) }} und.</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">
                    No hay movimientos de salida registrados.
                </p>
            @endforelse
            <p class="mt-3 text-[11px] text-gray-500 leading-relaxed">
                Este ranking sale de <code class="text-[10px]">inventario_movimientos</code> porque
                <code class="text-[10px]">venta_detalles</code> está vacía. Sirve para saber qué se mueve,
                no para cuánto se vendió.
            </p>
        </div>

        {{-- Proveedores con deuda --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4 gap-3">
                <h3 class="font-semibold text-gray-900">Proveedores y su deuda</h3>
                <a href="{{ route('proveedores') }}" class="text-xs text-indigo-600 hover:underline shrink-0">Ver todos</a>
            </div>
            @forelse ($proveedoresDeuda as $p)
                <div class="flex items-center justify-between py-2 border-t first:border-t-0 border-gray-100 gap-3">
                    <span class="text-sm text-gray-700 truncate">{{ $p->nombre }}</span>
                    <span class="text-sm font-semibold tabular-nums shrink-0 {{ $p->saldo_deuda > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                        {{ \App\Support\Money::cents($p->saldo_deuda) }}
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Sin proveedores registrados.</p>
            @endforelse
        </div>
    </div>

    {{-- ================= Tablas de apoyo ================= --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Clientes --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-4">Clientes registrados</h3>
            <div class="hidden md:block overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
                            <th class="py-2.5 pr-4 font-semibold">Cliente</th>
                            <th class="py-2.5 pr-4 font-semibold text-right">Compras</th>
                            <th class="py-2.5 font-semibold text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($clientes as $c)
                            <tr>
                                <td class="py-3 pr-4 text-gray-900">
                                    {{ $c->nombre }}
                                    <span class="block text-[11px] text-gray-400">{{ $c->telefono ?: 'sin teléfono' }}</span>
                                </td>
                                <td class="py-3 pr-4 text-right text-gray-500 tabular-nums">{{ $c->compras }}</td>
                                <td class="py-3 text-right font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::format($c->total_gastado) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-gray-500">Sin clientes registrados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="md:hidden space-y-2">
                @forelse ($clientes as $c)
                    <div class="rounded-xl border border-gray-200 p-3.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block text-sm font-medium text-gray-900 truncate">{{ $c->nombre }}</span>
                            <span class="block text-xs text-gray-500">{{ $c->telefono ?: 'sin teléfono' }} · {{ $c->compras }} compras</span>
                        </div>
                        <span class="font-bold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::format($c->total_gastado) }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-gray-500">Sin clientes registrados.</p>
                @endforelse
            </div>
        </div>

        {{-- Pedidos --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4 gap-3">
                <h3 class="font-semibold text-gray-900">Pedidos del período</h3>
                <a href="{{ route('pedidos') }}" class="text-xs text-indigo-600 hover:underline shrink-0">Ver todos</a>
            </div>
            @forelse ($pedidos as $p)
                <div class="flex items-center justify-between py-2 border-t first:border-t-0 border-gray-100 gap-3">
                    <div class="min-w-0">
                        <span class="block text-sm text-gray-900 truncate">{{ $p->numero_pedido }}</span>
                        <span class="block text-[11px] text-gray-400">
                            {{ $p->fecha_pedido?->translatedFormat('d/m/Y') }} · {{ $p->cliente_nombre ?: 'Sin cliente' }}
                        </span>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="block text-sm font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::format($p->total) }}</span>
                        <span class="block text-[11px] text-gray-400">{{ $p->estado }}</span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Sin pedidos en el período.</p>
            @endforelse
        </div>
    </div>

    {{-- Devoluciones --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4 gap-3">
            <h3 class="font-semibold text-gray-900">Devoluciones del período</h3>
            <a href="{{ route('devoluciones') }}" class="text-xs text-indigo-600 hover:underline shrink-0">Ver todas</a>
        </div>
        @forelse ($devoluciones as $d)
            <div class="flex items-start justify-between py-2 border-t first:border-t-0 border-gray-100 gap-3">
                <div class="min-w-0">
                    <span class="block text-sm text-gray-900">
                        Venta #{{ $d->venta_id }} · {{ $d->cliente_nombre ?: 'Sin cliente' }}
                    </span>
                    <span class="block text-[11px] text-gray-500">{{ $d->motivo }}</span>
                </div>
                <div class="text-right shrink-0">
                    <span class="block text-sm font-semibold text-rose-600 tabular-nums">{{ \App\Support\Money::format($d->total_devuelto) }}</span>
                    <span class="block text-[11px] text-gray-400">{{ $d->fecha?->translatedFormat('d/m/Y') }}</span>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Sin devoluciones en el período.</p>
        @endforelse
    </div>

    <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3">
        <p class="text-xs text-gray-500 leading-relaxed">
            Los valores salen de <code class="text-[11px]">ventas</code> (solo estado
            <em>Completada</em>) y de <code class="text-[11px]">movimientos_caja</code>. Las ventas anuladas
            del período son {{ $kpi['anuladas'] }} y están excluidas de todas las cifras de este reporte.
            El mejor día del período: {{ $kpi['mejorDia'][0]?->translatedFormat('d/m/Y') ?? '—' }}
            ({{ \App\Support\Money::format($kpi['mejorDia'][1]) }} en {{ $kpi['mejorDia'][2] }} ventas).
        </p>
    </div>
</div>

{{-- `window.Chart` es global (lo carga app.js). El layout no tiene @stack, asi
     que el script va aqui, igual que en Dashboard y Caja. --}}
<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof window.Chart === 'undefined') return;
        const Chart = window.Chart;
        const cop = (v) => '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v);
        const grid = '#f1f5f9';
        const leer = (id) => document.getElementById(id);
        const ejeY = (fmt) => ({
            grid: { color: grid }, border: { display: false },
            ticks: { color: '#94a3b8', font: { size: 10 }, callback: fmt },
        });

        // Serie diaria: ventas + egresos
        const diario = leer('repDiario');
        if (diario) {
            new Chart(diario, {
                type: 'bar',
                data: {
                    labels: JSON.parse(diario.dataset.etiquetas),
                    datasets: [
                        { label: 'Ventas', data: JSON.parse(diario.dataset.ventas), backgroundColor: '#6366f1', borderRadius: 4 },
                        { label: 'Egresos', data: JSON.parse(diario.dataset.egresos), backgroundColor: '#fb7185', borderRadius: 4 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, labels: { boxWidth: 10, usePointStyle: true, color: '#64748b', font: { size: 11 } } },
                        tooltip: { callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + cop(c.parsed.y) } },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                        y: ejeY(cop),
                    },
                },
            });
        }

        // Ticket promedio
        const ticket = leer('repTicket');
        if (ticket) {
            new Chart(ticket, {
                type: 'line',
                data: {
                    labels: JSON.parse(ticket.dataset.etiquetas),
                    datasets: [{
                        data: JSON.parse(ticket.dataset.valores),
                        borderColor: '#10b981', backgroundColor: '#10b98120',
                        fill: true, tension: 0.35, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 4,
                    }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + cop(c.parsed.y) } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                        y: ejeY(cop),
                    },
                },
            });
        }

        // Día de la semana
        const diaSemana = leer('repDiaSemana');
        if (diaSemana) {
            const ventas = JSON.parse(diaSemana.dataset.ventas);
            new Chart(diaSemana, {
                type: 'bar',
                data: {
                    labels: JSON.parse(diaSemana.dataset.etiquetas),
                    datasets: [{
                        data: JSON.parse(diaSemana.dataset.valores),
                        backgroundColor: '#8b5cf6', borderRadius: 6,
                    }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (c) => ' ' + cop(c.parsed.y),
                                afterLabel: (c) => ' ' + (ventas[c.dataIndex] ?? 0) + ' ventas',
                            },
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } },
                        y: ejeY(cop),
                    },
                },
            });
        }

        // Métodos de pago
        const metodos = leer('repMetodos');
        if (metodos) {
            new Chart(metodos, {
                type: 'doughnut',
                data: {
                    labels: JSON.parse(metodos.dataset.etiquetas),
                    datasets: [{
                        data: JSON.parse(metodos.dataset.valores),
                        backgroundColor: ['#6366f1', '#06b6d4', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6'],
                        borderWidth: 2, borderColor: '#ffffff',
                    }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + c.label + ': ' + cop(c.parsed) } } },
                },
            });
        }
    });
</script>
