<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Pruebas del servicio de caja contra `luxury_test` con transacciones.
 * CajaService sigue lanzando RuntimeException (convencion vigente del modulo:
 * Caja.php la captura localmente); las pruebas reflejan ese contrato.
 */
class CajaServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CajaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CajaService;

        // Ambiente esteril: ninguna caja abierta heredada del dataset.
        Caja::where('estado', 'abierta')->update(['estado' => 'cerrada', 'fecha_cierre' => now()]);
    }

    public function test_abrir_crea_caja_y_solo_puede_haber_una_abierta(): void
    {
        $caja = $this->service->abrir(50000.0, 1);

        $this->assertTrue($caja->exists);
        $this->assertSame(Caja::ESTADO_ABIERTA, $caja->estado);
        $this->assertSame(50000.0, (float) $caja->saldo_inicial);

        $this->expectException(RuntimeException::class);
        $this->service->abrir(10000.0, 1);
    }

    public function test_registrar_venta_descuenta_stock_crea_detalles_y_movimiento(): void
    {
        $caja = $this->service->abrir(0.0, 1);

        $producto = Producto::where('stock', '>=', 5)->orderBy('id')->first();
        $stockInicial = (int) $producto->stock;

        ['venta' => $venta] = $this->service->registrarVenta(
            $caja,
            [['id' => $producto->id, 'cantidad' => 3, 'precio' => 25000.0]],
            75000.0,
            'Efectivo',
            'Cliente de prueba',
        );

        $this->assertInstanceOf(Venta::class, $venta);
        $this->assertDatabaseHas('venta_detalles', [
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'cantidad' => 3,
        ]);
        $this->assertSame($stockInicial - 3, $producto->refresh()->stock);
        $this->assertDatabaseHas('movimientos_caja', [
            'caja_id' => $caja->id,
            'tipo' => MovimientoCaja::TIPO_VENTA,
            'venta_id' => $venta->id,
            'monto' => 75000.0,
        ]);
    }

    public function test_venta_en_efectivo_calcula_el_cambio(): void
    {
        $caja = $this->service->abrir(0.0, 1);

        $producto = Producto::where('stock', '>=', 2)->orderBy('id')->first();

        ['venta' => $venta, 'cambio' => $cambio] = $this->service->registrarVenta(
            $caja,
            [['id' => $producto->id, 'cantidad' => 1, 'precio' => 20000.0]],
            20000.0,
            'Efectivo',
            montoRecibido: 50000.0,
        );

        $this->assertSame(30000.0, $cambio);
        $this->assertSame(50000.0, (float) $venta->monto_recibido);
        $this->assertSame(30000.0, (float) $venta->cambio);
    }

    public function test_venta_con_stock_insuficiente_se_revierte_completa(): void
    {
        $caja = $this->service->abrir(0.0, 1);

        $producto = Producto::where('stock', '>=', 1)->orderByDesc('id')->first();
        $stockInicial = (int) $producto->stock;

        // Conteos ANTES: las tablas legacy ya traen filas (p. ej. 791 movimientos
        // de caja), lo que se verifica es que NO se escriban filas nuevas.
        $ventasAntes = (int) Venta::count();
        $detallesAntes = (int) DB::table('venta_detalles')->count();
        $movimientosAntes = (int) DB::table('movimientos_caja')->count();

        try {
            $this->service->registrarVenta(
                $caja,
                [['id' => $producto->id, 'cantidad' => $stockInicial + 999], ['id' => $producto->id, 'cantidad' => 1]],
                10000.0,
                'Efectivo',
            );
            $this->fail('Se esperaba RuntimeException por stock insuficiente.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame($ventasAntes, (int) Venta::count(), 'La venta no debe quedar escrita.');
        $this->assertSame($detallesAntes, (int) DB::table('venta_detalles')->count(), 'Sin detalles huerfanos.');
        $this->assertSame($movimientosAntes, (int) DB::table('movimientos_caja')->count(), 'Sin movimientos huerfanos.');
        $this->assertSame($stockInicial, $producto->refresh()->stock, 'El stock no debe cambiar.');
    }

    public function test_eliminar_venta_revierte_stock_y_limpia_detalles(): void
    {
        $caja = $this->service->abrir(0.0, 1);

        $producto = Producto::where('stock', '>=', 4)->orderBy('id')->first();
        $stockInicial = (int) $producto->stock;

        ['venta' => $venta] = $this->service->registrarVenta(
            $caja,
            [['id' => $producto->id, 'cantidad' => 4, 'precio' => 10000.0]],
            40000.0,
            'Efectivo',
        );

        $this->assertSame($stockInicial - 4, $producto->refresh()->stock);

        $this->service->eliminarVenta($venta->id);

        $this->assertNull(Venta::find($venta->id));
        $this->assertSame($stockInicial, $producto->refresh()->stock);
        $this->assertDatabaseMissing('venta_detalles', ['venta_id' => $venta->id]);
        $this->assertDatabaseMissing('movimientos_caja', ['venta_id' => $venta->id]);
    }

    public function test_cerrar_calcula_saldo_y_no_deja_caja_abierta(): void
    {
        $caja = $this->service->abrir(10000.0, 1);

        $producto = Producto::where('stock', '>=', 1)->orderBy('id')->first();

        $this->service->registrarVenta(
            $caja,
            [['id' => $producto->id, 'cantidad' => 1, 'precio' => 15000.0]],
            15000.0,
            'Efectivo',
        );

        $totales = $this->service->cerrar($caja);

        $this->assertSame(25000.0, $totales['saldo']);
        $this->assertSame(15000.0, $totales['ventas']);

        $caja->refresh();
        $this->assertSame(Caja::ESTADO_CERRADA, $caja->estado);
        $this->assertSame(25000.0, (float) $caja->saldo_final);
        $this->assertNotNull($caja->fecha_cierre);
    }

    public function test_reabrir_restaura_la_ultima_caja_cerrada(): void
    {
        $caja = $this->service->abrir(10000.0, 1);
        $this->service->cerrar($caja);

        $reabierta = $this->service->reabrir();

        $this->assertSame($caja->id, $reabierta->id);
        $this->assertSame(Caja::ESTADO_ABIERTA, $reabierta->estado);
        $this->assertNull($reabierta->fresh()->fecha_cierre);
    }
}
