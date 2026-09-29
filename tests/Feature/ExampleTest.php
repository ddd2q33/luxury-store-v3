<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raiz ya no sirve la landing de Laravel: ahora es una redireccion
     * (meta-refresh) al dashboard si hay sesion o al login si no.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertOk();
    }
}
