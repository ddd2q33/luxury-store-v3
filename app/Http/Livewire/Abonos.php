<?php

namespace App\Http\Livewire;

use App\Models\AbonoProveedor;
use App\Models\Proveedor;
use App\Support\DeudaProveedorService;
use App\Support\Money;
use Carbon\Carbon;

class Abonos extends PanelComponent
{
    public string $search = '';

    public string $proveedorId = '';

    public string $rango = 'todo'; // todo | mes | anio | custom

    public string $desde = '';

    public string $hasta = '';

    // ========== Formulario ==========
    public bool $showForm = false;

    public int $abonoProveedorId = 0;

    public string $monto = '';

    public string $referencia = '';

    public string $fecha = '';

    // ========== Anular ==========
    public ?int $porAnular = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'proveedorId' => ['except' => ''],
        'rango' => ['except' => 'todo'],
    ];

    protected function rules(): array
    {
        return [
            'abonoProveedorId' => 'required|integer|exists:proveedores,id',
            'monto' => ['required', 'numeric', 'min:1'],
            'referencia' => 'nullable|string|max:255',
            'fecha' => ['required', 'date'],
        ];
    }

    protected $messages = [
        'abonoProveedorId.required' => 'Selecciona el proveedor al que le haces el abono.',
        'abonoProveedorId.exists' => 'Ese proveedor ya no existe.',
        'monto.required' => 'Escribe el monto del abono.',
        'monto.numeric' => 'El monto debe ser un número. Usa punto para los decimales.',
        'monto.min' => 'El abono debe ser mayor a $0.',
        'fecha.required' => 'Escribe la fecha del abono.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingProveedorId(): void
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
            'mes' => [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()],
            'anio' => [Carbon::create($hoy->year, 1, 1)->toDateString(), Carbon::create($hoy->year, 12, 31)->toDateString()],
            'custom' => [
                $this->desde !== '' ? Carbon::parse($this->desde)->toDateString() : null,
                $this->hasta !== '' ? Carbon::parse($this->hasta)->toDateString() : null,
            ],
            default => [null, null],
        };
    }

    public function render()
    {
        [$desde, $hasta] = $this->rangoFechas();
        $termino = trim($this->search);

        $base = AbonoProveedor::query()
            ->when($desde, fn ($q) => $q->whereDate('fecha_abono', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha_abono', '<=', $hasta));

        $abonos = (clone $base)
            ->with('proveedor')
            ->when($this->proveedorId !== '', fn ($q) => $q->where('proveedor_id', (int) $this->proveedorId))
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->where('referencia', 'like', "%{$termino}%")
                    ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', "%{$termino}%"));
            }))
            ->orderByDesc('fecha_abono')
            ->orderByDesc('id')
            ->paginate(12);

        $deudaTotal = Proveedor::sum('saldo_deuda');

        return view('livewire.abonos', [
            'abonos' => $abonos,
            'proveedores' => Proveedor::orderBy('nombre')->get(['id', 'nombre', 'saldo_deuda']),
            'stats' => [
                'totalAbonado' => (clone $base)->sum('monto'),
                'deudaTotal' => $deudaTotal,
                'cantidad' => (clone $base)->count(),
                'proveedoresConDeuda' => Proveedor::where('saldo_deuda', '>', 0)->count(),
            ],
        ]);
    }

    // ====================================================================
    // Registro
    // ====================================================================

    public function nuevo(): void
    {
        $this->reset(['abonoProveedorId', 'monto', 'referencia', 'fecha']);
        $this->fecha = now()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    /** Al elegir proveedor, precarga el saldo para no tener que mirarlo. */
    public function updatedAbonoProveedorId(): void
    {
        $this->monto = '';
        $this->resetValidation();
    }

    public function saldoDelProveedor(): float
    {
        if (! $this->abonoProveedorId) {
            return 0.0;
        }

        return (float) (Proveedor::find($this->abonoProveedorId)?->saldo_deuda ?? 0);
    }

    public function guardar(DeudaProveedorService $servicio): void
    {
        $this->validate();

        $proveedor = Proveedor::findOrFail($this->abonoProveedorId);

        $ok = $this->ejecutar(
            fn () => $servicio->registrarAbono(
                $proveedor,
                (float) $this->monto,
                trim($this->referencia) ?: null,
                $this->fecha.' '.now()->format('H:i:s')
            ),
            sprintf(
                'Abono de %s a %s',
                Money::cents($this->monto),
                $proveedor->nombre
            )
        );

        if ($ok) {
            $this->reset(['abonoProveedorId', 'monto', 'referencia', 'fecha', 'showForm']);
        }
    }

    public function pedirAnular(int $id): void
    {
        $this->porAnular = $id;
    }

    public function anularConfirmado(DeudaProveedorService $servicio): void
    {
        $id = $this->porAnular;

        if ($id) {
            $abono = AbonoProveedor::with('proveedor')->findOrFail($id);
            $nombre = $abono->proveedor?->nombre ?? 'el proveedor';
            $monto = $abono->monto;

            $this->ejecutar(
                fn () => $servicio->anularAbono($abono),
                sprintf('Abono de %s anulado; la deuda de %s volvió a subir', Money::cents($monto), $nombre)
            );
        }

        $this->porAnular = null;
    }
}
