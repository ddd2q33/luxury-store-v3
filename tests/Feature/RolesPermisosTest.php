<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PanelMenu;
use App\Support\Roles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pruebas de la escala de roles y permisos (2026-10-03).
 *
 * El mapa rol -> permisos vive en App\Support\Roles, que es la fuente única.
 * Estas pruebas fijan tres cosas:
 *
 * 1. La matriz: qué módulo ve cada rol. Si alguien toca Roles::TODOS(), el
 *    test falla y se ve a Simple vista qué cambió.
 * 2. Que la RUTA de verdad corta el acceso, no solo el menú (un enlace oculto
 *    no es seguridad: se puede teclear la URL).
 * 3. Que PanelMenu y el mapa no se desincronicen: todo item del menú tiene su
 *    permiso en el mapa, y toda ruta de módulo lo declara.
 */
class RolesPermisosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario en memoria, sin tocar la BD. Alcanza para probar el mapa: lo que
     * se consulta es el enum de `rol` y `estado`, no la fila.
     */
    private function usuarioFalso(string $rol): User
    {
        return (new User())->forceFill([
            'rol' => $rol,
            'estado' => 'activo',
        ]);
    }

    // =================================================================
    // 1. La matriz de permisos
    // =================================================================

    public function test_admin_tiene_control_total(): void
    {
        $admin = $this->usuarioFalso(Roles::ADMIN);

        foreach (Roles::permisosDe(Roles::ADMIN) as $modulo) {
            $this->assertTrue($admin->puede($modulo), "El admin deberia poder [{$modulo}].");
        }

        // 20 módulos de negocio; `profile` no cuenta porque lo ve cualquiera
        // autenticado y no es un módulo (ver PanelMenu::gruposVisibles()).
        $this->assertCount(20, Roles::permisosDe(Roles::ADMIN), 'El admin deberia ver los 20 modulos.');
        $this->assertTrue($admin->puedeAccion('anular_venta'));
    }

    public function test_supervisor_ve_todo_menos_configuracion(): void
    {
        $supervisor = $this->usuarioFalso(Roles::SUPERVISOR);

        $this->assertFalse($supervisor->puede('configuracion'), 'El supervisor NO debe entrar a Configuracion.');
        $this->assertTrue($supervisor->puede('reportes'));
        $this->assertTrue($supervisor->puede('empleados'));
        $this->assertTrue($supervisor->puede('proveedores'));
        $this->assertTrue($supervisor->puedeAccion('anular_venta'));
    }

    public function test_operador_es_el_acceso_ancho_que_tenian_las_cuentas_existentes(): void
    {
        $operador = $this->usuarioFalso(Roles::OPERADOR);

        // Esto es exactamente lo que veia un cajero/empleado antes de 2026-10-03:
        // todo el negocio EXCEPTO reportes, empleados y configuracion.
        $this->assertTrue($operador->puede('proveedores'));
        $this->assertTrue($operador->puede('abonos'));
        $this->assertTrue($operador->puede('stock'));
        $this->assertFalse($operador->puede('reportes'));
        $this->assertFalse($operador->puede('empleados'));
        $this->assertFalse($operador->puede('configuracion'));

        // No puede anular ventas: es una acción destructiva de supervision.
        $this->assertFalse($operador->puedeAccion('anular_venta'));
    }

    public function test_cajero_no_toca_finanzas(): void
    {
        $cajero = $this->usuarioFalso(Roles::CAJERO);

        $this->assertTrue($cajero->puede('caja'));
        $this->assertTrue($cajero->puede('ventas'));
        $this->assertTrue($cajero->puede('clientes'));
        $this->assertTrue($cajero->puede('pedidos'));

        foreach (['proveedores', 'movimientos', 'facturacion', 'devoluciones', 'gastos', 'abonos', 'reportes', 'stock'] as $no) {
            $this->assertFalse($cajero->puede($no), "El cajero NO deberia poder [{$no}].");
        }

        $this->assertFalse($cajero->puedeAccion('anular_venta'));
    }

    public function test_empleado_trabaja_inventario_pero_no_abre_caja(): void
    {
        $empleado = $this->usuarioFalso(Roles::EMPLEADO);

        $this->assertTrue($empleado->puede('ingreso'));
        $this->assertTrue($empleado->puede('productos'));
        $this->assertTrue($empleado->puede('stock'));
        $this->assertTrue($empleado->puede('ventas'));

        $this->assertFalse($empleado->puede('caja'), 'El empleado NO debe abrir ni cerrar caja.');
        $this->assertFalse($empleado->puede('proveedores'));
        $this->assertFalse($empleado->puede('abonos'));
    }

    public function test_cuenta_inactiva_pierde_todos_los_permisos_aunque_mantenga_el_rol(): void
    {
        $inactivo = (new User())->forceFill([
            'rol' => Roles::ADMIN,
            'estado' => 'inactivo',
        ]);

        $this->assertFalse($inactivo->puede('caja'), 'Una cuenta inactiva no puede hacer nada.');
        $this->assertFalse($inactivo->puedeAccion('anular_venta'));
    }

    public function test_un_rol_desconocido_no_abre_nada(): void
    {
        // Si la BD quedara con un valor que el codigo no conoce, la falla segura
        // es negar TODO, no dejarlo pasar.
        $raro = $this->usuarioFalso('inventado');

        $this->assertFalse($raro->puede('caja'));
        $this->assertSame([], Roles::permisosDe('inventado'));
        $this->assertFalse(Roles::existe('inventado'));
    }

    // =================================================================
    // 2. La ruta de verdad corta el acceso
    // =================================================================

    /**
     * Ocultar el enlace NO es seguridad: la URL se puede teclear. Este test va
     * por HTTP contra las rutas de verdad.
     */
    public function test_cajero_recibe_403_en_un_modulo_de_finanzas(): void
    {
        $this->actingAs($this->crearUsuario(Roles::CAJERO));

        $this->get('/proveedores')->assertForbidden();
        $this->get('/gastos')->assertForbidden();
        $this->get('/reportes')->assertForbidden();
        $this->get('/configuracion')->assertForbidden();

        // Pero sí entra a lo suyo.
        $this->get('/caja')->assertOk();
        $this->get('/ventas')->assertOk();
    }

    public function test_empleado_recibe_403_en_caja(): void
    {
        $this->actingAs($this->crearUsuario(Roles::EMPLEADO));

        $this->get('/caja')->assertForbidden();
        $this->get('/proveedores')->assertForbidden();

        $this->get('/stock')->assertOk();
        $this->get('/productos')->assertOk();
    }

    public function test_supervisor_entra_a_reportes_pero_no_a_configuracion(): void
    {
        $this->actingAs($this->crearUsuario(Roles::SUPERVISOR));

        $this->get('/reportes')->assertOk();
        $this->get('/empleados')->assertOk();
        $this->get('/configuracion')->assertForbidden();
    }

    public function test_operador_conserva_todo_lo_que_tenia_antes_del_cambio(): void
    {
        $this->actingAs($this->crearUsuario(Roles::OPERADOR));

        // Lo que el cajero y el empleado veian antes (todo el panel salvo esto).
        foreach ([
            '/dashboard', '/caja', '/ventas', '/clientes', '/pedidos',
            '/ingreso', '/productos', '/inventario', '/stock', '/categorias', '/catalogo',
            '/proveedores', '/movimientos', '/facturacion', '/devoluciones', '/gastos', '/abonos',
        ] as $ruta) {
            $this->get($ruta)->assertOk();
        }

        $this->get('/reportes')->assertForbidden();
        $this->get('/empleados')->assertForbidden();
        $this->get('/configuracion')->assertForbidden();
    }

    /**
     * La barrera de mount(): aunque el middleware se saltara, el componente se
     * niega a montar.
     */
    public function test_un_cajero_no_puede_montar_reportes(): void
    {
        $this->actingAs($this->crearUsuario(Roles::CAJERO));

        $componente = Livewire::test('reportes');

        $this->assertSame(403, $componente->lastResponse->exception?->getStatusCode());
    }

    // =================================================================
    // 3. El menú y las rutas no se desincronizan del mapa
    // =================================================================

    public function test_todo_item_del_menu_tiene_su_permiso_en_el_mapa(): void
    {
        $vistos = [];

        foreach (PanelMenu::grupos() as $grupo) {
            foreach ($grupo['items'] as $item) {
                $permiso = $item['permiso'] ?? $item['uri'];

                if ($permiso === 'perfil') {
                    continue; // abierto a todos, resuelto aparte en PanelMenu
                }

                $vistos[$permiso] = $item['label'];

                $this->assertNotEmpty(
                    Roles::permisosDe(Roles::ADMIN),
                    'El mapa de permisos del admin deberia traer todos los modulos.'
                );
            }
        }

        // Si se agrega un módulo al menú y no al mapa, el admin tampoco lo vería.
        $this->assertSame(
            [],
            array_diff(array_keys($vistos), Roles::permisosDe(Roles::ADMIN)),
            'Items del menú sin permiso en App\Support\Roles: '
            .implode(', ', array_diff(array_keys($vistos), Roles::permisosDe(Roles::ADMIN)))
        );
    }

    public function test_el_menu_del_cajero_no_muestra_finanzas_ni_admin(): void
    {
        $this->actingAs($this->crearUsuario(Roles::CAJERO));

        $uris = $this->urisDelMenu();

        $this->assertContains('caja', $uris);
        $this->assertContains('ventas', $uris);

        // El menu usa `uri` (que es `profile`), no el nombre del permiso (`perfil`).
        $this->assertContains('profile', $uris, 'Perfil lo ve cualquiera autenticado.');

        foreach (['proveedores', 'abonos', 'gastos', 'stock', 'reportes', 'configuracion'] as $no) {
            $this->assertNotContains($no, $uris, "El menú del cajero no deberia mostrar [{$no}].");
        }
    }

    public function test_el_menu_del_admin_muestra_todos_los_grupos(): void
    {
        $this->actingAs($this->crearUsuario(Roles::ADMIN));

        $uris = $this->urisDelMenu();

        $this->assertContains('configuracion', $uris);
        $this->assertContains('reportes', $uris);
        $this->assertContains('proveedores', $uris);
        $this->assertCount(21, $uris);
    }

    /** @return array<int, string> Los uri visibles en el menú para el usuario actual. */
    private function urisDelMenu(): array
    {
        $uris = [];

        foreach (PanelMenu::gruposVisibles() as $grupo) {
            foreach ($grupo['items'] as $item) {
                $uris[] = $item['uri'];
            }
        }

        return $uris;
    }

    /**
     * Los roles del mapa tienen que ser EXACTAMENTE los del enum de la BD: si
     * el código acepta un rol que el enum no tiene, el alta de usuario revienta
     * con un error de MySQL en vez de un mensaje claro.
     */
    public function test_los_roles_del_mapa_coinciden_con_el_enum_de_la_bd(): void
    {
        $enum = DB::selectOne("SHOW COLUMNS FROM usuarios LIKE 'rol'")->Type;

        preg_match_all("/'([^']+)'/", $enum, $coincidencias);

        $enBd = $coincidencias[1];
        sort($enBd);
        $delCodigo = Roles::valores();
        sort($delCodigo);

        $this->assertSame(
            $delCodigo,
            $enBd,
            'App\Support\Roles y el enum usuarios.rol están desalineados.'
        );
    }

    /** Crea una cuenta real para poder probar rutas con sesión. */
    private function crearUsuario(string $rol): User
    {
        $sufijo = $rol.'_'.bin2hex(random_bytes(4));

        return User::create([
            'nombre' => 'Prueba '.$rol,
            'username' => 'prueba_'.$sufijo,
            'email' => 'prueba_'.$sufijo.'@example.test',
            'password' => bcrypt('prueba-1234'),
            'rol' => $rol,
            'estado' => 'activo',
        ]);
    }
}