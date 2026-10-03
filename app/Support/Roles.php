<?php

namespace App\Support;

use App\Models\User;

/**
 * Fuente ÚNICA de los roles del panel y de qué puede hacer cada uno.
 *
 * Decidido con el usuario el 2026-10-03: los roles viven en el enum
 * `usuarios.rol` y el mapa rol -> permisos aquí en código, no en tablas nuevas.
 * Consecuencia: para cambiar la escala hay que editar esta clase, no la BD.
 *
 * El nombre del permiso es el MISMO `uri` del módulo que ya usa PanelMenu y el
 * nombre de la ruta (`route('caja')`). Así no hay una segunda lista que se pueda
 * desincronizar de la primera: si un módulo no aparece en el menú, tampoco
 * existe el permiso y nadie puede perder acceso por olvido.
 *
 * NO usar listas de roles escritas a mano en otros sitios. Para validar o
 * mostrar el selector, usar `Roles::valores()` / `Roles::TODOS()`.
 */
class Roles
{
    public const ADMIN = 'admin';

    public const SUPERVISOR = 'supervisor';

    public const OPERADOR = 'operador';

    public const CAJERO = 'cajero';

    public const EMPLEADO = 'empleado';

    /**
     * Permisos de bajo nivel. Son los que se exigen en RUTA y botón, así que
     * conviene que sean finos y no "puedeVerTodoElModulo".
     *
     * Formato: uri del módulo => etiqueta para la vista.
     */
    private const MODULOS = [
        'dashboard' => 'Home',
        'caja' => 'Caja',
        'ventas' => 'Ventas',
        'clientes' => 'Clientes',
        'pedidos' => 'Pedidos',
        'ingreso' => 'Ingreso',
        'productos' => 'Productos',
        'inventario' => 'Inventario',
        'stock' => 'Stock',
        'categorias' => 'Categorías',
        'catalogo' => 'Catálogo',
        'proveedores' => 'Proveedores',
        'movimientos' => 'Movimientos',
        'facturacion' => 'Facturación',
        'devoluciones' => 'Devoluciones',
        'gastos' => 'Gastos',
        'abonos' => 'Abonos',
        'reportes' => 'Reportes',
        'empleados' => 'Empleados',
        'configuracion' => 'Configuración',
    ];

    /**
     * Permiso de una acción puntual dentro de un módulo. Vive aparte porque NO
     * coincide con ningún módulo del menú: se comprueba dentro del componente.
     */
    private const ACCIONES = [
        // Anular una venta borra la venta, su movimiento de caja y repone
        // stock. Es la acción más destructiva del panel.
        'anular_venta' => 'Anular ventas',
    ];

    /**
     * Definición de cada rol. `permisos` es la lista explícita de módulos.
     *
     * El admin NO se lista: tiene todos por definición (`total` = true). Escribir
     * 21 módulos a mano y olvidarse de uno sería un agujero silencioso.
     *
     * @return array<string, array{label:string, resumen:string, total:bool, permisos:string[], acciones:string[]}>
     */
    public static function TODOS(): array
    {
        return [
            self::ADMIN => [
                'label' => 'Administrador',
                'resumen' => 'Control total: todos los módulos, sin excepción.',
                'total' => true,
                'permisos' => [],
                'acciones' => array_keys(self::ACCIONES),
            ],

            self::SUPERVISOR => [
                'label' => 'Supervisor',
                'resumen' => 'Todo menos Configuración. Ve reportes, empleados y todo el dinero.',
                'total' => false,
                'permisos' => self::todosLosModulos([
                    self::RUTA_CONFIGURACION,
                ]),
                'acciones' => array_keys(self::ACCIONES),
            ],

            // Acceso amplio heredado. Es lo que tenían las cuentas existentes
            // antes de partir las rutas por permiso; por eso existe.
            self::OPERADOR => [
                'label' => 'Operador',
                'resumen' => 'Operación diaria completa: ve finanzas, pero no reportes, empleados ni Configuración.',
                'total' => false,
                'permisos' => self::todosLosModulos([
                    self::RUTA_CONFIGURACION,
                    'reportes',
                    'empleados',
                ]),
                'acciones' => [],
            ],

            self::CAJERO => [
                'label' => 'Cajero',
                'resumen' => 'Cobra en caja y vende. No ve finanzas ni reportes.',
                'total' => false,
                'permisos' => [
                    'dashboard', 'caja', 'ventas', 'clientes', 'pedidos', 'catalogo',
                ],
                'acciones' => [],
            ],

            self::EMPLEADO => [
                'label' => 'Empleado',
                'resumen' => 'Inventario y ventas. No abre caja ni ve finanzas.',
                'total' => false,
                'permisos' => [
                    'dashboard', 'ventas', 'clientes', 'pedidos', 'catalogo',
                    'ingreso', 'productos', 'inventario', 'stock', 'categorias',
                ],
                'acciones' => [],
            ],
        ];
    }

    private const RUTA_CONFIGURACION = 'configuracion';

    /** Todos los módulos del panel menos los indicados. */
    private static function todosLosModulos(array $excepto): array
    {
        return array_values(array_diff(array_keys(self::MODULOS), $excepto));
    }

    /** @return array<int, string> Los valores válidos del enum `usuarios.rol`. */
    public static function valores(): array
    {
        return array_keys(self::TODOS());
    }

    public static function existe(?string $rol): bool
    {
        return $rol !== null && array_key_exists($rol, self::TODOS());
    }

    /** Etiqueta legible de un rol. Nunca revienta si el rol no existe. */
    public static function etiqueta(?string $rol): string
    {
        return self::TODOS()[$rol]['label'] ?? 'Sin rol';
    }

    /** Texto de una línea para la UI (tarjetas de Configuración, tooltips). */
    public static function resumen(?string $rol): string
    {
        return self::TODOS()[$rol]['resumen'] ?? '';
    }

    /** Lista de roles para un <select>, ya etiquetados. */
    public static function opciones(): array
    {
        return collect(self::TODOS())
            ->map(fn (array $r) => $r['label'])
            ->all();
    }

    public static function esTotal(?string $rol): bool
    {
        return self::TODOS()[$rol]['total'] ?? false;
    }

    /**
     * ¿El usuario tiene este permiso de módulo?
     *
     * Un usuario null (no autenticado) NO tiene nada: así una vista que se
     * renderice sin sesión sale vacía en vez de reventar.
     */
    public static function usuarioPuede(?User $usuario, string $permiso): bool
    {
        if (! $usuario || ! $usuario->estaActivo()) {
            return false;
        }

        $definicion = self::TODOS()[$usuario->rol] ?? null;

        if (! $definicion) {
            // Rol desconocido (BD desalineada con el código): NO abrir nada.
            return false;
        }

        return $definicion['total'] || in_array($permiso, $definicion['permisos'], true);
    }

    /** ¿El usuario puede esta acción puntual dentro de un módulo? */
    public static function usuarioPuedeAccion(?User $usuario, string $accion): bool
    {
        if (! $usuario || ! $usuario->estaActivo()) {
            return false;
        }

        $definicion = self::TODOS()[$usuario->rol] ?? null;

        if (! $definicion) {
            return false;
        }

        return $definicion['total'] || in_array($accion, $definicion['acciones'], true);
    }

    /** Todos los permisos de un rol, para pintar la ficha del usuario. */
    public static function permisosDe(?string $rol): array
    {
        $definicion = self::TODOS()[$rol] ?? null;

        if (! $definicion) {
            return [];
        }

        if ($definicion['total']) {
            return array_keys(self::MODULOS);
        }

        return $definicion['permisos'];
    }

    /**
     * Módulos que el usuario NO puede ver, por si una vista los necesita para
     * decidir si se oculta algo.
     */
    public static function modulosNegados(?User $usuario): array
    {
        $negados = [];

        foreach (array_keys(self::MODULOS) as $modulo) {
            if (! self::usuarioPuede($usuario, $modulo)) {
                $negados[] = $modulo;
            }
        }

        return $negados;
    }

    /** Etiquetas de los módulos, para las listas de permisos de Configuración. */
    public static function etiquetaModulo(string $modulo): string
    {
        return self::MODULOS[$modulo] ?? $modulo;
    }

    /** Etiqueta de una acción puntual, para las fichas de permisos. */
    public static function etiquetaAccion(string $accion): string
    {
        return self::ACCIONES[$accion] ?? $accion;
    }
}