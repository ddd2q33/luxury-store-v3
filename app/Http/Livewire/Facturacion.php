<?php

namespace App\Http\Livewire;

use App\Models\Venta;
use Carbon\Carbon;

class Facturacion extends PanelComponent
{
    public string $search = '';

    public string $estado = 'todas'; // todas | Completada | Anulada

    public string $metodo = 'todos';

    public string $rango = 'mes'; // hoy | mes | anio | todo | custom

    public string $desde = '';

    public string $hasta = '';

    /** Venta abierta en la factura imprimible. */
    public ?int $facturaId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'estado' => ['except' => 'todas'],
        'metodo' => ['except' => 'todos'],
        'rango' => ['except' => 'mes'],
    ];

    public const ESTADOS = ['Completada', 'Anulada'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingEstado(): void
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

        // El filtro de estado decide AQUI, una sola vez: antes la base llevaba
        // where('estado','Completada') fijo y el select de estado no hacia nada.
        $base = Venta::query()
            ->when($this->estado !== 'todas', fn ($q) => $q->where('estado', $this->estado))
            ->when($desde, fn ($q) => $q->whereDate('fecha_venta', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha_venta', '<=', $hasta));

        $ventas = (clone $base)
            ->withCount('devoluciones')
            ->when($this->metodo !== 'todos', fn ($q) => $q->where('metodo_pago', $this->metodo))
            ->when($termino !== '', function ($q) use ($termino) {
                $q->where('cliente_nombre', 'like', "%{$termino}%");

                // Ademas de buscar por nombre, un termino puramente numerico
                // busca por numero de factura.
                if (ctype_digit($termino)) {
                    $q->orWhere('id', (int) $termino);
                }
            })
            ->orderByDesc('fecha_venta')
            ->orderByDesc('id')
            ->paginate(12);

        // Totales sobre el MISMO filtro elegido (estado + rango), no sobre
        // "Completadas" fijas: si se filtra Anuladas, las stats deben reflejarlo.
        $total = (clone $base)->sum('total');
        $cantidad = (clone $base)->count();

        $factura = $this->facturaId
            ? Venta::with(['detalles', 'devoluciones'])->find($this->facturaId)
            : null;

        return view('livewire.facturacion', [
            'ventas' => $ventas,
            'factura' => $factura,
            'metodos' => Venta::query()
                ->whereNotNull('metodo_pago')
                ->distinct()
                ->orderBy('metodo_pago')
                ->pluck('metodo_pago'),
            'stats' => [
                'total' => $total,
                'cantidad' => $cantidad,
                'promedio' => $cantidad > 0 ? $total / $cantidad : 0,
                'anuladas' => Venta::where('estado', 'Anulada')->count(),
            ],
        ]);
    }

    public function verFactura(int $id): void
    {
        $this->facturaId = $id;
    }

    public function cerrarFactura(): void
    {
        $this->facturaId = null;
    }
}
