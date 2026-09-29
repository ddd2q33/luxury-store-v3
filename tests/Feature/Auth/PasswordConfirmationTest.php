<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/confirm-password')
            ->assertOk();
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'clave-equivocada'])
            ->assertSessionHasErrors();
    }
}
