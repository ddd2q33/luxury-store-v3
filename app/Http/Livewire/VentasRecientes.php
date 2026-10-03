<?php

namespace App\Http\Livewire;

use App\Models\Venta;
use App\Support\Money;

class VentasRecientes extends PanelComponent
{
    /** Busca por cliente o id de venta. */
    public string $search = '';

    protected $queryString = ['search' => ['except' => '']];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $ventas = Venta::query()
            ->where('estado', 'Completada')
            ->when($this->search !== '', function ($q) {
                $termino = '%' . $this->search . '%';
                $q->where(function ($q) use ($termino) {
                    $q->where('cliente_nombre', 'like', $termino)
                        ->orWhere('id', 'like', $termino);
                });
            })
            ->orderByDesc('fecha_venta')
            ->paginate(10);

        return view('livewire.ventas-recientes', [
            'ventas' => $ventas,
        ]);
    }
}
