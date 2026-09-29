<?php

namespace App\Http\Livewire;

use App\Models\Categoria;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Support\Money;
use App\Support\StockService;

class Ingreso extends PanelComponent
{
    public string $modo = 'multiple'; // multiple | simple

    // ========== Ingreso simple ==========
    public string $producto_id = '';

    public string $cantidad = '1';

    // ========== Ingreso múltiple ==========
    public array $lineas = [];

    // ========== Comunes ==========
    public string $fecha = '';

    public string $observaciones = '';

    /** Productos agrupados por categoría para los <select>. */
    public array $catalogo = [];

    public function mount(): void
    {
        $this->fecha = now()->toDateString();
        $this->lineas = [$this->lineaVacia()];
        $this->catalogo = $this->construirCatalogo();
    }

    private function lineaVacia(): array
    {
        return ['producto_id' => '', 'cantidad' => 1];
    }

    private function construirCatalogo(): array
    {
        $productos = Producto::with('categoria')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria_id', 'precio', 'stock']);

        $agrupados = [];

        foreach ($productos as $p) {
            $cat = $p->categoria?->nombre ?? 'Sin categoría';
            $agrupados[$cat][] = [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'stock' => (int) $p->stock,
                'precio' => (float) ($p->precio ?? 0),
            ];
        }

        ksort($agrupados);

        return $agrupados;
    }

    public function render()
    {
        $precios = Producto::whereIn('id', array_filter(array_column($this->lineas, 'producto_id')))
            ->pluck('precio', 'id');

        $hoy = now()->toDateString();
        $semana = now()->subDays(6)->toDateString();

        $ultimos = InventarioMovimiento::with('producto')
            ->where('tipo', InventarioMovimiento::ENTRADA)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'producto_id', 'cantidad', 'observaciones', 'fecha']);

        return view('livewire.ingreso', [
            'precios' => $precios,
            'ultimos' => $ultimos,
            'stats' => [
                'hoy' => InventarioMovimiento::where('tipo', InventarioMovimiento::ENTRADA)->where('fecha', $hoy)->count(),
                'unidadesHoy' => (int) InventarioMovimiento::where('tipo', InventarioMovimiento::ENTRADA)->where('fecha', $hoy)->sum('cantidad'),
                'semana' => (int) InventarioMovimiento::where('tipo', InventarioMovimiento::ENTRADA)->whereBetween('fecha', [$semana, $hoy])->sum('cantidad'),
                'total' => InventarioMovimiento::where('tipo', InventarioMovimiento::ENTRADA)->count(),
            ],
        ]);
    }

    // ====================================================================
    // Gestión de líneas
    // ====================================================================

    public function agregarLinea(): void
    {
        $this->lineas[] = $this->lineaVacia();
    }

    public function quitarLinea(int $index): void
    {
        unset($this->lineas[$index]);
        $this->lineas = array_values($this->lineas);
    }

    // ====================================================================
    // Registro
    // ====================================================================

    public function registrarSimple(): void
    {
        $this->validate([
            'producto_id' => 'required|integer|exists:productos,id',
            'cantidad' => 'required|integer|min:1',
            'fecha' => 'required|date',
        ], [
            'producto_id.required' => 'Selecciona el producto que ingresó.',
            'cantidad.min' => 'La cantidad debe ser al menos 1.',
        ]);

        $this->ejecutar(
            fn () => StockService::entrada(
                (int) $this->producto_id,
                (int) $this->cantidad,
                trim($this->observaciones) !== '' ? trim($this->observaciones) : 'Ingreso de mercancía',
                $this->fecha
            ),
            sprintf(
                'Ingreso registrado: +%s unidad(es) a %s',
                number_format((int) $this->cantidad, 0, ',', '.'),
                Producto::find($this->producto_id)?->nombre ?? 'producto'
            )
        );

        $this->producto_id = '';
        $this->cantidad = '1';
        $this->observaciones = '';
    }

    public function registrarMultiple(): void
    {
        $this->validate([
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
            'fecha' => 'required|date',
        ], [
            'lineas.*.producto_id.required' => 'Todas las líneas necesitan un producto.',
            'lineas.*.cantidad.min' => 'La cantidad debe ser al menos 1.',
        ]);

        $lineas = array_map(fn ($l) => [
            'producto_id' => (int) $l['producto_id'],
            'cantidad' => (int) $l['cantidad'],
        ], $this->lineas);

        $this->ejecutar(
            fn () => StockService::ingresoMultiple(
                $lineas,
                trim($this->observaciones) !== '' ? trim($this->observaciones) : 'Ingreso de mercancía',
                $this->fecha
            ),
            'Ingreso multiple registrado correctamente'
        );

        $this->lineas = [$this->lineaVacia()];
        $this->observaciones = '';
    }

    public function usarModo(string $modo): void
    {
        $this->modo = $modo === 'simple' ? 'simple' : 'multiple';
        $this->resetValidation();
    }
}
