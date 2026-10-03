<?php

namespace App\Http\Livewire;

use App\Models\MovimientoCaja;
use App\Models\Venta;
use App\Services\CajaService;
use Illuminate\Support\Carbon;

class Ventas extends PanelComponent
{
    /** Preset del rango: hoy | semana | mes | mes_anterior | custom. */
    public string $preset = 'mes';

    public string $desde = '';

    public string $hasta = '';

    public string $search = '';

    public bool $showDetalle = false;

    public ?array $ventaDetalle = null;

    public ?int $ventaAEliminar = null;

    protected $queryString = [
        'preset' => ['except' => 'mes'],
        'desde' => ['except' => ''],
        'hasta' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->aplicarPreset($this->preset, inicial: true);
    }

    public function updatedPreset(): void
    {
        $this->aplicarPreset($this->preset);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    private function aplicarPreset(string $preset, bool $inicial = false): void
    {
        $hoy = today();

        [$desde, $hasta] = match ($preset) {
            'hoy' => [$hoy->copy(), $hoy->copy()],
            'semana' => [$hoy->copy()->startOfWeek(), $hoy->copy()],
            'mes_anterior' => [$hoy->copy()->subMonth()->startOfMonth(), $hoy->copy()->subMonth()->endOfMonth()],
            'mes' => [$hoy->copy()->startOfMonth(), $hoy->copy()],
            default => [
                $this->desde !== '' && strtotime($this->desde) ? Carbon::parse($this->desde) : $hoy->copy()->startOfMonth(),
                $this->hasta !== '' && strtotime($this->hasta) ? Carbon::parse($this->hasta) : $hoy->copy(),
            ],
        };

        $this->desde = $desde->toDateString();
        $this->hasta = $hasta->toDateString();
    }

    public function updatedDesde(): void
    {
        if ($this->desde > $this->hasta) {
            $this->hasta = $this->desde;
        }
        $this->preset = 'custom';
    }

    public function updatedHasta(): void
    {
        if ($this->hasta < $this->desde) {
            $this->desde = $this->hasta;
        }
        $this->preset = 'custom';
    }

    private function rango(): array
    {
        $desde = Carbon::parse($this->desde)->startOfDay();
        $hasta = Carbon::parse($this->hasta)->endOfDay();

        return [$desde, $hasta];
    }

    public function render(CajaService $service)
    {
        [$desde, $hasta] = $this->rango();
        $completadas = ['completada', 'pagada'];

        // ---------- KPIs del período ----------
        $ingresos = (float) Venta::whereIn(\DB::raw('LOWER(estado)'), $completadas)
            ->whereBetween('fecha_venta', [$desde, $hasta])
            ->sum('total');
        $cantidadCompletadas = Venta::whereIn(\DB::raw('LOWER(estado)'), $completadas)
            ->whereBetween('fecha_venta', [$desde, $hasta])
            ->count();
        $totalRegistros = Venta::whereBetween('fecha_venta', [$desde, $hasta])->count();

        $gastos = (float) MovimientoCaja::where('tipo', 'egreso')
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->sum('monto');
        $balance = $ingresos - $gastos;
        $ticketPromedio = $cantidadCompletadas > 0 ? $ingresos / $cantidadCompletadas : 0;

        // ---------- Variación vs período anterior equivalente ----------
        $diasPeriodo = max(1, (int) $desde->diffInDays($hasta) + 1);
        $inicioAnt = $desde->copy()->subDays($diasPeriodo)->startOfDay();
        $finAnt = $desde->copy()->subDay()->endOfDay();
        $totalAnterior = (float) Venta::whereIn(\DB::raw('LOWER(estado)'), $completadas)
            ->whereBetween('fecha_venta', [$inicioAnt, $finAnt])
            ->sum('total');
        $variacion = $totalAnterior > 0
            ? round(($ingresos - $totalAnterior) / $totalAnterior * 100, 1)
            : ($ingresos > 0 ? 100.0 : 0.0);

        // ---------- Serie por día (monto + cantidad), con días sin ventas en 0 ----------
        $filasDias = Venta::whereIn(\DB::raw('LOWER(estado)'), $completadas)
            ->whereBetween('fecha_venta', [$desde, $hasta])
            ->selectRaw('DATE(fecha_venta) AS d, COUNT(*) AS c, COALESCE(SUM(total), 0) AS t')
            ->groupByRaw('DATE(fecha_venta)')
            ->get()
            ->keyBy('d');

        $diasEs = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        $labelsDias = [];
        $totalesDias = [];
        $cantidadesDias = [];

        if ($diasPeriodo <= 92) {
            for ($f = $desde->copy(); $f <= $hasta; $f->addDay()) {
                $clave = $f->toDateString();
                $labelsDias[] = $diasEs[$f->dayOfWeek] . ' ' . $f->format('d');
                $totalesDias[] = (float) ($filasDias[$clave]->t ?? 0);
                $cantidadesDias[] = (int) ($filasDias[$clave]->c ?? 0);
            }
        } else {
            foreach ($filasDias as $fila) {
                $fecha = Carbon::parse($fila->d);
                $labelsDias[] = $diasEs[$fecha->dayOfWeek] . ' ' . $fecha->format('d');
                $totalesDias[] = (float) $fila->t;
                $cantidadesDias[] = (int) $fila->c;
            }
        }

        // ---------- Métodos de pago (movimientos tipo venta) ----------
        $metodos = MovimientoCaja::where('tipo', 'venta')
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->selectRaw('COALESCE(NULLIF(metodo_pago, ""), "Otro") AS metodo, COUNT(*) AS c, COALESCE(SUM(monto), 0) AS t')
            ->groupBy('metodo')
            ->orderByDesc('t')
            ->get();

        // ---------- Top clientes ----------
        $topClientes = Venta::whereIn(\DB::raw('LOWER(estado)'), $completadas)
            ->whereBetween('fecha_venta', [$desde, $hasta])
            ->whereNotNull('cliente_nombre')
            ->whereNotIn('cliente_nombre', ['', 'Consumidor Final'])
            ->selectRaw('COALESCE(NULLIF(cliente_nombre, ""), "Cliente General") AS nombre, COUNT(*) AS compras, COALESCE(SUM(total), 0) AS gastado')
            ->groupBy('nombre')
            ->orderByDesc('gastado')
            ->limit(5)
            ->get();

        // ---------- Estados ----------
        $estados = Venta::whereBetween('fecha_venta', [$desde, $hasta])
            ->selectRaw('estado, COUNT(*) AS c')
            ->groupBy('estado')
            ->orderByDesc('c')
            ->get();

        // ---------- Tabla ----------
        $termino = trim($this->search);
        $ventas = Venta::query()
            ->whereBetween('fecha_venta', [$desde, $hasta])
            ->when($termino !== '', function ($q) use ($termino) {
                $q->where(function ($q) use ($termino) {
                    $q->where('cliente_nombre', 'like', "%{$termino}%");
                    if (ctype_digit($termino)) {
                        $q->orWhere('id', (int) $termino);
                    }
                });
            })
            ->orderByDesc('fecha_venta')
            ->paginate(10);

        $datos = [
            'kpi' => [
                'ingresos' => $ingresos,
                'cantidadCompletadas' => $cantidadCompletadas,
                'totalRegistros' => $totalRegistros,
                'gastos' => $gastos,
                'balance' => $balance,
                'ticketPromedio' => $ticketPromedio,
                'variacion' => $variacion,
                'hayAnterior' => $totalAnterior > 0,
            ],
            'metodos' => $metodos,
            'topClientes' => $topClientes,
            'estados' => $estados,
            'ventas' => $ventas,
            'graficas' => [
                'labelsDias' => $labelsDias,
                'totalesDias' => $totalesDias,
                'cantidadesDias' => $cantidadesDias,
                'metodosLabels' => $metodos->pluck('metodo')->all(),
                'metodosTotales' => $metodos->pluck('t')->map(fn ($v) => (float) $v)->all(),
            ],
            'esAdmin' => auth()->user()?->esAdmin() ?? false,
        ];

        // Reconstruir gráficas en el navegador tras cada filtro/búsqueda/página.
        $this->dispatchBrowserEvent('ventas-graficas', $datos['graficas']);

        return view('livewire.ventas', $datos);
    }

    // ====================================================================
    // Detalle y anulación
    // ====================================================================

    public function verVenta(int $ventaId): void
    {
        $venta = Venta::with('detalles')->find($ventaId);
        if (! $venta) {
            return;
        }

        $this->ventaDetalle = [
            'id' => $venta->id,
            'cliente' => $venta->cliente_nombre ?: 'Consumidor Final',
            'fecha' => $venta->fecha_venta?->format('d/m/Y H:i'),
            'total' => (float) $venta->total,
            'metodo_pago' => $venta->metodo_pago,
            'estado' => $venta->estado,
            'motivo' => $venta->motivo,
            'monto_recibido' => $venta->monto_recibido !== null ? (float) $venta->monto_recibido : null,
            'cambio' => $venta->cambio !== null ? (float) $venta->cambio : null,
            'detalles' => $venta->detalles->map(fn ($d) => [
                'nombre' => $d->producto_nombre,
                'cantidad' => $d->cantidad,
                'precio' => (float) $d->precio_unitario,
                'subtotal' => (float) $d->subtotal,
            ])->all(),
        ];
        $this->showDetalle = true;
    }

    public function pedirEliminarVenta(int $ventaId): void
    {
        $this->ventaAEliminar = $ventaId;
        $this->showDetalle = false;
    }

    public function eliminarVentaConfirmada(CajaService $service): void
    {
        if ($this->ventaAEliminar) {
            try {
                $service->eliminarVenta($this->ventaAEliminar);
                $this->toast("Venta #{$this->ventaAEliminar} anulada y stock repuesto", 'ok');
            } catch (\RuntimeException $e) {
                $this->toast($e->getMessage(), 'error');
            }
        }
        $this->reset('ventaAEliminar', 'ventaDetalle', 'showDetalle');
    }

    public function closeDetalle(): void
    {
        $this->reset('ventaDetalle', 'showDetalle', 'ventaAEliminar');
    }

    protected function toast(string $mensaje, string $tipo = 'ok'): void
    {
        $this->dispatchBrowserEvent('ventas-toast', ['mensaje' => $mensaje, 'tipo' => $tipo]);
    }
}
