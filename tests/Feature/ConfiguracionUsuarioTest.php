<?php

namespace Tests\Feature;

use App\Http\Livewire\Configuracion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Alta de usuarios en Configuración (`Configuracion::guardarUsuario()`).
 *
 * Corre contra `luxury_test` (copia real del schema legacy) con
 * DatabaseTransactions: no se crea ni se borra nada en la BD real `luxury`.
 *
 * Reglas que fija: el admin crea usuarios con la contraseña cifrada, se rechazan
 * duplicados de username/email, contraseña corta o sin confirmar, rol/estado
 * inválidos, y un cajero no entra al módulo. NO se prueba el borrado: por diseño
 * no existe (se desactiva) para no dejar `usuario_id` colgados.
 */
class ConfiguracionUsuarioTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $admin = User::where('rol', 'admin')->where('estado', 'activo')->orderBy('id')->first();

        $this->assertNotNull($admin, 'El dataset de luxury_test no tiene un admin activo.');

        return $admin;
    }

    /**
     * Un cajero activo. Ojo: desde la migracion del 2026-10-03 las cuentas que
     * tenian `cajero` pasaron a `operador` (para no perderles el acceso ancho que
     * tenian), asi que el dataset ya no trae ninguno y hay que crearlo. Es
     * seguro porque el destino es `luxury_test` y hay transaccion.
     */
    private function cajero(): User
    {
        $cajero = User::where('rol', 'cajero')->where('estado', 'activo')->orderBy('id')->first();

        if ($cajero) {
            return $cajero;
        }

        return User::create([
            'nombre' => 'Cajero de Prueba',
            'username' => 'cajero.prueba',
            'email' => 'cajero.prueba@luxury.com',
            'rol' => 'cajero',
            'estado' => 'activo',
            'password' => Hash::make('ClaveDePrueba123'),
        ]);
    }

    private function comoAdmin(): void
    {
        $this->actingAs($this->admin());
    }

    public function test_admin_crea_un_usuario_con_la_password_cifrada(): void
    {
        $this->comoAdmin();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->set('nombre', 'Ana Prueba')
            ->set('username', 'ana.prueba')
            ->set('email', 'ana.prueba@luxury.com')
            ->set('rol', 'cajero')
            ->set('estado', 'activo')
            ->set('password', 'ClaveSegura123')
            ->set('passwordConfirm', 'ClaveSegura123')
            ->call('guardarUsuario')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $creado = User::where('email', 'ana.prueba@luxury.com')->first();

        $this->assertNotNull($creado, 'No se creó el usuario.');
        $this->assertSame('Ana Prueba', $creado->nombre);
        $this->assertSame('cajero', $creado->rol);
        $this->assertSame('activo', $creado->estado);
        $this->assertNotSame('ClaveSegura123', $creado->password, 'La contraseña quedó en texto plano.');
        $this->assertTrue(Hash::check('ClaveSegura123', $creado->password));
    }

    public function test_rechaza_un_correo_ya_registrado(): void
    {
        $this->comoAdmin();
        $existente = User::orderBy('id')->firstOrFail();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->set('nombre', 'X')
            ->set('username', 'usuario.'.uniqid())
            ->set('email', $existente->email)
            ->set('password', 'ClaveSegura123')
            ->set('passwordConfirm', 'ClaveSegura123')
            ->call('guardarUsuario')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_rechaza_un_username_ya_registrado(): void
    {
        $this->comoAdmin();
        $existente = User::whereNotNull('username')->firstOrFail();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->set('nombre', 'X')
            ->set('username', $existente->username)
            ->set('email', 'nuevo.'.uniqid().'@luxury.com')
            ->set('password', 'ClaveSegura123')
            ->set('passwordConfirm', 'ClaveSegura123')
            ->call('guardarUsuario')
            ->assertHasErrors(['username' => 'unique']);
    }

    public function test_rechaza_una_password_de_menos_de_ocho_caracteres(): void
    {
        $this->comoAdmin();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->set('nombre', 'X')
            ->set('username', 'usuario.'.uniqid())
            ->set('email', 'nuevo.'.uniqid().'@luxury.com')
            ->set('password', '123')
            ->set('passwordConfirm', '123')
            ->call('guardarUsuario')
            ->assertHasErrors(['password' => 'min']);
    }

    public function test_rechaza_una_confirmacion_que_no_coincide(): void
    {
        $this->comoAdmin();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->set('nombre', 'X')
            ->set('username', 'usuario.'.uniqid())
            ->set('email', 'nuevo.'.uniqid().'@luxury.com')
            ->set('password', 'ClaveSegura123')
            ->set('passwordConfirm', 'OtraClave123')
            ->call('guardarUsuario')
            ->assertHasErrors(['passwordConfirm' => 'same']);
    }

    public function test_rechaza_un_rol_que_no_existe(): void
    {
        $this->comoAdmin();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->set('nombre', 'X')
            ->set('username', 'usuario.'.uniqid())
            ->set('email', 'nuevo.'.uniqid().'@luxury.com')
            ->set('rol', 'superadmin')
            ->set('password', 'ClaveSegura123')
            ->set('passwordConfirm', 'ClaveSegura123')
            ->call('guardarUsuario')
            ->assertHasErrors(['rol' => 'in']);
    }

    public function test_un_cajero_no_entra_a_configuracion(): void
    {
        $this->actingAs($this->cajero())
            ->get(route('configuracion'))
            ->assertForbidden();
    }

    public function test_al_admin_se_le_abre_y_cierra_el_formulario(): void
    {
        $this->comoAdmin();

        Livewire::test(Configuracion::class)
            ->call('nuevoUsuario')
            ->assertSet('showForm', true)
            ->call('cerrarForm')
            ->assertSet('showForm', false);
    }
}
