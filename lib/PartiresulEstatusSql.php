<?php

declare(strict_types=1);

/**
 * Fragmentos SQL para partiresul.estatus según el tipo real de la columna.
 * En algunas instalaciones es TINYINT (0/1); en otras VARCHAR/ENUM ('pendiente_verificacion', 'confirmado').
 * Mezclar tipos provoca 1292 o comparaciones incorrectas.
 */
final class PartiresulEstatusSql
{
    private static ?bool $numeric = null;

    public static function resetCacheForTests(): void
    {
        self::$numeric = null;
    }

    public static function estatusColumnIsNumeric(PDO $pdo): bool
    {
        if (self::$numeric !== null) {
            return self::$numeric;
        }
        self::$numeric = true;
        try {
            $st = $pdo->query("SHOW COLUMNS FROM partiresul WHERE Field = 'estatus'");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row || empty($row['Type'])) {
                return self::$numeric;
            }
            $t = strtolower((string) $row['Type']);
            if (str_starts_with($t, 'enum')
                || str_starts_with($t, 'varchar')
                || str_starts_with($t, 'char')
                || str_starts_with($t, 'text')) {
                self::$numeric = false;
            }
        } catch (Throwable $e) {
            self::$numeric = true;
        }

        return self::$numeric;
    }

    /**
     * Condición WHERE con alias de tabla (ej. pr.estatus = …).
     */
    public static function qualifiedWherePendienteVerificacion(PDO $pdo, string $alias = 'pr'): string
    {
        $a = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) ? $alias : 'pr';

        return self::estatusColumnIsNumeric($pdo)
            ? "{$a}.estatus = 0"
            : "{$a}.estatus = 'pendiente_verificacion'";
    }

    public static function wherePendienteVerificacionSinAlias(PDO $pdo): string
    {
        return self::estatusColumnIsNumeric($pdo)
            ? 'estatus = 0'
            : "estatus = 'pendiente_verificacion'";
    }

    public static function setEstatusConfirmadoFragment(PDO $pdo): string
    {
        return self::estatusColumnIsNumeric($pdo) ? 'estatus = 1' : "estatus = 'confirmado'";
    }

    public static function setEstatusPendienteVerificacionFragment(PDO $pdo): string
    {
        return self::estatusColumnIsNumeric($pdo) ? 'estatus = 0' : "estatus = 'pendiente_verificacion'";
    }

    /**
     * Valor para INSERT/UPDATE con placeholder ? (entero o cadena según columna).
     *
     * @return int|string
     */
    public static function estatusValorParaPersistencia(PDO $pdo, bool $confirmado)
    {
        return self::estatusColumnIsNumeric($pdo)
            ? ($confirmado ? 1 : 0)
            : ($confirmado ? 'confirmado' : 'pendiente_verificacion');
    }

    public static function valueIsPendienteVerificacion(mixed $val, PDO $pdo): bool
    {
        if (self::estatusColumnIsNumeric($pdo)) {
            return (int) $val === 0;
        }
        $s = strtolower(trim((string) $val));

        return $s === 'pendiente_verificacion';
    }

    public static function valueIsConfirmado(mixed $val, PDO $pdo): bool
    {
        if (self::estatusColumnIsNumeric($pdo)) {
            return (int) $val === 1;
        }
        $s = strtolower(trim((string) $val));

        return $s === 'confirmado';
    }

    /**
     * partiresul.registrado: no usar `registrado = 1` si la columna puede ser VARCHAR.
     * En modo estricto MySQL convierte el valor a número y cadenas como 'pendiente' provocan 1292.
     * Comparación solo como texto: '1' coincide con TINYINT 1 y CHAR '1'.
     *
     * @param string $alias Alias de tabla (ej. pr, pr1) o vacío para columna suelta
     */
    public static function whereRegistradoUno(string $alias = ''): string
    {
        if ($alias !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('whereRegistradoUno: alias inválido');
        }
        $c = $alias === '' ? 'registrado' : $alias . '.registrado';

        return "TRIM(CAST({$c} AS CHAR)) = '1'";
    }

    /**
     * Comparación segura ff = 0 (evita 1292 si ff es VARCHAR con texto basura).
     *
     * @param string $alias Alias de tabla (ej. pr1)
     */
    public static function whereFfCero(string $alias = 'pr1'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('whereFfCero: alias inválido');
        }

        return "TRIM(CAST({$alias}.ff AS CHAR)) = '0'";
    }
}
