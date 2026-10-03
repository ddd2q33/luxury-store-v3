<?php

namespace Tests\Feature;

use App\Http\Livewire\Caja;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un producto sin precio de venta no debe entrar al carrito.
 *
 * Corre contra `luxury_test` con DatabaseTransactions: nada de RefreshDatabase
 * (dropearia el schema legacy) y nada se escribe en la BD real `luxury`.
 *
 * El motivo: 105 de los 106 productos tienen `precio = 0.00` porque el legacy
 * nunca lo cargo. Sin esta guarda el cajero armaba el carrito, cobraba, y
 * `CajaService::registrarVenta()` rechazaba el total con "debe ser mayor a
 * cero": un error sin relacion con lo que estaba pasando.
 */
class CajaPrecioCeroTest extends TestCase
{
    use DatabaseTransactions;

    private function productoSinPrecio(): Producto
    {
        $producto = Producto::where('precio', '<=', 0)
            ->where('stock', '>', 0)
            ->orderBy('id')
            ->first();

        if (! $producto) {
            $this->markTestSkipped('No hay productos sin precio con stock en el dataset.');
        }

        return $producto;
    }

    public function test_no_agrega_al_carrito_un_producto_sin_precio(): void
    {
        $this->actingAs(User::where('rol', 'admin')->firstOrFail());
        $producto = $this->productoSinPrecio();

        $componente = Livewire::test(Caja::class)
            ->call('agregarProducto', $producto->id);

        $carrito = $componente->get('carrito');

        $this->assertIsArray($carrito);
        $this->assertEmpty(
            $carrito,
            'Un producto sin precio no debe quedar en el carrito.'
        );
    }

    public function test_avisa_que_el_precio_esta_sin_cargar(): void
    {
        $this->actingAs(User::where('rol', 'admin')->firstOrFail());
        $producto = $this->productoSinPrecio();

        $componente = Livewire::test(Caja::class)
            ->call('agregarProducto', $producto->id);

        // OJO: `assertEmitted()` de Livewire 2 solo mira `effects.emits`, y un
        // `dispatchBrowserEvent()` cae en `effects.dispatches`. Por eso no sirve
        // aca y hay que leer el payload a mano.
        $toasts = array_values(array_filter(
            $componente->payload['effects']['dispatches'] ?? [],
            fn ($d) => ($d['event'] ?? null) === 'caja-toast'
        ));

        $this->assertCount(1, $toasts, 'Deberia emitirse exactamente un aviso.');
        $this->assertSame('error', $toasts[0]['data']['tipo'] ?? null);
        $this->assertStringContainsString(
            'no tiene precio de venta',
            $toasts[0]['data']['mensaje'] ?? ''
        );
        $this->assertStringContainsString(
            'Cargar precios',
            $toasts[0]['data']['mensaje'] ?? '',
            'El aviso debe decir donde cargar el precio.'
        );
    }

    public function test_sigue_dejando_agregar_un_producto_con_precio(): void
    {
        $this->actingAs(User::where('rol', 'admin')->firstOrFail());

        // En `luxury_test` NO hay ningun producto con precio (el dump es anterior a
        // que existiera ese dato), asi que se le pone precio aqui. Va dentro de la
        // transaccion de DatabaseTransactions: no se escribe en ninguna BD.
        $producto = Producto::where('stock', '>', 0)->orderBy('id')->first();

        if (! $producto) {
            $this->markTestSkipped('No hay productos con stock en el dataset.');
        }

        $producto->forceFill(['precio' => 50000])->save();

        $componente = Livewire::test(Caja::class)
            ->call('agregarProducto', $producto->id);

        $carrito = $componente->get('carrito');
        $ids = array_column($carrito, 'id');

        $this->assertContains($producto->id, $ids);
        $this->assertSame(50000.0, (float) $carrito[0]['precio']);
    }
}