<?php

namespace App\Http\Livewire;

use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Support\StockService;

class Stock extends PanelComponent
{
    public string $vista = 'movimientos'; // movimientos | alertas

    public string $filtroTipo = ''; // Entrada | Salida | Ajuste

    public string $search = '';

    // ========== Ajuste / merma ==========
    public bool $showAjuste = false;

    public string $ajusteProducto = '';

    public string $ajusteTipo = 'Ajuste'; // Ajuste (conteo) | Salida (merma)

    public string $ajusteCantidad = '';

    public string $ajusteObservaciones = '';

    // ========== Stock mínimo (columna que ya existe en productos) ==========
    public string $minimo = '';

    public int $minimoProducto = 0;

    protected $queryString = [
        'vista' => ['except' => 'movimientos'],
        'filtroTipo' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function render()
    {
        $termino = trim($this->search);
        $hoy = now()->toDateString();

        $movimientos = InventarioMovimiento::query()
            ->with('producto')
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->whereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$termino}%"))
                    ->orWhere('observaciones', 'like', "%{$termino}%");
            }))
            ->when($this->filtroTipo !== '', fn ($q) => $q->where('tipo', $this->filtroTipo))
            ->orderByDesc('id')
            ->paginate(12);

        // Productos que requieren atención, del peor al mejor.
        $alertas = Producto::with('categoria')
            ->necesitaReposicion()
            ->get()
            ->sortBy(fn ($p) => match ($p->estadoStock()) {
                'negativo' => 0,
                'agotado'  => 1,
                'bajo'     => 2,
                default    => 3,
            })
            ->values();

        $productoAjuste = $this->ajusteProducto !== ''
            ? Producto::find($this->ajusteProducto)
            : null;

        return view('livewire.stock', [
            'movimientos' => $movimientos,
            'alertas' => $alertas,
            'productoAjuste' => $productoAjuste,
            'ajustesDisponibles' => Producto::orderBy('nombre')->get(['id', 'nombre', 'stock', 'stock_minimo']),
            'stats' => [
                'hoy' => InventarioMovimiento::where('fecha', $hoy)->count(),
                'entradas' => (int) InventarioMovimiento::where('tipo', InventarioMovimiento::ENTRADA)->sum('cantidad'),
                'salidas' => (int) InventarioMovimiento::where('tipo', InventarioMovimiento::SALIDA)->sum('cantidad'),
                'alertas' => $alertas->count(),
            ],
        ]);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['search', 'filtroTipo', 'vista'], true)) {
            $this->resetPage();
        }
    }

    public function irA(string $vista): void
    {
        $this->vista = in_array($vista, ['movimientos', 'alertas'], true) ? $vista : 'movimientos';
        $this->resetPage();
    }

    // ====================================================================
    // Ajuste de inventario
    // ====================================================================

    public function abrirAjuste(?int $productoId = null): void
    {
        $this->reset(['ajusteProducto', 'ajusteCantidad', 'ajusteObservaciones', 'ajusteTipo']);
        $this->ajusteTipo = 'Ajuste';
        $this->ajusteProducto = $productoId !== null ? (string) $productoId : '';
        $this->resetValidation();
        $this->showAjuste = true;
    }

    public function guardarAjuste(): void
    {
        $this->validate([
            'ajusteProducto' => 'required|integer|exists:productos,id',
            'ajusteCantidad' => 'required|integer|min:0',
            'ajusteObservaciones' => 'nullable|string|max:500',
        ], [
            'ajusteProducto.required' => 'Selecciona el producto a ajustar.',
            'ajusteCantidad.min' => 'La cantidad no puede ser negativa.',
        ]);

        $cantidad = (int) $this->ajusteCantidad;
        $observaciones = trim($this->ajusteObservaciones);
        $productoId = (int) $this->ajusteProducto;

        if ($this->ajusteTipo === 'Salida') {
            $this->ejecutar(
                fn () => StockService::salida(
                    $productoId,
                    $cantidad,
                    $observaciones !== '' ? $observaciones : 'Baja de inventario'
                ),
                'Baja de inventario registrada'
            );
        } else {
            $this->ejecutar(
                fn () => StockService::ajuste($productoId, $cantidad, $observaciones),
                'Ajuste de inventario registrado'
            );
        }

        $this->showAjuste = false;
    }

    // ====================================================================
    // Stock mínimo
    // ====================================================================

    /**
     * Guarda el mínimo de reposición de un producto.
     *
     * Escribe productos.stock_minimo, que es una COLUMNA QUE YA EXISTE: el
     * legacy nunca la llenó (los 106 productos están en 0), por eso la alerta
     * "stock bajo" no podía dispararse. No pasa por StockService porque el
     * mínimo no mueve existencias ni genera inventario_movimientos.
     */
    public function guardarMinimo(): void
    {
        $this->validate(
            ['minimo' => 'required|integer|min:0|max:99999'],
            [
                'minimo.required' => 'Escribe el mínimo de stock.',
                'minimo.integer'  => 'El mínimo debe ser un número entero.',
                'minimo.min'      => 'El mínimo no puede ser negativo.',
            ]
        );

        $ok = $this->ejecutar(
            function () {
                $this->producto($this->minimoProducto)
                    ->forceFill(['stock_minimo' => (int) $this->minimo])
                    ->save();
            },
            'Stock mínimo actualizado'
        );

        if ($ok) {
            $this->cerrarMinimo();
        }
    }

    public function abrirMinimo(int $productoId): void
    {
        $producto = $this->producto($productoId);

        $this->minimoProducto = $productoId;
        $this->minimo = (string) $producto->stock_minimo;
        $this->resetValidation();
    }

    public function cerrarMinimo(): void
    {
        $this->reset(['minimoProducto', 'minimo']);
    }

    /**
     * Resuelve un producto o falla con una excepción de dominio.
     * Nunca findOrFail(): su ModelNotFoundException es un RuntimeException,
     * escapa de ejecutar() y deja la pantalla en blanco.
     */
    private function producto(int $id): Producto
    {
        $producto = Producto::find($id);

        if (! $producto) {
            throw new \DomainException('Ese producto ya no existe.');
        }

        return $producto;
    }

    public function cerrarAjuste(): void
    {
        $this->reset(['showAjuste', 'ajusteProducto', 'ajusteCantidad', 'ajusteObservaciones', 'ajusteTipo']);
    }
}
