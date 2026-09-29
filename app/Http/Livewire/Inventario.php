<?php

namespace App\Http\Livewire;

use App\Models\Categoria;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Support\Money;

class Inventario extends PanelComponent
{
    public string $search = '';

    public string $filtroEstado = ''; // agotado | bajo | negativo | sin_precio

    public function render()
    {
        $termino = trim($this->search);

        $porCategoria = $this->resumenPorCategoria();

        $productos = Producto::query()
            ->with('categoria')
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('proveedor', 'like', "%{$termino}%");
            }))
            ->when($this->filtroEstado === 'agotado', fn ($q) => $q->where('stock', '<=', 0)->where('stock', '>=', 0))
            ->when($this->filtroEstado === 'negativo', fn ($q) => $q->where('stock', '<', 0))
            ->when($this->filtroEstado === 'bajo', fn ($q) => $q->where('stock_minimo', '>', 0)->whereColumn('stock', '<=', 'stock_minimo')->where('stock', '>', 0))
            ->when($this->filtroEstado === 'sin_precio', fn ($q) => $q->where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0)))
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'categoria' => $p->categoria?->nombre ?? 'Sin categoría',
                'stock' => (int) $p->stock,
                'stock_minimo' => (int) $p->stock_minimo,
                'precio' => (float) ($p->precio ?? 0),
                'valor' => (float) $p->stock * (float) ($p->precio ?? 0),
                'estado' => $p->estadoStock(),
            ])
            ->sortByDesc('valor')
            ->values();

        return view('livewire.inventario', [
            'porCategoria' => $porCategoria,
            'productos' => $productos,
            'stats' => [
                'productos' => Producto::count(),
                'unidades' => (int) Producto::sum('stock'),
                'valorCosto' => (float) Producto::sum(\DB::raw('stock * COALESCE(precio, 0)')),
                'sinPrecio' => Producto::where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0))->count(),
                'valorConPrecio' => (float) Producto::where('precio', '>', 0)->sum(\DB::raw('stock * precio')),
                'conPrecio' => Producto::where('precio', '>', 0)->count(),
                'categorias' => Categoria::count(),
                'movimientos' => InventarioMovimiento::count(),
            ],
        ]);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['search', 'filtroEstado'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Valorización por categoría. Usa agregación directa porque `withSum` con
     * alias de relación no es válido en Laravel 9.
     */
    private function resumenPorCategoria(): array
    {
        $agregado = \DB::table('productos')
            ->selectRaw('categoria_id, COUNT(*) AS productos,
                         COALESCE(SUM(stock), 0) AS unidades,
                         COALESCE(SUM(stock * COALESCE(precio, 0)), 0) AS valor')
            ->groupBy('categoria_id')
            ->get()
            ->keyBy('categoria_id');

        return Categoria::orderBy('nombre')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'productos' => (int) ($agregado[$c->id]->productos ?? 0),
                'unidades' => (int) ($agregado[$c->id]->unidades ?? 0),
                'valor' => (float) ($agregado[$c->id]->valor ?? 0),
            ])
            ->sortByDesc('valor')
            ->values()
            ->all();
    }
}
