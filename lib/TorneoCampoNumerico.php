<?php

declare(strict_types=1);

/**
 * Motor numérico unificado (Individual, Parejas, Equipos, QR, desktop).
 * Normaliza hacia columnas INT/DOUBLE en torneos (resultados, sanciones, tarjetas).
 * Cualquier texto no numérico o marcador legacy ('pendiente', etc.) → 0 (mismo criterio en toda la app).
 */
final class TorneoCampoNumerico
{
    public static function intEstadistica(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) round($v);
        }
        $s = trim(strtolower((string) $v));
        if ($s === '' || $s === 'pendiente' || $s === 'null' || $s === 'n/a' || $s === '-') {
            return 0;
        }
        if (is_numeric($s)) {
            return (int) round((float) $s);
        }

        return 0;
    }

    /**
     * Valor para aritmética (restas de sanción, efectividad): mismo saneamiento que intEstadistica.
     */
    public static function floatCalculo(mixed $v): float
    {
        return (float) self::intEstadistica($v);
    }

    /**
     * Códigos de tarjeta en partiresul/inscritos: 0 ninguna, 1 amarilla, 3 roja, 4 negra.
     * Cualquier otro número o texto → 0.
     */
    public static function codigoTarjeta(mixed $v): int
    {
        $n = self::intEstadistica($v);

        return in_array($n, [0, 1, 3, 4], true) ? $n : 0;
    }
}
