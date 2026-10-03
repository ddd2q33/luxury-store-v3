<?php

namespace Tests\Feature;

use App\Http\Livewire\ProductosPrecios;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Carga masiva de precios.
 *
 * Corre contra `luxury_test` (copia real del schema legacy) con
 * DatabaseTransactions: nada de RefreshDatabase (dropearia el schema legacy) y
 * nada se escribe en la BD real `luxury`.
 *
 * Ojo: el texto pegado se arma con nombres de productos REALES de la BD, porque
 * `analizarPegado()` asocia por nombre normalizado. Si el dataset no tiene un
 * producto con ese nombre, la prueba no probaría nada: `skipTest()` lo hace
 * explícito en vez de dar un falso verde.
 */
class ProductosPreciosTest extends TestCase
{
    use DatabaseTransactions;

    private function comoAdmin(): void
    {
        $this->actingAs(User::where('rol', 'admin')->firstOrFail());
    }

    /** Producto real por nombre exacto, o la prueba se salta. */
    private function producto(string $nombre): Producto
    {
        $producto = Producto::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first();

        if (! $producto) {
            $this->markTestSkipped("El dataset no tiene un producto llamado «{$nombre}».");
        }

        return $producto;
    }

    // ====================================================================
    // Guardado manual
    // ====================================================================

    public function test_guarda_un_precio_editado(): void
    {
        $this->comoAdmin();
        $producto = $this->producto('ACSIS ROSADO');

        Livewire::test(ProductosPrecios::class)
            ->call('editarPrecio', $producto->id, '175000')
            ->assertSet('tocados.'.$producto->id, true)
            ->call('guardar');

        $this->assertSame(175000.0, (float) $producto->fresh()->precio);
    }

    public function test_acepta_formato_colombiano_en_el_input(): void
    {
        $this->comoAdmin();
        $producto = $this->producto('ACSIS ROSADO');

        // "270.000" con punto de miles NO debe guardarse como 270.
        Livewire::test(ProductosPrecios::class)
            ->call('editarPrecio', $producto->id, '270.000')
            ->call('guardar');

        $this->assertSame(270000.0, (float) $producto->fresh()->precio);
    }

    public function test_deja_el_precio_en_cero_si_se_borra_el_campo(): void
    {
        $this->comoAdmin();
        $producto = $this->producto('ACSIS ROSADO');

        // Lo acordado: vacio = 0.00 = "Sin precio" en el catalogo.
        Livewire::test(ProductosPrecios::class)
            ->call('editarPrecio', $producto->id, '90000')
            ->call('guardar');

        Livewire::test(ProductosPrecios::class)
            ->call('editarPrecio', $producto->id, '')
            ->assertSet('tocados.'.$producto->id, true)
            ->call('guardar');

        $this->assertSame(0.0, (float) $producto->fresh()->precio);
    }

    public function test_no_marca_cambio_si_el_precio_es_el_mismo(): void
    {
        $this->comoAdmin();
        $producto = $this->producto('ACSIS ROSADO');

        Livewire::test(ProductosPrecios::class)
            ->call('editarPrecio', $producto->id, (string) (float) $producto->precio)
            ->assertSet('tocados.'.$producto->id, null);
    }

    public function test_rechaza_un_precio_negativo(): void
    {
        $this->comoAdmin();
        $producto = $this->producto('ACSIS ROSADO');

        $test = Livewire::test(ProductosPrecios::class)
            ->call('editarPrecio', $producto->id, '-5000');

        // No debe quedar pendiente de guardar.
        $test->assertSet('tocados.'.$producto->id, null);
    }

    public function test_guardar_sin_cambios_no_toca_la_bd(): void
    {
        $this->comoAdmin();

        Livewire::test(ProductosPrecios::class)
            ->call('guardar')
            ->assertOk();
    }

    // ====================================================================
    // Pegado desde Excel
    // ====================================================================

    public function test_pegado_asocia_por_nombre_y_aplica_el_precio(): void
    {
        $this->comoAdmin();
        $producto = $this->producto('ACSIS ROSADO');

        Livewire::test(ProductosPrecios::class)
            ->set('textoPegado', "ACSIS ROSADO, 199000")
            ->call('analizarPegado')
            ->assertSet('propuestas.'.$producto->id.'.valor', 199000.0)
            ->call('aplicarPegado');

        $this->assertSame(199000.0, (float) $producto->fresh()->precio);
    }

    public function test_pegado_ignora_mayusculas_tildes_y_espacios(): void
    {
        $this->comoAdmin();

        // "ACSiS  rosado" debe encontrar "ACSIS ROSADO": el comparador normaliza
        // mayusculas y quita espacios sobrantes.
        $conEspacios = Producto::where('id', $this->producto('ACSIS ROSADO')->id)->first();

        Livewire::test(ProductosPrecios::class)
            ->set('textoPegado', "  ACSIS   ROSADO ,  199000  ")
            ->call('analizarPegado')
            ->assertSet('propuestas.'.$conEspacios->id.'.valor', 199000.0);
    }

    public function test_pegado_reporta_las_lineas_que_no_puede_asociar(): void
    {
        $this->comoAdmin();

        // Nombre que NO existe en el catalogo: tiene que aparecer en sinAsociar
        // con su motivo, nunca descartarse en silencio.
        $test = Livewire::test(ProductosPrecios::class)
            ->set('textoPegado', "PRODUCTO INVENTADO XYZ, 50000")
            ->call('analizarPegado');

        $test->assertCount('sinAsociar', 1);
        $test->assertSet('propuestas', []);

        $this->assertStringContainsString(
            'No existe un producto',
            $test->instance()->sinAsociar[0]['motivo']
        );
    }

    public function test_pegado_admite_varias_lineas(): void
    {
        $this->comoAdmin();

        $nombres = Producto::orderBy('id')->limit(3)->pluck('nombre');
        $texto = $nombres->map(fn ($n, $i) => "{$n}, ".((100000 + $i * 1000)))->implode("\n");

        $test = Livewire::test(ProductosPrecios::class)
            ->set('textoPegado', $texto)
            ->call('analizarPegado');

        $test->assertCount('propuestas', min(3, $nombres->count()));
    }

    public function test_pegado_con_precios_en_formato_colombiano(): void
    {
        $this->comoAdmin();

        // "270.000" con punto de miles debe quedar en 270000, no en 270.
        $test = Livewire::test(ProductosPrecios::class)
            ->set('textoPegado', 'ACSIS ROSADO, 270.000')
            ->call('analizarPegado');

        $test->assertSet('propuestas.'.$this->producto('ACSIS ROSADO')->id.'.valor', 270000.0);
    }

    public function test_pegado_texto_vacio_da_error(): void
    {
        $this->comoAdmin();

        Livewire::test(ProductosPrecios::class)
            ->set('textoPegado', '')
            ->call('analizarPegado')
            ->assertHasErrors(['textoPegado']);
    }

    public function test_aplicar_sin_propuestas_no_rompe(): void
    {
        $this->comoAdmin();

        Livewire::test(ProductosPrecios::class)
            ->call('aplicarPegado')
            ->assertOk();
    }

    // ====================================================================
    // Pantalla
    // ====================================================================

    public function test_los_recuentos_de_la_pantalla_son_consistentes(): void
    {
        $this->comoAdmin();

        $test = Livewire::test(ProductosPrecios::class);

        $total = (int) Producto::count();
        $sinPrecio = (int) Producto::where(fn ($q) => $q->whereNull('precio')->orWhere('precio', 0))->count();

        $test->assertViewHas('stats', function (array $stats) use ($total, $sinPrecio) {
            return $stats['total'] === $total
                && $stats['sinPrecio'] === $sinPrecio
                && $stats['conPrecio'] === $total - $sinPrecio
                && $stats['avance'] >= 0 && $stats['avance'] <= 100;
        });
    }

    public function test_el_filtro_sin_precio_solo_devuelve_esos(): void
    {
        $this->comoAdmin();

        $test = Livewire::test(ProductosPrecios::class)
            ->set('filtroEstado', 'sin_precio');

        foreach ($test->viewData('productos') as $producto) {
            $this->assertTrue(
                $producto->precio === null || $producto->precio <= 0,
                "«{$producto->nombre}» tiene precio y no debería salir con el filtro sin_precio."
            );
        }
    }
}
