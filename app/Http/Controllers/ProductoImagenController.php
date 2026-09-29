<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Response;

/**
 * Sirve la imagen de un producto (MEDIUMBLOB) como respuesta HTTP.
 *
 * Existe para que el listado use <img src> en vez de incrustar la foto en
 * base64 dentro del HTML: las fotos pesan hasta 2 MB y con base64 la página
 * se volvería de varios MB. El blob se manda con su Content-Type real y un
 * ETag para que el navegador lo cachee (el id va en la URL, no el contenido).
 */
class ProductoImagenController extends Controller
{
    public function __invoke(int $id): Response
    {
        $producto = Producto::find($id);

        if (! $producto || ! $producto->tieneImagen()) {
            abort(404);
        }

        $bytes = $producto->imagen;

        return response($bytes, 200, [
            'Content-Type' => $producto->imagenMime() ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"' . md5($bytes) . '"',
        ]);
    }
}
