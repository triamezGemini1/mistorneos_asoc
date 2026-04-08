<?php

declare(strict_types=1);

/**
 * Normaliza valores hacia columnas INT/DOUBLE en torneos (resultados, estadísticas).
 * Evita persistir cadenas como 'pendiente' que rompen MySQL en modo estricto.
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
}
