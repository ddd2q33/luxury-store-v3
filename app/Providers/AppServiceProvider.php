<?php

namespace App\Providers;

use App\Http\Middleware\EnsureEsAdmin;
use App\Http\Middleware\EnsurePermiso;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /**
         * El endpoint de Livewire (`/livewire/message/{componente}`) solo está
         * protegido por el grupo `web`. Por eso re-aplica, en cada acción, el
         * middleware de la ruta ORIGINAL filtrado por una lista de "persistentes".
         * La lista por defecto de Livewire 2 trae `Authenticate` pero NO trae
         * `EnsureEsAdmin`: es decir, la sesión se revalidaba en cada request
         * (por eso `PanelComponent::hydrate()` puede exigir usuario activo) pero
         * el ROL no se volvía a comprobar nunca.
         *
         * El `mount()` de cada componente admin-only cubre solo la carga inicial:
         * un admin degradado a cajero conservaba el rol hasta que se acabara la
         * sesión (SESSION_LIFETIME=120, con almacenamiento en archivo, o sea que
         * sobrevive al reinicio del navegador).
         *
         * Ojo: `gatherRouteMiddleware()` filtra por el middleware que declara la
* ruta original, así que `EnsureEsAdmin` solo se re-ejecuta en las rutas
         * que lo declaran (empleados, configuración, reportes). `/ventas` y demás
         * NO lo declaran y siguen sin exigir admin, que es lo que queremos.
         *
         * `EnsurePermiso` va en la misma lista por el mismo motivo: es el que
         * reparte los módulos entre los roles (cajero, supervisor, operador...).
         * Sin él, cambiar el rol de un usuario no le quitaría el acceso hasta
         * que se acabara la sesión, porque `mount()` solo corre al cargar.
         */
        Livewire::addPersistentMiddleware([
            EnsureEsAdmin::class,
            EnsurePermiso::class,
        ]);
    }
}