<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Producto;
use App\Support\Money;

/**
 * Arma el catálogo virtual (PDF) a partir de la tabla legacy `productos`.
 *
 * Decide qué productos entran y con qué datos. El PDF (resources/views/pdfs/
 * catalogo.blade.php) solo pinta lo que devuelve este servicio: no consulta
 * la BD por su cuenta.
 *
 * OJO con los datos reales: los 106 productos tienen `precio` en 0.00 (el
 * legacy nunca lo cargó). El catálogo marca esos productos como "Sin precio"
 * en vez de mostrar $0, para que el PDF no parezca un catálogo sin valores.
 */
class CatalogoService
{
    /**
     * @param  bool  $soloDisponibles  true = solo stock > 0 (lo que hay para vender).
     * @param  bool  $soloConImagen     filtra los que ya tienen foto (útil para limpiar el catálogo).
     * @param  string $categoriaId      '' = todas.
     * @param  bool  $conImagenes       false = no trae el MEDIUMBLOB (para la vista
     *                                  previa, que solo enseña 24 tarjetas).
     * @return array{
     *     productos: \Illuminate\Support\Collection,
     *     categorias: array<string,int>,
     *     total: int,
     *     conImagen: int,
     *     sinPrecio: int,
     *     categoriasIncluidas: string|null,
     *     filtros: array<string,string|bool>
     * }
     */
    public function catalogo(
        bool $soloDisponibles,
        bool $soloConImagen,
        string $categoriaId,
        bool $conImagenes = true,
    ): array {
        $base = Producto::query()
            ->when($soloDisponibles, fn ($q) => $q->where('stock', '>', 0))
            ->when($categoriaId !== '', fn ($q) => $q->where('categoria_id', $categoriaId))
            ->when($soloConImagen, fn ($q) => $q->whereNotNull('imagen'));

        // Los tres recuentos van en SQL: si se hicieran en PHP habría que
        // hidratar los 106 productos (con su MEDIUMBLOB) solo para contar.
        $total = (clone $base)->count();
        $conImagen = (clone $base)->whereNotNull('imagen')->count();
        $sinPrecio = (clone $base)->where(fn ($q) => $q->whereNull('precio')->orWhere('precio', '<=', 0))->count();

        $columnas = ['id', 'nombre', 'categoria_id', 'precio', 'stock', 'proveedor', 'descripcion', 'detalles'];
        if ($conImagenes) {
            $columnas[] = 'imagen';
        }

        // Sin tope de productos a propósito: un catálogo que se salta productos
        // sin avisar es peor que uno lento. Si algún día pesa demasiado, el
        // filtro por categoría (o "solo con imagen") recorta el PDF.
        $productos = (clone $base)
            ->with('categoria')
            ->orderBy('nombre')
            // `productos.*` arrastraría el MEDIUMBLOB de los 106 productos a
            // memoria aunque no se pinten: se piden solo las columnas del PDF.
            ->get($columnas);

        $categorias = Categoria::orderBy('nombre')->pluck('nombre', 'id');
        $incluida = $categoriaId !== '' ? ($categorias[$categoriaId] ?? 'la categoría elegida') : null;

        return [
            'productos' => $productos,
            'categorias' => $categorias->toArray(),
            'total' => $total,
            'conImagen' => $conImagen,
            'sinPrecio' => $sinPrecio,
            'categoriasIncluidas' => $incluida,
            'filtros' => [
                'soloDisponibles' => $soloDisponibles,
                'soloConImagen' => $soloConImagen,
                'categoriaId' => $categoriaId,
            ],
        ];
    }

    /** Resumen para la pantalla del módulo (KPIs sobre TODO el catálogo, no el PDF). */
    public function resumen(): array
    {
        $total = Producto::count();
        $disponibles = Producto::where('stock', '>', 0)->count();
        $conImagen = Producto::whereNotNull('imagen')->count();
        $conPrecio = Producto::where('precio', '>', 0)->count();

        return [
            'total' => $total,
            'disponibles' => $disponibles,
            'agotados' => $total - $disponibles,
            'conImagen' => $conImagen,
            'sinImagen' => $total - $conImagen,
            'sinPrecio' => $total - $conPrecio,
        ];
    }

    /** Nombre del archivo: "catalogo-luxury-2026-09-28.pdf". */
    public function nombreArchivo(): string
    {
        return 'catalogo-luxury-' . now()->format('Y-m-d') . '.pdf';
    }

    /** Precio listo para el PDF: "$45.000" o "Sin precio" (los $0 son dato faltante). */
    public static function precioLegible(?float $precio): string
    {
        return $precio ? Money::format($precio) : 'Sin precio';
    }
}
