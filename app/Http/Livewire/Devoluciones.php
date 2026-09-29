<?php

namespace App\Http\Livewire;

use App\Models\Devolucion;
use App\Models\Venta;
use App\Support\DevolucionService;
use App\Support\Money;
use Carbon\Carbon;

class Devoluciones extends PanelComponent
{
    public string $search = '';

    public string $rango = 'mes'; // hoy | mes | anio | todo | custom

    public string $desde = '';

    public string $hasta = '';

    // ========== Formulario ==========
    public bool $showForm = false;

    public string $ventaId = '';

    public string $motivo = '';

    public string $totalDevuelto = '';

    public string $fecha = '';

    public bool $registrarEnCaja = true;

    // ========== Anular ==========
    public ?int $porAnular = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'rango' => ['except' => 'mes'],
    ];

    protected function rules(): array
    {
        return [
            'ventaId' => 'required|integer|exists:ventas,id',
            'motivo' => 'required|string|max:255',
            'totalDevuelto' => ['required', 'numeric', 'min:1'],
            'fecha' => ['required', 'date'],
        ];
    }

    protected $messages = [
        'ventaId.required' => 'Selecciona la venta que vas a devolver.',
        'ventaId.exists' => 'Esa venta no existe.',
        'motivo.required' => 'Escribe el motivo de la devolución.',
        'totalDevuelto.required' => 'Escribe el monto a devolver.',
        'totalDevuelto.numeric' => 'El monto debe ser un número. Usa punto para los decimales.',
        'totalDevuelto.min' => 'El monto debe ser mayor a $0.',
        'fecha.required' => 'Escribe la fecha de la devolución.',
    ];

    public function updatingSearch(): void
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

        $devoluciones = Devolucion::query()
            ->with('venta')
            ->when($desde, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha', '<=', $hasta))
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->where('cliente_nombre', 'like', "%{$termino}%")
                    ->orWhere('motivo', 'like', "%{$termino}%");
            }))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(12);

        $base = fn () => Devolucion::query()
            ->when($desde, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha', '<=', $hasta));

        return view('livewire.devoluciones', [
            'devoluciones' => $devoluciones,
            'stats' => [
                'total' => $base()->sum('total_devuelto'),
                'cantidad' => $base()->count(),
                'hoy' => Devolucion::whereDate('fecha', now()->toDateString())->sum('total_devuelto'),
                'ventasDevueltas' => Devolucion::whereNotNull('venta_id')->distinct('venta_id')->count('venta_id'),
            ],
        ]);
    }

    // ====================================================================
    // Registro
    // ====================================================================

    public function nuevo(): void
    {
        $this->reset(['ventaId', 'motivo', 'totalDevuelto', 'fecha', 'registrarEnCaja']);
        $this->fecha = now()->toDateString();
        $this->registrarEnCaja = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedVentaId(): void
    {
        if ($this->ventaId === '') {
            $this->totalDevuelto = '';

            return;
        }

        $this->totalDevuelto = (string) $this->saldoDevoluble((int) $this->ventaId);
        $this->resetValidation();
    }

    /**
     * Lo que falta devolver de una venta. Delega en el servicio para que la
     * vista y el guardado usen exactamente la misma regla.
     */
    public function saldoDevoluble(int $ventaId): float
    {
        return app(DevolucionService::class)->saldoDevoluble($ventaId);
    }

    public function guardar(DevolucionService $servicio): void
    {
        $this->validate();

        $ok = $this->ejecutar(
            fn () => $servicio->registrar(
                (int) $this->ventaId,
                (float) $this->totalDevuelto,
                trim($this->motivo),
                $this->fecha.' '.now()->format('H:i:s'),
                (bool) $this->registrarEnCaja
            ),
            sprintf('Devolución de %s registrada', Money::cents($this->totalDevuelto))
        );

        if ($ok) {
            $this->reset(['ventaId', 'motivo', 'totalDevuelto', 'fecha', 'registrarEnCaja', 'showForm']);
        }
    }

    // ====================================================================
    // Anular
    // ====================================================================

    public function pedirAnular(int $id): void
    {
        $this->porAnular = $id;
    }

    public function anularConfirmado(DevolucionService $servicio): void
    {
        $id = $this->porAnular;

        if ($id) {
            $devolucion = Devolucion::findOrFail($id);
            $this->ejecutar(fn () => $servicio->anular($devolucion), 'Devolución anulada');
        }

        $this->porAnular = null;
    }
}
