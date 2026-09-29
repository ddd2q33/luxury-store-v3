<?php

namespace App\Http\Livewire;

use App\Models\Empleado;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Admin → Empleados. CRUD sobre la tabla legacy `empleados` (datos de RRHH) y
 * listado de solo lectura de `usuarios` (acceso al panel), que es una tabla
 * distinta: el login del panel nunca lee `empleados`.
 *
 * La tabla `empleados` llega vacía desde el legacy, así que la vista lo dice en
 * vez de mostrar datos inventados.
 */
class Empleados extends PanelComponent
{
    public string $search = '';

    // ========== Formulario ==========
    public bool $showForm = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $cargo = '';

    public string $telefono = '';

    public string $correo = '';

    public string $salario = '';

    public string $fechaIngreso = '';

    // ========== Eliminar ==========
    public ?int $porEliminar = null;

    protected $queryString = [
        'search' => ['except' => ''],
    ];

    /**
     * El middleware `admin` protege la ruta, pero el endpoint de Livewire es
     * publico: sin esta guarda un cajero podria montar el componente y llamar
     * a guardar() a mano.
     */
    public function mount(): void
    {
        abort_unless(Auth::user()?->esAdmin(), 403, 'No tienes permisos para acceder a esta seccion.');
    }

    protected function rules(): array
    {
        return [
            'nombre' => 'required|string|max:100',
            'cargo' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:100',
            'salario' => ['nullable', 'numeric', 'min:0'],
            'fechaIngreso' => 'nullable|date',
        ];
    }

    protected $messages = [
        'nombre.required' => 'Escribe el nombre del empleado.',
        'cargo.required' => 'Escribe el cargo. La columna no admite vacío.',
        'correo.email' => 'El correo no tiene un formato válido.',
        'salario.numeric' => 'El salario debe ser un número. Usa punto para los decimales.',
        'salario.min' => 'El salario no puede ser negativo.',
        'fechaIngreso.date' => 'La fecha de ingreso no es válida.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $termino = trim($this->search);

        $empleados = Empleado::query()
            ->when($termino !== '', fn ($q) => $q->where(function ($sub) use ($termino) {
                $sub->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('cargo', 'like', "%{$termino}%")
                    ->orWhere('correo', 'like', "%{$termino}%");
            }))
            ->orderBy('nombre')
            ->paginate(12);

        $totalEmpleados = Empleado::count();

        return view('livewire.empleados', [
            'empleados' => $empleados,
            'usuarios' => User::query()->orderBy('id')->get(),
            'stats' => [
                'total' => $totalEmpleados,
                'salarioTotal' => (float) Empleado::sum('salario'),
                'promedio' => $totalEmpleados > 0 ? (float) Empleado::avg('salario') : 0.0,
                'esteAnio' => Empleado::where('fecha_ingreso', '>=', Carbon::create(now()->year, 1, 1)->toDateString())->count(),
            ],
        ]);
    }

    // ====================================================================
    // CRUD
    // ====================================================================

    public function nuevo(): void
    {
        $this->reset(['editandoId', 'nombre', 'cargo', 'telefono', 'correo', 'salario']);
        $this->fechaIngreso = now()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function editar(int $id): void
    {
        // Sin findOrFail: su ModelNotFoundException es un RuntimeException y
        // escaparia como pantalla en blanco si el id quedo obsoleto.
        $empleado = Empleado::find($id);

        if (! $empleado) {
            $this->toastError('Ese empleado ya no existe.');

            return;
        }

        $this->editandoId = $empleado->id;
        $this->nombre = (string) $empleado->nombre;
        $this->cargo = (string) $empleado->cargo;
        $this->telefono = (string) $empleado->telefono;
        $this->correo = (string) $empleado->correo;
        $this->salario = $empleado->salario > 0 ? (string) $empleado->salario : '';
        $this->fechaIngreso = $empleado->fecha_ingreso?->toDateString() ?? '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function guardar(): void
    {
        $this->validate();

        $datos = [
            'nombre' => trim($this->nombre),
            'cargo' => trim($this->cargo),
            'telefono' => $this->telefono !== '' ? trim($this->telefono) : null,
            'correo' => $this->correo !== '' ? trim($this->correo) : null,
            'salario' => $this->salario !== '' ? round((float) $this->salario, 2) : null,
            'fecha_ingreso' => $this->fechaIngreso !== '' ? $this->fechaIngreso : null,
        ];

        if ($this->editandoId) {
            Empleado::where('id', $this->editandoId)->update($datos);
            $this->toastOk('Empleado actualizado');
        } else {
            Empleado::create($datos);
            $this->toastOk('Empleado registrado');
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

        if (! $id) {
            return;
        }

        $this->ejecutar(function () use ($id) {
            $empleado = Empleado::find($id);

            if (! $empleado) {
                throw new \DomainException('Ese empleado ya no existe.');
            }

            Empleado::whereKey($id)->delete();
        }, 'Empleado eliminado');

        $this->porEliminar = null;
    }

    public function cerrarForm(): void
    {
        $this->reset(['editandoId', 'nombre', 'cargo', 'telefono', 'correo', 'salario', 'fechaIngreso', 'showForm']);
    }
}
