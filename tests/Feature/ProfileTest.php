<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perfil sobre la tabla legacy `usuarios`: campos `nombre` (no `name`) y
 * SIN verificacion de email ni borrado de cuenta (el usuario vive referenciado
 * por cajas, abonos y devoluciones; se desactiva, no se borra).
 */
class ProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'nombre' => 'Nombre Actualizado',
                'email' => 'actualizado@luxury.test',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Nombre Actualizado', $user->nombre);
        $this->assertSame('actualizado@luxury.test', $user->email);
    }

    public function test_email_duplicado_es_rechazado(): void
    {
        $otro = User::factory()->create(['email' => 'ocupado@luxury.test']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'nombre' => $user->nombre,
                'email' => $otro->email,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_password_puede_actualizarse(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('nueva-clave-123', $user->refresh()->password));
    }

    public function test_la_contrasena_actual_es_obligatoria_para_cambiarla(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'clave-equivocada',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
    }
}
