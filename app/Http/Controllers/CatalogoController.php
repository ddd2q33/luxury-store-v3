<?php

namespace App\Http\Controllers;

use App\Services\CatalogoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Genera el catálogo virtual en PDF de los productos.
 *
 * Es una ruta aparte (no una acción de Livewire) porque un PDF de varios MB
 * no debe viajar dentro del JSON de Livewire: así el navegador lo recibe como
 * descarga normal y el PDF se construye en el servidor.
 *
 * Filtros (query string):
 *   - todos=1      incluye los agotados (sin esto, solo stock > 0)
 *   - imagen=1     solo productos que ya tienen foto
 *   - categoria=ID una categoría concreta
 */
class CatalogoController extends Controller
{
    public function pdf(Request $request, CatalogoService $servicio): Response
    {
        $datos = $servicio->catalogo(
            soloDisponibles: ! $request->boolean('todos'),
            soloConImagen: $request->boolean('imagen'),
            categoriaId: (string) $request->query('categoria', ''),
        );

        $pdf = Pdf::loadView('pdfs.catalogo', [
            'datos' => $datos,
            'generado' => now(),
        ])
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isHtml5ParserEnabled', true);

        return $pdf->download($servicio->nombreArchivo());
    }
}
