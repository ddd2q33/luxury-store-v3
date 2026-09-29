<?php

namespace App\Support;

use App\Models\InventarioMovimiento;
use App\Models\Producto;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Unico punto de escritura de `productos.stock`.
 *
 * Cualquier modulo (ventas, compras, ingresos, ajustes) debe pasar por aqui para
 * que el stock y el ledger `inventario_movimientos` nunca queden desincronizados.
 *
 * Reglas garantizadas:
 *  - Todo cambio de stock escribe su movimiento en la MISMA transaccion.
 *  - El stock nunca baja de 0 (lanza DomainException).
 *  - El movimiento hereda la categoria del producto en el momento del cambio.
 */
class StockService
{
    /**
     * Suma unidades (compra, devolucion de proveedor, conteo manual).
     *
     * @throws DomainException
     */
    public static function entrada(int $productoId, int $cantidad, string $observaciones = '', ?string $fecha = null): Producto
    {
        if ($cantidad < 1) {
            throw new DomainException('La cantidad de ingreso debe ser al menos 1.');
        }

        return self::aplicar($productoId, InventarioMovimiento::ENTRADA, $cantidad, $observaciones, $fecha);
    }

    /**
     * Resta unidades (venta, merma, consumo interno).
     *
     * @throws DomainException si no hay stock suficiente.
     */
    public static function salida(int $productoId, int $cantidad, string $observaciones = '', ?string $fecha = null): Producto
    {
        if ($cantidad < 1) {
            throw new DomainException('La cantidad de salida debe ser al menos 1.');
        }

        // find() + DomainException, nunca findOrFail(): su ModelNotFoundException
        // es una RuntimeException y se escapa de ejecutar() dejando pantalla blanca.
        $producto = Producto::find($productoId);

        if (! $producto) {
            throw new DomainException('El producto no existe o fue eliminado.');
        }

        if ($producto->stock < $cantidad) {
            throw new DomainException(sprintf(
                'Stock insuficiente en "%s": hay %d y se intentan retirar %d.',
                $producto->nombre,
                $producto->stock,
                $cantidad
            ));
        }

        return self::aplicar($productoId, InventarioMovimiento::SALIDA, $cantidad, $observaciones, $fecha);
    }

    /**
     * Fija el stock a un valor absoluto (conteo fisico / correccion).
     * El delta queda registrado en observaciones para trazabilidad.
     *
     * @throws DomainException
     */
    public static function ajuste(int $productoId, int $nuevoStock, string $observaciones = ''): Producto
    {
        if ($nuevoStock < 0) {
            throw new DomainException('El stock no puede quedar en negativo.');
        }

        $producto = Producto::find($productoId);

        if (! $producto) {
            throw new DomainException('El producto no existe o fue eliminado.');
        }

        $delta = $nuevoStock - $producto->stock;

        if ($delta === 0) {
            throw new DomainException('El conteo coincide con el stock actual, no hay nada que ajustar.');
        }

        $nota = sprintf('Conteo físico: %d → %d (%+d)', $producto->stock, $nuevoStock, $delta);

        return self::aplicar(
            $productoId,
            InventarioMovimiento::AJUSTE,
            $nuevoStock,
            trim($nota.($observaciones !== '' ? ' · '.$observaciones : ''))
        );
    }

    /**
     * Ingreso multiple en una sola transaccion: o entra todo, o no entra nada.
     *
     * @param  array<int, array{producto_id:int, cantidad:int}>  $lineas
     * @return array{productos:int, unidades:int}
     *
     * @throws DomainException
     */
    public static function ingresoMultiple(array $lineas, string $observaciones = '', ?string $fecha = null): array
    {
        $limpias = [];

        foreach ($lineas as $i => $linea) {
            $id = (int) ($linea['producto_id'] ?? 0);
            $cantidad = (int) ($linea['cantidad'] ?? 0);

            // No se descartan lineas en silencio: una cantidad invalida debe
            // avisarle al usuario, no desaparecer del formulario.
            if ($id <= 0) {
                throw new DomainException('La línea '.($i + 1).' no tiene producto seleccionado.');
            }

            if ($cantidad < 1) {
                throw new DomainException('La línea '.($i + 1).' necesita una cantidad mayor a 0.');
            }

            $limpias[] = ['producto_id' => $id, 'cantidad' => $cantidad];
        }

        if ($limpias === []) {
            throw new DomainException('Agrega al menos un producto con cantidad mayor a 0.');
        }

        return DB::transaction(function () use ($limpias, $observaciones, $fecha) {
            $unidades = 0;

            foreach ($limpias as $linea) {
                self::entrada($linea['producto_id'], $linea['cantidad'], $observaciones, $fecha);
                $unidades += $linea['cantidad'];
            }

            return ['productos' => count($limpias), 'unidades' => $unidades];
        });
    }

    /**
     * Aplica el delta correspondiente y deja el movimiento registrado.
     * DEBE ejecutarse dentro de una transaccion.
     */
    private static function aplicar(
        int $productoId,
        string $tipo,
        int $cantidad,
        string $observaciones,
        ?string $fecha = null
    ): Producto {
        return DB::transaction(function () use ($productoId, $tipo, $cantidad, $observaciones, $fecha) {
            // lockForUpdate evita que dos cajeros descuenten el mismo saldo a la vez.
            // first() + DomainException: findOrFail aqui escapa como RuntimeException.
            $producto = Producto::where('id', $productoId)->lockForUpdate()->first();

            if (! $producto) {
                throw new DomainException('El producto no existe o fue eliminado.');
            }

            $delta = match ($tipo) {
                InventarioMovimiento::ENTRADA => $cantidad,
                InventarioMovimiento::SALIDA => -$cantidad,
                // En un Ajuste, $cantidad ya es el stock absoluto objetivo.
                InventarioMovimiento::AJUSTE => $cantidad - $producto->stock,
            };

            $nuevoStock = $producto->stock + $delta;

            // Solo se bloquea cuando el movimiento RESTA. Una entrada siempre suma,
            // asi que debe poder usarse para recuperar un saldo negativo heredado
            // del sistema viejo (sin eso, esos productos serian incorregibles).
            if ($nuevoStock < 0 && $delta < 0) {
                throw new DomainException(sprintf(
                    'Stock insuficiente en "%s": hay %d y se requieren %d.',
                    $producto->nombre,
                    $producto->stock,
                    -$delta
                ));
            }

            // UPDATE directo: la tabla no tiene updated_at.
            Producto::where('id', $producto->id)->update(['stock' => $nuevoStock]);

            InventarioMovimiento::create([
                'producto_id' => $producto->id,
                // categoria_id es FK hacia categorias: un producto sin categoria
                // NO puede escribir 0 (no existe esa fila), el insert reventaria.
                'categoria_id' => $producto->categoria_id > 0 ? $producto->categoria_id : null,
                'cantidad' => $cantidad,
                'tipo' => $tipo,
                'observaciones' => $observaciones !== '' ? $observaciones : null,
                'fecha' => $fecha ?? now()->toDateString(),
            ]);

            $producto->stock = $nuevoStock;

            return $producto;
        });
    }
}
