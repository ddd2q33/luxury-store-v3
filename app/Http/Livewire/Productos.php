<?php

namespace App\Http\Livewire;

use App\Models\Categoria;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Support\StockService;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;

class Productos extends PanelComponent
{
    use WithFileUploads;

    public string $search = '';

    public string $filtroCategoria = '';

    public string $filtroEstado = ''; // agotado | bajo | negativo | sin_precio

    public string $orden = 'nombre'; // nombre | stock | precio

    // ========== Formulario ==========
    public bool $showForm = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $categoria_id = '';

    public string $precio = '';

    public string $stock = '0';

    public string $stock_minimo = '0';

    public string $proveedor = '';

    public string $descripcion = '';

    // ========== Imagen (opcional) ==========
    // Se sube un archivo, se convierte a bytes y se guarda en productos.imagen
    // (MEDIUMBLOB). No es obligatoria: sin imagen el catálogo pone un marcador.
    public $imagenArchivo;

    public bool $quitarImagen = false;

    // ========== Detalle / eliminar ==========
    public bool $showDetalle = false;

    public ?array $productoDetalle = null;

    public ?int $porEliminar = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filtroCategoria' => ['except' => ''],
        'filtroEstado' => ['except' => ''],
        'orden' => ['except' => 'nombre'],
    ];

    protected function rules(): array
    {
        return [
            'nombre' => 'required|string|max:150',
            'categoria_id' => 'required|integer|exists:categorias,id',
            'precio' => 'nullable|numeric|min:0|max:999999999',
            'stock' => 'required|integer|min:0',
            'stock_minimo' => 'required|integer|min:0',
            'proveedor' => 'nullable|string|max:150',
            'descripcion' => 'nullable|string|max:2000',
            'imagenArchivo' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:2048',
        ];
    }

    protected $messages = [
        'nombre.required' => 'El nombre del producto es obligatorio.',
        'categoria_id.required' => 'Selecciona una categoría: sin ella no se puede controlar el stock.',
        'categoria_id.exists' => 'La categoría seleccionada no existe.',
        'precio.numeric' => 'El precio debe ser un número.',
        'precio.min' => 'El precio no puede ser negativo.',
            'stock.integer' => 'El stock debe ser un número entero.',
            'stock.min' => 'El stock no puede ser negativo.',
            'stock_minimo.integer' => 'El stock mínimo debe ser un número entero.',
            'imagenArchivo.image' => 'La imagen debe ser un archivo de imagen.',
            'imagenArchivo.mimes' => 'Solo se permiten imágenes JPG, PNG, WEBP o GIF.',
            'imagenArchivo.max' => 'La imagen no puede pesar más de 2 MB.',
        ];

    public function updating(string $campo): void
    {
        if (in_array($campo, ['search', 'filtroCategoria', 'filtroEstado', 'orden'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $termino = trim($this->search);

        $productos = Producto::query()
            ->with('categoria')
            ->when($termino !== '', fn ($q) => $q->where(function ($q) use ($termino) {
                $q->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('proveedor', 'like', "%{$termino}%")
                    ->orWhere('descripcion', 'like', "%{$termino}%")
                    ->orWhere('detalles', 'like', "%{$termino}%");
            }))
            ->when($this->filtroCategoria !== '', fn ($q) => $q->where('categoria_id', $this->filtroCategoria))
            ->when($this->filtroEstado === 'agotado', fn ($q) => $q->where('stock', '<=', 0)->where('stock', '>=', 0))
            ->when($this->filtroEstado === 'negativo', fn ($q) => $q->where('stock', '<', 0))
            ->when($this->filtroEstado === 'bajo', fn ($q) => $q->where('stock_minimo', '>', 0)->whereColumn('stock', '<=', 'stock_minimo')->where('stock', '>', 0))
            ->when($this->filtroEstado === 'sin_precio', fn ($q) => $q->where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0)))
            ->orderBy(match ($this->orden) {
                'stock'  => 'stock',
                'precio' => 'precio',
                default  => 'nombre',
            })
            // No se trae el MEDIUMBLOB `imagen` al listado: son hasta 2 MB por
            // fila y la vista solo necesita saber SI tiene imagen (el icono),
            // no los bytes. La foto se carga aparte, al editar o en el PDF.
            ->addSelect([
                'id', 'nombre', 'categoria_id', 'precio', 'stock',
                'stock_minimo', 'proveedor', 'descripcion', 'fecha_creacion',
                DB::raw('(imagen IS NOT NULL) AS tiene_imagen'),
            ])
            ->paginate(10);

        return view('livewire.productos', [
            'productos' => $productos,
            'categorias' => Categoria::orderBy('nombre')->pluck('nombre', 'id'),
            'stats' => $this->stats(),
        ]);
    }

    private function stats(): array
    {
        return [
            'total' => Producto::count(),
            'unidades' => (int) Producto::sum('stock'),
            'valor' => (float) Producto::sum(\DB::raw('stock * COALESCE(precio, 0)')),
            'sinPrecio' => Producto::where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0))->count(),
            'agotados' => Producto::where('stock', '<=', 0)->where('stock', '>=', 0)->count(),
            'negativos' => Producto::where('stock', '<', 0)->count(),
            'bajos' => Producto::where('stock_minimo', '>', 0)
                ->whereColumn('stock', '<=', 'stock_minimo')
                ->where('stock', '>', 0)->count(),
        ];
    }

    // ====================================================================
    // CRUD
    // ====================================================================

    public function nuevo(): void
    {
        // OJO: el reset() de Livewire 2 es variadico y NO admite valores por
        // defecto (eso es Livewire 3): con ['stock' => '0'] recorre los
        // VALORES e intenta leer la propiedad "0", y revienta con
        // PropertyNotFoundException. Solo se le pasan nombres; los valores por
        // defecto ya son los de las declaraciones de arriba ('0').
        $this->reset('editandoId', 'nombre', 'precio', 'proveedor', 'descripcion', 'stock', 'stock_minimo');
        $this->resetImagen();
        $this->categoria_id = (string) ($this->filtroCategoria ?: '');
        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Busca el producto o lanza DomainException. NO se usa findOrFail(): su
     * ModelNotFoundException es un RuntimeException y escapa a pantalla en
     * blanco. Solo se carga la relacion o el conteo que el caller necesita.
     */
    private function producto(int $id, string $con = ''): Producto
    {
        $query = Producto::query();

        if ($con === 'categoria') {
            $query->with('categoria');
        } elseif ($con === 'movimientos') {
            $query->withCount('movimientos');
        }

        $producto = $query->find($id);

        if (! $producto) {
            throw new \DomainException('Ese producto ya no existe.');
        }

        return $producto;
    }

    public function editar(int $id): void
    {
        $this->cargar(function () use ($id) {
            $producto = $this->producto($id);
            // El detalle se cierra AQUÍ y no desde la vista. El botón de la
            // ficha decía `wire:click="editar(1); cerrarDetalle()"` y la segunda
            // sentencia NUNCA corría: el parser de Livewire 2 usa el regex
            // `/(.*?)\((.*)\)/s` (js/util/wire-directives.js), cuyo segundo grupo
            // es greedy, así que se comía `1); cerrarDetalle(` como parámetros y
            // el `cerrarDetalle()` quedaba como código muerto tras el `return`.
            // Resultado: los dos modales abiertos a la vez. Cerrar aquí hace que
            // funcione desde CUALQUIER botón que abra el formulario.
            $this->cerrarDetalle();
            $this->editandoId = $producto->id;
            $this->nombre = (string) $producto->nombre;
            $this->categoria_id = (string) ($producto->categoria_id ?? '');
            // Input type="number": solo admite punto decimal.
            $this->precio = $producto->precio === null
                ? ''
                : rtrim(rtrim(number_format((float) $producto->precio, 2, '.', ''), '0'), '.');
            $this->stock = (string) $producto->stock;
            $this->stock_minimo = (string) $producto->stock_minimo;
            $this->proveedor = (string) $producto->proveedor;
            $this->descripcion = (string) ($producto->descripcion ?: $producto->detalles);
            $this->resetImagen();
            $this->resetValidation();
            $this->showForm = true;
        });
    }

    public function guardar(): void
    {
        // Normaliza antes de validar: el usuario puede escribir "45,50" o pegar
        // el formato colombiano "1.234,56", y `numeric` los rechazaria.
        $this->precio = self::normalizarDecimal($this->precio);

        $this->validate();

        $precio = trim($this->precio) === '' ? null : (float) $this->precio;
        $stockInicial = (int) $this->stock;
        $minimo = (int) $this->stock_minimo;

        $datos = [
            'nombre' => trim($this->nombre),
            'categoria_id' => (int) $this->categoria_id,
            'precio' => $precio,
            'stock_minimo' => $minimo,
            'proveedor' => trim($this->proveedor) ?: null,
            'descripcion' => trim($this->descripcion) ?: null,
        ];

        // La imagen es OPCIONAL y solo se escribe si el usuario la tocó: así
        // editar el nombre o el precio no borra la foto ya guardada. Si no hay
        // columna en $datos, el UPDATE no la menciona y conserva la anterior.
        if ($this->imagenArchivo) {
            $datos['imagen'] = file_get_contents($this->imagenArchivo->getRealPath());
        } elseif ($this->quitarImagen) {
            $datos['imagen'] = null;
        }

        if ($this->editandoId) {
            // El stock NUNCA se edita aquí: solo se mueve desde Ingreso/Stock
            // para que cada cambio quede registrado en inventario_movimientos.
            Producto::where('id', $this->editandoId)->update($datos);
            $this->toastOk("Producto \"{$datos['nombre']}\" actualizado");

            $this->cerrarForm();

            return;
        }

        $datos['stock'] = 0;
        $producto = Producto::create($datos);

        if ($stockInicial > 0) {
            StockService::entrada(
                $producto->id,
                $stockInicial,
                'Stock inicial al crear el producto'
            );
        }

        $this->toastOk("Producto \"{$datos['nombre']}\" creado");
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
                $producto = $this->producto($id, 'movimientos');

                if ($producto->movimientos_count > 0) {
                    throw new \DomainException(sprintf(
                        '"%s" tiene %d movimiento(s) de inventario. Desactívalo en lugar de borrarlo para no perder la trazabilidad.',
                        $producto->nombre,
                        $producto->movimientos_count
                    ));
                }

                $nombre = $producto->nombre;
                $producto->delete();
                $this->toastOk("Producto \"{$nombre}\" eliminado");
            });

            if (! $ok) {
                $this->porEliminar = null;

                return;
            }
        }

        $this->porEliminar = null;
    }

    // ====================================================================
    // Detalle con historial de movimientos
    // ====================================================================

    public function verDetalle(int $id): void
    {
        $this->cargar(function () use ($id) {
            $producto = $this->producto($id, 'categoria');

            $movimientos = InventarioMovimiento::where('producto_id', $producto->id)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'tipo', 'cantidad', 'observaciones', 'fecha']);

            $this->productoDetalle = [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'categoria' => $producto->categoria?->nombre ?? 'Sin categoría',
                'precio' => (float) ($producto->precio ?? 0),
                'stock' => (int) $producto->stock,
                'stock_minimo' => (int) $producto->stock_minimo,
                'estado' => $producto->estadoStock(),
                'proveedor' => (string) ($producto->proveedor ?: ''),
                'descripcion' => (string) ($producto->descripcion ?: ''),
                'totalMovimientos' => InventarioMovimiento::where('producto_id', $producto->id)->count(),
                'movimientos' => $movimientos->map(fn ($m) => [
                    'id' => $m->id,
                    'tipo' => (string) $m->tipo,
                    'cantidad' => (int) $m->cantidad,
                    'observaciones' => (string) ($m->observaciones ?: ''),
                    'fecha' => $m->fecha?->format('d/m/Y'),
                ])->all(),
            ];

            $this->showDetalle = true;
        });
    }

    public function cerrarDetalle(): void
    {
        $this->reset(['showDetalle', 'productoDetalle']);
    }

    /**
     * Acepta "45.50", "45,50" y "1.234,56" (formato CO) y devuelve siempre
     * un número con punto decimal, que es lo que espera el input y `numeric`.
     */
    private static function normalizarDecimal(string $valor): string
    {
        $v = trim($valor);

        if ($v === '') {
            return '';
        }

        // Formato con miles: "1.234,56" -> el punto es separador de miles.
        if (str_contains($v, ',') && str_contains($v, '.')) {
            return str_replace(['.', ','], ['', '.'], $v);
        }

        return str_replace(',', '.', $v);
    }

    public function cerrarForm(): void
    {
        $this->reset([
            'editandoId', 'nombre', 'categoria_id', 'precio',
            'stock', 'stock_minimo', 'proveedor', 'descripcion', 'showForm',
        ]);
        $this->resetImagen();
    }

    /** Limpia el archivo nuevo y el flag de quitar (al abrir/cerrar el form). */
    private function resetImagen(): void
    {
        $this->imagenArchivo = null;
        $this->quitarImagen = false;
    }

    /**
     * Botón "Quitar imagen" del formulario. Antes la vista hacía
     * `wire:click="$set('quitarImagen', true); $set('imagenArchivo', null)"`, pero
     * por el mismo parser de Livewire 2 (ver `editar()`) solo se aplicaba el
     * PRIMER `$set`: `imagenArchivo` quedaba con el archivo recién elegido y el
     * preview seguía mostrándolo. Ahora es un método y se aplican los dos.
     */
    public function quitarImagenElegida(): void
    {
        $this->quitarImagen = true;
        $this->imagenArchivo = null;
    }

    /**
     * Data URI para la vista previa del formulario: si el usuario acaba de
     * elegir un archivo se muestra ese; si no, la imagen ya guardada del
     * producto en edición. Devuelve null si no hay nada que mostrar.
     */
    public function previewImagen(): ?string
    {
        if ($this->imagenArchivo) {
            // El preview se dibuja en cuanto se elige el archivo, ANTES de que
            // validate() lo rechace. Si el usuario elige un PDF, temporaryUrl()
            // de Livewire lanza "This driver does not support creating
            // temporary URLs" (solo tiene ruta firmada para mimes previsualizables)
            // y se va la pantalla en blanco. Aquí solo se previsualiza lo que
            // Livewire sabe mostrar; el rechazo con el mensaje de la regla
            // "image|mimes:..." sigue llegando al pulsar Guardar.
            try {
                return $this->imagenArchivo->isPreviewable()
                    ? $this->imagenArchivo->temporaryUrl()
                    : null;
            } catch (\Throwable) {
                return null;
            }
        }

        if ($this->editandoId && ! $this->quitarImagen) {
            return Producto::find($this->editandoId)?->imagenDataUri();
        }

        return null;
    }
}
