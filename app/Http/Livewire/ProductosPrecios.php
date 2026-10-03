<?php

namespace App\Http\Livewire;

use App\Models\Categoria;
use App\Models\Producto;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * CARGA MASIVA DE PRECIOS (`/productos/precios`, admin-only).
 *
 * Por que existe: `productos.precio` esta en `0.00` en 105 de los 106 productos
 * (el legacy nunca lo cargo). Como TODO lo monetario de producto depende de esa
 * columna, hoy el valor de inventario, los margenes, los reportes por categoria y
 * el catalogo PDF dan $0 o "Sin precio".
 *
 * NO se puede recuperar del historial: `movimientos_caja.descripcion` guarda el
 * total del carrito, no el precio unitario (AF1 aparece a 120k, 130k, 140k, 150k
 * y 160k), y solo 17 de 541 ventas casan con `productos.nombre`. Hay que
 * digitarlos.
 *
 * Decisiones (2026-10-03):
 *  - Las dos vias: escribir a mano fila por fila, o pegar la columna desde Excel.
 *  - Lo que quede sin precio se deja en `0.00` y sigue mostrando "Sin precio" en
 *    el catalogo (`CatalogoService::precioLegible()`). NO se agrega columna ni
 *    tabla: `precio` ya existe.
 *  - Guardar es una sola transaccion: o se aplican todos los precios, o ninguno.
 *
 * Por que escribe directo y no por un servicio: `precio` es un atributo comercial
 * del producto, no un movimiento de inventario ni de caja. NO toca `productos.stock`
 * ni genera `inventario_movimientos`, asi que no aplica StockService. Es el mismo
 * criterio que `Stock::guardarMinimo()` con `stock_minimo`.
 */
class ProductosPrecios extends PanelComponent
{
    // ========== Filtros ==========
    public string $search = '';

    public string $filtroCategoria = '';

    /** Ver solo los que faltan por precio, o solo los que ya lo tienen. */
    public string $filtroEstado = ''; // sin_precio | con_precio

    // ========== Preciosbeing editados ==========
    /** precio_por_id => string (lo que escribe el usuario, todavía sin guardar) */
    public array $precios = [];

    /** Ids que cambiaron en esta sesion, para resaltar y habilitar "Guardar". */
    public array $tocados = [];

    // ========== Pegado desde Excel ==========
    public bool $showPegar = false;

    public string $textoPegado = '';

    /** Resultado del analisis del pegado, antes de aplicar: pregunta -> propuesta. */
    public array $propuestas = [];

    /** Resumen de lo que NO se pudo asociar, para no perderlo en silencio. */
    public array $sinAsociar = [];

    protected $queryString = [
        'search' => ['except' => ''],
        'filtroCategoria' => ['except' => ''],
        'filtroEstado' => ['except' => ''],
    ];

    public function render()
    {
        $termino = trim($this->search);

        $productos = Producto::query()
            ->with('categoria')
            ->when($termino !== '', fn ($q) => $q->where('nombre', 'like', "%{$termino}%"))
            ->when($this->filtroCategoria !== '', fn ($q) => $q->where('categoria_id', $this->filtroCategoria))
            ->when($this->filtroEstado === 'sin_precio', fn ($q) => $q->where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0)))
            ->when($this->filtroEstado === 'con_precio', fn ($q) => $q->where('precio', '>', 0))
            ->orderBy('nombre')
            ->get();

        // Los recuentos van en SQL, no contando en PHP: se hace en TODOS los
        // productos para que el filtro no altere los numeros del encabezado.
        $total = (int) Producto::count();
        $sinPrecio = (int) Producto::where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0))->count();

        return view('livewire.productos-precios', [
            'productos' => $productos,
            'categorias' => Categoria::orderBy('nombre')->pluck('nombre', 'id'),
            'stats' => [
                'total' => $total,
                'conPrecio' => $total - $sinPrecio,
                'sinPrecio' => $sinPrecio,
                'avance' => $total > 0 ? (int) round((($total - $sinPrecio) / $total) * 100) : 0,
                // Valor del inventario con los precios de ahora: mientras siga
                // en 0 sale $0, que es la truth y no un bug de la pantalla.
                'valorInventario' => (float) Producto::query()
                    ->selectRaw('COALESCE(SUM(precio * stock), 0) AS v')
                    ->value('v'),
            ],
        ]);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['search', 'filtroCategoria', 'filtroEstado'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Marca un precio como editado. No guarda nada: solo lo deja pendiente para
     * el "Guardar cambios" de abajo (una transaccion para toda la pantalla).
     */
    public function editarPrecio(int $id, string $valor): void
    {
        $normalizado = self::normalizarDecimal($valor);

        if ($normalizado !== '' && (! is_numeric($normalizado) || (float) $normalizado < 0)) {
            $this->addError("precios.{$id}", 'El precio no es un número válido.');

            return;
        }

        $this->resetErrorBag("precios.{$id}");

        $this->precios[$id] = $normalizado;

        $producto = Producto::find($id);

        // Si el usuario deja el campo igual a lo que ya estaba, no cuenta como
        // cambio: si no, "Guardar" se quedaria habilitado sin motivo.
        $actual = $producto ? $producto->precio : null;
        $nuevo = $normalizado === '' ? 0.0 : (float) $normalizado;

        if (abs((float) $actual - $nuevo) < 0.001) {
            unset($this->tocados[$id]);
        } else {
            $this->tocados[$id] = true;
        }
    }

    /**
     * Guarda TODOS los precios editados en una sola transaccion.
     *
     * Los productos que el usuario dejo vacios se escriben en 0.00: es el estado
     * acordado ("Sin precio" en el catalogo), no un error.
     */
    public function guardar(): void
    {
        if ($this->tocados === []) {
            $this->toastError('No hay precios nuevos que guardar.');

            return;
        }

        $this->validate([
            'precios' => ['array'],
            'precios.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [
            'precios.*.numeric' => 'El precio debe ser un numero (se permiten puntos y comas).',
            'precios.*.min' => 'El precio no puede ser negativo.',
            'precios.*.max' => 'Ese precio es demasiado alto; revisa que no hayas tipeado de mas.',
        ]);

        $guardados = 0;

        $ok = $this->ejecutar(function () use (&$guardados) {
            DB::transaction(function () use (&$guardados) {
                foreach (array_keys($this->tocados) as $id) {
                    $producto = Producto::find((int) $id);

                    // El producto pudo borrarse mientras se editaba.
                    if (! $producto) {
                        continue;
                    }

                    $crudo = $this->precios[$id] ?? '';
                    $normalizado = self::normalizarDecimal((string) $crudo);
                    $valor = $normalizado === '' ? 0.0 : (float) $normalizado;

                    $producto->forceFill(['precio' => $valor])->save();
                    $guardados++;
                }
            });
        }, '');

        // `ejecutar()` con mensaje vacio: el toast real se despacha aqui, con el
        // conteo, porque el mensaje depende de cuantos se applicaron.
        if ($ok) {
            $this->toastOk($guardados === 1
                ? 'Precio actualizado'
                : sprintf('%d precios actualizados', $guardados));
        }

        $this->tocados = [];
        $this->precios = [];
        $this->resetValidation();
    }

    // ====================================================================
    // Pegado desde Excel
    // ====================================================================

    public function abrirPegar(): void
    {
        $this->reset(['textoPegado', 'propuestas', 'sinAsociar', 'showPegar', 'showPegarErrores']);
        $this->showPegar = true;
    }

    public function cerrarPegar(): void
    {
        $this->reset(['textoPegado', 'propuestas', 'sinAsociar', 'showPegar', 'showPegarErrores']);
    }

    public bool $showPegarErrores = false;

    /**
     * Analiza el texto pegado y arma las propuestas, SIN guardar todavía.
     *
     * Acepta una línea por producto, con el nombre y el precio separados por
     * coma, tabulador o punto y coma. Ejemplos que funcionan:
     *   vomeromorado    150000
     *   vomeromorado    150.000
     *   "ACSIS ROSADO", 150000,50
     *
     * La idea es que al pegar desde Excel la columna caiga directo, y si el
     * nombre no esta, se reporte en `sinAsociar` en vez de perderse en silencio.
     */
    public function analizarPegado(): void
    {
        $this->reset(['propuestas', 'sinAsociar', 'showPegarErrores']);
        $this->validate([
            'textoPegado' => ['required', 'string', 'min:3'],
        ], [
            'textoPegado.required' => 'Pega aqui la lista de productos y precios.',
        ]);

        $lineas = preg_split('/\r\n|\r|\n/', trim($this->textoPegado)) ?: [];

        // Indice por nombre normalizado: mayusculas, sin tildes y sin espacios
        // extra. Asi "Vomero  Morado" y "vomeromorado" son el mismo producto.
        $porNombre = [];
        foreach (Producto::orderBy('id')->get() as $producto) {
            $porNombre[self::normalizarNombre($producto->nombre)][] = $producto;
        }

        $propuestas = [];
        $sinAsociar = [];
        $vistos = [];

        foreach ($lineas as $num => $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            // Ultimo campo numerico de la linea: permite que el precio sea el
            // ultimo campo aunque el nombre lleve comas ("ACSIS, ROSADO, 150000").
            if (! preg_match('/^(.*?)[\t;,]+[^\t;,]*?([\d.,\s]+)$/u', $linea, $m)) {
                $sinAsociar[] = ['linea' => $num + 1, 'texto' => $linea, 'motivo' => 'No se entiende la linea (debe ser "nombre, precio").'];

                continue;
            }

            $nombre = trim($m[1], " \t;, ");
            $precio = self::normalizarDecimal($m[2]);

            if ($nombre === '') {
                $sinAsociar[] = ['linea' => $num + 1, 'texto' => $linea, 'motivo' => 'La linea no trae nombre de producto.'];

                continue;
            }

            if ($precio === '' || ! is_numeric($precio)) {
                $sinAsociar[] = ['linea' => $num + 1, 'texto' => $linea, 'motivo' => 'No se pudo leer el precio.'];

                continue;
            }

            $clave = self::normalizarNombre($nombre);

            if (! isset($porNombre[$clave])) {
                $sinAsociar[] = ['linea' => $num + 1, 'texto' => $linea, 'motivo' => 'No existe un producto con ese nombre.'];

                continue;
            }

            // Nombres repetidos en el catálogo (p. ej. dos "vomero"): NO se adivina
            // a cuál de los dos va el precio. La línea va a `sinAsociar` con el
            // motivo, y el que hay en la lista sigue mostrando «Sin precio».
            if (count($porNombre[$clave]) > 1) {
                $sinAsociar[] = [
                    'linea' => $num + 1,
                    'texto' => $linea,
                    'motivo' => sprintf('Hay %d productos con el nombre "%s"; revisa el listado a mano.', count($porNombre[$clave]), $nombre),
                ];

                continue;
            }

            // Un producto puede repetirse en el texto pegado (una fila por
            // tienda, por ejemplo): gana el último valor leído.
            $id = $porNombre[$clave][0]->id;
            $propuestas[$id] = [
                'id' => $id,
                'nombre' => $porNombre[$clave][0]->nombre,
                'anterior' => Money::format($porNombre[$clave][0]->precio),
                'nuevo' => Money::format((float) $precio),
                'valor' => (float) $precio,
            ];
            $vistos[$id] = true;
        }

        if ($propuestas === [] && $sinAsociar === []) {
            $this->addError('textoPegado', 'No hay ninguna linea para procesar.');

            return;
        }

        $this->propuestas = $propuestas;
        $this->sinAsociar = $sinAsociar;
        $this->showPegarErrores = true;
    }

    /**
     * Aplica las propuestas aceptadas. Es un metodo y no la vista la que decide:
     * el pegado se lee desde PHP para no depender de JavaScript.
     */
    public function aplicarPegado(): void
    {
        if ($this->propuestas === []) {
            $this->toastError('No hay propuestas que aplicar.');

            return;
        }

        $guardados = 0;

        $ok = $this->ejecutar(function () use (&$guardados) {
            DB::transaction(function () use (&$guardados) {
                foreach ($this->propuestas as $id => $propuesta) {
                    $producto = Producto::find((int) $id);

                    if (! $producto) {
                        continue;
                    }

                    $producto->forceFill(['precio' => (float) $propuesta['valor']])->save();
                    $guardados++;
                }
            });
        }, '');

        if ($ok) {
            $this->toastOk($guardados === 1
                ? 'Precio actualizado'
                : sprintf('%d precios actualizados desde la lista', $guardados));

            $this->cerrarPegar();
        }
    }

    // ====================================================================
    // Utilidades
    // ====================================================================

    /**
     * Acepta "45.50", "45,50", "1.234,56" (formato CO) y "150 000".
     * Devuelve "" si no queda nada, o el numero en punto decimal.
     *
     * Misma logica que `Productos::normalizarDecimal()`, replicada aqui porque
     * es privada: los dos modulos editan precios y no quiero un acoplamiento
     * entre componentes Livewire.
     */
    private static function normalizarDecimal(string $valor): string
    {
        $v = trim($valor);

        // Espacios (incluye el espacio fino de Excel): "150 000" -> "150000".
        $v = str_replace(["\xC2\xA0", ' '], '', $v);

        if ($v === '') {
            return '';
        }

        // Formato con miles: "1.234,56" -> el punto es separador de miles.
        if (str_contains($v, ',') && str_contains($v, '.')) {
            return str_replace(['.', ','], ['', '.'], $v);
        }

        // "1,234" con coma de miles: mas de 3 digitos despues -> es separador.
        if (str_contains($v, ',') && ! str_contains($v, '.')) {
            $partes = explode(',', $v);

            if (count($partes) === 2 && strlen($partes[1]) === 3) {
                return str_replace(',', '', $v);
            }

            return str_replace(',', '.', $v);
        }

        // "1.234" con punto de miles: igual que arriba, pero solo si lo que
        // sigue al punto son exactamente 3 digitos y no mas.
        if (str_contains($v, '.')) {
            $partes = explode('.', $v);

            if (count($partes) === 2 && strlen($partes[1]) === 3) {
                return str_replace('.', '', $v);
            }
        }

        return $v;
    }

    /** Sin tildes, sin mayusculas y sin espacios sobrantes, para comparar nombres. */
    private static function normalizarNombre(string $nombre): string
    {
        $n = trim($nombre);
        $n = strtr(mb_strtolower($n), 'áàäâãéèëêíìïîóòöôõúùüûñç', 'aaaaaeeeeiiiiooooouuuunc');
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;

        // Sin espacios ni signos: "vomero morado" y "vomero-morado" colisionan.
        return preg_replace('/[^a-z0-9]/', '', $n) ?? $n;
    }
}
