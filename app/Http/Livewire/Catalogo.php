<?php

namespace App\Http\Livewire;

use App\Models\Producto;
use App\Services\CatalogoService;

/**
 * Módulo "Catálogo": arma el catálogo virtual en PDF de los productos.
 *
 * NO genera el PDF aquí: solo elige los filtros y lleva la cuenta de lo que
 * se va a exportar. La descarga la produce CatalogoController::pdf() en la
 * ruta /catalogo/pdf, para que el archivo llegue por HTTP (y no metido en el
 * JSON de Livewire, que no transporta binarios de un PDF de varios MB).
 */
class Catalogo extends PanelComponent
{
    public bool $soloDisponibles = true;

    public bool $soloConImagen = false;

    public string $categoriaId = '';

    protected $queryString = [
        'soloDisponibles' => ['except' => true],
        'soloConImagen' => ['except' => false],
        'categoriaId' => ['except' => ''],
    ];

    public function render(CatalogoService $servicio)
    {
        // La previa no pide los blobs: con 106 productos son varios MB de
        // base64 que no se ven. Se piden solo los de las 24 tarjetas que
        // realmente se pintan (el PDF sí los carga todos, en CatalogoService).
        $datos = $servicio->catalogo(
            soloDisponibles: $this->soloDisponibles,
            soloConImagen: $this->soloConImagen,
            categoriaId: $this->categoriaId,
            conImagenes: false,
        );

        $primeros = $datos['productos']->take(24)->values();

        $blobs = $primeros->isEmpty()
            ? collect()
            : Producto::whereIn('id', $primeros->pluck('id'))->get(['id', 'imagen'])->keyBy('id');

        $productos = $primeros->map(fn (Producto $p) => [
            'id' => $p->id,
            'nombre' => (string) $p->nombre,
            'categoria' => (string) ($p->categoria?->nombre ?? 'Sin categoría'),
            'precio' => (float) ($p->precio ?? 0),
            'stock' => (int) $p->stock,
            'imagen' => $blobs[$p->id]->imagenDataUri(),
        ]);

        return view('livewire.catalogo', [
            'resumen' => $servicio->resumen(),
            'categorias' => $datos['categorias'],
            'productos' => $productos,
            'total' => $datos['total'],
            'conImagen' => $datos['conImagen'],
            'sinPrecio' => $datos['sinPrecio'],
            'nombreArchivo' => $servicio->nombreArchivo(),
            // Enlace del PDF con los filtros actuales, para el botón de descarga.
            'urlPdf' => route('catalogo.pdf', [
                'todos' => $this->soloDisponibles ? null : 1,
                'imagen' => $this->soloConImagen ? 1 : null,
                'categoria' => $this->categoriaId !== '' ? $this->categoriaId : null,
            ]),
        ]);
    }

    /** Vuelve a los filtros de fábrica: lo disponible, sin filtro de imagen. */
    public function reiniciar(): void
    {
        $this->soloDisponibles = true;
        $this->soloConImagen = false;
        $this->categoriaId = '';
    }
}
