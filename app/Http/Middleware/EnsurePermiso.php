<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;

/**
 * Exige un permiso de MÓDULO para poder entrar a una ruta.
 *
 * Uso: `Route::get('/caja', ...)->middleware('permiso:caja')`. El nombre del
 * permiso es el mismo `uri` del módulo (ver App\Support\Roles), así que no hay
 * una lista de permisos aparte que se pueda desincronizar del menú.
 *
 * Diferencia con EnsureEsAdmin: aquel es binario y solo se usa donde de verdad
 * hace falta administration de cuentas. Este es el que reparte el resto.
 *
 * Devuelve 403, no 404: es más honesto en un panel interno donde el usuario ya
 * sabe qué rutas existen, y no filtra URLs de datos que no necesita conocer.
 */
class EnsurePermiso
{
    public function handle(Request $request, Closure $next, string $modulo)
    {
        $usuario = $request->user();

        // Sesión válida: la de verdad la exige el middleware `auth`, y
        // `PanelComponent::hydrate()` en cada request de Livewire. Si no hay
        // usuario es que la ruta se montó sin `auth`, que sería un error.
        abort_unless($usuario, 403, 'Sesión requerida.');

        abort_unless(
            Roles::usuarioPuede($usuario, $modulo),
            403,
            'No tienes permiso para ver esta sección.'
        );

        return $next($request);
    }
}