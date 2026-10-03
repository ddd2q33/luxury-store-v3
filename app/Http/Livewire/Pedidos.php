<?php

namespace App\Http\Livewire;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;

class Pedidos extends PanelComponent
{
    public string $tab = 'lista'; // lista | nuevo

    public string $filtroEstado = 'todos';

    public string $search = '';

    // ========== Nuevo pedido ==========
    public ?int $clienteId = null;

    public string $clienteNombre = '';

    public string $clienteTelefono = '';

    public string $fechaEntrega = '';

    public string $metodoPago = 'Pendiente';

    public string $observaciones = '';

    public array $items = [];

    public string $searchProducto = '';

    // ========== UI ==========
    public bool $showDetalle = false;

    public ?array $pedidoDetalle = null;

    public ?int $porEliminar = null;

    public ?int $porCambiarEstado = null;

    public string $nuevoEstado = '';

    protected $queryString = ['tab' => ['except' => 'lista'], 'filtroEstado' => ['except' => 'todos'], 'search' => ['except' => '']];

    protected $messages = [
        'clienteNombre.required' => 'El nombre del cliente es requerido.',
        'fechaEntrega.required' => 'Define la fecha de entrega.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $termino = trim($this->search);

        $pedidos = Pedido::query()
            ->when($this->filtroEstado !== 'todos', fn ($q) => $q->where('estado', $this->filtroEstado))
            ->when($termino !== '', function ($q) use ($termino) {
                $q->where(function ($q) use ($termino) {
                    $q->where('numero_pedido', 'like', "%{$termino}%")
                        ->orWhere('cliente_nombre', 'like', "%{$termino}%")
                        ->orWhere('cliente_telefono', 'like', "%{$termino}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(10);

        // Productos sugeridos para el formulario (con precio > 0 y stock)
        $productosSugeridos = [];
        if ($this->tab === 'nuevo' && mb_strlen(trim($this->searchProducto)) >= 2) {
            $terminoProducto = '%' . trim($this->searchProducto) . '%';
            $productosSugeridos = Producto::where('nombre', 'like', $terminoProducto)
                ->orderBy('nombre')
                ->limit(6)
                ->get(['id', 'nombre', 'precio', 'stock'])
                ->toArray();
        }

        return view('livewire.pedidos', [
            'pedidos' => $pedidos,
            'stats' => [
                'pendientes' => Pedido::where('estado', 'pendiente')->count(),
                'preparando' => Pedido::whereIn('estado', ['confirmado', 'preparando'])->count(),
                'listos' => Pedido::where('estado', 'listo')->count(),
                'entregadosMes' => Pedido::where('estado', 'entregado')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            ],
            'productosSugeridos' => $productosSugeridos,
            'totalNuevo' => $this->totalNuevo(),
        ]);
    }

    public function totalNuevo(): float
    {
        return round(collect($this->items)->sum(fn ($i) => $i['cantidad'] * $i['precio']), 2);
    }

    // ====================================================================
    // Nuevo pedido
    // ====================================================================

    public function irNuevo(): void
    {
        $this->resetFormulario();
        $this->tab = 'nuevo';
    }

    public function agregarProducto(int $productoId): void
    {
        $producto = Producto::find($productoId);
        if (! $producto) {
            return;
        }

        $pos = collect($this->items)->search(fn ($i) => $i['id'] === $producto->id);
        $cantidadActual = $pos === false ? 0 : $this->items[$pos]['cantidad'];

        if ($cantidadActual + 1 > $producto->stock) {
            $this->toast("Stock insuficiente de \"{$producto->nombre}\" (disponible: {$producto->stock})", 'error');

            return;
        }

        if ($pos === false) {
            $this->items[] = [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'precio' => (float) $producto->precio,
                'cantidad' => 1,
                'stock' => $producto->stock,
            ];
        } else {
            $this->items[$pos]['cantidad']++;
        }

        $this->searchProducto = '';
    }

    public function cambiarCantidad(int $indice, int $delta): void
    {
        if (! isset($this->items[$indice])) {
            return;
        }

        $nueva = $this->items[$indice]['cantidad'] + $delta;

        if ($nueva <= 0) {
            unset($this->items[$indice]);
            $this->items = array_values($this->items);

            return;
        }

        if ($nueva > $this->items[$indice]['stock']) {
            $this->toast("Solo hay {$this->items[$indice]['stock']} unidades de \"{$this->items[$indice]['nombre']}\"", 'error');

            return;
        }

        $this->items[$indice]['cantidad'] = $nueva;
    }

    public function quitarItem(int $indice): void
    {
        unset($this->items[$indice]);
        $this->items = array_values($this->items);
    }

    /** Reglas de estado del viejo: solo un pedido pendiente/confirma reserva stock. */
    public function guardarPedido(): void
    {
        $this->validate([
            'clienteNombre' => 'required|string|max:100',
            'fechaEntrega' => 'required|date',
            'items' => 'required|array|min:1',
        ]);

        try {
            $pedido = DB::transaction(function () {
                // Validar stock de todos los items con lock
                foreach ($this->items as $item) {
                    $producto = Producto::lockForUpdate()->find($item['id']);
                    if (! $producto || $producto->stock < $item['cantidad']) {
                        $nombre = $producto->nombre ?? $item['nombre'];
                        throw new \RuntimeException("Stock insuficiente de \"{$nombre}\".");
                    }
                }

                $pedido = Pedido::create([
                    'numero_pedido' => $this->generarNumeroPedido(),
                    'cliente_id' => $this->clienteId,
                    'cliente_nombre' => trim($this->clienteNombre),
                    'cliente_telefono' => trim($this->clienteTelefono),
                    'fecha_pedido' => now(),
                    'fecha_entrega' => $this->fechaEntrega,
                    'estado' => 'pendiente',
                    'total' => $this->totalNuevo(),
                    'metodo_pago' => $this->metodoPago,
                    'observaciones' => trim($this->observaciones),
                ]);

                foreach ($this->items as $item) {
                    PedidoDetalle::create([
                        'pedido_id' => $pedido->id,
                        'producto_id' => $item['id'],
                        'producto_nombre' => $item['nombre'],
                        'cantidad' => $item['cantidad'],
                        'precio_unitario' => $item['precio'],
                        'subtotal' => round($item['cantidad'] * $item['precio'], 2),
                    ]);

                    // Reservar stock al crear (mismo comportamiento del sistema viejo).
                    // Se devuelve al cancelar el pedido o al eliminarlo activo.
                    Producto::where('id', $item['id'])->decrement('stock', $item['cantidad']);
                }

                return $pedido;
            });

            $this->toast("Pedido {$pedido->numero_pedido} creado", 'ok');
            $this->resetFormulario();
            $this->tab = 'lista';
        } catch (\RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /** PED-YYYYMM-0001 (mismo formato del sistema viejo). */
    private function generarNumeroPedido(): string
    {
        $prefijo = 'PED-' . now()->format('Ym') . '-';
        $ultimo = Pedido::where('numero_pedido', 'like', $prefijo . '%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(numero_pedido, '-', -1) AS UNSIGNED)) AS n")
            ->value('n');

        return $prefijo . str_pad(((int) $ultimo) + 1, 4, '0', STR_PAD_LEFT);
    }

    private function resetFormulario(): void
    {
        $this->reset(['clienteId', 'clienteNombre', 'clienteTelefono', 'fechaEntrega', 'metodoPago', 'observaciones', 'items', 'searchProducto']);
        $this->metodoPago = 'Pendiente';
    }

    // ====================================================================
    // Estados
    // ====================================================================

    public function pedirCambiarEstado(int $id, string $estado): void
    {
        $this->porCambiarEstado = $id;
        $this->nuevoEstado = $estado;
    }

    /**
     * Busca el pedido con sus detalles o lanza DomainException. NO se usa
     * findOrFail(): su ModelNotFoundException es un RuntimeException y escapa
     * a pantalla en blanco.
     */
    private function pedido(int $id): Pedido
    {
        $pedido = Pedido::with('detalles')->find($id);
        if (! $pedido) {
            throw new \DomainException('Ese pedido ya no existe.');
        }

        return $pedido;
    }

    public function cambiarEstadoConfirmado(): void
    {
        if (! $this->porCambiarEstado || ! in_array($this->nuevoEstado, Pedido::ESTADOS, true)) {
            return;
        }

        try {
            $pedido = $this->pedido($this->porCambiarEstado);

            DB::transaction(function () use ($pedido) {
                // Regla de negocio del sistema viejo: al cancelar se devuelve stock;
                // si se re-activa desde cancelado, se vuelve a descontar.
                if ($this->nuevoEstado === 'cancelado' && $pedido->estado !== 'cancelado') {
                    foreach ($pedido->detalles as $detalle) {
                        if ($detalle->producto_id > 0) {
                            Producto::where('id', $detalle->producto_id)->increment('stock', $detalle->cantidad);
                        }
                    }
                } elseif ($pedido->estado === 'cancelado' && $this->nuevoEstado !== 'cancelado') {
                    foreach ($pedido->detalles as $detalle) {
                        $producto = Producto::lockForUpdate()->find($detalle->producto_id);
                        if ($producto && $producto->stock < $detalle->cantidad) {
                            throw new \DomainException("No se puede reactivar: stock insuficiente de \"{$producto->nombre}\".");
                        }
                        $producto?->decrement('stock', $detalle->cantidad);
                    }
                }

                $pedido->update(['estado' => $this->nuevoEstado]);
            });

            $this->toast("Pedido {$pedido->numero_pedido} → {$this->nuevoEstado}", 'ok');
            $this->reset('porCambiarEstado', 'nuevoEstado');
        } catch (\DomainException $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    // ====================================================================
    // Detalle / eliminar
    // ====================================================================

    public function verPedido(int $id): void
    {
        $pedido = Pedido::with('detalles')->find($id);
        if (! $pedido) {
            return;
        }

        $this->pedidoDetalle = [
            'id' => $pedido->id,
            'numero' => (string) $pedido->numero_pedido,
            'cliente' => (string) $pedido->cliente_nombre,
            'telefono' => (string) $pedido->cliente_telefono,
            'fechaPedido' => $pedido->fecha_pedido?->format('d/m/Y H:i'),
            'fechaEntrega' => $pedido->fecha_entrega?->format('d/m/Y'),
            'estado' => (string) $pedido->estado,
            'total' => (float) $pedido->total,
            'metodoPago' => (string) $pedido->metodo_pago,
            'observaciones' => (string) $pedido->observaciones,
            'detalles' => $pedido->detalles->map(fn ($d) => [
                'nombre' => $d->producto_nombre,
                'cantidad' => $d->cantidad,
                'precio' => (float) $d->precio_unitario,
                'subtotal' => (float) $d->subtotal,
            ])->all(),
        ];
        $this->showDetalle = true;
    }

    public function pedirEliminar(int $id): void
    {
        $this->porEliminar = $id;
        $this->showDetalle = false;
    }

    public function eliminarConfirmado(): void
    {
        if ($this->porEliminar) {
            DB::transaction(function () {
                $pedido = Pedido::find($this->porEliminar);
                if ($pedido) {
                    // Si el pedido estaba reservando stock (no cancelado), devolverlo
                    if ($pedido->estado !== 'cancelado') {
                        foreach ($pedido->detalles as $detalle) {
                            if ($detalle->producto_id > 0) {
                                Producto::where('id', $detalle->producto_id)->increment('stock', $detalle->cantidad);
                            }
                        }
                    }
                    $pedido->detalles()->delete();
                    $pedido->delete();
                }
            });
            $this->toast('Pedido eliminado y stock repuesto', 'ok');
        }
        $this->reset('porEliminar', 'pedidoDetalle', 'showDetalle');
    }

    public function closeDetalle(): void
    {
        $this->reset('showDetalle', 'pedidoDetalle');
    }

    protected function toast(string $mensaje, string $tipo = 'ok'): void
    {
        $this->dispatchBrowserEvent('pedidos-toast', ['mensaje' => $mensaje, 'tipo' => $tipo]);
    }
}
