<?php

namespace App\Http\Livewire;

use App\Models\Categoria;

class Categorias extends PanelComponent
{
    public string $search = '';

    public string $filtro = 'todas'; // todas | con_productos | vacias

    // ========== Formulario ==========
    public bool $showForm = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $descripcion = '';

    // ========== Eliminar ==========
    public ?int $porEliminar = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filtro' => ['except' => 'todas'],
    ];

    protected function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('categorias', 'nombre')
                    ->ignore($this->editandoId)
                    ->where(fn ($q) => $q->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($this->nombre))])),
            ],
            'descripcion' => 'nullable|string|max:1000',
        ];
    }

    protected $messages = [
        'nombre.required' => 'El nombre de la categoría es obligatorio.',
        'nombre.unique' => 'Ya existe una categoría con ese nombre.',
        'nombre.max' => 'Máximo 100 caracteres.',
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

        $categorias = Categoria::query()
            ->withCount('productos')
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('descripcion', 'like', "%{$termino}%");
            }))
            ->when($this->filtro === 'con_productos', fn ($q) => $q->has('productos'))
            ->when($this->filtro === 'vacias', fn ($q) => $q->doesntHave('productos'))
            ->orderBy('nombre')
            ->paginate(10);

        return view('livewire.categorias', [
            'categorias' => $categorias,
            'stats' => [
                'total' => Categoria::count(),
                'conProductos' => Categoria::has('productos')->count(),
                'vacias' => Categoria::doesntHave('productos')->count(),
                'productos' => \App\Models\Producto::count(),
            ],
        ]);
    }

    // ====================================================================
    // CRUD
    // ====================================================================

    public function nuevo(): void
    {
        $this->reset(['editandoId', 'nombre', 'descripcion']);
        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Busca la categoría o lanza DomainException. NO se usa findOrFail(): su
     * ModelNotFoundException es un RuntimeException y escapa a pantalla en
     * blanco. El conteo de productos solo se carga si el caller lo pide.
     */
    private function categoria(int $id, bool $conProductos = false): Categoria
    {
        $categoria = $conProductos
            ? Categoria::withCount('productos')->find($id)
            : Categoria::find($id);

        if (! $categoria) {
            throw new \DomainException('Esa categoría ya no existe.');
        }

        return $categoria;
    }

    public function editar(int $id): void
    {
        $this->cargar(function () use ($id) {
            $categoria = $this->categoria($id);
            $this->editandoId = $categoria->id;
            $this->nombre = (string) $categoria->nombre;
            $this->descripcion = (string) $categoria->descripcion;
            $this->resetValidation();
            $this->showForm = true;
        });
    }

    public function guardar(): void
    {
        $this->validate();

        $datos = [
            'nombre' => trim($this->nombre),
            'descripcion' => trim($this->descripcion) ?: null,
        ];

        if ($this->editandoId) {
            Categoria::where('id', $this->editandoId)->update($datos);
            $this->toastOk("Categoría \"{$datos['nombre']}\" actualizada");
        } else {
            Categoria::create($datos);
            $this->toastOk("Categoría \"{$datos['nombre']}\" creada");
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
            $ok = $this->cargar(function () use ($id) {
                $categoria = $this->categoria($id, conProductos: true);

                // Los productos no se borran en cascada (no hay FK en el legacy):
                // se bloquea el borrado para no dejar productos huerfanos.
                if ($categoria->productos_count > 0) {
                    throw new \DomainException(sprintf(
                        '"%s" tiene %d producto(s). Muévelos a otra categoría antes de eliminarla.',
                        $categoria->nombre,
                        $categoria->productos_count
                    ));
                }

                $nombre = $categoria->nombre;
                $categoria->delete();
                $this->toastOk("Categoría \"{$nombre}\" eliminada");
            });

            if (! $ok) {
                $this->porEliminar = null;

                return;
            }
        }

        $this->porEliminar = null;
    }

    public function cerrarForm(): void
    {
        $this->reset(['editandoId', 'nombre', 'descripcion', 'showForm']);
    }
}
