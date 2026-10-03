<?php

namespace App\Http\Livewire;

use App\Models\Proveedor;
use App\Support\DeudaProveedorService;
use App\Support\Money;
use Illuminate\Validation\Rule;

class Proveedores extends PanelComponent
{
    public string $search = '';

    public string $filtro = 'todos'; // todos | con_deuda | sin_deuda

    // ========== Formulario ==========
    public bool $showForm = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $contacto = '';

    public string $telefono = '';

    public string $correo = '';

    public string $direccion = '';

    public string $descripcionDeudaInicial = '';

    /** Solo al crear: deja la deuda inicial y su asiento. Al editar se ignora. */
    public string $deudaInicial = '0';

    // ========== Ajuste de deuda ==========
    public bool $showAjuste = false;

    public ?int $ajusteId = null;

    public string $nuevoSaldo = '';

    public string $razonAjuste = '';

    // ========== Historial de deuda ==========
    public ?int $historialId = null;

    // ========== Eliminar ==========
    public ?int $porEliminar = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filtro' => ['except' => 'todos'],
    ];

    protected function rules(): array
    {
        return [
            'nombre' => [
                'required', 'string', 'max:150',
                Rule::unique('proveedores', 'nombre')
                    ->ignore($this->editandoId)
                    ->where(fn ($q) => $q->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($this->nombre))])),
            ],
            'contacto' => 'nullable|string|max:100',
            'telefono' => 'nullable|string|max:50',
            'correo' => ['nullable', 'email', 'max:100'],
            'direccion' => 'nullable|string|max:1000',
            'descripcionDeudaInicial' => 'nullable|string|max:255',
            'deudaInicial' => ['nullable', 'numeric', 'min:0'],
            'nuevoSaldo' => ['required', 'numeric', 'min:0'],
            'razonAjuste' => 'required|string|max:255',
        ];
    }

    protected $messages = [
        'nombre.required' => 'El nombre del proveedor es obligatorio.',
        'nombre.unique' => 'Ya existe un proveedor con ese nombre.',
        'correo.email' => 'Ese correo no es válido.',
        'deudaInicial.numeric' => 'La deuda inicial debe ser un número.',
        'deudaInicial.min' => 'La deuda inicial no puede ser negativa.',
        'nuevoSaldo.required' => 'Escribe el nuevo saldo de deuda.',
        'nuevoSaldo.min' => 'El saldo de deuda no puede quedar negativo.',
        'razonAjuste.required' => 'El ajuste siempre necesita un motivo.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFiltro(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $termino = trim($this->search);

        $proveedores = Proveedor::query()
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('contacto', 'like', "%{$termino}%")
                    ->orWhere('correo', 'like', "%{$termino}%")
                    ->orWhere('telefono', 'like', "%{$termino}%");
            }))
            ->when($this->filtro === 'con_deuda', fn ($q) => $q->where('saldo_deuda', '>', 0))
            ->when($this->filtro === 'sin_deuda', fn ($q) => $q->where(function ($q) {
                $q->whereNull('saldo_deuda')->orWhere('saldo_deuda', '<=', 0);
            }))
            ->orderBy('nombre')
            ->paginate(10);

        return view('livewire.proveedores', [
            'proveedores' => $proveedores,
            'historial' => $this->historialId
                ? Proveedor::find($this->historialId)?->historialDeuda()->orderByDesc('fecha_registro')->get()
                : null,
            'proveedorHistorial' => $this->historialId ? Proveedor::find($this->historialId) : null,
            'stats' => [
                'total' => Proveedor::count(),
                'conDeuda' => Proveedor::where('saldo_deuda', '>', 0)->count(),
                'totalDeuda' => Proveedor::sum('saldo_deuda'),
                'compras' => \App\Models\Compra::count(),
            ],
        ]);
    }

    // ====================================================================
    // CRUD
    // ====================================================================

    public function nuevo(): void
    {
        $this->reset([
            'editandoId', 'nombre', 'contacto', 'telefono', 'correo',
            'direccion', 'descripcionDeudaInicial', 'deudaInicial',
        ]);
        $this->deudaInicial = '0';
        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Busca el proveedor o lanza DomainException. NO se usa findOrFail(): su
     * ModelNotFoundException es un RuntimeException y escapa a pantalla en
     * blanco. Los conteos solo se cargan si el caller los necesita.
     */
    private function proveedor(int $id, bool $conRelations = false): Proveedor
    {
        $proveedor = $conRelations
            ? Proveedor::withCount(['abonos', 'compras'])->find($id)
            : Proveedor::find($id);

        if (! $proveedor) {
            throw new \DomainException('Ese proveedor ya no existe.');
        }

        return $proveedor;
    }

    public function editar(int $id): void
    {
        $this->cargar(function () use ($id) {
            $p = $this->proveedor($id);
            $this->editandoId = $p->id;
            $this->nombre = (string) $p->nombre;
            $this->contacto = (string) $p->contacto;
            $this->telefono = (string) $p->telefono;
            $this->correo = (string) $p->correo;
            $this->direccion = (string) $p->direccion;
            $this->descripcionDeudaInicial = (string) $p->descripcion_deuda_inicial;
            $this->resetValidation();
            $this->showForm = true;
        });
    }

    public function guardar(DeudaProveedorService $servicio): void
    {
        $this->validate();

        $datos = [
            'nombre' => trim($this->nombre),
            'contacto' => trim($this->contacto) ?: null,
            'telefono' => trim($this->telefono) ?: null,
            'correo' => trim($this->correo) ?: null,
            'direccion' => trim($this->direccion) ?: null,
            'descripcion_deuda_inicial' => trim($this->descripcionDeudaInicial) ?: null,
        ];

        $ok = $this->ejecutar(function () use ($datos, $servicio) {
            if ($this->editandoId) {
                // La deuda NO se edita aqui a mano: se cambia por Abonos o por
                // el ajuste auditado, para que saldo_deuda nunca se descuadre.
                Proveedor::where('id', $this->editandoId)->update($datos);

                return;
            }

            $servicio->crearConDeudaInicial($datos, (float) ($this->deudaInicial ?: 0));
        }, $this->editandoId ? 'Proveedor actualizado' : 'Proveedor creado');

        if ($ok) {
            $this->cerrarForm();
        }
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
                $p = $this->proveedor($id, conRelations: true);

                if ($p->abonos_count > 0) {
                    throw new \DomainException(sprintf(
                        '"%s" tiene %d abono(s) registrado(s). Anulalos antes de eliminarlo.',
                        $p->nombre,
                        $p->abonos_count
                    ));
                }

                if ($p->compras_count > 0) {
                    throw new \DomainException(sprintf(
                        '"%s" tiene %d compra(s) asociadas. No se puede eliminar.',
                        $p->nombre,
                        $p->compras_count
                    ));
                }

                if ((float) ($p->saldo_deuda ?? 0) > 0) {
                    throw new \DomainException(sprintf(
                        '"%s" debe %s. Salda la deuda antes de eliminarlo.',
                        $p->nombre,
                        Money::cents($p->saldo_deuda)
                    ));
                }

                Proveedor::whereKey($id)->delete();
                $this->toastOk("Proveedor \"{$p->nombre}\" eliminado");
            });
        }

        $this->porEliminar = null;
    }

    // ====================================================================
    // Deuda
    // ====================================================================

    public function verHistorial(int $id): void
    {
        $this->historialId = $id;
    }

    public function cerrarHistorial(): void
    {
        $this->historialId = null;
    }

    public function abrirAjuste(int $id): void
    {
        $this->cargar(function () use ($id) {
            $p = $this->proveedor($id);
            $this->ajusteId = $p->id;
            $this->nuevoSaldo = (string) (float) ($p->saldo_deuda ?? 0);
            $this->razonAjuste = '';
            $this->resetValidation();
            $this->showAjuste = true;
        });
    }

    public function confirmarAjuste(DeudaProveedorService $servicio): void
    {
        $id = $this->ajusteId;
        $this->validate(['nuevoSaldo' => 'required|numeric|min:0', 'razonAjuste' => 'required|string|max:255']);

        if (! $id) {
            return;
        }

        $proveedor = $this->cargar(fn () => $this->proveedor($id));

        if (! $proveedor) {
            return;
        }

        $this->ejecutar(
            fn () => $servicio->ajustarDeuda($proveedor, (float) $this->nuevoSaldo, $this->razonAjuste),
            'Deuda ajustada'
        );

        $this->resetAjuste();
    }

    private function resetAjuste(): void
    {
        $this->reset(['ajusteId', 'nuevoSaldo', 'razonAjuste', 'showAjuste']);
    }

    public function cerrarForm(): void
    {
        $this->reset([
            'editandoId', 'nombre', 'contacto', 'telefono', 'correo',
            'direccion', 'descripcionDeudaInicial', 'deudaInicial', 'showForm',
        ]);
    }
}
