<?php

namespace App\Support;

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
     * @return array<int, array{titulo:string, icon:string, items:array<int, array{uri:string, label:string, corto:string, icon:string, admin:bool}>}>
     */
    public static function grupos(): array
    {
        return [
            [
                'titulo' => 'Principal',
                'icon' => 'home',
                'items' => [
                    ['uri' => 'dashboard', 'label' => 'Home', 'corto' => 'Home', 'icon' => 'chart-line', 'admin' => false],
                    ['uri' => 'caja', 'label' => 'Caja', 'corto' => 'Caja', 'icon' => 'banknotes', 'admin' => false],
                    ['uri' => 'ventas', 'label' => 'Ventas', 'corto' => 'Ventas', 'icon' => 'shopping-cart', 'admin' => false],
                ],
            ],
            [
                'titulo' => 'Clientes',
                'icon' => 'users',
                'items' => [
                    ['uri' => 'clientes', 'label' => 'Clientes', 'corto' => 'Clientes', 'icon' => 'users', 'admin' => false],
                    ['uri' => 'pedidos', 'label' => 'Pedidos', 'corto' => 'Pedidos', 'icon' => 'clipboard-list', 'admin' => false],
                ],
            ],
            [
                'titulo' => 'Inventario',
                'icon' => 'cube',
                'items' => [
                    ['uri' => 'ingreso', 'label' => 'Ingreso', 'corto' => 'Ingreso', 'icon' => 'truck', 'admin' => false],
                    ['uri' => 'productos', 'label' => 'Productos', 'corto' => 'Productos', 'icon' => 'cube', 'admin' => false],
                    ['uri' => 'inventario', 'label' => 'Inventario', 'corto' => 'Inventario', 'icon' => 'building-office', 'admin' => false],
                    ['uri' => 'stock', 'label' => 'Stock', 'corto' => 'Stock', 'icon' => 'bars-3', 'admin' => false],
                    ['uri' => 'categorias', 'label' => 'Categorías', 'corto' => 'Categorías', 'icon' => 'tag', 'admin' => false],
                    ['uri' => 'catalogo', 'label' => 'Catálogo', 'corto' => 'Catálogo', 'icon' => 'book-open', 'admin' => false],
                ],
            ],
            [
                'titulo' => 'Finanzas',
                'icon' => 'banknotes',
                'items' => [
                    ['uri' => 'proveedores', 'label' => 'Proveedores', 'corto' => 'Prov', 'icon' => 'truck', 'admin' => false],
                    ['uri' => 'movimientos', 'label' => 'Movimientos', 'corto' => 'Mov', 'icon' => 'arrows-right-left', 'admin' => false],
                    ['uri' => 'facturacion', 'label' => 'Facturación', 'corto' => 'Factura', 'icon' => 'receipt-percent', 'admin' => false],
                    ['uri' => 'devoluciones', 'label' => 'Devoluciones', 'corto' => 'Dev', 'icon' => 'trending-down', 'admin' => false],
                    ['uri' => 'gastos', 'label' => 'Gastos', 'corto' => 'Gastos', 'icon' => 'credit-card', 'admin' => false],
                    ['uri' => 'abonos', 'label' => 'Abonos', 'corto' => 'Abonos', 'icon' => 'banknotes', 'admin' => false],
                ],
            ],
            [
                'titulo' => 'Admin',
                'icon' => 'shield-check',
                'items' => [
                    // Perfil lo ve cualquiera logueado; los otros son admin-only
                    // y se filtran solos en gruposVisibles() para el resto de roles.
                    ['uri' => 'profile', 'label' => 'Perfil', 'corto' => 'Perfil', 'icon' => 'user-circle', 'admin' => false],
                    ['uri' => 'reportes', 'label' => 'Reportes', 'corto' => 'Reportes', 'icon' => 'presentation-chart-bar', 'admin' => true],
                    ['uri' => 'empleados', 'label' => 'Empleados', 'corto' => 'Empleados', 'icon' => 'users', 'admin' => true],
                    ['uri' => 'configuracion', 'label' => 'Configuración', 'corto' => 'Config', 'icon' => 'cog', 'admin' => true],
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

    /** Grupos que tienen al menos un ítem visible para el usuario actual. */
    public static function gruposVisibles(): array
    {
        $esAdmin = Auth::user()?->esAdmin() ?? false;

        return collect(static::normalizar(static::grupos()))
            ->map(function (array $grupo) use ($esAdmin) {
                $grupo['items'] = array_values(array_filter(
                    $grupo['items'],
                    fn (array $item) => ! $item['admin'] || $esAdmin
                ));

                return $grupo;
            })
            ->filter(fn (array $grupo) => count($grupo['items']) > 0)
            ->values()
            ->all();
    }
}
