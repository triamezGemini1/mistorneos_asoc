<?php
declare(strict_types=1);

/**
 * Portal público info torneo (mesa + resumen + listados): sesión y datos de solo lectura.
 */
final class PublicTorneoPortalHelper
{
    public const SESSION_KEY = 'info_torneo_portal_v1';

    /** Torneo visible y en curso (no cerrado). */
    public static function getTorneoParaPortal(\PDO $pdo, int $torneoId): ?array
    {
        $st = $pdo->prepare('SELECT id, nombre, modalidad, rondas, estatus, locked, fechator, lugar FROM tournaments WHERE id = ?');
        $st->execute([$torneoId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((int) ($row['estatus'] ?? 0) !== 1) {
            return null;
        }
        if ((int) ($row['locked'] ?? 0) === 1) {
            return null;
        }
        return $row;
    }

    /** Torneo existe (para mensaje “finalizado”). */
    public static function getTorneoBasico(\PDO $pdo, int $torneoId): ?array
    {
        $st = $pdo->prepare('SELECT id, nombre, estatus, locked FROM tournaments WHERE id = ?');
        $st->execute([$torneoId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Torneo activo para QR de jugador (lectura): incluye torneos finalizados (locked) mientras estén publicados.
     */
    public static function getTorneoParaQrJugador(\PDO $pdo, int $torneoId): ?array
    {
        $st = $pdo->prepare('SELECT id, nombre, modalidad, rondas, estatus, locked, fechator, lugar FROM tournaments WHERE id = ?');
        $st->execute([$torneoId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((int) ($row['estatus'] ?? 0) !== 1) {
            return null;
        }

        return $row;
    }

    public static function sessionGetUserId(int $torneoId): ?int
    {
        $bag = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($bag)) {
            return null;
        }
        if ((int) ($bag['torneo_id'] ?? 0) !== $torneoId) {
            return null;
        }
        $uid = (int) ($bag['id_usuario'] ?? 0);
        return $uid > 0 ? $uid : null;
    }

    public static function sessionSet(int $torneoId, int $idUsuario): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'torneo_id' => $torneoId,
            'id_usuario' => $idUsuario,
            'ts' => time(),
        ];
    }

    public static function sessionClear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchListadoGeneral(\PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare(
            'SELECT 
                i.id_usuario,
                i.ptosrnk,
                i.efectividad,
                i.ganados,
                i.perdidos,
                i.puntos,
                i.posicion,
                COALESCE(u.nombre, u.username) AS nombre_jugador,
                u.cedula,
                c.nombre AS club_nombre,
                i.codigo_equipo
            FROM inscritos i
            LEFT JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ?
            AND (i.estatus IN (1, 2, \'1\', \'2\', \'confirmado\', \'solvente\'))
            ORDER BY i.ptosrnk DESC, i.efectividad DESC, i.ganados DESC, nombre_jugador ASC'
        );
        $st->execute([$torneoId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array{resumen: array<string, mixed>, partidas: list<array<string, mixed>>, posicion: int, jugador: array<string, mixed>}
     */
    public static function fetchResumenParticipacion(\PDO $pdo, int $torneoId, int $idUsuario): array
    {
        require_once __DIR__ . '/ResumenParticipacionHelper.php';

        $st = $pdo->prepare(
            'SELECT u.id AS id_usuario, u.nombre, u.cedula, i.codigo_equipo
             FROM inscritos i
             INNER JOIN usuarios u ON u.id = i.id_usuario
             WHERE i.torneo_id = ? AND i.id_usuario = ?
             LIMIT 1'
        );
        $st->execute([$torneoId, $idUsuario]);
        $jugador = $st->fetch(\PDO::FETCH_ASSOC) ?: ['id_usuario' => $idUsuario, 'nombre' => '', 'cedula' => '', 'codigo_equipo' => ''];

        $stIns = $pdo->prepare(
            'SELECT i.*, COALESCE(u.nombre, u.username) AS nombre_jugador
             FROM inscritos i
             LEFT JOIN usuarios u ON u.id = i.id_usuario
             WHERE i.torneo_id = ? AND i.id_usuario = ?
             LIMIT 1'
        );
        $stIns->execute([$torneoId, $idUsuario]);
        $inscritoRow = $stIns->fetch(\PDO::FETCH_ASSOC) ?: [];

        $stMod = $pdo->prepare('SELECT COALESCE(modalidad, 1) AS modalidad FROM tournaments WHERE id = ?');
        $stMod->execute([$torneoId]);
        $esModalidadEquipos = (int) (($stMod->fetch(\PDO::FETCH_ASSOC)['modalidad'] ?? 1)) === 3;

        $equipoRow = null;
        if ($esModalidadEquipos && !empty($inscritoRow['codigo_equipo'])) {
            $stEq = $pdo->prepare(
                'SELECT posicion FROM equipos WHERE id_torneo = ? AND codigo_equipo = ? AND estatus = 0 LIMIT 1'
            );
            $stEq->execute([$torneoId, $inscritoRow['codigo_equipo']]);
            $equipoRow = $stEq->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $posicion = ResumenParticipacionHelper::resolverPosicionMostrada($inscritoRow, $esModalidadEquipos, $equipoRow);

        $st = $pdo->prepare(
            'SELECT partida, mesa, secuencia, resultado1, resultado2, efectividad, ff, registrado
             FROM partiresul
             WHERE id_torneo = ? AND id_usuario = ?
             ORDER BY partida ASC, CAST(mesa AS UNSIGNED) ASC'
        );
        $st->execute([$torneoId, $idUsuario]);
        $partidas_raw = $st->fetchAll(\PDO::FETCH_ASSOC);
        $partidas = [];
        foreach ($partidas_raw as $p) {
            $mesa = (int) $p['mesa'];
            $sec = (int) ($p['secuencia'] ?? 0);
            $r1 = (int) ($p['resultado1'] ?? 0);
            $r2 = (int) ($p['resultado2'] ?? 0);
            $compañero = '';
            $contrario1 = '';
            $contrario2 = '';
            $ganada = 0;
            if ($mesa > 0) {
                $joinU = ResumenParticipacionHelper::sqlJoinUsuariosDesdePartiresul('pr', 'u', 'LEFT');
                $exprNom = ResumenParticipacionHelper::sqlExprNombreUsuarioPartiresul('u', 'pr');
                $stmt_mesa = $pdo->prepare(
                    "SELECT pr.id_usuario, pr.secuencia, {$exprNom} AS nombre
                     FROM partiresul pr
                     {$joinU}
                     WHERE pr.id_torneo = ? AND pr.partida = ? AND CAST(pr.mesa AS SIGNED) = ?
                     ORDER BY pr.secuencia ASC"
                );
                $stmt_mesa->execute([$torneoId, $p['partida'], $p['mesa']]);
                $en_mesa = $stmt_mesa->fetchAll(\PDO::FETCH_ASSOC);
                $idsProp = ResumenParticipacionHelper::normalizarIdsPartiresulJugador($idUsuario, $idUsuario, 0);
                $resMesa = ResumenParticipacionHelper::resolverCompaneroYContrarios($en_mesa, $idsProp, $sec);
                if ($resMesa['companero'] !== null) {
                    $compañero = $resMesa['companero']['nombre'];
                } else {
                    $hist = ResumenParticipacionHelper::buscarCompaneroEnHistorialParejas($pdo, $torneoId, (int) $p['partida'], $idsProp);
                    if ($hist !== null) {
                        $compañero = $hist['nombre'];
                    }
                }
                $idxC = 0;
                foreach ($resMesa['contrarios'] as $cont) {
                    if ($idxC === 0) {
                        $contrario1 = $cont['nombre'];
                    } else {
                        $contrario2 = $cont['nombre'];
                    }
                    $idxC++;
                }
                $ganada = (in_array($sec, [1, 2], true) && $r1 > $r2) || (in_array($sec, [3, 4], true) && $r2 > $r1) ? 1 : 0;
            }
            $partidas[] = array_merge($p, [
                'compañero' => $compañero ?: '—',
                'contrario1' => $contrario1 ?: '—',
                'contrario2' => $contrario2 ?: '—',
                'ganada' => $ganada,
            ]);
        }

        $resumen = [
            'puntos' => (int) ($inscritoRow['puntos'] ?? 0),
            'efectividad' => (int) ($inscritoRow['efectividad'] ?? 0),
            'ganados' => (int) ($inscritoRow['ganados'] ?? 0),
            'perdidos' => (int) ($inscritoRow['perdidos'] ?? 0),
            'ptosrnk' => (int) ($inscritoRow['ptosrnk'] ?? 0),
            'posicion' => $posicion,
        ];

        return [
            'jugador' => $jugador,
            'resumen' => $resumen,
            'partidas' => $partidas,
            'posicion' => $posicion,
        ];
    }
}
