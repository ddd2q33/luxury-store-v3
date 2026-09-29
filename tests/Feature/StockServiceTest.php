<?php

namespace Tests\Feature;

use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Support\StockService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pruebas del UNICO punto que muta productos.stock.
 *
 * Corren contra `luxury_test` (copia real del schema legacy, ver phpunit.xml)
 * y usan DatabaseTransactions: nada de RefreshDatabase (dropearia el schema
 * legacy) y nada se persiste en la BD real `luxury`.
 */
class StockServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_entrada_suma_stock_y_registra_movimiento(): void
    {
        $producto = Producto::orderBy('id')->first();
        $stockInicial = (int) $producto->stock;

        $devuelto = StockService::entrada($producto->id, 5, 'Prueba automatica');

        $this->assertSame($stockInicial + 5, $devuelto->stock);
        $this->assertDatabaseHas('inventario_movimientos', [
            'producto_id' => $producto->id,
            'tipo' => InventarioMovimiento::ENTRADA,
            'cantidad' => 5,
            'observaciones' => 'Prueba automatica',
        ]);
    }

    public function test_salida_resta_stock_y_no_permite_bajar_de_cero(): void
    {
        $producto = Producto::where('stock', '>=', 3)->orderBy('id')->first();
        $stockInicial = (int) $producto->stock;

        $devuelto = StockService::salida($producto->id, 2);

        $this->assertSame($stockInicial - 2, $devuelto->stock);

        $this->expectException(DomainException::class);
        StockService::salida($producto->id, $stockInicial - 2 + 1000);
    }

    public function test_ajuste_fija_el_stock_absoluto_y_deja_trazabilidad(): void
    {
        $producto = Producto::orderBy('id')->first();
        $stockInicial = (int) $producto->stock;

        $devuelto = StockService::ajuste($producto->id, $stockInicial + 3, 'Conteo fisico');

        $this->assertSame($stockInicial + 3, $devuelto->stock);

        $movimiento = InventarioMovimiento::where('producto_id', $producto->id)
            ->where('tipo', InventarioMovimiento::AJUSTE)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($movimiento);
        $this->assertStringContainsString('Conteo', (string) $movimiento->observaciones);
    }

    public function test_producto_inexistente_lanza_domainexception_y_no_pantalla_blanca(): void
    {
        // Regresion: usar findOrFail() aqui soltaba una ModelNotFoundException
        // (RuntimeException) que escapaba de PanelComponent::ejecutar().
        $idFantasma = (int) DB::table('productos')->max('id') + 1000;

        $this->expectException(DomainException::class);
        StockService::salida($idFantasma, 1);
    }

    public function test_ajuste_con_mismo_stock_no_hace_nada(): void
    {
        $producto = Producto::orderBy('id')->first();

        $this->expectException(DomainException::class);
        StockService::ajuste($producto->id, (int) $producto->stock);
    }

    public function test_movimiento_de_producto_sin_categoria_va_con_null_no_con_cero(): void
    {
        // Regresion: escribir categoria_id = 0 reventaba contra la FK a categorias.
        $producto = Producto::whereNull('categoria_id')->first();

        if (! $producto) {
            $this->markTestSkipped('No hay productos sin categoria en el dataset.');
        }

        $stockInicial = (int) $producto->stock;

        StockService::entrada($producto->id, 1, 'Sin categoria');

        $this->assertDatabaseHas('inventario_movimientos', [
            'producto_id' => $producto->id,
            'cantidad' => 1,
            'categoria_id' => null,
        ]);
        $this->assertSame($stockInicial + 1, $producto->refresh()->stock);
    }
}
