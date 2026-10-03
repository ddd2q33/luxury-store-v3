<?php

namespace Tests\Feature;

use App\Http\Livewire\PanelComponent;
use App\Http\Middleware\EnsureEsAdmin;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pruebas de la GUARDIA de autorizacion del panel (2026-10-02).
 *
 * Estas tres cosas ya se rompieron una vez cada una y costaron pantallas en
 * blanco o escritura sin permiso, asi que quedan fijadas con test:
 *
 * 1. TODO componente del panel hereda de PanelComponent. Caja, Ventas, Pedidos,
 *    Clientes y VentasRecientes heredaban de Livewire\Component, asi que un
 *    usuario desactivado a mitad de sesion conservaba escritura sobre la caja y
 *    el stock.
 * 2. EnsureEsAdmin esta en la lista de middleware persistente de Livewire. Sin
 *    eso el rol nunca se revalidaba: mount() solo corre en la carga inicial.
 * 3. No queda ningun findOrFail() en un componente Livewire: su
 *    ModelNotFoundException es un RuntimeException y escapa a pantalla en blanco.
 */
class PanelGuardiaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Cada componente del panel tiene que pasar por hydrate() de PanelComponent,
     * que exige sesion activa en CADA accion (no solo al cargar la pagina).
     */
    public function test_todo_componente_del_panel_extiende_panelcomponent(): void
    {
        $archivos = glob(app_path('Http/Livewire/*.php'));

        $this->assertNotEmpty($archivos, 'No se encontraron componentes Livewire.');

        $reprobados = [];

        foreach ($archivos as $archivo) {
            $clase = 'App\\Http\\Livewire\\'.basename($archivo, '.php');

            if ($clase === PanelComponent::class) {
                continue;
            }

            if (class_exists($clase) && ! is_subclass_of($clase, PanelComponent::class)) {
                $reprobados[] = class_basename($clase);
            }
        }

        $this->assertSame(
            [],
            $reprobados,
            'Estos componentes NO heredan de PanelComponent y se saltan hydrate(): '
            .implode(', ', $reprobados)
        );
    }

    /**
     * Los componentes que sobreescriben toast() deben mantenerlo `protected`.
     * Bajarlo a `private` es ERROR FATAL de PHP ("Access level to X::toast()
     * must be protected"), asi que se comprueba por reflection y no a ojo.
     */
    public function test_los_toast_sobreescritos_siguen_protected(): void
    {
        $vistos = [];

        foreach (glob(app_path('Http/Livewire/*.php')) as $archivo) {
            $clase = 'App\\Http\\Livewire\\'.basename($archivo, '.php');

            if (! class_exists($clase) || ! is_subclass_of($clase, PanelComponent::class)) {
                continue;
            }

            $toast = new \ReflectionMethod($clase, 'toast');

            if ($toast->getDeclaringClass()->getName() === PanelComponent::class) {
                continue;
            }

            $vistos[$clase] = $toast->isPrivate() ? 'private' : 'protected';
        }

        $this->assertNotEmpty($vistos, 'Se esperaba al menos un toast() sobreescrito.');

        $this->assertSame(
            [],
            array_keys(array_filter($vistos, fn ($visibilidad) => $visibilidad !== 'protected')),
            'Un toast() sobreescrito quedo private: PHP lo declara error fatal.'
        );
    }

    /**
     * Sin esto, degradar a un admin conservaba sus poderes de admin hasta que se
     * acabara la sesion (SESSION_LIFETIME=120, almacenamiento en archivo).
     */
    public function test_ensure_es_admin_esta_en_el_middleware_persistente_de_livewire(): void
    {
        $this->assertContains(EnsureEsAdmin::class, Livewire::getPersistentMiddleware());
    }

    /**
     * Un usuario desactivado a mitad de sesion NO puede seguir actuando. Antes de
     * esto, Caja::registrarVenta() le funcionaba igual que a uno activo.
     */
    public function test_usuario_desactivado_no_puede_registrar_una_venta_en_caja(): void
    {
        $usuario = $this->usuario('cajero');
        $this->actingAs($usuario);

        // Sesion abierta con el usuario todavia activo.
        $componente = Livewire::test('caja');
        $componente->assertOk();

        // Se desactiva a mitad de sesion (otro admin lo hizo en Config).
        $usuario->forceFill(['estado' => 'inactivo'])->save();

        // Misma instancia: esta llamada es un request posterior, que es donde
        // hydrate() tiene que volver a exigir la sesion activa.
        $componente->call('registrarVenta');

        // Ojo: el harness de Livewire 2 NO relanza la excepcion, la deja en
        // `lastResponse->exception` (Testing/Concerns/MakesCallsToComponent.php:145).
        // Por eso no se puede usar expectExceptionCode().
        $this->assertSesionExpirada($componente);
    }

    /**
     * El cierre de caja es la accion mas sensible del panel. Se prueba aparte
     * porque es la que escribe saldo_final y fecha_cierre.
     */
    public function test_usuario_desactivado_no_puede_cerrar_la_caja(): void
    {
        $usuario = $this->usuario('cajero');
        $this->actingAs($usuario);

        $componente = Livewire::test('caja');
        $componente->assertOk();

        $usuario->forceFill(['estado' => 'inactivo'])->save();

        $componente->call('cerrarCaja');

        $this->assertSesionExpirada($componente);
    }

    /**
     * Regresion del bug de findOrFail: si el registro se borro entre el render y
     * el click, antes salia una pantalla en blanco; ahora debe salir un toast.
     */
    public function test_editar_un_registro_borrado_devuelve_toast_y_no_error(): void
    {
        $this->actingAs($this->usuario('admin'));

        $componente = Livewire::test('clientes')->call('editar', 999999);

        $componente->assertHasNoErrors();

        $dispatches = $componente->payload['effects']['dispatches'] ?? [];

        $this->assertNotEmpty($dispatches, 'Se esperaba un toast de error.');

        $this->assertSame('clientes-toast', $dispatches[0]['event']);
        $this->assertSame('error', $dispatches[0]['data']['tipo']);
    }

    /**
     * Los helpers de cada componente devuelven null cuando el registro no existe,
     * para que el formulario NO se cierre y el error llegue como toast.
     */
    public function test_ver_detalle_de_un_registro_borrado_no_revienta(): void
    {
        $this->actingAs($this->usuario('admin'));

        $componente = Livewire::test('clientes')->call('verDetalle', 999999);

        $componente->assertHasNoErrors();
        $componente->assertSet('showDetalle', false);
    }

    /** No debe quedar ningun findOrFail/firstOrFail real (fuera de comentarios). */
    public function test_no_quedan_findorfail_en_los_componentes_livewire(): void
    {
        $infractores = [];

        foreach (glob(app_path('Http/Livewire/*.php')) as $archivo) {
            // Se borran los comentarios para no contar las menciones que los
            // propios helpers hacen de findOrFail al explicar por que no se usa.
            $codigo = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($archivo));
            $codigo = preg_replace('#//[^\n]*#', '', (string) $codigo);

            if (preg_match('#(firstOrFail|findOrFail)\s*\(#', (string) $codigo)) {
                $infractores[] = basename($archivo);
            }
        }

        $this->assertSame(
            [],
            $infractores,
            'findOrFail() sigue presente en: '.implode(', ', $infractores)
            .'. Usar el helper del componente (find() + DomainException).'
        );
    }

    /**
     * Un usuario activo con ese rol. Usa uno del dataset si existe y, si no,
     * crea uno de usar y tirar: la migracion del 2026-10-03 remapeo las cuentas
     * que existian a `operador`, asi que `cajero` ya no esta en el dataset y
     * estas pruebas no pueden depender de el.
     *
     * Es seguro crear usuarios AQUI porque el destino es `luxury_test` (una copia
     * descartable) y la transaccion se revierte al final. En `luxury` real NO se
     * puede: el hash de password no es recuperable.
     */
    private function usuario(string $rol): User
    {
        $usuario = User::where('rol', $rol)->where('estado', 'activo')->orderBy('id')->first();

        if ($usuario) {
            return $usuario;
        }

        $this->assertTrue(Roles::existe($rol), "El rol [{$rol}] no existe en el enum de usuarios.rol.");

        return User::create([
            'nombre' => "Prueba {$rol}",
            'username' => "prueba.{$rol}",
            'email' => "prueba.{$rol}@luxury.com",
            'rol' => $rol,
            'estado' => 'activo',
            'password' => Hash::make('ClaveDePrueba123'),
        ]);
    }

    /** hydrate() tiene que haber cortado la llamada con un 401. */
    private function assertSesionExpirada($componente): void
    {
        $excepcion = $componente->lastResponse->exception ?? null;

        $this->assertNotNull(
            $excepcion,
            'La llamada NO fue bloqueada: un usuario desactivado pudo seguir actuando.'
        );

        $this->assertInstanceOf(
            \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class,
            $excepcion
        );

        $this->assertSame(401, $excepcion->getStatusCode(), 'El bloqueo no fue un 401.');
    }
}
