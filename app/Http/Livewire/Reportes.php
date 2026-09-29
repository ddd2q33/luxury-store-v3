<?php

namespace App\Http\Livewire;

use App\Models\Cliente;
use App\Models\Devolucion;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin -> Reportes. Reconstruye `oldluxury/reportes/index.php`, que era el
 * modulo mas completo del sistema anterior (8 secciones + 4 graficas) y el
 * unico que no se migro.
 *
 * Decisiones:
 * - Es SOLO LECTURA: no escribe nada, asi que no necesita servicios ni
 *   transacciones. Los datos de negocio se siguen mutando por StockService,
 *   CajaService, DeudaProveedorService y DevolucionService.
 * - Todo se calcula sobre `ventas`/`movimientos_caja` con filtro de estado
 *   `Completada`. `ventas.estado` es 'Completada' | 'Anulada' y el monto real
 *   esta en `ventas.total` (`total_general` esta en 0.00 en toda la tabla).
 * - La ventana por defecto son los ultimos 30 dias, no "todo": con 542 ventas
 *   de 2023 en adelante, un reporte sin rango no responde nada.
 */
class Reportes extends PanelComponent
{
    /** Presets: hoy, 7, 30, 90, mes, anio, todo. */
    public string $preset = '30';

    public string $desde = '';

    public string $hasta = '';

    public string $vista = 'resumen';

    /** Solo admin: el middleware protege la ruta, no el endpoint de Livewire. */
    public function mount(): void
    {
        abort_unless(Auth::user()?->esAdmin(), 403, 'No tienes permisos para acceder a esta seccion.');

        $this->aplicarPreset('30');
    }

    protected $queryString = ['preset', 'desde', 'hasta', 'vista'];

    public function aplicarPreset(string $preset): void
    {
        $this->preset = $preset;

        [$ini, $fin] = match ($preset) {
            'hoy' => [today(), today()],
            '7' => [today()->subDays(6), today()],
            '30' => [today()->subDays(29), today()],
            '90' => [today()->subDays(89), today()],
            'mes' => [today()->startOfMonth(), today()],
            'anio' => [today()->startOfYear(), today()],
            // 'todo' no se puede acotar por mes: la tabla va a 2023.
            default => [null, null],
        };

        $this->desde = $ini?->format('Y-m-d') ?? '';
        $this->hasta = $fin?->format('Y-m-d') ?? '';

        $this->resetPage();
    }

    /** Los dos campos de fecha escriben a mano: se pierde el preset, no el rango. */
    public function updatedDesde(): void
    {
        $this->preset = 'custom';
    }

    public function updatedHasta(): void
    {
        $this->preset = 'custom';
    }

    public function render()
    {
        $ini = $this->desde !== '' ? Carbon::parse($this->desde)->startOfDay() : null;
        $fin = $this->hasta !== '' ? Carbon::parse($this->hasta)->endOfDay() : null;

        return view('livewire.reportes', [
            'ini' => $ini,
            'fin' => $fin,
            'kpi' => $this->kpi($ini, $fin),
            'serieDiaria' => $this->serieDiaria($ini, $fin),
            'porMetodo' => $this->porMetodo($ini, $fin),
            'porDiaSemana' => $this->porDiaSemana($ini, $fin),
            'topProductos' => $this->topProductos($ini, $fin),
            'topClientes' => $this->topClientes($ini, $fin),
            'estadoStock' => $this->estadoStock(),
            'proveedoresDeuda' => $this->proveedoresDeuda(),
            'clientes' => $this->clientes($ini, $fin),
            'pedidos' => $this->pedidos($ini, $fin),
            'devoluciones' => $this->devoluciones($ini, $fin),
        ]);
    }

    /**
     * Cifras cabecera. Todo se deriva de `ventas` completadas y de los
     * movimientos de caja, que es donde el legacy sacaba los reportes.
     */
    private function kpi(?Carbon $ini, ?Carbon $fin): array
    {
        $ventas = Venta::where('estado', 'Completada')->tap(fn ($q) => $q->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin])
        ));

        $total = (float) (clone $ventas)->sum('total');
        $cantidad = (clone $ventas)->count();
        $ticket = $cantidad > 0 ? $total / $cantidad : 0.0;
        $maxVenta = (float) (clone $ventas)->max('total');

        // Las anuladas van en su propia consulta: si se agregan a la de
        // completadas el filtro `estado = Completada` las deja en 0 siempre.
        $anuladas = (int) Venta::where('estado', 'Anulada')->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin])
        )->count();
        $egresos = (float) MovimientoCaja::where('tipo', 'egreso')->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha', [$ini, $fin])
        )->sum('monto');
        $ingresos = (float) MovimientoCaja::where('tipo', 'ingreso')->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha', [$ini, $fin])
        )->sum('monto');
        $devoluciones = (float) Devolucion::when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha', [$ini, $fin])
        )->sum('total_devuelto');

        $clientes = (int) (clone $ventas)->conClienteIdentificado()->distinct()->count('cliente_nombre');

        $mejorDia = $this->mejorDia($ini, $fin);

        return [
            'total' => $total,
            'cantidad' => $cantidad,
            'ticket' => $ticket,
            'maxVenta' => $maxVenta,
            'anuladas' => $anuladas,
            'egresos' => $egresos,
            'ingresos' => $ingresos,
            'devoluciones' => $devoluciones,
            'flujoNeto' => $total + $ingresos - $egresos - $devoluciones,
            'clientes' => $clientes,
            'mejorDia' => $mejorDia,
            'sinPrecio' => Producto::where('precio', '<=', 0)->count(),
            'totalProductos' => Producto::count(),
        ];
    }

    /** @return array{0: ?Carbon, 1: float, 2: int} fecha del mejor dia, monto y ventas */
    private function mejorDia(?Carbon $ini, ?Carbon $fin): array
    {
        $fila = Venta::where('estado', 'Completada')->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin])
        )
            ->selectRaw('DATE(fecha_venta) AS dia, COALESCE(SUM(total),0) AS t, COUNT(*) AS c')
            ->groupByRaw('DATE(fecha_venta)')
            ->orderByDesc('t')
            ->toBase()->first();

        if (! $fila) {
            return [null, 0.0, 0];
        }

        return [Carbon::parse($fila->dia), (float) $fila->t, (int) $fila->c];
    }

    /**
     * Serie diaria con cero en los dias sin ventas: una grafica con huecos hace
     * creer que faltaron dias. Se rellena en PHP, no con un join a una tabla
     * calendario que este schema legacy no tiene.
     */
    private function serieDiaria(?Carbon $ini, ?Carbon $fin): array
    {
        $ini ??= Venta::where('estado', 'Completada')->min('fecha_venta');
        $fin ??= today();

        if (! $ini) {
            return ['etiquetas' => [], 'ventas' => [], 'egresos' => [], 'tickets' => []];
        }

        $ini = Carbon::parse($ini)->startOfDay();
        $fin = Carbon::parse($fin)->endOfDay();

        // Techo defensivo: 'todo' son ~3 anos, 1000 dias entran de sobra.
        if ($ini->diffInDays($fin) > 1000) {
            $ini = $fin->copy()->subDays(1000);
        }

        // OJO: una consulta `selectRaw` de agregados sobre un MODELO devuelve
        // instancias del modelo, no stdClass. Then `->keyBy('dia')` + `(float)`
        // castea el MODELO y revienta con "Object of class MovimientoCaja could
        // not be converted to float". `toBase()` devuelve el query builder y con
        // el ->get() salen stdClass limpios.
        $ventas = Venta::where('estado', 'Completada')
            ->whereBetween('fecha_venta', [$ini, $fin])
            ->selectRaw('DATE(fecha_venta) AS dia, COALESCE(SUM(total),0) AS t, COUNT(*) AS c')
            ->groupByRaw('DATE(fecha_venta)')
            ->toBase()->get()->keyBy('dia');

        $egresos = MovimientoCaja::where('tipo', 'egreso')
            ->whereBetween('fecha', [$ini, $fin])
            ->selectRaw('DATE(fecha) AS dia, COALESCE(SUM(monto),0) AS m')
            ->groupByRaw('DATE(fecha)')
            ->toBase()->get()->keyBy('dia');

        $etiquetas = $ventas = $egr = $tickets = [];

        for ($d = $ini->copy(); $d->lte($fin); $d->addDay()) {
            $clave = $d->format('Y-m-d');
            $v = $ventas[$clave] ?? null;
            $e = $egresos[$clave] ?? null;

            $etiquetas[] = $d->translatedFormat('d/m');
            $ventas[] = (float) ($v->t ?? 0);
            $egr[] = (float) ($e->m ?? 0);
            $tickets[] = $v && $v->c > 0 ? round($v->t / $v->c, 0) : 0;
        }

        return [
            'etiquetas' => $etiquetas,
            'ventas' => $ventas,
            'egresos' => $egr,
            'tickets' => $tickets,
        ];
    }

    private function porMetodo(?Carbon $ini, ?Carbon $fin): Collection
    {
        // Se agrupa por la columna PLANA, no por `COALESCE(NULLIF(metodo_pago,..))`.
        // MySQL/MariaDB con ONLY_FULL_GROUP_BY rechaza la expresion en el GROUP BY
        // (error 1055) aunque sea identica a la del SELECT, asi que la etiqueta
        // vacia se normaliza DESPUES en PHP.
        //
        // El alias tampoco puede llamarse `total`: `ventas` ya tiene una columna
        // `total` y el ORDER BY resolveria a la columna en vez de al alias.
        return Venta::where('estado', 'Completada')->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin])
        )
            ->selectRaw('metodo_pago, COUNT(*) AS ventas, COALESCE(SUM(total),0) AS total_gastado')
            ->groupBy('metodo_pago')
            ->orderByDesc('total_gastado')
            ->toBase()
            ->get()
            ->map(function ($fila) {
                $fila->metodo = $fila->metodo_pago !== null && $fila->metodo_pago !== ''
                    ? $fila->metodo_pago
                    : 'Sin dato';

                return $fila;
            })
            ->sortByDesc('total_gastado')
            ->values();
    }

    /** Lunes..Domingo. MySQL DAYOFWEEK va 1=domingo, se reordena. */
    private function porDiaSemana(?Carbon $ini, ?Carbon $fin): array
    {
        $filas = Venta::where('estado', 'Completada')->when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin])
        )
            ->selectRaw('DAYOFWEEK(fecha_venta) AS d, COALESCE(SUM(total),0) AS t, COUNT(*) AS c')
            ->groupByRaw('DAYOFWEEK(fecha_venta)')
            ->toBase()->get()->keyBy('d');

        $serie = [];
        foreach (['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'] as $i => $nombre) {
            $dia = $i === 0 ? 2 : $i + 1;
            $f = $filas[$dia] ?? null;
            $serie[] = [
                'dia' => $nombre,
                'total' => (float) ($f->t ?? 0),
                'ventas' => (int) ($f->c ?? 0),
            ];
        }

        return $serie;
    }

    /**
     * Productos mas vendidos.
     *
     * `venta_detalles` esta vacia, asi que el unico dato de que salio mercancia
     * es `inventario_movimientos`, cuyo enum real es 'Entrada' | 'Salida' |
     * 'Ajuste' (verificado: 1, 3 y 2 filas respectivamente). Solo se cuentan
     * 'Salida'; `Ajuste` NO es una venta (corrige un conteo) y `Entrada` es una
     * compra o devolucion.
     *
     * OJO: aqui hay solo 6 movimientos en toda la BD, as que este ranking es
     * practicamente vacio y la vista lo advierte. Sirve para que la BD se
     * llene, no para vender hoy.
     */
    private function topProductos(?Carbon $ini, ?Carbon $fin): Collection
    {
        return DB::table('inventario_movimientos as im')
            ->join('productos as p', 'p.id', '=', 'im.producto_id')
            ->where('im.tipo', 'Salida')
            ->when($ini && $fin, fn ($q) => $q->whereBetween('im.fecha', [$ini, $fin]))
            ->selectRaw('p.id, p.nombre, p.stock, SUM(ABS(im.cantidad)) AS unidades, COUNT(*) AS movimientos')
            ->groupBy('p.id', 'p.nombre', 'p.stock')
            ->orderByDesc('unidades')
            ->limit(10)
            ->get();
    }

    private function topClientes(?Carbon $ini, ?Carbon $fin): Collection
    {
        return Venta::where('estado', 'Completada')
            ->when($ini && $fin, fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin]))
            ->conClienteIdentificado()
            ->selectRaw('cliente_nombre, COUNT(*) AS compras, COALESCE(SUM(total),0) AS total_gastado, MAX(fecha_venta) AS ultima')
            ->groupBy('cliente_nombre')
            ->orderByDesc('total_gastado')
            ->limit(10)
            ->get();
    }

    private function estadoStock(): array
    {
        $agotado = Producto::where('stock', '=', 0)->count();
        $negativo = Producto::where('stock', '<', 0)->count();
        $bajo = Producto::where('stock', '>', 0)->where('stock_minimo', '>', 0)
            ->whereColumn('stock', '<=', 'stock_minimo')->count();
        $normal = Producto::count() - $agotado - $negativo - $bajo;

        return compact('agotado', 'bajo', 'negativo', 'normal');
    }

    private function proveedoresDeuda(): Collection
    {
        return Proveedor::orderByDesc('saldo_deuda')->limit(10)->get();
    }

    private function clientes(?Carbon $ini, ?Carbon $fin): Collection
    {
        $compras = Venta::where('estado', 'Completada')
            ->conClienteIdentificado()
            ->when($ini && $fin, fn ($q) => $q->whereBetween('fecha_venta', [$ini, $fin]))
            // El alias DEBE ser `total_gastado`: es el que lee el mapper de
            // abajo. Con `AS total` la tabla de clientes salia siempre en $0.
            ->selectRaw('cliente_nombre, COUNT(*) AS compras, COALESCE(SUM(total),0) AS total_gastado, MAX(fecha_venta) AS ultima')
            ->groupBy('cliente_nombre')
            ->orderByDesc('total_gastado')
            ->get()->keyBy('cliente_nombre');

        return Cliente::orderByDesc('creado_en')->limit(10)->get()->map(function ($c) use ($compras) {
            $k = $compras->get($c->nombre);
            $c->compras = (int) ($k->compras ?? 0);
            $c->total_gastado = (float) ($k->total_gastado ?? 0);
            $c->ultima = $k->ultima ?? null;

            return $c;
        });
    }

    private function pedidos(?Carbon $ini, ?Carbon $fin): Collection
    {
        return Pedido::when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha_pedido', [$ini, $fin])
        )
            ->orderByDesc('fecha_pedido')
            ->limit(10)
            ->get();
    }

    private function devoluciones(?Carbon $ini, ?Carbon $fin): Collection
    {
        return Devolucion::when(
            $ini && $fin,
            fn ($q) => $q->whereBetween('fecha', [$ini, $fin])
        )
            ->orderByDesc('fecha')
            ->limit(10)
            ->get();
    }
}
