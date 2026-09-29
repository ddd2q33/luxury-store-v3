<?php

namespace App\Http\Controllers;

use App\Models\{AbonoProveedor, Caja, Cliente, Compra, Devolucion, MovimientoCaja, Pedido, Producto, Proveedor, Venta};
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $hoy = today();

        // ========== Ventas ==========
        $ventasHoy = $this->sumVentas($hoy->copy()->startOfDay(), $hoy->copy()->endOfDay());
        $ventasAyer = $this->sumVentas($hoy->copy()->subDay()->startOfDay(), $hoy->copy()->subDay()->endOfDay());
        $ventasMes = $this->sumVentas($hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth());
        $ventasMesAnterior = $this->sumVentas($hoy->copy()->subMonth()->startOfMonth(), $hoy->copy()->subMonth()->endOfMonth());
        $ventasHistorico = (float) Venta::where('estado', 'Completada')->sum('total');
        $numVentasHoy = Venta::where('estado', 'Completada')->whereDate('fecha_venta', $hoy)->count();
        $ticketPromedio = $numVentasHoy > 0 ? $ventasHoy / $numVentasHoy : 0;

        // El % de variación hay que calcularlo siempre: si ayer fue 0 y hoy hubo
        // ventas, la subida es del 100%, no "0%" ni una tarjeta vacia. Antes solo
        // se pintaba el porcentaje cuando ayer era > 0, y en ese caso se
        // comia el contador de ventas y el ticket promedio.
        $variacionAyer = $this->variacion($ventasHoy, $ventasAyer);

        // ========== Gastos (movimientos_caja tipo egreso) ==========
        $gastosHoy = MovimientoCaja::where('tipo', 'egreso')->whereDate('fecha', $hoy)->sum('monto');
        $gastosSemana = MovimientoCaja::where('tipo', 'egreso')->whereBetween('fecha', [$hoy->copy()->startOfWeek(), $hoy->copy()->endOfWeek()])->sum('monto');
        $gastosMes = MovimientoCaja::where('tipo', 'egreso')->whereMonth('fecha', $hoy->month)->whereYear('fecha', $hoy->year)->sum('monto');
        $gastosAno = MovimientoCaja::where('tipo', 'egreso')->whereYear('fecha', $hoy->year)->sum('monto');

        // ========== Balance y margen ==========
        // El legacy los tenía (dashboard/index.php:901 y :905) y se perdieron al
        // migrar. Son los dos numeros que dicen si el mes cierra bien.
        $balanceMes = $ventasMes - $gastosMes;
        $margenMes = $ventasMes > 0 ? round($balanceMes / $ventasMes * 100, 1) : 0.0;

        // ========== Caja ==========
        $cajaAbierta = Caja::where('estado', 'abierta')->orderByDesc('fecha_apertura')->first();
        $saldoCaja = 0.0;
        if ($cajaAbierta) {
            $ingresos = $cajaAbierta->movimientos()->whereIn('tipo', ['venta', 'ingreso'])->sum('monto');
            $egresos = $cajaAbierta->movimientos()->where('tipo', 'egreso')->sum('monto');
            $saldoCaja = $cajaAbierta->saldo_inicial + $ingresos - $egresos;
        }

        // ========== Productos / inventario ==========
        // "Bajo" usa productos.stock_minimo (columna que ya existe), no un
        // umbral fijo: así el Dashboard coincide con Productos, Inventario
        // y Stock, que consultan el mismo scope necesitaReposicion().
        $totalProductos = Producto::count();
        // Ojo: "Agotado" es stock === 0 EXACTO (48 productos). Los negativos (10)
        // NO son agotados: son un dato roto que el legacy dejo pasar, y se
        // reportan aparte para que el dueño vea la magnitud del problema. Antes
        // se agrupaban con `stock <= 0` y por eso salia 58 en lugar de 48.
        $productosAgotados = Producto::where('stock', '=', 0)->count();
        $productosNegativos = Producto::where('stock', '<', 0)->count();
        $productosBajoStock = Producto::where('stock', '>', 0)
            ->where('stock_minimo', '>', 0)
            ->whereColumn('stock', '<=', 'stock_minimo')
            ->count();
        $productosStockNormal = $totalProductos - $productosAgotados - $productosBajoStock - $productosNegativos;
        $productosSinMinimo = Producto::where('stock', '>', 0)->where('stock_minimo', '<=', 0)->count();
        $stockTotalUnidades = (int) Producto::sum('stock');
        $valorInventario = (float) Producto::selectRaw('COALESCE(SUM(stock * precio), 0) AS v')->value('v');
        $totalCategorias = \App\Models\Categoria::count();

        // Lo más urgente primero: negativos, agotados y luego los más bajos.
        $urgentesInventario = Producto::necesitaReposicion()
            ->orderByRaw('CASE WHEN stock < 0 THEN 0 WHEN stock = 0 THEN 1 ELSE 2 END, stock ASC')
            ->limit(10)
            ->get(['id', 'nombre', 'stock', 'stock_minimo']);

        // ========== Clientes / proveedores / compras ==========
        $totalClientes = Cliente::count();
        $clientesNuevosMes = Cliente::whereMonth('creado_en', $hoy->month)->whereYear('creado_en', $hoy->year)->count();
        $totalProveedores = Proveedor::count();
        $deudaProveedores = (float) Proveedor::sum('saldo_deuda');
        $proveedoresConDeuda = Proveedor::where('saldo_deuda', '>', 0)->count();
        $abonosMes = AbonoProveedor::whereMonth('fecha_abono', $hoy->month)->whereYear('fecha_abono', $hoy->year)->sum('monto');
        $comprasMes = Compra::whereMonth('fecha', $hoy->month)->whereYear('fecha', $hoy->year)->sum('total_general');
        $comprasHistorico = (float) Compra::sum('total_general');
        $comprasRegistradas = Compra::count();

        // ========== Pedidos ==========
        // Una "notificacion" es algo que el dueño tiene que hacer HOY: confirmar
        // un pedido nuevo, perseguir una entrega que ya vencio, o despachar
        // uno que quedo listo. Un pedido entregado o cancelado no avisa de nada,
        // por eso los estados cerrados quedan fuera del contador de alertas.
        $abiertos = Pedido::ESTADOS_ABIERTOS;

        $pedidosEstados = Pedido::selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->pluck('total', 'estado');
        $pedidosTotal = (int) $pedidosEstados->sum();

        $pedidosPendientes = (int) Pedido::where('estado', 'pendiente')->count();
        $pedidosEnProceso = (int) Pedido::whereIn('estado', ['confirmado', 'preparando'])->count();
        $pedidosListos = (int) Pedido::where('estado', 'listo')->count();
        $pedidosAbiertos = (int) Pedido::whereIn('estado', $abiertos)->count();
        // Dinero que ya está comprometido pero todavía no es ingreso: sirve para
        // no leer el mes como cerrado cuando la mercancía aún no salió.
        $pedidosMontoAbierto = (float) Pedido::whereIn('estado', $abiertos)->sum('total');

        // fecha_entrega es NULL cuando el cliente no pactó fecha: esos pedidos
        // NUNCA se cuentan como vencidos, si no se marcarían solos para siempre.
        $pedidosVencidos = (int) Pedido::whereIn('estado', $abiertos)
            ->whereNotNull('fecha_entrega')->whereDate('fecha_entrega', '<', $hoy)->count();
        $pedidosEntregaHoy = (int) Pedido::whereIn('estado', $abiertos)
            ->whereNotNull('fecha_entrega')->whereDate('fecha_entrega', $hoy)->count();
        $pedidosNuevosHoy = (int) Pedido::whereDate('created_at', $hoy)->count();

        // El número del cartel de alerta. Los "listos" no van aquí: no requieren
        // una decisión, solo que alguien los recoja.
        $pedidosPorAtender = $pedidosPendientes + $pedidosVencidos;

        // La cola de trabajo real: los 6 pedidos abiertos más antiguos. Primero
        // los que tienen fecha de entrega (y dentro de ellos el más viejo), y
        // al final los que no pactaron fecha, que no se pueden comparar por fecha.
        $pedidosCola = Pedido::whereIn('estado', $abiertos)
            ->orderByRaw('CASE WHEN fecha_entrega IS NULL THEN 1 ELSE 0 END, fecha_entrega ASC, id ASC')
            ->limit(6)
            ->get(['id', 'numero_pedido', 'cliente_nombre', 'cliente_telefono', 'fecha_pedido', 'fecha_entrega', 'estado', 'total'])
            // El flag se calcula AQUÍ y no en la vista: fecha_entrega es un date
            // (00:00), así que un pedido que se entrega HOY no está vencido
            // aunque se compare contra now() con hora. Comparar por día evita
            // marcarlo vencido y ensuciar el contador de alertas.
            ->map(function ($p) use ($hoy) {
                $p->setAttribute('vencido', $p->fecha_entrega !== null && $p->fecha_entrega->startOfDay()->lt($hoy));
                $p->setAttribute('diasAtraso', $p->fecha_entrega === null
                    ? 0
                    : (int) $hoy->diffInDays($p->fecha_entrega->startOfDay()));

                return $p;
            });

        // ========== Devoluciones (30 días) ==========
        $desde30 = $hoy->copy()->subDays(29)->startOfDay();
        $devoluciones30 = (int) Devolucion::whereBetween('fecha', [$desde30, $hoy->copy()->endOfDay()])->count();
        $devoluciones30Monto = (float) Devolucion::whereBetween('fecha', [$desde30, $hoy->copy()->endOfDay()])->sum('total_devuelto');

        // ========== Movimientos del día ==========
        $ventasMovHoy = MovimientoCaja::where('tipo', 'venta')->whereDate('fecha', $hoy)->sum('monto');
        $ingresosMovHoy = MovimientoCaja::where('tipo', 'ingreso')->whereDate('fecha', $hoy)->sum('monto');
        $egresosMovHoy = MovimientoCaja::where('tipo', 'egreso')->whereDate('fecha', $hoy)->sum('monto');
        $balanceMov = $ventasMovHoy + $ingresosMovHoy - $egresosMovHoy;

        // ========== Gráficas ==========
        $ventas7 = $this->serieVentasDias(7);
        $gastos7 = $this->serieGastosDias(7);
        [$labels12, $ventas12] = $this->serieVentasMensual(12);

        $ventasPorHora = array_fill(0, 24, 0);
        Venta::selectRaw('HOUR(fecha_venta) AS hora, COUNT(*) AS total')
            ->where('estado', 'Completada')
            ->whereBetween('fecha_venta', [$hoy->copy()->subDays(30)->startOfDay(), $hoy->copy()->endOfDay()])
            ->groupByRaw('HOUR(fecha_venta)')
            ->get()
            ->each(fn ($r) => $ventasPorHora[$r->hora] = (int) $r->total);

        $metodosPago = MovimientoCaja::where('tipo', 'venta')
            ->whereNotNull('metodo_pago')
            ->selectRaw('metodo_pago, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total')
            ->groupBy('metodo_pago')
            ->orderByDesc('total')
            ->get();

        // 524 de las 542 ventas guardan 'Cliente General' en cliente_nombre: es el
        // mismo centinela que cliente_id = 0 (venta de mostrador), NO un cliente.
        // Sin excluirlo, el "Top clientes" quedaba dominado por un fantasma con el
        // 95% de la facturacion. Ahora se separa de los clientes reales.
        $topClientes = Venta::where('estado', 'Completada')
            ->whereNotNull('cliente_nombre')
            ->whereNotIn('cliente_nombre', self::CLIENTES_SENTINELA)
            ->selectRaw('cliente_nombre, COUNT(*) AS compras, COALESCE(SUM(total), 0) AS total_gastado')
            ->groupBy('cliente_nombre')
            ->orderByDesc('total_gastado')
            ->limit(5)
            ->get();

        // Cuanto de la facturacion son ventas de mostrador sin cliente identified.
        $ventasMostrador = Venta::where('estado', 'Completada')
            ->where(function ($q) {
                $q->whereNull('cliente_nombre')
                    ->orWhereIn('cliente_nombre', self::CLIENTES_SENTINELA);
            })
            ->count();

        return view('dashboard', [
            'kpi' => [
                'ventasHoy' => $ventasHoy,
                'ventasAyer' => $ventasAyer,
                'variacionAyer' => $variacionAyer,
                'ventasMes' => $ventasMes,
                'ventasMesAnterior' => $ventasMesAnterior,
                'ventasHistorico' => $ventasHistorico,
                'numVentasHoy' => $numVentasHoy,
                'ticketPromedio' => $ticketPromedio,
                'gastosHoy' => $gastosHoy,
                'gastosSemana' => $gastosSemana,
                'gastosMes' => $gastosMes,
                'gastosAno' => $gastosAno,
                'balanceMes' => $balanceMes,
                'margenMes' => $margenMes,
                'saldoCaja' => $saldoCaja,
                'cajaAbierta' => (bool) $cajaAbierta,
                'totalProductos' => $totalProductos,
                'totalCategorias' => $totalCategorias,
                'productosAgotados' => $productosAgotados,
                'productosNegativos' => $productosNegativos,
                'productosBajoStock' => $productosBajoStock,
                'productosStockNormal' => $productosStockNormal,
                'productosSinMinimo' => $productosSinMinimo,
                'stockTotalUnidades' => $stockTotalUnidades,
                'valorInventario' => $valorInventario,
                'totalClientes' => $totalClientes,
                'clientesNuevosMes' => $clientesNuevosMes,
                'ventasMostrador' => $ventasMostrador,
                'totalProveedores' => $totalProveedores,
                'deudaProveedores' => $deudaProveedores,
                'proveedoresConDeuda' => $proveedoresConDeuda,
                'abonosMes' => $abonosMes,
                'comprasMes' => $comprasMes,
                'comprasHistorico' => $comprasHistorico,
                'comprasRegistradas' => $comprasRegistradas,
                'pedidosEstados' => $pedidosEstados,
                'pedidosTotal' => $pedidosTotal,
                'pedidosPendientes' => $pedidosPendientes,
                'pedidosEnProceso' => $pedidosEnProceso,
                'pedidosListos' => $pedidosListos,
                'pedidosAbiertos' => $pedidosAbiertos,
                'pedidosMontoAbierto' => $pedidosMontoAbierto,
                'pedidosVencidos' => $pedidosVencidos,
                'pedidosEntregaHoy' => $pedidosEntregaHoy,
                'pedidosNuevosHoy' => $pedidosNuevosHoy,
                'pedidosPorAtender' => $pedidosPorAtender,
                'devoluciones30' => $devoluciones30,
                'devoluciones30Monto' => $devoluciones30Monto,
                'ventasMovHoy' => $ventasMovHoy,
                'ingresosMovHoy' => $ingresosMovHoy,
                'egresosMovHoy' => $egresosMovHoy,
                'balanceMov' => $balanceMov,
            ],
            'graficas' => [
                'ventas7' => $ventas7,
                'gastos7' => $gastos7,
                'labels7' => $this->labelsDias(7),
                'labels12' => $labels12,
                'ventas12' => $ventas12,
                'ventasPorHora' => $ventasPorHora,
                'ventasPorDiaSemana' => $this->serieVentasDiaSemana(12),
                'labelsDiaSemana' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
                'metodosPago' => $metodosPago,
            ],
            'urgentesInventario' => $urgentesInventario,
            'pedidosCola' => $pedidosCola,
            'topClientes' => $topClientes,
        ]);
    }

    /** Nombres que NO son un cliente real sino la venta de mostrador. Ver Venta::CLIENTES_SENTINELA. */
    public const CLIENTES_SENTINELA = Venta::CLIENTES_SENTINELA;

    /** Variación porcentual. Si el periodo anterior fue 0 y este no, es +100%. */
    private function variacion(float $actual, float $anterior): ?float
    {
        if ($anterior > 0) {
            return round(($actual - $anterior) / $anterior * 100, 1);
        }

        return $actual > 0 ? 100.0 : null;
    }

    /**
     * Ventas por día de la semana, de lunes a domingo.
     * El legacy lo tenía en reportes (chartPorDiaSemana) y se perdió al migrar.
     * Es la gráfica que responde "qué día hay que abrir más personal".
     */
    private function serieVentasDiaSemana(int $meses): array
    {
        $desde = today()->subMonths($meses - 1)->startOfMonth();

        // MySQL DAYOFWEEK: 1 = domingo ... 7 = sabado. Se reordena a L-D.
        $filas = Venta::where('estado', 'Completada')
            ->whereBetween('fecha_venta', [$desde, today()->endOfDay()])
            ->selectRaw('DAYOFWEEK(fecha_venta) AS d, COALESCE(SUM(total), 0) AS t')
            ->groupByRaw('DAYOFWEEK(fecha_venta)')
            ->pluck('t', 'd');

        $serie = [];
        foreach (['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'] as $i => $_) {
            $diaMysql = $i === 0 ? 2 : $i + 1;   // 2=lunes ... 7=sabado, 1=domingo
            $serie[] = (float) ($filas[$diaMysql] ?? 0);
        }

        return $serie;
    }

    private function sumVentas(Carbon $desde, Carbon $hasta): float
    {
        return (float) Venta::where('estado', 'Completada')
            ->whereBetween('fecha_venta', [$desde, $hasta])
            ->sum('total');
    }

    /** Serie diaria de ventas de los Últimos N días. */
    private function serieVentasDias(int $dias): array
    {
        $desde = today()->subDays($dias - 1)->startOfDay();

        $filas = Venta::where('estado', 'Completada')
            ->whereBetween('fecha_venta', [$desde, today()->endOfDay()])
            ->selectRaw('DATE(fecha_venta) AS d, COALESCE(SUM(total), 0) AS t')
            ->groupByRaw('DATE(fecha_venta)')
            ->pluck('t', 'd');

        return $this->mapearDias($dias, $filas);
    }

    /** Serie diaria de egresos de los Últimos N días. */
    private function serieGastosDias(int $dias): array
    {
        $desde = today()->subDays($dias - 1)->startOfDay();

        $filas = MovimientoCaja::where('tipo', 'egreso')
            ->whereBetween('fecha', [$desde, today()->endOfDay()])
            ->selectRaw('DATE(fecha) AS d, COALESCE(SUM(monto), 0) AS t')
            ->groupByRaw('DATE(fecha)')
            ->pluck('t', 'd');

        return $this->mapearDias($dias, $filas);
    }

    private function mapearDias(int $dias, $filas): array
    {
        $serie = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = today()->subDays($i)->toDateString();
            $serie[] = (float) ($filas[$fecha] ?? 0);
        }

        return $serie;
    }

    /** Serie mensual de ventas de los Últimos N meses. */
    private function serieVentasMensual(int $meses): array
    {
        $mesesEs = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $desde = today()->subMonths($meses - 1)->startOfMonth();

        $filas = Venta::where('estado', 'Completada')
            ->whereBetween('fecha_venta', [$desde, today()->endOfDay()])
            ->selectRaw("DATE_FORMAT(fecha_venta, '%Y-%m') AS m, COALESCE(SUM(total), 0) AS t")
            ->groupByRaw("DATE_FORMAT(fecha_venta, '%Y-%m')")
            ->pluck('t', 'm');

        $labels = [];
        $serie = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $fecha = today()->subMonths($i)->startOfMonth();
            $clave = $fecha->format('Y-m');
            $labels[] = $mesesEs[$fecha->month - 1] . ' ' . $fecha->year;
            $serie[] = (float) ($filas[$clave] ?? 0);
        }

        return [$labels, $serie];
    }

    /** Etiquetas "Lun dd/mm" para los Últimos N días. */
    private function labelsDias(int $dias): array
    {
        $diasEs = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        $labels = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = today()->subDays($i);
            $labels[] = $diasEs[$fecha->dayOfWeek] . ' ' . $fecha->format('d/m');
        }

        return $labels;
    }
}

