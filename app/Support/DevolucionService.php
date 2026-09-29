<?php

namespace App\Support;

use App\Models\Caja;
use App\Models\Devolucion;
use App\Models\MovimientoCaja;
use App\Models\Venta;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Unico punto que crea y anula devoluciones.
 *
 * El formulario ponia max="..." en el input, pero eso NO es validacion de
 * servidor: se podia devolver mas que el total de la venta. Aqui se valida
 * contra la suma real de devoluciones previas, con la venta bloqueada para que
 * dos devoluciones simultaneas no se pasen el saldo.
 */
class DevolucionService
{
    /**
     * Caja abierta actual. null si no hay turno abierto.
     */
    public function cajaAbiertaId(): ?int
    {
        return Caja::where('estado', 'abierta')->orderByDesc('id')->value('id');
    }

    /**
     * Cuanto falta devolver de una venta: total menos lo ya devuelto.
     */
    public function saldoDevoluble(int $ventaId): float
    {
        $total = (float) (Venta::whereKey($ventaId)->value('total') ?? 0);
        $devuelto = (float) Devolucion::where('venta_id', $ventaId)->sum('total_devuelto');

        return round(max(0, $total - $devuelto), 2);
    }

    /**
     * Registra una devolución y, si hay turno abierto, el egreso de caja
     * correspondiente. Si algo falla se deshace todo.
     *
     * @throws DomainException
     */
    public function registrar(
        int $ventaId,
        float $totalDevuelto,
        string $motivo,
        ?string $fecha = null,
        bool $registrarEnCaja = true
    ): Devolucion {
        $totalDevuelto = round($totalDevuelto, 2);

        if ($totalDevuelto <= 0) {
            throw new DomainException('El monto a devolver debe ser mayor a $0.');
        }

        if (trim($motivo) === '') {
            throw new DomainException('Escribe el motivo de la devolución.');
        }

        return DB::transaction(function () use ($ventaId, $totalDevuelto, $motivo, $fecha, $registrarEnCaja) {
            $venta = Venta::whereKey($ventaId)->lockForUpdate()->firstOrFail();

            $devueltoPrevio = (float) Devolucion::where('venta_id', $venta->id)->sum('total_devuelto');
            $disponible = round((float) $venta->total - $devueltoPrevio, 2);

            if ($totalDevuelto - $disponible > 0.004) {
                throw new DomainException(sprintf(
                    'No se puede devolver %s: a esa venta solo le quedan %s por devolver (total %s).',
                    Money::cents($totalDevuelto),
                    Money::cents(max(0, $disponible)),
                    Money::cents($venta->total)
                ));
            }

            $cajaId = $this->cajaAbiertaId();
            $momento = $fecha ?: now()->format('Y-m-d H:i:s');

            $devolucion = Devolucion::create([
                'venta_id' => $venta->id,
                'cliente_nombre' => $venta->cliente_nombre,
                'motivo' => mb_substr(trim($motivo), 0, 255),
                'total_devuelto' => $totalDevuelto,
                'usuario_id' => Auth::id(),
                'caja_id' => $cajaId,
                'fecha' => $momento,
            ]);

            if ($registrarEnCaja && $cajaId) {
                $movimiento = MovimientoCaja::create([
                    'caja_id' => $cajaId,
                    'tipo' => 'devolucion',
                    'metodo_pago' => $venta->metodo_pago ?: 'Efectivo',
                    'monto' => $totalDevuelto,
                    'descripcion' => 'Devolución de la venta #'.$venta->id.': '.$devolucion->motivo,
                    'fecha' => $momento,
                    'venta_id' => $venta->id,
                ]);

                $devolucion->update(['movimiento_caja_id' => $movimiento->id]);
            }

            return $devolucion->refresh();
        });
    }

    /**
     * Anula la devolución y borra el movimiento de caja que generó.
     *
     * @throws DomainException
     */
    public function anular(Devolucion $devolucion): void
    {
        DB::transaction(function () use ($devolucion) {
            if ($devolucion->movimiento_caja_id) {
                MovimientoCaja::whereKey($devolucion->movimiento_caja_id)->delete();
            }

            $devolucion->delete();
        });
    }
}
