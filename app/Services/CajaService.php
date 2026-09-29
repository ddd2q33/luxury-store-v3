<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio del módulo de caja, migrada desde oldluxury/caja.
 * Toda escritura pasa por aquí para que ventas y movimientos queden
 * consistentes (transacciones) y el stock no quede en negativos
 * (el sistema viejo permitía stock negativo; el nuevo lo valida).
 */
class CajaService
{
    /** Caja actualmente abierta (la más reciente). */
    public function cajaActiva(): ?Caja
    {
        return Caja::where('estado', 'abierta')->orderByDesc('fecha_apertura')->first();
    }

    /** Totales y saldo de una caja (saldo = inicial + ventas + ingresos − egresos/devoluciones). */
    public function totales(Caja $caja): array
    {
        $t = $caja->movimientos()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'venta' THEN monto ELSE 0 END), 0) AS ventas")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) AS ingresos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo IN ('egreso', 'devolucion') THEN monto ELSE 0 END), 0) AS egresos")
            ->selectRaw('COUNT(*) AS movimientos')
            ->first();

        return [
            'ventas' => (float) $t->ventas,
            'ingresos' => (float) $t->ingresos,
            'egresos' => (float) $t->egresos,
            'movimientos' => (int) $t->movimientos,
            'saldo' => $caja->saldo_inicial + (float) $t->ventas + (float) $t->ingresos - (float) $t->egresos,
        ];
    }

    /** Abre una caja nueva con saldo inicial. Falla si ya hay una abierta. */
    public function abrir(float $saldoInicial, int $usuarioId): Caja
    {
        return DB::transaction(function () use ($saldoInicial, $usuarioId) {
            // Chequeo con lockForUpdate: serializa dos aperturas simultaneas.
            // Sin lock, dos cajeros podrian pasar el check-then-create y abrir
            // dos cajas a la vez; con lectura actual (no snapshot) el segundo
            // SI ve la caja que el primero acaba de insertar.
            if ($this->cajaActivaBloqueada()) {
                throw new \RuntimeException('Ya existe una caja abierta.');
            }

            return Caja::create([
                'fecha_apertura' => now(),
                'saldo_inicial' => $saldoInicial,
                'estado' => Caja::ESTADO_ABIERTA,
                'usuario_id' => $usuarioId,
            ]);
        });
    }

    /** Reabre la última caja cerrada (mismo comportamiento del sistema viejo). */
    public function reabrir(): Caja
    {
        return DB::transaction(function () {
            if ($this->cajaActivaBloqueada()) {
                throw new \RuntimeException('Ya existe una caja abierta.');
            }

            $ultima = Caja::where('estado', 'cerrada')->orderByDesc('fecha_cierre')->first();
            if (! $ultima) {
                throw new \RuntimeException('No hay caja cerrada para reabrir.');
            }

            $ultima->forceFill([
                'estado' => Caja::ESTADO_ABIERTA,
                'fecha_cierre' => null,
                'saldo_final' => null,
            ])->save();

            return $ultima;
        });
    }

    /**
     * Igual que cajaActiva() pero con SELECT ... FOR UPDATE: para usarse DENTRO
     * de la transaccion de abrir/reabrir. En una lectura corriente (snapshot
     * REPEATABLE READ) el segundo cajero no veria la fila recien insertada.
     */
    private function cajaActivaBloqueada(): ?Caja
    {
        return Caja::where('estado', 'abierta')
            ->orderByDesc('fecha_apertura')
            ->lockForUpdate()
            ->first();
    }

    /** Cierra la caja activa calculando el saldo esperado. */
    public function cerrar(Caja $caja): array
    {
        return DB::transaction(function () use ($caja) {
            $totales = $this->totales($caja);

            $caja->forceFill([
                'saldo_final' => $totales['saldo'],
                'fecha_cierre' => now(),
                'estado' => Caja::ESTADO_CERRADA,
            ])->save();

            return $totales;
        });
    }

    /**
     * Registra una venta completa: venta + detalles + descuento de stock + movimiento de caja.
     *
     * @param  array<int, array{id:int, cantidad:int, precio:float, nombre?:string}>  $items
     * @return array{venta: Venta, cambio: float}
     */
    public function registrarVenta(
        Caja $caja,
        array $items,
        float $total,
        string $metodoPago,
        string $clienteNombre = 'Cliente general',
        string $motivo = 'Venta general',
        ?float $montoRecibido = null,
    ): array {
        if ($total <= 0) {
            throw new \InvalidArgumentException('El total debe ser mayor a cero.');
        }

        // Efectivo exige que lo recibido cubra el total; otros métodos se cobran exactos.
        if ($metodoPago === 'Efectivo') {
            $montoRecibido ??= $total;
            if ($montoRecibido < $total) {
                throw new \InvalidArgumentException('El monto recibido es menor al total.');
            }
            $cambio = round($montoRecibido - $total, 2);
        } else {
            $montoRecibido = $total;
            $cambio = 0.0;
        }

        return DB::transaction(function () use ($caja, $items, $total, $metodoPago, $clienteNombre, $motivo, $montoRecibido, $cambio) {
            // Validar stock disponible de todos los items antes de escribir nada.
            foreach ($items as $item) {
                $producto = Producto::lockForUpdate()->find($item['id']);
                if (! $producto) {
                    throw new \RuntimeException("El producto #{$item['id']} no existe.");
                }
                if ($producto->stock < $item['cantidad']) {
                    throw new \RuntimeException("Stock insuficiente de \"{$producto->nombre}\" (disponible: {$producto->stock}).");
                }
            }

            $venta = Venta::create([
                'cliente_nombre' => $clienteNombre !== '' ? $clienteNombre : 'Cliente general',
                'fecha_venta' => now(),
                'total' => $total,
                'metodo_pago' => $metodoPago,
                'monto_recibido' => $montoRecibido,
                'cambio' => $cambio,
                'motivo' => $motivo !== '' ? $motivo : 'Venta general',
            ]);

            foreach ($items as $item) {
                $producto = Producto::lockForUpdate()->find($item['id']);

                VentaDetalle::create([
                    'venta_id' => $venta->id,
                    'producto_id' => $producto->id,
                    'producto_nombre' => $producto->nombre,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio'],
                    'subtotal' => round($item['cantidad'] * $item['precio'], 2),
                ]);

                $producto->decrement('stock', $item['cantidad']);
            }

            MovimientoCaja::create([
                'caja_id' => $caja->id,
                'tipo' => MovimientoCaja::TIPO_VENTA,
                'metodo_pago' => $metodoPago,
                'monto' => $total,
                'descripcion' => $motivo !== '' ? $motivo : 'Venta general',
                'fecha' => now(),
                'venta_id' => $venta->id,
            ]);

            return ['venta' => $venta, 'cambio' => $cambio];
        });
    }

    /** Elimina una venta revirtiendo stock, detalles y movimiento de caja (como el viejo). */
    public function eliminarVenta(int $ventaId): void
    {
        DB::transaction(function () use ($ventaId) {
            $venta = Venta::lockForUpdate()->find($ventaId);
            if (! $venta) {
                throw new \RuntimeException('La venta no existe.');
            }

            foreach ($venta->detalles as $detalle) {
                if ($detalle->producto_id > 0) {
                    Producto::where('id', $detalle->producto_id)->increment('stock', $detalle->cantidad);
                }
            }

            $venta->detalles()->delete();
            MovimientoCaja::where('venta_id', $venta->id)->delete();
            $venta->delete();
        });
    }
}
