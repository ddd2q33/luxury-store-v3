<x-panel-layout>
    {{-- El titulo va DENTRO de la vista, no en un slot del layout: el layout ya
         no tiene header (ver AGENTS.md). Antes usaba <x-slot name="header"> y
         nunca se renderizo, por eso /dashboard salia sin titulo. --}}
    <div class="p-4 sm:p-6 lg:p-8 space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                    <x-heroicon name="chart-line" class="w-6 h-6 text-indigo-600" />
                    Home
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">Resumen del negocio en tiempo real</p>
            </div>
            <div class="flex items-center gap-2 bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm text-gray-600 shrink-0">
                <x-heroicon name="clock" class="w-4 h-4" />
                <span class="hidden sm:inline">Hoy es</span>
                <span class="font-semibold text-gray-900">{{ now()->translatedFormat('d M Y') }}</span>
            </div>
        </div>
        {{-- ================= KPIs ================= --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500">Ventas hoy</p>
                    <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600">
                        <x-heroicon name="currency-dollar" class="w-5 h-5" />
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['ventasHoy']) }}</p>
                <p class="mt-1 text-xs text-gray-500 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    {{-- El porcentaje YA NO se come el contador: antes el @else solo
                         se alcanzaba cuando ventasAyer era 0, o sea casi nunca. --}}
                    @if ($kpi['variacionAyer'] !== null)
                        <span class="{{ $kpi['variacionAyer'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }} font-semibold">
                            {{ $kpi['variacionAyer'] >= 0 ? '▲' : '▼' }} {{ \App\Support\Money::number(abs($kpi['variacionAyer'])) }}%
                        </span>
                        <span>vs ayer ·</span>
                    @endif
                    <span>{{ $kpi['numVentasHoy'] }} {{ $kpi['numVentasHoy'] == 1 ? 'venta' : 'ventas' }} · ticket {{ \App\Support\Money::format($kpi['ticketPromedio']) }}</span>
                </p>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500">Ventas del mes</p>
                    <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600">
                        <x-heroicon name="trending-up" class="w-5 h-5" />
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['ventasMes']) }}</p>
                <p class="mt-1 text-xs text-gray-500">Histórico: {{ \App\Support\Money::format($kpi['ventasHistorico']) }}</p>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500">Gastos del mes</p>
                    <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-rose-50 text-rose-600">
                        <x-heroicon name="receipt-percent" class="w-5 h-5" />
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['gastosMes']) }}</p>
                <p class="mt-1 text-xs text-gray-500">Hoy: {{ \App\Support\Money::format($kpi['gastosHoy']) }} · Año: {{ \App\Support\Money::format($kpi['gastosAno']) }}</p>
            </div>

            {{-- Balance y margen: los dos números que el legacy tenía
                 (dashboard/index.php:901 y :905) y se perdieron al migrar. --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500">Balance del mes</p>
                    <span class="flex items-center justify-center w-9 h-9 rounded-xl {{ $kpi['balanceMes'] >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' }}">
                        <x-heroicon name="scale" class="w-5 h-5" />
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums {{ $kpi['balanceMes'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    {{ $kpi['balanceMes'] >= 0 ? '' : '-' }}{{ \App\Support\Money::format(abs($kpi['balanceMes'])) }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    Margen {{ \App\Support\Money::number($kpi['margenMes']) }}% · ventas {{ \App\Support\Money::format($kpi['ventasMes']) }} → gastos {{ \App\Support\Money::format($kpi['gastosMes']) }}
                </p>
                @if ($kpi['gastosMes'] == 0 && $kpi['ventasMes'] > 0)
                    <p class="mt-1.5 text-[11px] text-amber-600 leading-tight">
                        Sin egresos registrados este mes: el margen del 100% no es real todavía.
                    </p>
                @endif
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500">Saldo en caja</p>
                    <span class="flex items-center justify-center w-9 h-9 rounded-xl {{ $kpi['cajaAbierta'] ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-400' }}">
                        <x-heroicon name="banknotes" class="w-5 h-5" />
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['saldoCaja']) }}</p>
                <p class="mt-1 text-xs {{ $kpi['cajaAbierta'] ? 'text-emerald-600' : 'text-gray-500' }}">
                    {{ $kpi['cajaAbierta'] ? 'Caja abierta' : 'Sin caja abierta' }}
                </p>
            </div>
        </div>

        {{-- ================= Alertas de pedidos =================
             Un pedido solo avisa cuando hay algo que hacer HOY: confirmar uno
             nuevo o perseguir una entrega ya vencida. Los "listos" NO cuentan
             como alerta (no requieren una decisión, solo que alguien los recoja)
             y los cerrados (entregado/cancelado) ya no son nada. Por eso el
             número del contador es pedidosPendientes + pedidosVencidos. --}}
        @php
            $hayAlertaPedidos = $kpi['pedidosPorAtender'] > 0;
            $tonoPedidos = $hayAlertaPedidos
                ? ['caja' => 'border-amber-200 bg-amber-50', 'icono' => 'bg-amber-100 text-amber-600', 'titulo' => 'text-amber-900', 'texto' => 'text-amber-800', 'boton' => 'bg-amber-600 text-white hover:bg-amber-700']
                : ['caja' => 'border-emerald-200 bg-emerald-50', 'icono' => 'bg-emerald-100 text-emerald-600', 'titulo' => 'text-emerald-900', 'texto' => 'text-emerald-800', 'boton' => 'bg-emerald-600 text-white hover:bg-emerald-700'];

            // Solo se pintan los chips que tienen algo que contar. Un "en
            // preparación" con valor 0 no ocupa espacio ni distrae.
            $chipsPedidos = array_values(array_filter([
                ['n' => $kpi['pedidosPendientes'], 'txt' => 'sin confirmar', 'clase' => 'bg-amber-100 text-amber-800'],
                ['n' => $kpi['pedidosVencidos'], 'txt' => 'con entrega vencida', 'clase' => 'bg-rose-100 text-rose-800'],
                ['n' => $kpi['pedidosEntregaHoy'], 'txt' => 'se entregan hoy', 'clase' => 'bg-indigo-100 text-indigo-800'],
                ['n' => $kpi['pedidosEnProceso'], 'txt' => 'en preparación', 'clase' => 'bg-gray-100 text-gray-700'],
                ['n' => $kpi['pedidosListos'], 'txt' => 'listos para recoger', 'clase' => 'bg-emerald-100 text-emerald-800'],
            ], fn ($c) => $c['n'] > 0));
        @endphp

        <div class="rounded-2xl border px-4 py-3 sm:px-5 {{ $tonoPedidos['caja'] }}">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                <span class="flex items-center justify-center w-10 h-10 rounded-xl shrink-0 {{ $tonoPedidos['icono'] }}">
                    <x-heroicon :name="$hayAlertaPedidos ? 'bell-alert' : 'check-circle'" class="w-5 h-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold {{ $tonoPedidos['titulo'] }}">
                        @if ($hayAlertaPedidos)
                            {{ \App\Support\Money::number($kpi['pedidosPorAtender']) }}
                            {{ $kpi['pedidosPorAtender'] == 1 ? 'pedido necesita' : 'pedidos necesitan' }} tu atención
                        @else
                            Ningún pedido necesita atención
                        @endif
                    </p>

                    @if ($chipsPedidos)
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @foreach ($chipsPedidos as $chip)
                                <span class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs {{ $chip['clase'] }}">
                                    <span class="font-bold tabular-nums">{{ \App\Support\Money::number($chip['n']) }}</span>
                                    {{ $chip['txt'] }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs {{ $tonoPedidos['texto'] }} mt-1 leading-relaxed">
                            No hay pedidos pendientes ni entregas vencidas.
                            @if ($kpi['pedidosTotal'] > 0)
                                Hay {{ \App\Support\Money::number($kpi['pedidosTotal']) }} en el histórico.
                            @endif
                        </p>
                    @endif
                </div>

                <div class="flex flex-col sm:flex-row lg:flex-col xl:flex-row items-stretch sm:items-center gap-2 shrink-0">
                    <div class="text-left lg:text-right xl:text-left">
                        <p class="text-[11px] uppercase tracking-wider font-semibold {{ $tonoPedidos['texto'] }}">Comprometido</p>
                        <p class="text-base font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['pedidosMontoAbierto']) }}</p>
                    </div>
                    <a href="{{ route('pedidos') }}"
                       class="inline-flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-xl text-sm font-semibold transition-colors {{ $tonoPedidos['boton'] }}">
                        <x-heroicon name="clipboard-list" class="w-4 h-4" />
                        Ver pedidos
                    </a>
                </div>
            </div>
        </div>
        {{-- ================= Avisos de datos que hacen que un KPI no sirva ================= --}}
        @if ($kpi['valorInventario'] == 0 && $kpi['totalProductos'] > 0)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-3">
                <x-heroicon name="exclamation-triangle" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                <p class="text-sm text-amber-800 leading-relaxed">
                    <strong>El valor del inventario aparece en $0</strong> porque
                    <strong>ninguno de los {{ \App\Support\Money::number($kpi['totalProductos']) }} productos tiene precio de venta</strong>
                    (están en 0.00). Hasta que los cargues, el inventario, los márgenes y los reportes por
                    categoría no pueden dar cifras reales.
                    <a href="{{ route('productos') }}" class="font-semibold underline">Cargar precios</a>
                </p>
            </div>
        @endif

        @if ($kpi['productosNegativos'] > 0)
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 flex items-start gap-3">
                <x-heroicon name="exclamation-circle" class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                <p class="text-sm text-rose-800 leading-relaxed">
                    <strong>{{ $kpi['productosNegativos'] }} {{ $kpi['productosNegativos'] == 1 ? 'producto tiene' : 'productos tienen' }} stock negativo.</strong>
                    El sistema anterior los dejaba pasar y por eso la suma de unidades da
                    {{ \App\Support\Money::number($kpi['stockTotalUnidades']) }}.
                    Corrígelos con un ajuste de inventario, nunca editando el producto a mano.
                    <a href="{{ route('stock') }}" class="font-semibold underline">Ir a Stock</a>
                </p>
            </div>
        @endif

        {{-- ================= Gráficas principales ================= --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between mb-4 gap-3">
                    <h3 class="font-semibold text-gray-900">Ventas vs gastos (7 días)</h3>
                    <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full shrink-0">Tendencia</span>
                </div>
                <div class="h-64">
                    <canvas id="chartVentas7"
                            data-labels='@json($graficas['labels7'])'
                            data-ventas='@json($graficas['ventas7'])'
                            data-gastos='@json($graficas['gastos7'])'></canvas>
                </div>
                {{-- La serie de gastos ya se calculaba pero NUNCA se pintaba: era una
                     de las dos líneas que el legacy tenía en esta gráfica. --}}
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-indigo-500"></span> Ventas
                        {{ \App\Support\Money::format(array_sum($graficas['ventas7'])) }} en 7 días
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-rose-400"></span> Egresos
                        {{ \App\Support\Money::format(array_sum($graficas['gastos7'])) }} en 7 días
                    </span>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-900 mb-4">Métodos de pago</h3>
                <div class="h-56">
                    <canvas id="chartMetodosPago"
                            data-labels='@json($graficas['metodosPago']->pluck('metodo_pago')->all())'
                            data-valores='@json($graficas['metodosPago']->pluck('total')->map(fn ($v) => (float) $v)->all())'></canvas>
                </div>
                <div class="mt-3 space-y-1.5">
                    @foreach ($graficas['metodosPago'] as $mp)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600">{{ $mp->metodo_pago }}</span>
                            <span class="font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::format($mp->total) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Esta tarjeta va DENTRO de la misma rejilla: ocupa una fila propia
                 de ancho completo. Antes se coloco fuera de la rejilla y dejo un
                 cierre de mas que cerraba el contenedor de la pagina antes de
                 tiempo (todo el dashboard inferior se quedaba sin padding ni
                 space-y-6). --}}
            <div class="lg:col-span-3 bg-white rounded-2xl border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-900 mb-1">Ventas por día de la semana</h3>
                <p class="text-xs text-gray-500 mb-3">Últimos 12 meses · qué día hay que abrir más personal</p>
                <div class="h-52">
                    <canvas id="chartDiaSemana"
                            data-labels='@json($graficas['labelsDiaSemana'])'
                            data-valores='@json($graficas['ventasPorDiaSemana'])'></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-900 mb-4">Evolución mensual (12 meses)</h3>
                <div class="h-64">
                    <canvas id="chartMensual"
                            data-labels='@json($graficas['labels12'])'
                            data-valores='@json($graficas['ventas12'])'></canvas>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <h3 class="font-semibold text-gray-900 mb-4">Ventas por hora (30 días)</h3>
                <div class="h-64">
                    <canvas id="chartHoras"
                            data-valores='@json($graficas['ventasPorHora'])'></canvas>
                </div>
            </div>
        </div>

        {{-- ================= Inventario y proveedores ================= --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center justify-between mb-4 gap-3">
                    <h3 class="font-semibold text-gray-900">Control de stock</h3>
                    <span class="text-xs text-gray-500 text-right">{{ \App\Support\Money::number($kpi['totalProductos']) }} productos · {{ \App\Support\Money::format($kpi['valorInventario']) }} en inventario</span>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                    <div class="rounded-xl bg-emerald-50 p-3 text-center">
                        <p class="text-lg font-bold text-emerald-700">{{ $kpi['productosStockNormal'] }}</p>
                        <p class="text-xs text-emerald-600">Normal</p>
                    </div>
                    <div class="rounded-xl bg-amber-50 p-3 text-center">
                        <p class="text-lg font-bold text-amber-700">{{ $kpi['productosBajoStock'] }}</p>
                        <p class="text-xs text-amber-600">Bajo mínimo</p>
                    </div>
                    <div class="rounded-xl bg-rose-50 p-3 text-center">
                        <p class="text-lg font-bold text-rose-700">{{ $kpi['productosAgotados'] }}</p>
                        <p class="text-xs text-rose-600">Agotado (stock 0)</p>
                    </div>
                    <div class="rounded-xl bg-slate-100 p-3 text-center">
                        <p class="text-lg font-bold text-slate-700">{{ $kpi['productosNegativos'] }}</p>
                        <p class="text-xs text-slate-600">Stock negativo</p>
                    </div>
                </div>
                @forelse ($urgentesInventario as $p)
                    @php $estado = $p->estadoStock(); @endphp
                    <div class="flex items-center justify-between gap-3 py-2 border-t border-gray-100">
                        <div class="min-w-0">
                            <span class="block text-sm text-gray-700 truncate">{{ $p->nombre }}</span>
                            @if ($p->stock_minimo > 0)
                                <span class="block text-[10px] text-gray-400 tabular-nums">mín. {{ $p->stock_minimo }} und.</span>
                            @endif
                        </div>
                        <span class="text-sm font-semibold tabular-nums shrink-0
                            @switch($estado)
                                @case('negativo') text-rose-600 @break
                                @case('agotado') text-rose-600 @break
                                @default text-amber-600 @endswitch">
                            {{ $p->stock }} und.
                        </span>
                    </div>
                @empty
                    <p class="py-3 text-sm text-gray-500 border-t border-gray-100">Todo el stock está en niveles normales.</p>
                @endforelse
                <a href="{{ route('stock') }}"
                   class="mt-3 flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-xl bg-indigo-50 text-indigo-700 text-sm font-semibold hover:bg-indigo-100">
                    Ver y reponer
                </a>
                @if ($kpi['productosSinMinimo'] > 0)
                    <p class="mt-2 text-xs text-gray-500 leading-relaxed">
                        <strong class="text-gray-700">{{ \App\Support\Money::number($kpi['productosSinMinimo']) }}</strong>
                        productos con existencias no tienen stock mínimo definido, así que todavía no pueden marcarse como
                        <span class="text-amber-600 font-semibold">bajo</span>. Defínelo en
                        <a href="{{ route('productos') }}" class="text-indigo-600 underline">Productos</a> o al reponer en Stock.
                    </p>
                @endif
            </div>

            <div class="space-y-6">
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <h3 class="font-semibold text-gray-900 mb-4">Proveedores</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-gray-50 p-3">
                            <p class="text-lg font-bold text-gray-900">{{ $kpi['totalProveedores'] }}</p>
                            <p class="text-xs text-gray-500">Proveedores</p>
                        </div>
                        <div class="rounded-xl bg-rose-50 p-3">
                            <p class="text-lg font-bold text-rose-700">{{ \App\Support\Money::format($kpi['deudaProveedores']) }}</p>
                            <p class="text-xs text-rose-600">Deuda total</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3">
                            <p class="text-lg font-bold text-gray-900">{{ \App\Support\Money::format($kpi['abonosMes']) }}</p>
                            <p class="text-xs text-gray-500">Abonos del mes</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3">
                            <p class="text-lg font-bold text-gray-900">{{ \App\Support\Money::format($kpi['comprasMes']) }}</p>
                            <p class="text-xs text-gray-500">Compras del mes</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-4 gap-3">
                        <h3 class="font-semibold text-gray-900">Top clientes</h3>
                        <span class="text-xs text-gray-400 shrink-0">clientes reales</span>
                    </div>
                    @forelse ($topClientes as $c)
                        <div class="flex items-center justify-between py-2 border-t first:border-t-0 border-gray-100 gap-3">
                            <span class="text-sm text-gray-700 truncate">{{ $c->cliente_nombre }}</span>
                            <span class="text-sm font-semibold text-gray-900 tabular-nums shrink-0">{{ \App\Support\Money::format($c->total_gastado) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Sin ventas registradas a clientes identificados.</p>
                    @endforelse

                    {{-- Aviso clave: sin esto el dueño cree que "Cliente General" es
                         su mejor cliente, cuando son 524 ventas de mostrador. --}}
                    <div class="mt-3 pt-3 border-t border-gray-100">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm text-gray-600">Ventas de mostrador</span>
                            <span class="text-sm font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::number($kpi['ventasMostrador']) }}</span>
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500 leading-relaxed">
                            Ventas guardadas con <span class="font-mono">cliente_id = 0</span> y nombre
                            «Cliente General». No son un cliente: quedan fuera del ranking de arriba.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================= Operación: KPIs que el controller ya calculaba pero
             que nunca se pintaban en ninguna vista ================= --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-1">Resumen del negocio</h3>
            <p class="text-xs text-gray-500 mb-4">Acumulados históricos y estado de pedidos, devoluciones y caja</p>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                <div class="rounded-xl bg-gray-50 p-3">
                    <p class="text-base font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['ventasHistorico']) }}</p>
                    <p class="text-xs text-gray-500 leading-tight mt-0.5">Facturación histórica</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-3">
                    <p class="text-base font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['gastosAno']) }}</p>
                    <p class="text-xs text-gray-500 leading-tight mt-0.5">Egresos del año
                        <span class="text-gray-400">· semana {{ \App\Support\Money::format($kpi['gastosSemana']) }}</span>
                    </p>
                </div>
                <div class="rounded-xl bg-gray-50 p-3">
                    <p class="text-base font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::number($kpi['totalClientes']) }}</p>
                    <p class="text-xs text-gray-500 leading-tight mt-0.5">Clientes registrados
                        @if ($kpi['clientesNuevosMes'] > 0)
                            <span class="text-emerald-600 font-semibold">+{{ $kpi['clientesNuevosMes'] }} este mes</span>
                        @endif
                    </p>
                </div>
                <div class="rounded-xl bg-gray-50 p-3">
                    <p class="text-base font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::number($kpi['totalCategorias']) }}</p>
                    <p class="text-xs text-gray-500 leading-tight mt-0.5">Categorías
                        <span class="text-gray-400">· {{ \App\Support\Money::number($kpi['totalProductos']) }} productos</span>
                    </p>
                </div>
                <div class="rounded-xl bg-gray-50 p-3">
                    <p class="text-base font-bold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['comprasHistorico']) }}</p>
                    <p class="text-xs text-gray-500 leading-tight mt-0.5">Compras históricas
                        <span class="text-gray-400">· {{ $kpi['comprasRegistradas'] }} registradas</span>
                    </p>
                </div>
                <div class="rounded-xl bg-gray-50 p-3">
                    <p class="text-base font-bold text-gray-900 tabular-nums">
                        {{ $kpi['proveedoresConDeuda'] }}<span class="text-sm font-semibold text-gray-400">/{{ $kpi['totalProveedores'] }}</span>
                    </p>
                    <p class="text-xs text-gray-500 leading-tight mt-0.5">Proveedores con deuda</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 pt-4 border-t border-gray-100">
                {{-- Pedidos por estado. Las etiquetas salen de Pedido::ETIQUETAS para
                     no repetir el mapa en cada vista. --}}
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Pedidos</p>
                    @if ($kpi['pedidosTotal'] == 0)
                        <p class="text-sm text-gray-500">Sin pedidos registrados.</p>
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($kpi['pedidosEstados'] as $estado => $totalPedidos)
                                <span class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs {{ in_array($estado, \App\Models\Pedido::ESTADOS_ABIERTOS, true) ? 'bg-indigo-50 text-indigo-800' : 'bg-gray-50 text-gray-500' }}">
                                    {{ \App\Models\Pedido::ETIQUETAS[$estado] ?? $estado }}
                                    <span class="font-bold text-gray-900 tabular-nums">{{ $totalPedidos }}</span>
                                </span>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-[11px] text-gray-400">
                            {{ \App\Support\Money::number($kpi['pedidosTotal']) }} en total
                            @if ($kpi['pedidosAbiertos'] > 0)
                                · {{ \App\Support\Money::number($kpi['pedidosAbiertos']) }} abiertos por
                                {{ \App\Support\Money::format($kpi['pedidosMontoAbierto']) }}
                            @endif
                        </p>
                    @endif
                </div>

                {{-- Devoluciones 30 días --}}
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Devoluciones (30 días)</p>
                    @if ($kpi['devoluciones30'] == 0)
                        <p class="text-sm text-gray-500">Sin devoluciones registradas.</p>
                    @else
                        <p class="text-sm text-gray-700">
                            <span class="font-bold text-gray-900 tabular-nums">{{ $kpi['devoluciones30'] }}</span>
                            {{ $kpi['devoluciones30'] == 1 ? 'devolución por' : 'devoluciones por' }}
                            <span class="font-semibold tabular-nums">{{ \App\Support\Money::format($kpi['devoluciones30Monto']) }}</span>
                        </p>
                    @endif
                </div>

                {{-- Flujo de caja del día --}}
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Flujo de caja hoy</p>
                    <dl class="space-y-1 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-gray-500">Ventas</dt>
                            <dd class="font-semibold text-gray-900 tabular-nums">{{ \App\Support\Money::format($kpi['ventasMovHoy']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-gray-500">Otros ingresos</dt>
                            <dd class="font-semibold text-emerald-600 tabular-nums">{{ \App\Support\Money::format($kpi['ingresosMovHoy']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-gray-500">Egresos</dt>
                            <dd class="font-semibold text-rose-600 tabular-nums">→{{ \App\Support\Money::format($kpi['egresosMovHoy']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 pt-1 mt-1 border-t border-gray-100">
                            <dt class="text-gray-700 font-medium">Flujo neto</dt>
                            <dd class="font-bold tabular-nums {{ $kpi['balanceMov'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ \App\Support\Money::format($kpi['balanceMov']) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        {{-- ================= Cola de pedidos =================
             La cola de trabajo real: los pedidos ABIERTOS más antiguos, con los
             vencidos primero. No es una tabla con scroll: cada pedido es una
             fila que se reordena sola en móvil, así que se lee igual a 375px
             que a 1280px. El flag `vencido` lo calcula el controller (ver
             DashboardController), nunca la vista. --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div class="min-w-0">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <x-heroicon name="truck" class="w-5 h-5 text-indigo-500" />
                        Cola de pedidos
                    </h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        @if ($kpi['pedidosAbiertos'] > 0)
                            {{ \App\Support\Money::number($kpi['pedidosAbiertos']) }} abiertos · primero los más antiguos
                        @else
                            Nada pendiente de despachar
                        @endif
                    </p>
                </div>
                <a href="{{ route('pedidos') }}"
                   class="inline-flex items-center justify-center gap-1.5 min-h-[44px] px-3 rounded-xl text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 transition-colors shrink-0">
                    Ver todos
                </a>
            </div>

            @if ($pedidosCola->isEmpty())
                <div class="py-6 text-center">
                    <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-100 text-gray-400 mb-3">
                        <x-heroicon name="clipboard-list" class="w-6 h-6" />
                    </span>
                    <p class="text-sm font-medium text-gray-700">No hay pedidos abiertos</p>
                    <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto leading-relaxed">
                        Cuando registres un pedido aparecerá aquí, ordenado por fecha de entrega
                        y con los vencidos arriba.
                    </p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 -mx-1">
                    @foreach ($pedidosCola as $pedido)
                        <li>
                            <a href="{{ route('pedidos') }}"
                               class="flex items-center gap-3 sm:gap-4 min-h-[44px] px-1 py-3 rounded-lg hover:bg-gray-50 transition-colors">
                                {{-- Número + estado. En móvil el estado va debajo del
                                     número, en escritorio al lado. --}}
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-semibold text-gray-900 text-sm">{{ $pedido->numero_pedido }}</span>
                                        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-semibold {{ $pedido->vencido ? 'bg-rose-100 text-rose-800' : 'bg-indigo-50 text-indigo-800' }}">
                                            {{ \App\Models\Pedido::ETIQUETAS[$pedido->estado] ?? $pedido->estado }}
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 truncate">
                                        {{ $pedido->cliente_nombre }}
                                        @if ($pedido->cliente_telefono)
                                            <span class="text-gray-400">· {{ $pedido->cliente_telefono }}</span>
                                        @endif
                                    </p>
                                </div>

                                {{-- Entrega: el dato que decide la urgencia. --}}
                                <div class="text-left sm:text-right shrink-0 max-w-[45%] sm:max-w-none">
                                    @if ($pedido->fecha_entrega === null)
                                        <span class="inline-flex items-center gap-1 text-xs text-gray-400">
                                            <x-heroicon name="clock" class="w-3.5 h-3.5" />
                                            Sin fecha
                                        </span>
                                    @elseif ($pedido->vencido)
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-rose-700">
                                            <x-heroicon name="exclamation-triangle" class="w-3.5 h-3.5" />
                                            {{ $pedido->diasAtraso }}
                                            {{ $pedido->diasAtraso == 1 ? 'día de retraso' : 'días de retraso' }}
                                        </span>
                                    @elseif ($pedido->fecha_entrega->isSameDay(today()))
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-700">
                                            <x-heroicon name="truck" class="w-3.5 h-3.5" />
                                            Se entrega hoy
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-500">
                                            Entrega {{ $pedido->fecha_entrega->format('d/m/Y') }}
                                        </span>
                                    @endif
                                    <p class="text-sm font-bold text-gray-900 tabular-nums mt-0.5">
                                        {{ \App\Support\Money::format($pedido->total) }}
                                    </p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        {{-- ================= Ventas recientes (Livewire) ================= --}}
        <div class="pt-2">
            @livewire('ventas-recientes')
        </div>
    </div>

    @php
        $configCharts = [
            'labels7' => $graficas['labels7'],
            'ventas7' => $graficas['ventas7'],
            'gastos7' => $graficas['gastos7'],
            'labels12' => $graficas['labels12'],
            'ventas12' => $graficas['ventas12'],
            'horas' => $graficas['ventasPorHora'],
            'diaSemana' => $graficas['ventasPorDiaSemana'],
        ];
    @endphp
    {{-- `window.Chart` es global (lo carga app.js). Sin esta guarda, el bloque
         no debe romperse aunque Chart todavía no haya llegado. --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof window.Chart === 'undefined') return;
            const Chart = window.Chart;
            const formatCOP = (v) => '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v);

            // Un <canvas> no lo cubre el CSS: los colores de la grilla, las
            // etiquetas y el borde del dona hay que darlos a mano. Ademas hay que
            // REDIBUJAR las gráficas cuando cambia el tema, o en modo oscuro se
            // quedan con la grilla clarisima sobre el fondo oscuro.
            const paleta = () => {
                const oscuro = document.documentElement.classList.contains('dark');
                return {
                    grid: oscuro ? '#1e2a44' : '#f1f5f9',
                    tick: oscuro ? '#8fa1bd' : '#94a3b8',
                    leyenda: oscuro ? '#8fa1bd' : '#64748b',
                    borde: oscuro ? '#111a2e' : '#ffffff',
                };
            };

            let graficas = [];

            function crearGraficas() {
                graficas.forEach((g) => g.destroy());
                graficas = [];

                const T = paleta();
                const gridColor = T.grid;

                const lineDefaults = {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: (ctx) => ' ' + formatCOP(ctx.parsed.y ?? ctx.parsed) } },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: T.tick, font: { size: 11 } } },
                        y: { grid: { color: gridColor }, border: { display: false }, ticks: { color: T.tick, font: { size: 11 }, callback: (v) => formatCOP(v) } },
                    },
                };

                const datasetsLine = (color) => [{
                    data: [],
                    borderColor: color,
                    backgroundColor: color + '20',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }];

                // Ventas 7 días vs gastos
                const ventas7 = document.getElementById('chartVentas7');
                if (ventas7) {
                    graficas.push(new Chart(ventas7, {
                        type: 'line',
                        data: {
                            labels: JSON.parse(ventas7.dataset.labels),
                            datasets: [
                                {
                                    label: 'Ventas',
                                    data: JSON.parse(ventas7.dataset.ventas),
                                    borderColor: '#6366f1',
                                    backgroundColor: '#6366f120',
                                    fill: true,
                                    tension: 0.35,
                                    borderWidth: 2.5,
                                    pointRadius: 3,
                                    pointHoverRadius: 5,
                                },
                                {
                                    label: 'Egresos',
                                    data: JSON.parse(ventas7.dataset.gastos),
                                    borderColor: '#fb7185',
                                    backgroundColor: '#fb718520',
                                    fill: true,
                                    tension: 0.35,
                                    borderWidth: 2.5,
                                    borderDash: [5, 4],
                                    pointRadius: 3,
                                    pointHoverRadius: 5,
                                },
                            ],
                        },
                        options: { ...lineDefaults, plugins: { ...lineDefaults.plugins, legend: { display: true, labels: { boxWidth: 10, usePointStyle: true, color: T.leyenda, font: { size: 11 } } } } },
                    }));
                }

                // Mensual
                const mensual = document.getElementById('chartMensual');
                if (mensual) {
                    const d = datasetsLine('#8b5cf6');
                    d[0].data = JSON.parse(mensual.dataset.valores);
                    graficas.push(new Chart(mensual, {
                        type: 'line',
                        data: { labels: JSON.parse(mensual.dataset.labels), datasets: d },
                        options: lineDefaults,
                    }));
                }

                // Por hora (barras)
                const horas = document.getElementById('chartHoras');
                if (horas) {
                    graficas.push(new Chart(horas, {
                        type: 'bar',
                        data: {
                            labels: Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0') + 'h'),
                            datasets: [{ data: JSON.parse(horas.dataset.valores), backgroundColor: '#8b5cf6' }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ' ' + ctx.parsed.y + ' ventas' } } },
                            scales: {
                                x: { grid: { display: false }, ticks: { color: T.tick, font: { size: 10 }, maxRotation: 0 } },
                                y: { grid: { color: gridColor }, border: { display: false }, ticks: { color: T.tick, precision: 0 } },
                            },
                        },
                    }));
                }

                // Ventas por día de la semana (Lun..Dom)
                const diaSemana = document.getElementById('chartDiaSemana');
                if (diaSemana) {
                    graficas.push(new Chart(diaSemana, {
                        type: 'bar',
                        data: {
                            labels: JSON.parse(diaSemana.dataset.labels),
                            datasets: [{
                                data: JSON.parse(diaSemana.dataset.valores),
                                backgroundColor: '#10b981',
                                borderRadius: 6,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ' ' + formatCOP(ctx.parsed.y) } } },
                            scales: {
                                x: { grid: { display: false }, ticks: { color: T.tick, font: { size: 11 } } },
                                y: { grid: { color: gridColor }, border: { display: false }, ticks: { color: T.tick, font: { size: 10 }, callback: (v) => formatCOP(v) } },
                            },
                        },
                    }));
                }

                // Métodos de pago (dona)
                const metodos = document.getElementById('chartMetodosPago');
                if (metodos) {
                    graficas.push(new Chart(metodos, {
                        type: 'doughnut',
                        data: {
                            labels: JSON.parse(metodos.dataset.labels),
                            datasets: [{
                                data: JSON.parse(metodos.dataset.valores),
                                backgroundColor: ['#8b5cf6', '#6366f1', '#06b6d4', '#10b981', '#f59e0b', '#f43f5e'],
                                borderWidth: 2,
                                borderColor: T.borde,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '62%',
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: (ctx) => ' ' + ctx.label + ': ' + formatCOP(ctx.parsed) } },
                            },
                        },
                    }));
                }
            }

            crearGraficas();

            // Al cambiar el tema se destruyen y se vuelven a crear. El observer
            // mira la clase del <html>, que es lo unico que el toggle cambia.
            if (window.MutationObserver) {
                let anterior = document.documentElement.classList.contains('dark');
                new MutationObserver(() => {
                    const actual = document.documentElement.classList.contains('dark');
                    if (actual === anterior) return;
                    anterior = actual;
                    crearGraficas();
                }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            }
        });
    </script>
</x-panel-layout>

