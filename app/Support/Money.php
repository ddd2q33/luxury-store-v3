<?php

namespace App\Support;

/**
 * Formato de moneda/números heredado del sistema anterior (estilo colombiano:
 * punto como separador de miles, sin decimales).
 */
class Money
{
    public static function format(float|int|null $valor): string
    {
        return '$' . number_format((float) $valor, 0, ',', '.');
    }

    public static function number(float|int|null $valor): string
    {
        return number_format((float) $valor, 0, ',', '.');
    }

    /**
     * Con centavos. Para montos que en la BD son decimal(20,2): deudas, abonos,
     * facturas y saldos, donde redondear a entero esconde diferencias reales.
     */
    public static function cents(float|int|null $valor): string
    {
        return '$' . number_format((float) $valor, 2, ',', '.');
    }
}
