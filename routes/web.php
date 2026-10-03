<?php

use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductoImagenController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/*
|--------------------------------------------------------------------------
| Permisos (2026-10-03)
|--------------------------------------------------------------------------
|
| Cada módulo cuelga de `permiso:<uri>`, que resuelve contra el mapa rol ->
| permisos de App\Support\Roles. El nombre del permiso ES el uri del módulo,
| así que no hay una segunda lista que se pueda desincronizar del menú.
|
| `EnsurePermiso` está en la lista de middleware persistente de Livewire, de
| modo que cambiar el rol de alguien le quita el acceso en su siguiente acción,
| no solo al recargar la página.
|
| Ojo: el middleware protege la ruta, y el endpoint de Livewire re-aplica el
| mismo middleware persistente, así que no basta con ocultarle el enlace: el
| componente también comprueba en mount() (segunda barrera).
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'permiso:dashboard'])->name('dashboard');

Route::get('/caja', fn () => view('pages.caja'))
    ->middleware(['auth', 'permiso:caja'])->name('caja');

Route::get('/ventas', fn () => view('pages.ventas'))
    ->middleware(['auth', 'permiso:ventas'])->name('ventas');

Route::get('/clientes', fn () => view('pages.clientes'))
    ->middleware(['auth', 'permiso:clientes'])->name('clientes');

Route::get('/pedidos', fn () => view('pages.pedidos'))
    ->middleware(['auth', 'permiso:pedidos'])->name('pedidos');

/*
|--------------------------------------------------------------------------
| Modulo Inventario
|--------------------------------------------------------------------------
| Ingreso, Productos, Inventario, Stock y Categorias.
| El stock NUNCA se edita desde un formulario de producto: todas las entradas,
| salidas y ajustes pasan por App\Support\StockService, que escribe el
| movimiento en inventario_movimientos dentro de la misma transaccion.
*/
Route::middleware('auth')->group(function () {
    Route::get('/ingreso', fn () => view('pages.ingreso'))
        ->middleware('permiso:ingreso')->name('ingreso');
    Route::get('/productos', fn () => view('pages.productos'))
        ->middleware('permiso:productos')->name('productos');
    Route::get('/inventario', fn () => view('pages.inventario'))
        ->middleware('permiso:inventario')->name('inventario');
    Route::get('/stock', fn () => view('pages.stock'))
        ->middleware('permiso:stock')->name('stock');
    Route::get('/categorias', fn () => view('pages.categorias'))
        ->middleware('permiso:categorias')->name('categorias');
    Route::get('/catalogo', fn () => view('pages.catalogo'))
        ->middleware('permiso:catalogo')->name('catalogo');

    // Bytes de productos.imagen para los <img src> del listado. El id va en
    // la URL y el ETag en el contenido, asi el navegador cachea la foto.
    // Pide permiso de productos y no de catalogo porque el catalogo tambien
    // los pinta: si solo el cajero tiene catalogo, igual puede ver las fotos.
    Route::get('/productos/imagen/{id}', ProductoImagenController::class)
        ->middleware('permiso:productos')
        ->whereNumber('id')->name('productos.imagen');

    // Descarga del catálogo virtual en PDF. ?todos=1 incluye los agotados.
    Route::get('/catalogo/pdf', [CatalogoController::class, 'pdf'])
        ->middleware('permiso:catalogo')->name('catalogo.pdf');
});

/*
|--------------------------------------------------------------------------
| Modulo Finanzas
|--------------------------------------------------------------------------
| Proveedores, Movimientos, Facturacion, Devoluciones, Gastos y Abonos.
| La deuda de un proveedor NUNCA se edita desde un formulario: todo pasa por
| App\Support\DeudaProveedorService, que escribe saldo_deuda y su historial en
| la misma transaccion. Ojo: proveedores.deuda esta huerfana y no se usa.
*/
Route::middleware('auth')->group(function () {
    Route::get('/proveedores', fn () => view('pages.proveedores'))
        ->middleware('permiso:proveedores')->name('proveedores');
    Route::get('/movimientos', fn () => view('pages.movimientos'))
        ->middleware('permiso:movimientos')->name('movimientos');
    Route::get('/facturacion', fn () => view('pages.facturacion'))
        ->middleware('permiso:facturacion')->name('facturacion');
    Route::get('/devoluciones', fn () => view('pages.devoluciones'))
        ->middleware('permiso:devoluciones')->name('devoluciones');
    Route::get('/gastos', fn () => view('pages.gastos'))
        ->middleware('permiso:gastos')->name('gastos');
    Route::get('/abonos', fn () => view('pages.abonos'))
        ->middleware('permiso:abonos')->name('abonos');
});

/*
|--------------------------------------------------------------------------
| Modulo Admin
|--------------------------------------------------------------------------
| Perfil, Empleados, Reportes y Configuracion.
|
| Perfil lo ve cualquier usuario autenticado (es su propia cuenta) y por eso NO
| cuelga de `permiso:`; es el unico lugar abierto a todos.
|
| Config NO borra usuarios: no hay claves foraneas hacia `usuarios` y cajas,
| abonos_proveedores, devoluciones e historiales guardan `usuario_id`.
|
| Configuracion lleva DOBLE middleware a proposito: `permiso:configuracion`
| (que solo tiene el admin en App\Support\Roles) y `admin` (que vuelve a
| exigir rol admin sin mirar el mapa). Es el modulo mas sensible del panel, y
| son dos capas independientes: si alguien cambiara el mapa de permisos sin
| querer, Config sigue exigiendo control total.
|
| Reportes y Empleados ya no son admin-only: los ve tambien el supervisor.
*/
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'permiso:empleados'])->group(function () {
    Route::get('/empleados', fn () => view('pages.empleados'))->name('empleados');
});

Route::middleware(['auth', 'permiso:reportes'])->group(function () {
    Route::get('/reportes', fn () => view('pages.reportes'))->name('reportes');
});

Route::middleware(['auth', 'permiso:configuracion', 'admin'])->group(function () {
    Route::get('/configuracion', fn () => view('pages.configuracion'))->name('configuracion');

    // Carga masiva de precios: pide el mismo permiso que Configuracion porque
    // escribe en TODO el catálogo. No está en PanelMenu a propósito: es una
    // pantalla de trabajo puntual, no un módulo de uso diario. Se entra desde
    // Productos -> "Cargar precios".
    Route::get('/productos/precios', fn () => view('pages.productos-precios'))->name('productos.precios');
});

require __DIR__.'/auth.php';