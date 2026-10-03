<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Menú del panel. Los módulos se irán agregando conforme avance
 * la migración desde oldluxury. El estado activo se calcula por URI,
 * no por nombre de ruta, para que los módulos pendientes no rompan nada.
 *
 * Cada item lleva DOS etiquetas:
 * - `label`: la larga, legible en el drawer y para lectores de pantalla.
 * - `corto`: la del sidebar de escritorio, que va en una rejilla de 4
 *   columnas como en `oldluxury/views/partials/app_nav.php`, donde caben
 *   "Prov", "Mov" y "Dev" y NO "Proveedores" ni "Movimientos".
 */
class PanelMenu
{
    /**
     * @return array<int, array{titulo:string, icon:string, items:array<int, array{uri:string, label:string, corto:string, icon:string, permiso:string}>}>
     */
    public static function grupos(): array
    {
        return [
            [
                'titulo' => 'Principal',
                'icon' => 'home',
                'items' => [
                    ['uri' => 'dashboard', 'label' => 'Home', 'corto' => 'Home', 'icon' => 'chart-line', 'permiso' => 'dashboard'],
                    ['uri' => 'caja', 'label' => 'Caja', 'corto' => 'Caja', 'icon' => 'banknotes', 'permiso' => 'caja'],
                    ['uri' => 'ventas', 'label' => 'Ventas', 'corto' => 'Ventas', 'icon' => 'shopping-cart', 'permiso' => 'ventas'],
                ],
            ],
            [
                'titulo' => 'Clientes',
                'icon' => 'users',
                'items' => [
                    ['uri' => 'clientes', 'label' => 'Clientes', 'corto' => 'Clientes', 'icon' => 'users', 'permiso' => 'clientes'],
                    ['uri' => 'pedidos', 'label' => 'Pedidos', 'corto' => 'Pedidos', 'icon' => 'clipboard-list', 'permiso' => 'pedidos'],
                ],
            ],
            [
                'titulo' => 'Inventario',
                'icon' => 'cube',
                'items' => [
                    ['uri' => 'ingreso', 'label' => 'Ingreso', 'corto' => 'Ingreso', 'icon' => 'truck', 'permiso' => 'ingreso'],
                    ['uri' => 'productos', 'label' => 'Productos', 'corto' => 'Productos', 'icon' => 'cube', 'permiso' => 'productos'],
                    ['uri' => 'inventario', 'label' => 'Inventario', 'corto' => 'Inventario', 'icon' => 'building-office', 'permiso' => 'inventario'],
                    ['uri' => 'stock', 'label' => 'Stock', 'corto' => 'Stock', 'icon' => 'bars-3', 'permiso' => 'stock'],
                    ['uri' => 'categorias', 'label' => 'Categorías', 'corto' => 'Categorías', 'icon' => 'tag', 'permiso' => 'categorias'],
                    ['uri' => 'catalogo', 'label' => 'Catálogo', 'corto' => 'Catálogo', 'icon' => 'book-open', 'permiso' => 'catalogo'],
                ],
            ],
            [
                'titulo' => 'Finanzas',
                'icon' => 'banknotes',
                'items' => [
                    ['uri' => 'proveedores', 'label' => 'Proveedores', 'corto' => 'Prov', 'icon' => 'truck', 'permiso' => 'proveedores'],
                    ['uri' => 'movimientos', 'label' => 'Movimientos', 'corto' => 'Mov', 'icon' => 'arrows-right-left', 'permiso' => 'movimientos'],
                    ['uri' => 'facturacion', 'label' => 'Facturación', 'corto' => 'Factura', 'icon' => 'receipt-percent', 'permiso' => 'facturacion'],
                    ['uri' => 'devoluciones', 'label' => 'Devoluciones', 'corto' => 'Dev', 'icon' => 'trending-down', 'permiso' => 'devoluciones'],
                    ['uri' => 'gastos', 'label' => 'Gastos', 'corto' => 'Gastos', 'icon' => 'credit-card', 'permiso' => 'gastos'],
                    ['uri' => 'abonos', 'label' => 'Abonos', 'corto' => 'Abonos', 'icon' => 'banknotes', 'permiso' => 'abonos'],
                ],
            ],
            [
                'titulo' => 'Admin',
                'icon' => 'shield-check',
                'items' => [
                    // Perfil lo ve cualquiera logueado; se filtra con un permiso
                    // inventado que todos tienen, para no dejar una excepcion
                    // suelta en el filtro de abajo.
                    ['uri' => 'profile', 'label' => 'Perfil', 'corto' => 'Perfil', 'icon' => 'user-circle', 'permiso' => 'perfil'],
                    ['uri' => 'reportes', 'label' => 'Reportes', 'corto' => 'Reportes', 'icon' => 'presentation-chart-bar', 'permiso' => 'reportes'],
                    ['uri' => 'empleados', 'label' => 'Empleados', 'corto' => 'Empleados', 'icon' => 'users', 'permiso' => 'empleados'],
                    ['uri' => 'configuracion', 'label' => 'Configuración', 'corto' => 'Config', 'icon' => 'cog', 'permiso' => 'configuracion'],
                ],
            ],
        ];
    }

    /**
 * Normaliza los items que vinieron sin `corto` (por si alguien agrega uno
     * nuevo y se le olvida): cae a `label` recortado, nunca a una cadena vacía
     * que rompería el `corto` de los demás.
     */
    private static function normalizar(array $grupos): array
    {
        foreach ($grupos as $gi => $grupo) {
            foreach ($grupo['items'] as $ii => $item) {
                $grupos[$gi]['items'][$ii]['corto'] = $item['corto']
                    ?? ($item['label'] === 'Configuración' ? 'Config' : mb_substr($item['label'], 0, 8));
            }
        }

        return $grupos;
    }

    /**
     * Grupos con al menos un ítem visible para el usuario actual.
     *
     * El filtro es por PERMISO, no por `esAdmin()`: cada rol ve su parte
     * (2026-10-03). Un cajero, por ejemplo, solo entra al grupo Principal y
     * Clientes; el grupo Admin entero desaparece para él.
     *
     * `perfil` no está en App\Support\Roles porque lo ve cualquiera
     * autenticado: se resuelve acá para no abrir una excepción en el filtro.
     */
    public static function gruposVisibles(): array
    {
        $usuario = Auth::user();

        return collect(static::normalizar(static::grupos()))
            ->map(function (array $grupo) use ($usuario) {
                $grupo['items'] = array_values(array_filter(
                    $grupo['items'],
                    fn (array $item) => static::puedeVer($usuario, $item['permiso'] ?? $item['uri'])
                ));

                return $grupo;
            })
            ->filter(fn (array $grupo) => count($grupo['items']) > 0)
            ->values()
            ->all();
    }

    /**
     * El permiso `perfil` es el único abierto a todos los autenticados: es la
     * cuenta propia del usuario, no un módulo del negocio.
     */
    private static function puedeVer(?User $usuario, string $permiso): bool
    {
        if (! $usuario) {
            return false;
        }

        if ($permiso === 'perfil') {
            return $usuario->estaActivo();
        }

        return $usuario->puede($permiso);
    }
}
