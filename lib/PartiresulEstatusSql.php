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
            if (self::strStartsWith($t, 'enum')
                || self::strStartsWith($t, 'varchar')
                || self::strStartsWith($t, 'char')
                || self::strStartsWith($t, 'text')) {
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
    /** Alias de tabla MySQL (ASCII o UTF-8, p. ej. pr_compañero). */
    private static function aliasTablaValido(string $alias): bool
    {
        return $alias !== '' && preg_match('/^[\p{L}_][\p{L}\p{N}_]*$/u', $alias) === 1;
    }

    public static function whereRegistradoUno(string $alias = ''): string
    {
        if ($alias !== '' && !self::aliasTablaValido($alias)) {
            throw new InvalidArgumentException('whereRegistradoUno: alias inválido');
        }
        $c = $alias === '' ? 'registrado' : $alias . '.registrado';

        return "TRIM(CAST({$c} AS CHAR)) = '1'";
    }

    /**
     * Mesa/partida sin resultado guardado (equivalente a registrado ≠ 1 completo).
     * No usar `(registrado = 0 OR registrado IS NULL)`: si la columna es VARCHAR con 'pendiente',
     * `registrado = 0` fuerza conversión a número y dispara 1292 (p. ej. al generar la siguiente ronda).
     */
    public static function whereRegistradoNoCompleto(string $alias = ''): string
    {
        if ($alias !== '' && !self::aliasTablaValido($alias)) {
            throw new InvalidArgumentException('whereRegistradoNoCompleto: alias inválido');
        }
        $c = $alias === '' ? 'registrado' : $alias . '.registrado';

        return "(COALESCE(TRIM(CAST({$c} AS CHAR)), '') <> '1')";
    }

    /**
     * Expresión SQL (en SELECT … GROUP BY mesa): 1 si todos los jugadores de la mesa tienen registrado=1.
     */
    public static function exprMesaTodosJugadoresRegistrados(string $alias = 'pr'): string
    {
        if ($alias !== '' && !self::aliasTablaValido($alias)) {
            throw new InvalidArgumentException('exprMesaTodosJugadoresRegistrados: alias inválido');
        }
        $w = self::whereRegistradoUno($alias);

        return "MIN(CASE WHEN {$w} THEN 1 ELSE 0 END)";
    }

    /**
     * Mesas de la ronda donde falta al menos un jugador con registrado=1 (misma regla que el contador «Faltan»).
     */
    public static function contarMesasIncompletas(PDO $pdo, int $torneoId, int $ronda): int
    {
        $exprOk = self::exprMesaTodosJugadoresRegistrados('pr');
        $sql = "SELECT COUNT(*) FROM (
            SELECT pr.mesa
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND CAST(pr.mesa AS SIGNED) > 0
            GROUP BY pr.mesa
            HAVING {$exprOk} = 0
        ) mesas_pendientes";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);

        return (int) $stmt->fetchColumn();
    }

    private static function strStartsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }

    /**
     * Comparación segura ff = 0 (evita 1292 si ff es VARCHAR con texto basura).
     *
     * @param string $alias Alias de tabla (ej. pr1)
     */
    public static function whereFfCero(string $alias = 'pr1'): string
    {
        if (!self::aliasTablaValido($alias)) {
            throw new InvalidArgumentException('whereFfCero: alias inválido');
        }

        return "TRIM(CAST({$alias}.ff AS CHAR)) = '0'";
    }

    /**
     * Comparación segura ff = 1 (evita 1292 si ff es VARCHAR con texto como 'pendiente').
     */
    public static function whereFfUno(string $alias = 'pr1'): string
    {
        if (!self::aliasTablaValido($alias)) {
            throw new InvalidArgumentException('whereFfUno: alias inválido');
        }

        return "TRIM(CAST({$alias}.ff AS CHAR)) = '1'";
    }

    /**
     * Subconsulta COUNT de partidas con forfait (ff = 1) por jugador/torneo (SELECT exterior con alias i).
     */
    public static function sqlSubqueryCountGffPorUsuarioTorneo(): string
    {
        return '(SELECT COUNT(*) FROM partiresul pr_gff WHERE pr_gff.id_usuario = i.id_usuario AND pr_gff.id_torneo = i.torneo_id AND '
            . self::whereFfUno('pr_gff') . ')';
    }
}
