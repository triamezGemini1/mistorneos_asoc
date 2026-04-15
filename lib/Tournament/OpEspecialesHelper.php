<?php

declare(strict_types=1);

namespace Tournament;

require_once __DIR__ . '/../PartiresulEstatusSql.php';
require_once __DIR__ . '/../InscritosHelper.php';
require_once __DIR__ . '/../InscritosPartiresulHelper.php';
require_once __DIR__ . '/../TorneoCampoNumerico.php';
require_once __DIR__ . '/../Tournament/Handlers/TournamentActionHandler.php';

/**
 * Operaciones especiales (solo torneos con estatus 9 — carga especial / simulación).
 * Persistencia principal: partiresul; equipos vía joins; “mesas por ronda” = filas partiresul (partida + mesa).
 */
final class OpEspecialesHelper
{
    public const ESTATUS_CARGA_ESPECIAL = 9;

    /**
     * @return array<string, mixed> Fila tournaments
     */
    public static function obtenerTorneoObligatorio(int $torneoId): array
    {
        if ($torneoId <= 0) {
            throw new \InvalidArgumentException('Torneo no válido.');
        }
        $pdo = \DB::pdo();
        $st = $pdo->prepare('SELECT id, nombre, estatus, modalidad, puntos, rondas FROM tournaments WHERE id = ?');
        $st->execute([$torneoId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Torneo no encontrado.');
        }

        return $row;
    }

    public static function esCargaEspecial(array $torneo): bool
    {
        return (int) ($torneo['estatus'] ?? 0) === self::ESTATUS_CARGA_ESPECIAL;
    }

    /**
     * Actualiza inscritos desde partiresul (misma idea que InscritosPartiresulHelper por jugador).
     */
    public static function sincronizarEstadisticasInscritos(int $torneoId): void
    {
        $pdo = \DB::pdo();
        $st = $pdo->prepare('SELECT DISTINCT id_usuario FROM inscritos WHERE torneo_id = ? AND estatus != 4');
        $st->execute([$torneoId]);
        while ($uid = $st->fetchColumn()) {
            \InscritosPartiresulHelper::actualizarEstadisticas((int) $uid, $torneoId);
        }
    }

    public static function idEquipoDeUsuario(int $torneoId, int $idUsuario): ?int
    {
        if ($idUsuario <= 0) {
            return null;
        }
        $pdo = \DB::pdo();
        $st = $pdo->prepare(
            'SELECT e.id FROM equipos e
             INNER JOIN inscritos i ON i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo
             WHERE e.id_torneo = ? AND i.id_usuario = ? AND e.estatus = 0
             LIMIT 1'
        );
        $st->execute([$torneoId, $idUsuario]);
        $id = $st->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarFilasMesa(\PDO $pdo, int $torneoId, int $ronda, int $mesa): array
    {
        $st = $pdo->prepare(
            'SELECT * FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? ORDER BY secuencia ASC'
        );
        $st->execute([$torneoId, $ronda, $mesa]);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Intercambia dos jugadores entre dos mesas de la misma ronda (por id de fila partiresul).
     *
     * @throws \RuntimeException
     */
    public static function swapAtletasPorIdsPartiresul(
        int $torneoId,
        int $ronda,
        int $idRowA,
        int $idRowB,
        int $modalidad
    ): void {
        $pdo = \DB::pdo();
        $st = $pdo->prepare('SELECT * FROM partiresul WHERE id = ? AND id_torneo = ? AND partida = ?');
        $st->execute([$idRowA, $torneoId, $ronda]);
        $a = $st->fetch(\PDO::FETCH_ASSOC);
        $st->execute([$idRowB, $torneoId, $ronda]);
        $b = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$a || !$b) {
            throw new \RuntimeException('No se encontraron ambas filas en esa ronda.');
        }
        $mesaA = (int) $a['mesa'];
        $mesaB = (int) $b['mesa'];
        if ($mesaA <= 0 || $mesaB <= 0) {
            throw new \RuntimeException('El swap solo aplica a mesas de juego (mesa &gt; 0).');
        }
        if ($mesaA === $mesaB) {
            throw new \RuntimeException('Seleccione dos mesas distintas.');
        }

        $ua = (int) $a['id_usuario'];
        $ub = (int) $b['id_usuario'];

        if ($modalidad === 3) {
            $stU = $pdo->prepare(
                'SELECT id_usuario FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = ? ORDER BY secuencia'
            );
            $stU->execute([$torneoId, $ronda, $mesaA]);
            $uidsA = array_map('intval', $stU->fetchAll(\PDO::FETCH_COLUMN));
            $stU->execute([$torneoId, $ronda, $mesaB]);
            $uidsB = array_map('intval', $stU->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($uidsA as $i => $v) {
                if ($v === $ua) {
                    $uidsA[$i] = $ub;
                }
            }
            foreach ($uidsB as $i => $v) {
                if ($v === $ub) {
                    $uidsB[$i] = $ua;
                }
            }
            $chk = static function (array $uids) use ($torneoId): void {
                $seen = [];
                foreach ($uids as $uid) {
                    $e = self::idEquipoDeUsuario($torneoId, $uid);
                    if ($e === null) {
                        continue;
                    }
                    if (isset($seen[$e])) {
                        throw new \RuntimeException(
                            'La Regla de Oro impide este intercambio: habría dos jugadores del mismo equipo en una mesa.'
                        );
                    }
                    $seen[$e] = true;
                }
            };
            $chk($uidsA);
            $chk($uidsB);
        }

        $pdo->beginTransaction();
        try {
            $u1 = $pdo->prepare('UPDATE partiresul SET id_usuario = ? WHERE id = ? AND id_torneo = ?');
            $u1->execute([$ub, $idRowA, $torneoId]);
            $u1->execute([$ua, $idRowB, $torneoId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('No se pudo completar el intercambio: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Aplica FF + puntos de penalización (sanción) y marca mesa como registrada vía núcleo de resultados.
     *
     * @param list<int> $idsPartiresul
     */
    public static function aplicarForfaitFilas(
        int $torneoId,
        int $ronda,
        array $idsPartiresul,
        int $puntosPenal,
        int $registradoPorUserId
    ): int {
        $idsPartiresul = array_values(array_filter(array_map('intval', $idsPartiresul), static fn (int $x) => $x > 0));
        if ($idsPartiresul === []) {
            return 0;
        }
        $pdo = \DB::pdo();
        $porMesa = [];
        $st = $pdo->prepare('SELECT * FROM partiresul WHERE id = ? AND id_torneo = ? AND partida = ?');
        foreach ($idsPartiresul as $pid) {
            $st->execute([$pid, $torneoId, $ronda]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int) $row['mesa'] <= 0) {
                continue;
            }
            $mesa = (int) $row['mesa'];
            $porMesa[$mesa] = $porMesa[$mesa] ?? [];
            $porMesa[$mesa][(int) $row['id']] = true;
        }
        $n = 0;
        foreach ($porMesa as $mesa => $_ids) {
            $filas = self::listarFilasMesa($pdo, $torneoId, $ronda, $mesa);
            if (count($filas) !== 4) {
                continue;
            }
            $jugadores = [];
            foreach ($filas as $f) {
                $id = (int) $f['id'];
                $marcarFf = isset($_ids[$id]);
                $jugadores[] = self::filaPartiresulAJugadorPost($f, $marcarFf, $marcarFf ? $puntosPenal : 0);
            }
            \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                $pdo,
                $torneoId,
                $ronda,
                $mesa,
                $jugadores,
                $registradoPorUserId,
                ''
            );
            $n++;
        }

        return $n;
    }

    /**
     * Tarjeta administrativa (amarilla/roja) + puntos de sanción en filas seleccionadas.
     *
     * @param list<int> $idsPartiresul
     */
    public static function aplicarTarjetasFilas(
        int $torneoId,
        int $ronda,
        array $idsPartiresul,
        int $codigoTarjeta,
        int $puntosSancion,
        int $registradoPorUserId
    ): int {
        $codigoTarjeta = \TorneoCampoNumerico::codigoTarjeta($codigoTarjeta);
        $idsPartiresul = array_values(array_filter(array_map('intval', $idsPartiresul), static fn (int $x) => $x > 0));
        if ($idsPartiresul === []) {
            return 0;
        }
        $pdo = \DB::pdo();
        $porMesa = [];
        $st = $pdo->prepare('SELECT * FROM partiresul WHERE id = ? AND id_torneo = ? AND partida = ?');
        foreach ($idsPartiresul as $pid) {
            $st->execute([$pid, $torneoId, $ronda]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int) $row['mesa'] <= 0) {
                continue;
            }
            $mesa = (int) $row['mesa'];
            $porMesa[$mesa] = $porMesa[$mesa] ?? [];
            $porMesa[$mesa][(int) $row['id']] = ['tarjeta' => $codigoTarjeta, 'sancion' => min(80, max(0, $puntosSancion))];
        }
        $n = 0;
        foreach ($porMesa as $mesa => $mapTar) {
            $filas = self::listarFilasMesa($pdo, $torneoId, $ronda, $mesa);
            if (count($filas) !== 4) {
                continue;
            }
            $jugadores = [];
            foreach ($filas as $f) {
                $id = (int) $f['id'];
                $extra = $mapTar[$id] ?? null;
                $jugadores[] = self::filaPartiresulAJugadorPost(
                    $f,
                    false,
                    0,
                    $extra['tarjeta'] ?? null,
                    $extra['sancion'] ?? null
                );
            }
            \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                $pdo,
                $torneoId,
                $ronda,
                $mesa,
                $jugadores,
                $registradoPorUserId,
                ''
            );
            $n++;
        }

        return $n;
    }

    /**
     * Carga masiva: rellena cada mesa completa con un resultado base coherente (pareja 1–2 vs 3–4).
     */
    public static function cargaMasivaResultadosBase(int $torneoId, int $ronda, int $registradoPorUserId): int
    {
        $pdo = \DB::pdo();
        $st = $pdo->prepare(
            'SELECT DISTINCT mesa FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0 ORDER BY mesa ASC'
        );
        $st->execute([$torneoId, $ronda]);
        $mesas = $st->fetchAll(\PDO::FETCH_COLUMN);
        $stT = $pdo->prepare('SELECT puntos FROM tournaments WHERE id = ?');
        $stT->execute([$torneoId]);
        $puntosTorneo = (int) $stT->fetchColumn();
        if ($puntosTorneo <= 0) {
            $puntosTorneo = 200;
        }
        $win = min((int) round($puntosTorneo * 0.55), (int) round($puntosTorneo * 1.6));
        $lose = min($win - 5, (int) round($puntosTorneo * 0.50));
        if ($lose < 0) {
            $lose = 0;
        }

        $n = 0;
        foreach ($mesas as $mesaVal) {
            $mesa = (int) $mesaVal;
            $filas = self::listarFilasMesa($pdo, $torneoId, $ronda, $mesa);
            if (count($filas) !== 4) {
                continue;
            }
            $jugadores = [];
            foreach ($filas as $f) {
                $sec = (int) $f['secuencia'];
                $esA = ($sec === 1 || $sec === 2);
                $jugadores[] = [
                    'id' => (int) $f['id'],
                    'id_usuario' => (int) $f['id_usuario'],
                    'secuencia' => $sec,
                    'resultado1' => (string) ($esA ? $win : $lose),
                    'resultado2' => (string) ($esA ? $lose : $win),
                    'tarjeta' => '0',
                    'sancion' => '0',
                    'chancleta' => '0',
                    'zapato' => '0',
                ];
            }
            \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore(
                $pdo,
                $torneoId,
                $ronda,
                $mesa,
                $jugadores,
                $registradoPorUserId,
                ''
            );
            $n++;
        }

        return $n;
    }

    /**
     * @return array{integridad: list<array<string, mixed>>, gdu: list<array<string, mixed>>, ff_incoherente: list<array<string, mixed>>}
     */
    public static function reporteAuditoria(int $torneoId): array
    {
        $pdo = \DB::pdo();
        $reg = \PartiresulEstatusSql::whereRegistradoUno('pr');
        $ff1 = \PartiresulEstatusSql::whereFfUno('pr');
        $r1 = \InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $r2 = \InscritosHelper::sqlExprColumnaNumerica('pr.resultado2');
        $sn = \InscritosHelper::sqlExprColumnaNumerica('pr.sancion');

        $integridad = [];

        $stMesas = $pdo->prepare(
            "SELECT partida, mesa, COUNT(*) AS c
             FROM partiresul pr
             WHERE pr.id_torneo = ? AND pr.mesa > 0
             GROUP BY partida, mesa
             HAVING c <> 4"
        );
        $stMesas->execute([$torneoId]);
        foreach ($stMesas->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $integridad[] = [
                'tipo' => 'mesa_incompleta',
                'partida' => (int) $m['partida'],
                'mesa' => (int) $m['mesa'],
                'filas' => (int) $m['c'],
            ];
        }

        $st = $pdo->prepare(
            "SELECT pr.partida, pr.mesa, e.id AS id_equipo, COUNT(*) AS c
             FROM partiresul pr
             INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
             INNER JOIN equipos e ON e.id_torneo = pr.id_torneo AND e.codigo_equipo = i.codigo_equipo AND e.estatus = 0
             WHERE pr.id_torneo = ? AND pr.mesa > 0
             GROUP BY pr.partida, pr.mesa, e.id
             HAVING c > 1"
        );
        $st->execute([$torneoId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $integridad[] = [
                'tipo' => 'equipo_duplicado_mesa',
                'partida' => (int) $m['partida'],
                'mesa' => (int) $m['mesa'],
                'id_equipo' => (int) $m['id_equipo'],
                'repetidos' => (int) $m['c'],
            ];
        }

        $gdu = [];
        $stRondas = $pdo->prepare(
            "SELECT DISTINCT partida FROM partiresul WHERE id_torneo = ? AND mesa > 0 ORDER BY partida ASC"
        );
        $stRondas->execute([$torneoId]);
        $rondas = $stRondas->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rondas as $partida) {
            $partida = (int) $partida;
            $stM = $pdo->prepare(
                "SELECT DISTINCT mesa FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0"
            );
            $stM->execute([$torneoId, $partida]);
            while ($mesa = $stM->fetchColumn()) {
                $mesa = (int) $mesa;
                $stJ = $pdo->prepare(
                    "SELECT pr.id_usuario, pr.resultado1, pr.resultado2, pr.sancion, pr.ff,
                            {$r1} AS r1, {$r2} AS r2, {$sn} AS sn
                     FROM partiresul pr
                     WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? AND {$reg}"
                );
                $stJ->execute([$torneoId, $partida, $mesa]);
                $rows = $stJ->fetchAll(\PDO::FETCH_ASSOC);
                if (count($rows) !== 4) {
                    continue;
                }
                $ganadores = [];
                $perdedoresPf = [];
                foreach ($rows as $row) {
                    if (self::filaFfActiva($row)) {
                        continue;
                    }
                    $r1v = (float) $row['r1'];
                    $r2v = (float) $row['r2'];
                    $snv = (float) $row['sn'];
                    $adj = max(0.0, $r1v - $snv);
                    $gana = ($snv <= 0 && $r1v > $r2v) || ($snv > 0 && $adj > $r2v);
                    $pf = $r1v;
                    if ($gana) {
                        $ganadores[] = ['id_usuario' => (int) $row['id_usuario'], 'pf' => $pf];
                    } else {
                        $perdedoresPf[] = $pf;
                    }
                }
                if ($ganadores === [] || $perdedoresPf === []) {
                    continue;
                }
                $maxPer = max($perdedoresPf);
                foreach ($ganadores as $g) {
                    if ($g['pf'] <= $maxPer + 0.00001) {
                        $gdu[] = [
                            'partida' => $partida,
                            'mesa' => $mesa,
                            'id_usuario' => $g['id_usuario'],
                            'pf' => $g['pf'],
                            'max_pf_perdedor_mesa' => $maxPer,
                        ];
                    }
                }
            }
        }

        $ff_incoherente = [];
        $stFf = $pdo->prepare(
            "SELECT pr.id, pr.partida, pr.mesa, pr.id_usuario, pr.resultado1, pr.resultado2, pr.sancion, pr.ff
             FROM partiresul pr
             WHERE pr.id_torneo = ? AND pr.mesa > 0 AND {$reg} AND {$ff1}"
        );
        $stFf->execute([$torneoId]);
        while ($row = $stFf->fetch(\PDO::FETCH_ASSOC)) {
            $r1v = \TorneoCampoNumerico::floatCalculo($row['resultado1'] ?? 0);
            $r2v = \TorneoCampoNumerico::floatCalculo($row['resultado2'] ?? 0);
            $snv = \TorneoCampoNumerico::floatCalculo($row['sancion'] ?? 0);
            $adj = max(0.0, $r1v - $snv);
            $pareceGanador = ($snv <= 0 && $r1v > $r2v) || ($snv > 0 && $adj > $r2v);
            if ($pareceGanador) {
                $ff_incoherente[] = [
                    'tipo' => 'ff_con_marcador_ganador',
                    'id' => (int) $row['id'],
                    'partida' => (int) $row['partida'],
                    'mesa' => (int) $row['mesa'],
                    'id_usuario' => (int) $row['id_usuario'],
                ];
            }
        }

        return [
            'integridad' => $integridad,
            'gdu' => $gdu,
            'ff_incoherente' => $ff_incoherente,
        ];
    }

    /**
     * @param array<string, mixed> $f
     */
    private static function filaPartiresulAJugadorPost(
        array $f,
        bool $ff,
        int $sancionFf,
        ?int $tarjeta = null,
        ?int $sancion = null
    ): array {
        $sBase = $sancion !== null
            ? min(80, max(0, $sancion))
            : ($ff ? min(80, max(0, $sancionFf)) : \TorneoCampoNumerico::intEstadistica($f['sancion'] ?? 0));
        $out = [
            'id' => (int) $f['id'],
            'id_usuario' => (int) $f['id_usuario'],
            'secuencia' => (int) $f['secuencia'],
            'resultado1' => (string) \TorneoCampoNumerico::intEstadistica($f['resultado1'] ?? 0),
            'resultado2' => (string) \TorneoCampoNumerico::intEstadistica($f['resultado2'] ?? 0),
            'tarjeta' => (string) ($tarjeta ?? \TorneoCampoNumerico::intEstadistica($f['tarjeta'] ?? 0)),
            'sancion' => (string) $sBase,
            'chancleta' => (string) \TorneoCampoNumerico::intEstadistica($f['chancleta'] ?? 0),
            'zapato' => (string) \TorneoCampoNumerico::intEstadistica($f['zapato'] ?? 0),
        ];
        if ($ff) {
            $out['ff'] = '1';
        }

        return $out;
    }

    private static function filaFfActiva(array $row): bool
    {
        $v = $row['ff'] ?? 0;
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return trim((string) $v) === '1';
    }
}
