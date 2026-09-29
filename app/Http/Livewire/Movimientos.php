<?php

namespace App\Http\Livewire;

use App\Models\MovimientoCaja;
use Carbon\Carbon;

class Movimientos extends PanelComponent
{
    public string $search = '';

    public string $tipo = 'todos'; // todos | ingreso | egreso | venta | devolucion

    public string $metodo = 'todos';

    public string $rango = 'mes'; // hoy | mes | anio | todo | custom

    public string $desde = '';

    public string $hasta = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'tipo' => ['except' => 'todos'],
        'metodo' => ['except' => 'todos'],
        'rango' => ['except' => 'mes'],
    ];

    public const TIPOS = [
        'ingreso' => 'Ingreso',
        'egreso' => 'Egreso',
        'venta' => 'Venta',
        'devolucion' => 'Devolución',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    public function updatingMetodo(): void
    {
        $this->resetPage();
    }

    public function updatingRango(): void
    {
        $this->resetPage();
    }

    /** @return array{0: ?string, 1: ?string} */
    private function rangoFechas(): array
    {
        $hoy = Carbon::today();

        return match ($this->rango) {
            'hoy' => [$hoy->toDateString(), $hoy->toDateString()],
            'anio' => [Carbon::create($hoy->year, 1, 1)->toDateString(), Carbon::create($hoy->year, 12, 31)->toDateString()],
            'todo' => [null, null],
            'custom' => [
                $this->desde !== '' ? Carbon::parse($this->desde)->toDateString() : null,
                $this->hasta !== '' ? Carbon::parse($this->hasta)->toDateString() : null,
            ],
            default => [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()],
        };
    }

    public function render()
    {
        [$desde, $hasta] = $this->rangoFechas();
        $termino = trim($this->search);

        $base = MovimientoCaja::query()
            ->when($desde, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha', '<=', $hasta));

        $movimientos = (clone $base)
            ->with('caja')
            ->when($this->tipo !== 'todos', fn ($q) => $q->where('tipo', $this->tipo))
            ->when($this->metodo !== 'todos', fn ($q) => $q->where('metodo_pago', $this->metodo))
            ->when($termino !== '', fn ($q) => $q->where('descripcion', 'like', "%{$termino}%"))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(15);

        // Ingresos = 'ingreso' + 'venta' (el enum no tiene un valor 'devolucion'
        // positivo: las devoluciones restan).
        $entradas = (clone $base)
            ->whereIn('tipo', ['ingreso', 'venta'])
            ->sum('monto');
        $salidas = (clone $base)
            ->whereIn('tipo', ['egreso', 'devolucion'])
            ->sum('monto');

        $hoyQuery = fn () => MovimientoCaja::whereDate('fecha', now()->toDateString());

        return view('livewire.movimientos', [
            'movimientos' => $movimientos,
            'metodos' => MovimientoCaja::query()
                ->whereNotNull('metodo_pago')
                ->distinct()
                ->orderBy('metodo_pago')
                ->pluck('metodo_pago'),
            'stats' => [
                'entradas' => $entradas,
                'salidas' => $salidas,
                'balance' => $entradas - $salidas,
                // Balance (no suma bruta) del dia: mezclar entradas y salidas
                // en un solo numero no significa nada.
                'hoy' => $hoyQuery()
                    ->whereIn('tipo', ['ingreso', 'venta'])->sum('monto')
                    - $hoyQuery()->whereIn('tipo', ['egreso', 'devolucion'])->sum('monto'),
            ],
        ]);
    }
}
