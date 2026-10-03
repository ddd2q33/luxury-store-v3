<?php

namespace App\Http\Livewire;

use App\Models\Gasto;
use App\Support\Money;
use Carbon\Carbon;

class Gastos extends PanelComponent
{
    public string $search = '';

    public string $rango = 'mes'; // hoy | mes | anio | todo | custom

    public string $desde = '';

    public string $hasta = '';

    // ========== Formulario ==========
    public bool $showForm = false;

    public ?int $editandoId = null;

    public string $descripcion = '';

    public string $monto = '';

    public string $fecha = '';

    public string $hora = '';

    // ========== Eliminar ==========
    public ?int $porEliminar = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'rango' => ['except' => 'mes'],
    ];

    protected function rules(): array
    {
        return [
            'descripcion' => 'required|string|max:255',
            'monto' => ['required', 'numeric', 'min:1'],
            'fecha' => ['required', 'date'],
            'hora' => ['nullable', 'date_format:H:i'],
        ];
    }

    protected $messages = [
        'descripcion.required' => 'Describe el gasto.',
        'monto.required' => 'Escribe el monto.',
        'monto.numeric' => 'El monto debe ser un número. Usa punto para los decimales.',
        'monto.min' => 'El monto debe ser mayor a $0.',
        'fecha.required' => 'Escribe la fecha del gasto.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRango(): void
    {
        $this->resetPage();
    }

    /**
     * Rango de fechas del filtro. null = sin limite.
     *
     * @return array{0: ?string, 1: ?string}
     */
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

        $base = Gasto::query()
            ->when($desde, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha', '<=', $hasta));

        $gastos = (clone $base)
            ->when($termino !== '', fn ($q) => $q->where('descripcion', 'like', "%{$termino}%"))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(12);

        $total = (clone $base)->sum('monto');

        return view('livewire.gastos', [
            'gastos' => $gastos,
            'totalRango' => $total,
            'stats' => [
                'totalGeneral' => Gasto::sum('monto'),
                'totalMes' => Gasto::whereYear('fecha', now()->year)
                    ->whereMonth('fecha', now()->month)->sum('monto'),
                'totalHoy' => Gasto::whereDate('fecha', now()->toDateString())->sum('monto'),
                'cantidad' => (clone $base)->count(),
                'promedio' => (clone $base)->count() > 0 ? $total / (clone $base)->count() : 0,
            ],
        ]);
    }

    // ====================================================================
    // CRUD
    // ====================================================================

    public function nuevo(): void
    {
        $this->reset(['editandoId', 'descripcion', 'monto']);
        $this->fecha = now()->toDateString();
        $this->hora = now()->format('H:i');
        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Busca el gasto o lanza DomainException. NO se usa findOrFail(): su
     * ModelNotFoundException es un RuntimeException y escapa a pantalla en
     * blanco.
     */
    private function gasto(int $id): Gasto
    {
        $gasto = Gasto::find($id);
        if (! $gasto) {
            throw new \DomainException('Ese gasto ya no existe.');
        }

        return $gasto;
    }

    public function editar(int $id): void
    {
        $this->cargar(function () use ($id) {
            $gasto = $this->gasto($id);
            $this->editandoId = $gasto->id;
            $this->descripcion = (string) $gasto->descripcion;
            $this->monto = (string) (float) $gasto->monto;
            $this->fecha = \Illuminate\Support\Carbon::parse($gasto->fecha)->toDateString();
            $this->hora = substr((string) $gasto->hora, 0, 5);
            $this->resetValidation();
            $this->showForm = true;
        });
    }

    public function guardar(): void
    {
        $this->validate();

        $datos = [
            'descripcion' => trim($this->descripcion),
            'monto' => round((float) $this->monto, 2),
            'fecha' => $this->fecha,
            'hora' => $this->hora !== '' ? $this->hora.':00' : now()->format('H:i:s'),
        ];

        if ($this->editandoId) {
            Gasto::where('id', $this->editandoId)->update($datos);
            $this->toastOk('Gasto actualizado');
        } else {
            Gasto::create($datos);
            $this->toastOk('Gasto registrado');
        }

        $this->cerrarForm();
    }

    public function pedirEliminar(int $id): void
    {
        $this->porEliminar = $id;
    }

    public function eliminarConfirmado(): void
    {
        $id = $this->porEliminar;

        if ($id) {
            $this->cargar(function () use ($id) {
                $gasto = $this->gasto($id);
                Gasto::whereKey($id)->delete();
                $this->toastOk(sprintf(
                    'Gasto eliminado: %s de %s',
                    Money::cents($gasto->monto),
                    Carbon::parse($gasto->fecha)->format('d/m/Y')
                ));
            });
        }

        $this->porEliminar = null;
    }

    public function cerrarForm(): void
    {
        $this->reset(['editandoId', 'descripcion', 'monto', 'fecha', 'hora', 'showForm']);
    }
}
