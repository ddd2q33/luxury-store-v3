<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Auth sobre la tabla legacy `usuarios` (schema real, no el de Breeze).
 * Corre contra `luxury_test` con transacciones: el hash y `ultimo_login`
 * reales de `luxury` jamas se tocan (ademas, testea en otra BD).
 */
class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'contrasena-incorrecta',
        ]);

        $this->assertGuest();
    }

    public function test_usuarios_inactivos_no_pueden_entrar(): void
    {
        // El sistema bloquea inactivos aunque las credenciales sean validas.
        $user = User::factory()->create(['estado' => 'inactivo']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_logout_cierra_la_sesion_y_lleva_al_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
