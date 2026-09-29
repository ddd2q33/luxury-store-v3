<?php

namespace App\Support;

use App\Models\AbonoProveedor;
use App\Models\HistorialDeudaProveedor;
use App\Models\Proveedor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use DomainException;

/**
 * Unico punto que muta proveedores.saldo_deuda.
 *
 * Igual que StockService con productos.stock: si se edita el saldo a mano desde
 * un formulario, el saldo y su historial se descuadran.
 *
 * Decisiones de diseno:
 *  - 'deuda' NO se toca. La BD tiene saldo_deuda y deuda; el sistema viejo solo
 *    usaba saldo_deuda, y deuda quedo huerfana.
 *  - Los abonos se auditan en abonos_proveedores (que ya guarda deuda_anterior
 *    y deuda_nueva). historial_deuda_proveedores tiene un enum de solo
 *    'saldo_inicial' y 'ajuste', asi que NO se le meten abonos.
 */
class DeudaProveedorService
{
    /**
     * Registra un abono (pago al proveedor) y descuenta la deuda.
     *
     * @throws DomainException si el monto es invalido o excede la deuda.
     */
    public function registrarAbono(
        Proveedor $proveedor,
        float $monto,
        ?string $referencia = null,
        ?string $fecha = null,
        ?int $usuarioId = null
    ): AbonoProveedor {
        $monto = round($monto, 2);

        if ($monto <= 0) {
            throw new DomainException('El monto del abono debe ser mayor a $0.');
        }

        return DB::transaction(function () use ($proveedor, $monto, $referencia, $fecha, $usuarioId) {
            $p = Proveedor::whereKey($proveedor->id)->lockForUpdate()->firstOrFail();

            $deudaAntes = (float) ($p->saldo_deuda ?? 0);

            if ($monto - $deudaAntes > 0.004) {
                throw new DomainException(sprintf(
                    'El abono supera la deuda: %s es mayor al saldo pendiente de %s.',
                    Money::cents($monto),
                    Money::cents($deudaAntes)
                ));
            }

            $deudaNueva = round(max(0, $deudaAntes - $monto), 2);

            $abono = AbonoProveedor::create([
                'proveedor_id' => $p->id,
                'monto' => $monto,
                'deuda_anterior' => $deudaAntes,
                'deuda_nueva' => $deudaNueva,
                'fecha_abono' => $fecha ?: now()->format('Y-m-d H:i:s'),
                'referencia' => $referencia,
                'usuario_id' => $usuarioId ?: Auth::id(),
            ]);

            Proveedor::whereKey($p->id)->update(['saldo_deuda' => $deudaNueva]);

            return $abono;
        });
    }

    /**
     * Anula un abono y devuelve el monto a la deuda del proveedor.
     */
    public function anularAbono(AbonoProveedor $abono): Proveedor
    {
        return DB::transaction(function () use ($abono) {
            $p = Proveedor::whereKey($abono->proveedor_id)->lockForUpdate()->firstOrFail();

            $deudaAntes = (float) ($p->saldo_deuda ?? 0);
            $deudaNueva = round($deudaAntes + (float) $abono->monto, 2);

            $abono->delete();
            Proveedor::whereKey($p->id)->update(['saldo_deuda' => $deudaNueva]);

            return $p->refresh();
        });
    }

    /**
     * Ajuste manual de deuda (correccion de saldos). Queda auditado como 'ajuste'.
     */
    public function ajustarDeuda(Proveedor $proveedor, float $nuevoSaldo, string $razon): Proveedor
    {
        $nuevoSaldo = round($nuevoSaldo, 2);

        if ($nuevoSaldo < 0) {
            throw new DomainException('El saldo de deuda no puede quedar negativo.');
        }

        if (trim($razon) === '') {
            throw new DomainException('Escribe el motivo del ajuste.');
        }

        return DB::transaction(function () use ($proveedor, $nuevoSaldo, $razon) {
            $p = Proveedor::whereKey($proveedor->id)->lockForUpdate()->firstOrFail();

            $anterior = (float) ($p->saldo_deuda ?? 0);

            $this->registrarHistorial(
                $p->id,
                'ajuste',
                $anterior,
                $nuevoSaldo,
                $nuevoSaldo - $anterior,
                $razon
            );

            Proveedor::whereKey($p->id)->update(['saldo_deuda' => $nuevoSaldo]);

            return $p->refresh();
        });
    }

    /**
     * Crea un proveedor con deuda inicial y deja el asiento 'saldo_inicial'.
     * Se llama dentro de la misma transaccion del alta.
     */
    public function crearConDeudaInicial(array $datos, float $deudaInicial): Proveedor
    {
        $deudaInicial = round($deudaInicial, 2);

        if ($deudaInicial < 0) {
            throw new DomainException('La deuda inicial no puede ser negativa.');
        }

        return DB::transaction(function () use ($datos, $deudaInicial) {
            $proveedor = Proveedor::create($datos);

            if ($deudaInicial > 0) {
                Proveedor::whereKey($proveedor->id)->update(['saldo_deuda' => $deudaInicial]);

                $this->registrarHistorial(
                    $proveedor->id,
                    'saldo_inicial',
                    0,
                    $deudaInicial,
                    $deudaInicial,
                    'Deuda registrada al crear el proveedor'
                );
            }

            return $proveedor->refresh();
        });
    }

    private function registrarHistorial(
        int $proveedorId,
        string $tipo,
        float $anterior,
        float $nuevo,
        float $diferencia,
        string $razon
    ): void {
        HistorialDeudaProveedor::create([
            'proveedor_id' => $proveedorId,
            'tipo_movimiento' => $tipo,
            'monto_anterior' => $anterior,
            'monto_nuevo' => $nuevo,
            'diferencia' => $diferencia,
            'razon' => mb_substr(trim($razon), 0, 255),
            'fecha_registro' => now()->format('Y-m-d H:i:s'),
            'usuario_id' => Auth::id(),
        ]);
    }
}
