<?php
declare(strict_types=1);

/**
 * Escisión permanente de un subconjunto de equipos hacia un nuevo registro en `tournaments`:
 * migración atómica de equipos, inscritos y resultados asociados para que el torneo original
 * quede sin esos equipos y pueda recalcularse de forma independiente.
 */
final class TorneoSegmentacionService
{
    /**
     * @param list<int> $idsEquiposSeleccionados IDs de filas en `equipos.id`
     *
     * @return array{
     *   ok: bool,
     *   id_torneo_nuevo: int|null,
     *   errores: list<string>,
     *   equipos_movidos: int,
     *   inscritos_movidos: int,
     *   partiresul_movidos: int
     * }
     */
    public static function segmentarTorneoEquipos(PDO $pdo, int $idTorneoOriginal, array $idsEquiposSeleccionados, string $nombreNuevoGrupo): array
    {
        $out = [
            'ok' => false,
            'id_torneo_nuevo' => null,
            'errores' => [],
            'equipos_movidos' => 0,
            'inscritos_movidos' => 0,
            'partiresul_movidos' => 0,
        ];

        $nombreNuevoGrupo = trim($nombreNuevoGrupo);
        if ($idTorneoOriginal <= 0) {
            $out['errores'][] = 'Torneo original inválido.';

            return $out;
        }
        if ($nombreNuevoGrupo === '') {
            $out['errores'][] = 'Indique un nombre para el nuevo torneo (grupo segmentado).';

            return $out;
        }

        $idsEquiposSeleccionados = array_values(array_unique(array_filter(array_map(static fn ($x) => (int) $x, $idsEquiposSeleccionados), static fn ($x) => $x > 0)));
        if ($idsEquiposSeleccionados === []) {
            $out['errores'][] = 'Seleccione al menos un equipo a segmentar.';

            return $out;
        }

        $stT = $pdo->prepare('SELECT id, modalidad FROM tournaments WHERE id = ? LIMIT 1');
        $stT->execute([$idTorneoOriginal]);
        $torneoRow = $stT->fetch(PDO::FETCH_ASSOC);
        if (! $torneoRow) {
            $out['errores'][] = 'Torneo original no encontrado.';

            return $out;
        }
        $mod = (int) ($torneoRow['modalidad'] ?? 0);
        if (! in_array($mod, [2, 3, 4], true)) {
            $out['errores'][] = 'La segmentación solo aplica a modalidades por equipos / parejas (2, 3 o 4).';

            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($idsEquiposSeleccionados), '?'));
        $stE = $pdo->prepare(
            "SELECT id, id_torneo, codigo_equipo FROM equipos WHERE id IN ($placeholders)"
        );
        $stE->execute($idsEquiposSeleccionados);
        $filasEq = $stE->fetchAll(PDO::FETCH_ASSOC);
        if (count($filasEq) !== count($idsEquiposSeleccionados)) {
            $out['errores'][] = 'Uno o más IDs de equipo no existen.';

            return $out;
        }
        foreach ($filasEq as $fe) {
            if ((int) ($fe['id_torneo'] ?? 0) !== $idTorneoOriginal) {
                $out['errores'][] = 'Todos los equipos deben pertenecer al torneo original seleccionado.';

                return $out;
            }
        }

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM equipos WHERE id_torneo = ? AND estatus = 0');
        $stCount->execute([$idTorneoOriginal]);
        $totalEquiposActivos = (int) $stCount->fetchColumn();
        if ($totalEquiposActivos <= count($idsEquiposSeleccionados)) {
            $out['errores'][] = 'Debe quedar al menos un equipo en el torneo original (no segmente todos).';

            return $out;
        }

        $codigos = [];
        foreach ($filasEq as $fe) {
            $c = trim((string) ($fe['codigo_equipo'] ?? ''));
            if ($c !== '') {
                $codigos[] = $c;
            }
        }
        $codigos = array_values(array_unique($codigos));
        if ($codigos === []) {
            $out['errores'][] = 'Los equipos seleccionados no tienen código de equipo válido.';

            return $out;
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $out['errores'][] = 'La segmentación permanente de torneo no está disponible en SQLite; use MySQL/MariaDB.';

            return $out;
        }

        $pdo->beginTransaction();
        try {
            $idNuevo = self::insertarClonTorneo($pdo, $idTorneoOriginal, $nombreNuevoGrupo);
            if ($idNuevo <= 0) {
                throw new RuntimeException('No se pudo crear el torneo clonado (ID no generado).');
            }
            $chk = $pdo->prepare('SELECT id FROM tournaments WHERE id = ? LIMIT 1');
            $chk->execute([$idNuevo]);
            if (! $chk->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('El torneo clonado no quedó registrado correctamente.');
            }

            $stUpEq = $pdo->prepare(
                "UPDATE equipos SET id_torneo = ? WHERE id_torneo = ? AND id IN ($placeholders)"
            );
            $paramsEq = array_merge([$idNuevo, $idTorneoOriginal], $idsEquiposSeleccionados);
            $stUpEq->execute($paramsEq);
            $out['equipos_movidos'] = $stUpEq->rowCount();

            $phCod = implode(',', array_fill(0, count($codigos), '?'));
            $paramsIns = array_merge([$idNuevo, $idTorneoOriginal], $codigos);
            $stUpI = $pdo->prepare(
                "UPDATE inscritos SET torneo_id = ? WHERE torneo_id = ? AND codigo_equipo IN ($phCod)"
            );
            $stUpI->execute($paramsIns);
            $out['inscritos_movidos'] = $stUpI->rowCount();

            // partiresul: fuente de verdad = inscritos ya migrados al torneo nuevo (mismo id_usuario, filas aún en id_torneo origen)
            $stPr = $pdo->prepare(
                'UPDATE partiresul pr
                 INNER JOIN inscritos i ON i.id_usuario = pr.id_usuario AND i.torneo_id = ?
                 SET pr.id_torneo = ?
                 WHERE pr.id_torneo = ?'
            );
            $stPr->execute([$idNuevo, $idNuevo, $idTorneoOriginal]);
            $out['partiresul_movidos'] = $stPr->rowCount();

            try {
                $stMa = $pdo->prepare(
                    'UPDATE mesas_asignacion ma
                     INNER JOIN inscritos i ON i.id_usuario = ma.id_usuario AND i.torneo_id = ?
                     SET ma.tournament_id = ?
                     WHERE ma.tournament_id = ?'
                );
                $stMa->execute([$idNuevo, $idNuevo, $idTorneoOriginal]);
            } catch (Throwable $e) {
                // tabla opcional
            }

            try {
                // Historial de parejas: solo si ambos jugadores quedaron inscritos en el torneo nuevo
                $stHp = $pdo->prepare(
                    'UPDATE historial_parejas hp
                     SET hp.torneo_id = ?
                     WHERE hp.torneo_id = ?
                       AND hp.jugador_1_id IN (SELECT id_usuario FROM inscritos WHERE torneo_id = ?)
                       AND hp.jugador_2_id IN (SELECT id_usuario FROM inscritos WHERE torneo_id = ?)'
                );
                $stHp->execute([$idNuevo, $idTorneoOriginal, $idNuevo, $idNuevo]);
            } catch (Throwable $e) {
                // tabla opcional / motor distinto
            }

            try {
                require_once dirname(__DIR__) . '/lib/TorneoSplitRankingService.php';
                TorneoSplitRankingService::limpiarSplit($pdo, $idTorneoOriginal);
                TorneoSplitRankingService::limpiarSplit($pdo, $idNuevo);
            } catch (Throwable $e) {
                // no bloquear segmentación
            }

            $pdo->commit();
            $out['ok'] = true;
            $out['id_torneo_nuevo'] = $idNuevo;

            try {
                self::recalcularTrasSegmentacion($idTorneoOriginal, $idNuevo);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('TorneoSegmentacionService: recalcular tras segmentación: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['errores'][] = $e->getMessage();
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $out['errores'][] = $e->getFile() . ':' . $e->getLine();
            }
        }

        return $out;
    }

    private static function recalcularTrasSegmentacion(int $idOriginal, int $idNuevo): void
    {
        if (! defined('TORNEO_GESTION_SKIP_AUTH')) {
            define('TORNEO_GESTION_SKIP_AUTH', true);
        }
        if (! defined('TORNEO_GESTION_SKIP_ROUTER')) {
            define('TORNEO_GESTION_SKIP_ROUTER', true);
        }
        require_once dirname(__DIR__) . '/modules/torneo_gestion.php';
        if (function_exists('recalcularClasificacionEquiposYJugadores')) {
            recalcularClasificacionEquiposYJugadores($idOriginal);
            recalcularClasificacionEquiposYJugadores($idNuevo);
        }
    }

    /**
     * Inserta una fila en `tournaments` copiando todas las columnas salvo `id`, con nombre nuevo y slug anulado.
     */
    private static function insertarClonTorneo(PDO $pdo, int $idOrigen, string $nombreNuevo): int
    {
        $cols = self::columnasTournamentsSinId($pdo);
        if ($cols === []) {
            throw new RuntimeException('No se pudieron leer columnas de tournaments.');
        }

        $selectParts = [];
        $params = [];
        foreach ($cols as $c) {
            if ($c === 'nombre') {
                $selectParts[] = '?';
                $params[] = $nombreNuevo;
            } elseif ($c === 'slug') {
                $selectParts[] = 'NULL';
            } else {
                $selectParts[] = 't.`' . str_replace('`', '``', $c) . '`';
            }
        }
        $params[] = $idOrigen;

        $colList = '`' . implode('`,`', array_map(static function ($c) {
            return str_replace('`', '``', $c);
        }, $cols)) . '`';
        $sql = "INSERT INTO `tournaments` ($colList) SELECT " . implode(',', $selectParts) . ' FROM `tournaments` t WHERE t.`id` = ?';

        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<string>
     */
    private static function columnasTournamentsSinId(PDO $pdo): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $cols = [];
        if ($driver === 'sqlite') {
            $r = $pdo->query('PRAGMA table_info(tournaments)');
            if ($r) {
                while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                    $n = (string) ($row['name'] ?? '');
                    if ($n !== '' && strtolower($n) !== 'id') {
                        $cols[] = $n;
                    }
                }
            }

            return $cols;
        }

        $r = $pdo->query('SHOW COLUMNS FROM tournaments');
        if (! $r) {
            return [];
        }
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            $f = (string) ($row['Field'] ?? '');
            if ($f !== '' && strtolower($f) !== 'id') {
                $cols[] = $f;
            }
        }

        return $cols;
    }
}
