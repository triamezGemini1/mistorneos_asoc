<?php
declare(strict_types=1);

/**
 * Clasificación en dos bloques dentro del mismo torneo (equipos/parejas):
 * grupo A = equipos marcados; grupo B = resto. Posiciones y clasiequi 1..n en cada bloque;
 * recalcula inscritos.posicion y ptosrnk por bloque.
 *
 * Requiere tablas torneo_equipo_split (SQL en sql/sql/create_torneo_equipo_split.sql).
 */
final class TorneoSplitRankingService
{
    public static function tablaExiste(PDO $pdo): bool
    {
        try {
            $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='torneo_equipo_split' LIMIT 1");

                return $st && $st->fetch() !== false;
            }
            $st = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'torneo_equipo_split' LIMIT 1");

            return $st && (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function ensureTabla(PDO $pdo): void
    {
        if (self::tablaExiste($pdo)) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `torneo_equipo_split` (
              `torneo_id` INT UNSIGNED NOT NULL,
              `codigo_equipo` VARCHAR(20) NOT NULL,
              `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`torneo_id`, `codigo_equipo`),
              KEY `idx_torneo_equipo_split_torneo` (`torneo_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return list<string>
     */
    public static function obtenerCodigosGrupoA(PDO $pdo, int $torneoId): array
    {
        if (! self::tablaExiste($pdo) || $torneoId <= 0) {
            return [];
        }
        $st = $pdo->prepare('SELECT codigo_equipo FROM torneo_equipo_split WHERE torneo_id = ? ORDER BY codigo_equipo ASC');
        $st->execute([$torneoId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $c = trim((string) ($row['codigo_equipo'] ?? ''));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $codigosGrupoA
     */
    public static function guardarGrupoA(PDO $pdo, int $torneoId, array $codigosGrupoA): void
    {
        self::ensureTabla($pdo);
        $pdo->prepare('DELETE FROM torneo_equipo_split WHERE torneo_id = ?')->execute([$torneoId]);
        $ins = $pdo->prepare('INSERT INTO torneo_equipo_split (torneo_id, codigo_equipo) VALUES (?, ?)');
        foreach ($codigosGrupoA as $c) {
            $c = trim((string) $c);
            if ($c === '') {
                continue;
            }
            $ins->execute([$torneoId, $c]);
        }
    }

    public static function limpiarSplit(PDO $pdo, int $torneoId): void
    {
        if (! self::tablaExiste($pdo)) {
            return;
        }
        $pdo->prepare('DELETE FROM torneo_equipo_split WHERE torneo_id = ?')->execute([$torneoId]);
    }

    private static function cargarFuncionesTorneoGestion(): void
    {
        if (! function_exists('actualizarEstadisticasEquipos')) {
            if (! defined('TORNEO_GESTION_SKIP_AUTH')) {
                define('TORNEO_GESTION_SKIP_AUTH', true);
            }
            if (! defined('TORNEO_GESTION_SKIP_ROUTER')) {
                define('TORNEO_GESTION_SKIP_ROUTER', true);
            }
            require_once dirname(__DIR__) . '/modules/torneo_gestion.php';
        }
    }

    /**
     * @param list<string> $codigosGrupoA
     *
     * @return array{ok: bool, errores: list<string>, equipos_grupo_a: int, equipos_grupo_b: int}
     */
    public static function aplicarClasificacionDosBloques(PDO $pdo, int $torneoId, array $codigosGrupoA): array
    {
        require_once __DIR__ . '/InscritosHelper.php';
        require_once __DIR__ . '/ClasirankingRankingHelper.php';

        $out = ['ok' => false, 'errores' => [], 'equipos_grupo_a' => 0, 'equipos_grupo_b' => 0];

        if ($torneoId <= 0) {
            $out['errores'][] = 'Torneo inválido.';

            return $out;
        }

        $st = $pdo->prepare('SELECT id, modalidad FROM tournaments WHERE id = ?');
        $st->execute([$torneoId]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
        if (! $torneo) {
            $out['errores'][] = 'Torneo no encontrado.';

            return $out;
        }
        $mod = (int) ($torneo['modalidad'] ?? 0);
        if (! in_array($mod, [2, 3, 4], true)) {
            $out['errores'][] = 'Esta herramienta solo aplica a modalidades por equipos / parejas (2, 3 o 4).';

            return $out;
        }

        $codigosGrupoA = array_values(array_unique(array_filter(array_map(static fn ($x) => trim((string) $x), $codigosGrupoA))));
        if ($codigosGrupoA === []) {
            $out['errores'][] = 'Seleccione al menos un equipo para el bloque separado (grupo A).';

            return $out;
        }

        self::ensureTabla($pdo);
        self::guardarGrupoA($pdo, $torneoId, $codigosGrupoA);

        self::cargarFuncionesTorneoGestion();
        actualizarEstadisticasEquipos($torneoId);

        $setA = array_flip($codigosGrupoA);
        $og = InscritosHelper::sqlExprColumnaNumerica('ganados');
        $oe = InscritosHelper::sqlExprColumnaNumerica('efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('puntos');
        $ope = InscritosHelper::sqlExprColumnaNumerica('perdidos');

        $stmtEq = $pdo->prepare(
            "SELECT codigo_equipo, puntos, ganados, perdidos, efectividad FROM equipos
             WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != ''
             ORDER BY $og DESC, $oe DESC, $op DESC, $ope ASC, codigo_equipo ASC"
        );
        $stmtEq->execute([$torneoId]);
        $filasEq = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
        $listaA = [];
        $listaB = [];
        foreach ($filasEq as $row) {
            $c = trim((string) ($row['codigo_equipo'] ?? ''));
            if ($c === '') {
                continue;
            }
            if (isset($setA[$c])) {
                $listaA[] = $row;
            } else {
                $listaB[] = $row;
            }
        }
        if ($listaA === []) {
            $out['errores'][] = 'Ningún código de equipo coincide con equipos activos del torneo.';

            return $out;
        }
        if ($listaB === []) {
            $out['errores'][] = 'Debe quedar al menos un equipo en el bloque complementario (no marque todos).';

            return $out;
        }

        $out['equipos_grupo_a'] = count($listaA);
        $out['equipos_grupo_b'] = count($listaB);

        $updEq = $pdo->prepare('UPDATE equipos SET posicion = ? WHERE id_torneo = ? AND codigo_equipo = ?');
        $updClasi = $pdo->prepare(
            'UPDATE inscritos i SET i.clasiequi = ? WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND ' . InscritosHelper::sqlWhereActivoConAlias('i')
        );

        $pos = 1;
        foreach ($listaA as $eq) {
            $cod = (string) $eq['codigo_equipo'];
            $updEq->execute([$pos, $torneoId, $cod]);
            $updClasi->execute([$pos, $torneoId, $cod]);
            $pos++;
        }
        $pos = 1;
        foreach ($listaB as $eq) {
            $cod = (string) $eq['codigo_equipo'];
            $updEq->execute([$pos, $torneoId, $cod]);
            $updClasi->execute([$pos, $torneoId, $cod]);
            $pos++;
        }

        self::recalcularPosicionesInscritosDosBloquesInterno($pdo, $torneoId, $setA, $mod);

        asignarNumeroSecuencialPorEquipo($torneoId);

        $out['ok'] = true;

        return $out;
    }

    /**
     * @param array<string, true> $setA
     */
    private static function recalcularPosicionesInscritosDosBloquesInterno(PDO $pdo, int $torneoId, array $setA, int $modalidadNum): void
    {
        $rg = InscritosHelper::sqlExprColumnaNumerica('ganados');
        $re = InscritosHelper::sqlExprColumnaNumerica('efectividad');
        $rp = InscritosHelper::sqlExprColumnaNumerica('puntos');

        $stmt = $pdo->prepare(
            "SELECT id, id_usuario, codigo_equipo, clasiequi,
                    $rg as ganados, $re as efectividad, $rp as puntos
             FROM inscritos
             WHERE torneo_id = ? AND " . InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO . "
             ORDER BY $rg DESC, $re DESC, $rp DESC"
        );
        $stmt->execute([$torneoId]);
        $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($todos === []) {
            return;
        }

        $bloqueA = [];
        $bloqueB = [];
        foreach ($todos as $row) {
            $cod = trim((string) ($row['codigo_equipo'] ?? ''));
            if ($cod !== '' && isset($setA[$cod])) {
                $bloqueA[] = $row;
            } else {
                $bloqueB[] = $row;
            }
        }

        if (in_array($modalidadNum, [2, 4], true)) {
            $ganadosEquipoPorCodigo = [];
            $stmtEqG = $pdo->prepare(
                "SELECT codigo_equipo, ganados FROM equipos WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != ''"
            );
            $stmtEqG->execute([$torneoId]);
            while ($rowEq = $stmtEqG->fetch(PDO::FETCH_ASSOC)) {
                $cod = trim((string) ($rowEq['codigo_equipo'] ?? ''));
                if ($cod !== '') {
                    $ganadosEquipoPorCodigo[$cod] = (int) ($rowEq['ganados'] ?? 0);
                }
            }
        } else {
            $ganadosEquipoPorCodigo = [];
        }

        if (in_array($modalidadNum, [2, 4], true)) {
            $tipoTorneoClasi = 2;
        } elseif ($modalidadNum === 3) {
            $tipoTorneoClasi = 3;
        } else {
            $tipoTorneoClasi = 1;
        }
        $limitePosiciones = ($tipoTorneoClasi === 2) ? 20 : (($tipoTorneoClasi === 3) ? 10 : 30);

        $existeClasiRanking = false;
        try {
            $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $stmtCk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clasiranking' LIMIT 1");
                $existeClasiRanking = ($stmtCk && $stmtCk->fetch() !== false);
            } else {
                $stmtCk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'clasiranking' LIMIT 1");
                $existeClasiRanking = ($stmtCk && $stmtCk->fetch() !== false);
            }
        } catch (Throwable $e) {
            $existeClasiRanking = false;
        }

        $puntosPorPartidaGanadaFuera = null;
        $puntosAsistenciaFuera = 1;
        if ($existeClasiRanking && $limitePosiciones >= 1) {
            try {
                $stmtF = $pdo->prepare(
                    'SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia
                     FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1'
                );
                $stmtF->execute([$tipoTorneoClasi, $limitePosiciones]);
                $rf = $stmtF->fetch(PDO::FETCH_ASSOC);
                if ($rf) {
                    $puntosPorPartidaGanadaFuera = (int) ($rf['puntos_por_partida_ganada'] ?? 0);
                    $puntosAsistenciaFuera = (int) ($rf['puntos_asistencia'] ?? 1);
                }
            } catch (Throwable $e) {
                try {
                    $stmtF = $pdo->prepare('SELECT puntos_por_partida_ganada FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1');
                    $stmtF->execute([$tipoTorneoClasi, $limitePosiciones]);
                    $rf = $stmtF->fetch(PDO::FETCH_ASSOC);
                    if ($rf) {
                        $puntosPorPartidaGanadaFuera = (int) ($rf['puntos_por_partida_ganada'] ?? 0);
                    }
                } catch (Throwable $e2) {
                }
            }
        }
        if ($existeClasiRanking && $puntosPorPartidaGanadaFuera === null) {
            try {
                $stmtF2 = $pdo->prepare(
                    'SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia FROM clasiranking WHERE tipo_torneo = ? ORDER BY clasificacion DESC LIMIT 1'
                );
                $stmtF2->execute([$tipoTorneoClasi]);
                $rf2 = $stmtF2->fetch(PDO::FETCH_ASSOC);
                if ($rf2) {
                    $puntosPorPartidaGanadaFuera = (int) ($rf2['puntos_por_partida_ganada'] ?? 0);
                    $puntosAsistenciaFuera = (int) ($rf2['puntos_asistencia'] ?? 1);
                }
            } catch (Throwable $e) {
            }
        }
        $pppFuera = (int) ($puntosPorPartidaGanadaFuera ?? 0);
        $pasFuera = max(1, (int) ($puntosAsistenciaFuera ?? 1));

        $pdo->prepare('UPDATE inscritos SET posicion = 0 WHERE torneo_id = ?')->execute([$torneoId]);

        $stmtUpdate = $pdo->prepare('UPDATE inscritos SET posicion = ?, ptosrnk = ? WHERE id = ?');
        $rankingPorClasificacion = [];

        $procesarBloque = static function (array $inscritosBloque) use (
            $stmtUpdate,
            $modalidadNum,
            $ganadosEquipoPorCodigo,
            $tipoTorneoClasi,
            $limitePosiciones,
            $existeClasiRanking,
            $pdo,
            &$rankingPorClasificacion,
            $pppFuera,
            $pasFuera
        ): void {
            $posicion = 1;
            foreach ($inscritosBloque as $inscrito) {
                $id = (int) $inscrito['id'];
                $ganados = (int) ($inscrito['ganados'] ?? 0);
                $codEq = trim((string) ($inscrito['codigo_equipo'] ?? ''));
                if (in_array($modalidadNum, [2, 4], true) && $codEq !== '' && array_key_exists($codEq, $ganadosEquipoPorCodigo)) {
                    $ganados = $ganadosEquipoPorCodigo[$codEq];
                }
                $clasiequi = (int) ($inscrito['clasiequi'] ?? 0);
                $clasificacionRanking = $posicion;
                if (in_array($modalidadNum, [2, 3, 4], true) && $clasiequi > 0) {
                    $clasificacionRanking = $clasiequi;
                }
                $ptosrnk = $pasFuera;
                if ($existeClasiRanking && $clasificacionRanking >= 1 && $clasificacionRanking <= $limitePosiciones) {
                    if (! array_key_exists($clasificacionRanking, $rankingPorClasificacion)) {
                        $rankingPorClasificacion[$clasificacionRanking] = ClasirankingRankingHelper::obtenerFilaParaClasificacion(
                            $pdo,
                            $tipoTorneoClasi,
                            $clasificacionRanking,
                            $limitePosiciones
                        );
                    }
                    $ranking = $rankingPorClasificacion[$clasificacionRanking];
                    if ($ranking) {
                        $ptosrnk = (int) $ranking['puntos_posicion'] + ($ganados * (int) $ranking['puntos_por_partida_ganada']) + (int) $ranking['puntos_asistencia'];
                    } else {
                        $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
                    }
                } elseif ($existeClasiRanking && $clasificacionRanking > $limitePosiciones) {
                    $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
                } elseif ($existeClasiRanking) {
                    $ptosrnk = ($ganados * $pppFuera) + $pasFuera;
                }
                $stmtUpdate->execute([$posicion, $ptosrnk, $id]);
                $posicion++;
            }
        };

        $procesarBloque($bloqueA);
        $procesarBloque($bloqueB);

        try {
            $pdo->prepare('UPDATE inscritos SET ptosrnk = 0 WHERE torneo_id = ? AND (estatus = 4 OR estatus = \'retirado\')')->execute([$torneoId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Quita el split y ejecuta la clasificación global habitual.
     */
    public static function restaurarClasificacionUnica(PDO $pdo, int $torneoId): array
    {
        $out = ['ok' => false, 'errores' => []];
        if ($torneoId <= 0) {
            $out['errores'][] = 'Torneo inválido.';

            return $out;
        }
        self::limpiarSplit($pdo, $torneoId);
        self::cargarFuncionesTorneoGestion();
        if (function_exists('recalcularClasificacionEquiposYJugadores')) {
            recalcularClasificacionEquiposYJugadores($torneoId);
        }
        $out['ok'] = true;

        return $out;
    }
}
