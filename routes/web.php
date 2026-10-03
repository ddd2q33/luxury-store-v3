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

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth'])->name('dashboard');

Route::get('/caja', fn () => view('pages.caja'))
    ->middleware(['auth'])->name('caja');

Route::get('/ventas', fn () => view('pages.ventas'))
    ->middleware(['auth'])->name('ventas');

Route::get('/clientes', fn () => view('pages.clientes'))
    ->middleware(['auth'])->name('clientes');

Route::get('/pedidos', fn () => view('pages.pedidos'))
    ->middleware(['auth'])->name('pedidos');

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
    Route::get('/ingreso', fn () => view('pages.ingreso'))->name('ingreso');
    Route::get('/productos', fn () => view('pages.productos'))->name('productos');
    Route::get('/inventario', fn () => view('pages.inventario'))->name('inventario');
    Route::get('/stock', fn () => view('pages.stock'))->name('stock');
    Route::get('/categorias', fn () => view('pages.categorias'))->name('categorias');
    Route::get('/catalogo', fn () => view('pages.catalogo'))->name('catalogo');
    // Bytes de productos.imagen para los <img src> del listado. El id va en
    // la URL y el ETag en el contenido, asi el navegador cachea la foto.
    Route::get('/productos/imagen/{id}', ProductoImagenController::class)
        ->whereNumber('id')->name('productos.imagen');
    // Descarga del catálogo virtual en PDF. ?todos=1 incluye los agotados.
    Route::get('/catalogo/pdf', [CatalogoController::class, 'pdf'])->name('catalogo.pdf');
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
    Route::get('/proveedores', fn () => view('pages.proveedores'))->name('proveedores');
    Route::get('/movimientos', fn () => view('pages.movimientos'))->name('movimientos');
    Route::get('/facturacion', fn () => view('pages.facturacion'))->name('facturacion');
    Route::get('/devoluciones', fn () => view('pages.devoluciones'))->name('devoluciones');
    Route::get('/gastos', fn () => view('pages.gastos'))->name('gastos');
    Route::get('/abonos', fn () => view('pages.abonos'))->name('abonos');
});

/*
|--------------------------------------------------------------------------
| Modulo Admin
|--------------------------------------------------------------------------
| Perfil, Empleados y Configuracion.
|
| Perfil lo ve cualquier usuario autenticado (es su propia cuenta). Empleados y
| Config son admin-only con el middleware `admin` (App\Http\Middleware\EnsureEsAdmin).
| Ojo: el middleware protege la RUTA, no el endpoint de Livewire, asi que los
| componentes Empleados y Configuracion vuelven a comprobar el rol en mount().
|
| Config NO borra usuarios: no hay claves foraneas hacia `usuarios` y cajas,
| abonos_proveedores, devoluciones e historiales guardan `usuario_id`.
|
| Reportes tambien es admin-only: es el modulo mas amplio del legacy (8
| secciones + 4 graficas) y por aqui se ven las cifras de todo el negocio.
| Es de SOLO LECTURA, asi que no necesita servicios de dominio.
*/
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/empleados', fn () => view('pages.empleados'))->name('empleados');
    Route::get('/configuracion', fn () => view('pages.configuracion'))->name('configuracion');
    Route::get('/reportes', fn () => view('pages.reportes'))->name('reportes');

    // Carga masiva de precios: admin-only porque escribe en TODO el catálogo.
    // No está en PanelMenu a propósito: es una pantalla de trabajo pontual, no
    // un módulo de uso diario. Se entra desde Productos -> "Cargar precios".
    Route::get('/productos/precios', fn () => view('pages.productos-precios'))->name('productos.precios');
});

require __DIR__.'/auth.php';
