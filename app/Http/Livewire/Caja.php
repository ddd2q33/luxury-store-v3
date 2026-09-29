<?php

namespace App\Http\Livewire;

use App\Models\Cliente;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\CajaService;
use Livewire\Component;
use Livewire\WithPagination;

class Caja extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    // ========== Estado UI ==========
    public string $tab = 'venta'; // venta | movimientos | historial

    public string $filtroMov = 'todos'; // todos | venta | ingreso | egreso

    public bool $showApertura = false;

    public bool $showCerrar = false;

    public bool $showMovimiento = false;

    public bool $showCliente = false;

    public bool $showTicket = false;

    public bool $showDetalle = false;

    public bool $showEliminar = false;

    // ========== Apertura / cierre ==========
    public $saldoInicial = '';

    public $totalesCierre = null;

    // ========== POS ==========
    public string $search = '';

    public string $codigoBarras = '';

    public array $carrito = [];

    public string $metodoPago = 'Efectivo';

    public $montoRecibido = null;

    public string $clienteNombre = 'Consumidor Final';

    public ?int $clienteId = null;

    public string $motivo = 'Venta general';

    public bool $ventaManual = false;

    public $totalManual = null;

    public ?array $ticket = null;

    // ========== Movimiento manual ==========
    public string $movTipo = 'ingreso';

    public string $movMetodo = 'Efectivo';

    public $movMonto = null;

    public string $movMotivo = '';

    // ========== Cliente rápido ==========
    public string $nuevoClienteNombre = '';

    public string $nuevoClienteTelefono = '';

    // ========== Historial ==========
    public string $searchHistorial = '';

    public ?array $ventaDetalle = null;

    public ?int $ventaAEliminar = null;

    protected $queryString = ['tab' => ['except' => 'venta']];

    protected $listeners = ['detalle-venta-cerrada' => 'closeDetalle'];

    // ====================================================================
    // Datos computados (render)
    // ====================================================================

    public function render(CajaService $service)
    {
        $caja = $service->cajaActiva();
        $totales = $caja ? $service->totales($caja) : null;

        $resultados = [];
        if ($this->search !== '' && mb_strlen($this->search) >= 2) {
            $termino = '%' . $this->search . '%';
            $resultados = Producto::query()
                ->where('nombre', 'like', $termino)
                ->orderBy('nombre')
                ->limit(6)
                ->get(['id', 'nombre', 'precio', 'stock'])
                ->toArray();
        }

        $subtotal = $this->subtotal();
        $puedeCobrar = count($this->carrito) > 0 || ($this->ventaManual && (float) ($this->totalManual ?: 0) > 0);

        return view('livewire.caja', [
            'caja' => $caja,
            'totales' => $totales,
            'resultados' => $resultados,
            'subtotal' => $subtotal,
            'puedeCobrar' => $puedeCobrar,
            'cambio' => $this->cambioCalculado(),
            'montosRapidos' => $this->montosRapidos(),
            'turno' => $this->duracionTurno($caja),
            'ventasHoy' => $this->ventasDeHoy(),
            'historial' => $this->historial(),
            'movimientos' => $this->movimientosFiltrados($caja),
            'fechaHoy' => now()->isoFormat('dddd D [de] MMMM'),
        ]);
    }

    public function subtotal(): float
    {
        if ($this->ventaManual) {
            return (float) ($this->totalManual ?: 0);
        }

        return round(collect($this->carrito)->sum(fn ($i) => $i['cantidad'] * $i['precio']), 2);
    }

    public function cambioCalculado(): float
    {
        if ($this->metodoPago !== 'Efectivo') {
            return 0.0;
        }

        return round(max(0, (float) ($this->montoRecibido ?: 0) - $this->subtotal()), 2);
    }

    /** Montos sugeridos de billetes para el campo "monto recibido". */
    public function montosRapidos(): array
    {
        $total = $this->subtotal();
        if ($total <= 0) {
            return [];
        }

        $montos = [];
        foreach ([1000, 5000, 10000, 20000, 50000, 100000, 200000, 500000] as $paso) {
            $montos[] = (int) (ceil($total / $paso) * $paso);
        }

        $montos = array_values(array_unique(array_filter($montos, fn ($m) => $m >= $total)));
        sort($montos);

        return array_slice($montos, 0, 4);
    }

    /** "2 h 15 min" desde la apertura del turno. */
    private function duracionTurno(?\App\Models\Caja $caja): string
    {
        if (! $caja?->fecha_apertura) {
            return '';
        }

        $mins = (int) abs(now()->diffInMinutes($caja->fecha_apertura));
        if ($mins < 1) {
            return 'un momento';
        }

        return $mins >= 60
            ? intdiv($mins, 60) . ' h ' . ($mins % 60) . ' min'
            : $mins . ' min';
    }

    private function ventasDeHoy(): array
    {
        $hoy = now()->toDateString();

        return [
            'cantidad' => Venta::where('estado', 'Completada')->whereDate('fecha_venta', $hoy)->count(),
            'total' => (float) Venta::where('estado', 'Completada')->whereDate('fecha_venta', $hoy)->sum('total'),
        ];
    }

    private function historial()
    {
        return Venta::query()
            ->when(trim($this->searchHistorial) !== '', function ($q) {
                $t = trim($this->searchHistorial);
                $q->where(function ($q) use ($t) {
                    $q->where('cliente_nombre', 'like', "%{$t}%");
                    if (ctype_digit($t)) {
                        $q->orWhere('id', (int) $t);
                    }
                });
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'cliente_nombre', 'fecha_venta', 'total', 'metodo_pago']);
    }

    private function movimientosFiltrados(?\App\Models\Caja $caja)
    {
        if (! $caja) {
            return null;
        }

        $query = $caja->movimientos()->orderByDesc('fecha');

        match ($this->filtroMov) {
            'venta' => $query->where('tipo', 'venta'),
            'ingreso' => $query->where('tipo', 'ingreso'),
            'egreso' => $query->whereIn('tipo', ['egreso', 'devolucion']),
            default => null,
        };

        return $query->paginate(8, ['*'], 'movs');
    }

    // ====================================================================
    // Apertura / cierre / reapertura
    // ====================================================================

    public function abrirCaja(CajaService $service): void
    {
        $this->validate(['saldoInicial' => 'required|numeric|min:0'], [
            'saldoInicial.required' => 'Ingresa el saldo inicial.',
            'saldoInicial.min' => 'El saldo inicial no puede ser negativo.',
        ]);

        try {
            $service->abrir((float) $this->saldoInicial, auth()->id());
            $this->reset('saldoInicial', 'showApertura');
            $this->toast('Caja abierta correctamente', 'ok');
        } catch (\RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function confirmarCerrar(CajaService $service): void
    {
        $caja = $service->cajaActiva();
        if (! $caja) {
            return;
        }
        $this->totalesCierre = $service->totales($caja);
        $this->showCerrar = true;
    }

    public function cerrarCaja(CajaService $service): void
    {
        $caja = $service->cajaActiva();
        if (! $caja) {
            $this->showCerrar = false;

            return;
        }
        $service->cerrar($caja);
        $this->showCerrar = false;
        $this->toast('Caja cerrada. Saldo final: $' . number_format($this->totalesCierre['saldo'] ?? 0, 0, ',', '.'), 'ok');
        $this->totalesCierre = null;
    }

    public function reabrirCaja(CajaService $service): void
    {
        try {
            $service->reabrir();
            $this->toast('Caja reabierta', 'ok');
        } catch (\RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    // ====================================================================
    // POS: carrito
    // ====================================================================

    /** Se dispara con Enter en el campo de escáner/búsqueda exacta. */
    public function agregarPorCodigo(): void
    {
        $codigo = trim($this->codigoBarras);
        if ($codigo === '') {
            return;
        }

        $producto = Producto::where('id', ctype_digit($codigo) ? (int) $codigo : 0)
            ->orWhere('nombre', $codigo)
            ->first();

        if (! $producto) {
            $this->toast("Producto no encontrado: {$codigo}", 'error');
            $this->codigoBarras = '';

            return;
        }

        $this->codigoBarras = '';
        $this->agregarProducto($producto->id);
    }

    public function agregarProducto(int $productoId): void
    {
        $producto = Producto::find($productoId);
        if (! $producto) {
            return;
        }

        $pos = collect($this->carrito)->search(fn ($i) => $i['id'] === $producto->id);
        $cantidadActual = $pos === false ? 0 : $this->carrito[$pos]['cantidad'];

        if ($cantidadActual + 1 > $producto->stock) {
            $this->toast("Stock insuficiente de \"{$producto->nombre}\" (disponible: {$producto->stock})", 'error');

            return;
        }

        if ($pos === false) {
            $this->carrito[] = [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'precio' => (float) $producto->precio,
                'cantidad' => 1,
                'stock' => $producto->stock,
            ];
        } else {
            $this->carrito[$pos]['cantidad']++;
        }

        $this->search = '';
        $this->codigoBarras = '';
        $this->dispatchBrowserEvent('scanner-focus');
    }

    public function cambiarCantidad(int $indice, int $delta): void
    {
        if (! isset($this->carrito[$indice])) {
            return;
        }

        $nueva = $this->carrito[$indice]['cantidad'] + $delta;

        if ($nueva <= 0) {
            $this->quitarProducto($indice);

            return;
        }

        if ($nueva > $this->carrito[$indice]['stock']) {
            $this->toast("Solo hay {$this->carrito[$indice]['stock']} unidades de \"{$this->carrito[$indice]['nombre']}\"", 'error');

            return;
        }

        $this->carrito[$indice]['cantidad'] = $nueva;
    }

    public function quitarProducto(int $indice): void
    {
        unset($this->carrito[$indice]);
        $this->carrito = array_values($this->carrito);
    }

    public function limpiarCarrito(): void
    {
        $this->carrito = [];
    }

    // ====================================================================
    // Numpad (venta manual)
    // ====================================================================

    public function numpad(string $tecla): void
    {
        if ($tecla === 'C') {
            $this->totalManual = null;

            return;
        }

        if ($tecla === '←') {
            $this->totalManual = substr((string) ($this->totalManual ?? ''), 0, -1);

            return;
        }

        $actual = (string) ($this->totalManual ?? '');
        if (strlen($actual) >= 9 || ($actual === '' && in_array($tecla, ['0', '00'], true))) {
            return;
        }

        $this->totalManual = $actual . $tecla;
    }

    public function numpadSumar(int $monto): void
    {
        $this->totalManual = (int) ($this->totalManual ?: 0) + $monto;
    }

    // ====================================================================
    // Venta
    // ====================================================================

    public function registrarVenta(CajaService $service): void
    {
        $caja = $service->cajaActiva();
        if (! $caja) {
            $this->toast('Abre la caja antes de vender', 'error');

            return;
        }

        $items = $this->ventaManual ? [] : $this->carrito;
        $total = $this->subtotal();

        if ($total <= 0) {
            $this->toast('Agrega productos o define un total válido', 'error');

            return;
        }

        if ($this->metodoPago === 'Efectivo' && (float) ($this->montoRecibido ?: 0) < $total) {
            $this->toast('El monto recibido es menor al total', 'error');

            return;
        }

        try {
            $resultado = $service->registrarVenta(
                $caja,
                $items,
                $total,
                $this->metodoPago,
                $this->clienteNombre,
                $this->motivo,
                $this->metodoPago === 'Efectivo' ? (float) $this->montoRecibido : null,
            );

            $this->ticket = [
                'id' => $resultado['venta']->id,
                'total' => $total,
                'cambio' => $resultado['cambio'],
                'metodo_pago' => $this->metodoPago,
                'cliente' => $this->clienteNombre,
                'fecha' => now()->format('d/m/Y H:i'),
            ];
            $this->showTicket = true;
            $this->limpiarVenta();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    private function limpiarVenta(): void
    {
        $this->reset(['carrito', 'montoRecibido', 'clienteId', 'ventaManual', 'totalManual']);
        $this->clienteNombre = 'Consumidor Final';
        $this->motivo = 'Venta general';
        $this->metodoPago = 'Efectivo';
    }

    // ====================================================================
    // Movimientos manuales
    // ====================================================================

    public function registrarMovimiento(CajaService $service): void
    {
        $caja = $service->cajaActiva();
        if (! $caja) {
            $this->toast('No hay caja abierta', 'error');

            return;
        }

        $this->validate([
            'movMonto' => 'required|numeric|min:1',
            'movTipo' => 'in:ingreso,egreso,devolucion',
        ], [
            'movMonto.required' => 'Ingresa el monto.',
            'movMonto.min' => 'El monto debe ser mayor a cero.',
        ]);

        MovimientoCaja::create([
            'caja_id' => $caja->id,
            'tipo' => $this->movTipo,
            'metodo_pago' => $this->movMetodo,
            'monto' => (float) $this->movMonto,
            'descripcion' => $this->movMotivo !== ''
                ? $this->movMotivo
                : ucfirst($this->movTipo),
            'fecha' => now(),
        ]);

        $this->reset(['movMonto', 'movMotivo', 'showMovimiento']);
        $this->movTipo = 'ingreso';
        $this->toast('Movimiento registrado', 'ok');
    }

    // ====================================================================
    // Cliente rápido
    // ====================================================================

    public function crearCliente(): void
    {
        $this->validate(['nuevoClienteNombre' => 'required|string|max:100'], [
            'nuevoClienteNombre.required' => 'El nombre del cliente es requerido.',
        ]);

        $cliente = Cliente::create([
            'nombre' => $this->nuevoClienteNombre,
            'telefono' => $this->nuevoClienteTelefono,
            'correo' => '',
        ]);

        $this->clienteId = $cliente->id;
        $this->clienteNombre = $cliente->nombre;
        $this->reset(['nuevoClienteNombre', 'nuevoClienteTelefono', 'showCliente']);
        $this->toast("Cliente \"{$cliente->nombre}\" creado", 'ok');
    }

    public function seleccionarCliente(int $id): void
    {
        $cliente = Cliente::find($id);
        if ($cliente) {
            $this->clienteId = $cliente->id;
            $this->clienteNombre = $cliente->nombre;
        }
        $this->showCliente = false;
    }

    public function clienteFinal(): void
    {
        $this->clienteId = null;
        $this->clienteNombre = 'Consumidor Final';
        $this->showCliente = false;
    }

    // ====================================================================
    // Historial: detalle y anulación
    // ====================================================================

    public function verVenta(int $ventaId): void
    {
        $venta = Venta::with('detalles')->find($ventaId);
        if (! $venta) {
            return;
        }

        $this->ventaDetalle = [
            'id' => $venta->id,
            'cliente' => $venta->cliente_nombre,
            'fecha' => $venta->fecha_venta?->format('d/m/Y H:i'),
            'total' => (float) $venta->total,
            'metodo_pago' => $venta->metodo_pago,
            'motivo' => $venta->motivo,
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
        $this->showEliminar = true;
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
        $this->reset('ventaAEliminar', 'showEliminar', 'ventaDetalle', 'showDetalle');
    }

    // ====================================================================
    // Utilidades
    // ====================================================================

    public function closeTicket(): void
    {
        $this->reset('ticket', 'showTicket');
        $this->dispatchBrowserEvent('scanner-focus');
    }

    public function closeDetalle(): void
    {
        $this->reset('ventaDetalle', 'showDetalle');
    }

    private function toast(string $mensaje, string $tipo = 'ok'): void
    {
        $this->dispatchBrowserEvent('caja-toast', ['mensaje' => $mensaje, 'tipo' => $tipo]);
    }
}
