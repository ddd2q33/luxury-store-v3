<?php

namespace Tests\Feature;

use App\Http\Livewire\Productos;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regresion de los modales de Productos.
 *
 * Contexto: en Livewire 2.12 el parser de `wire:click` usa el regex
 * `/(.*?)\((.*)\)/s` (vendor/livewire/livewire/js/util/wire-directives.js), cuyo
 * segundo grupo es GREEDY. Con `wire:click="editar(1); cerrarDetalle()"` se
 * comia `1); cerrarDetalle(` como parametros, el `cerrarDetalle()` quedaba tras
 * el `return` (codigo muerto) y los DOS modales quedaban abiertos a la vez.
 * Lo mismo pasaba con `$set('quitarImagen', true); $set('imagenArchivo', null)`,
 * del que solo se aplicaba el primer `$set`.
 *
 * Por eso ahora ambas acciones son metodos de un solo proposito
 * (`editar()` y `quitarImagenElegida()`) y la vista no encadena sentencias.
 *
 * Corre contra `luxury_test` con DatabaseTransactions (nunca RefreshDatabase:
 * dropearia el schema legacy).
 */
class ProductosModalTest extends TestCase
{
    use DatabaseTransactions;

    private function comoAdmin(): void
    {
        $this->actingAs(User::where('rol', 'admin')->firstOrFail());
    }

    public function test_editar_cierra_el_modal_de_detalle_y_no_deja_los_dos_abiertos(): void
    {
        $this->comoAdmin();
        $producto = Producto::orderBy('id')->firstOrFail();

        // Paso 1: se abre el detalle desde la lista.
        Livewire::test(Productos::class)
            ->call('verDetalle', $producto->id)
            ->assertSet('showDetalle', true)
            ->assertSet('showForm', false);

        // Paso 2: el boton "Editar" de la ficha. La vista llama SOLO `editar()`.
        $test = Livewire::test(Productos::class)
            ->call('verDetalle', $producto->id)
            ->call('editar', $producto->id);

        $test->assertSet('showForm', true)
            ->assertSet('showDetalle', false)
            ->assertSet('productoDetalle', null)
            ->assertSet('editandoId', $producto->id)
            ->assertSet('nombre', (string) $producto->nombre);

        // Y en el HTML renderizado queda UN solo modal (el del formulario).
        // Ojo: en el testable de Livewire 2 no existe `->html()`; el DOM final
        // queda en `payload['effects']['html']` (TestableLivewire.php:103).
        $html = $test->payload['effects']['html'];
        $this->assertSame(1, substr_count($html, 'class="fixed inset-0 z-50'), 'Se esperaba un solo modal abierto');
    }

    public function test_editar_sobre_un_id_inexistente_no_cierra_ni_abre_nada(): void
    {
        $this->comoAdmin();

        // El helper `producto()` lanza DomainException y `cargar()` lo captura:
        // no debe dejar el formulario abierto a medias.
        Livewire::test(Productos::class)
            ->call('editar', 999999999)
            ->assertSet('showForm', false)
            ->assertSet('editandoId', null);
    }

    public function test_quitar_imagen_limpia_el_archivo_elegido_y_marca_el_flag(): void
    {
        $this->comoAdmin();
        $producto = Producto::orderBy('id')->firstOrFail();

        $test = Livewire::test(Productos::class)->call('editar', $producto->id);

        // Simula que el usuario acaba de elegir un archivo. Se asigna la propiedad
        // directamente porque `TemporaryUploadedFile` no se puede construir sin
        // pasar por el endpoint de subida de Livewire.
        $test->set('imagenArchivo', 'contenido-falso');

        $test->call('quitarImagenElegida')
            ->assertSet('quitarImagen', true)
            ->assertSet('imagenArchivo', null);
    }

    public function test_reset_imagen_al_cerrar_el_formulario(): void
    {
        $this->comoAdmin();
        $producto = Producto::orderBy('id')->firstOrFail();

        Livewire::test(Productos::class)
            ->call('editar', $producto->id)
            ->call('quitarImagenElegida')
            ->set('imagenArchivo', 'contenido-falso')
            ->call('cerrarForm')
            ->assertSet('showForm', false)
            ->assertSet('quitarImagen', false)
            ->assertSet('imagenArchivo', null);
    }

    public function test_la_vista_no_encadena_sentencias_en_wire_click(): void
    {
        // El bug es del parser de Livewire, no de PHP: aunque el servidor este
        // bien, `wire:click="a(); b()"` solo ejecuta `a()`. Este test es la red
        // de seguridad para que nadie vuelva a escribir dos acciones en un clic.
        $vista = file_get_contents(resource_path('views/livewire/productos.blade.php'));

        $this->assertIsString($vista);
        $this->assertSame(
            0,
            preg_match('/wire:click="[^"]*;[^"]*"/', $vista),
            'Hay un wire:click con dos sentencias: en Livewire 2 solo se ejecuta la primera.'
        );
    }
}
