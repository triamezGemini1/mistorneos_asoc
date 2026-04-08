<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use PDO;
use PDOException;

require_once __DIR__ . '/../../InscritosHelper.php';

/**
 * Ranking de equipos: lectura desde tabla equipos o agregados SUM desde inscritos,
 * ordenación (ganados, efectividad, puntos, código) y asignación de posiciones.
 */
final class TeamPerformanceHandler
{
    private function __construct()
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRankingPorEquipos(int $torneoId, string $modalidad): array
    {
        if ($torneoId <= 0) {
            return [];
        }

        $pdo = \DB::pdo();
        $incluirJugadores = strtolower($modalidad) === 'detallado';

        $ordG = \InscritosHelper::sqlExprColumnaNumerica('e.ganados');
        $ordE = \InscritosHelper::sqlExprColumnaNumerica('e.efectividad');
        $ordP = \InscritosHelper::sqlExprColumnaNumerica('e.puntos');
        $sqlEquipos = "
            SELECT 
                e.id as equipo_id,
                e.codigo_equipo,
                e.nombre_equipo,
                e.id_club,
                c.nombre as club_nombre,
                e.posicion,
                e.ganados,
                e.perdidos,
                e.efectividad,
                e.puntos,
                e.sancion,
                e.gff
            FROM equipos e
            LEFT JOIN clubes c ON e.id_club = c.id
            WHERE e.id_torneo = ? 
                AND e.estatus = 0
                AND e.codigo_equipo IS NOT NULL
                AND e.codigo_equipo != ''
            ORDER BY 
                $ordG DESC,
                $ordE DESC,
                $ordP DESC,
                e.codigo_equipo ASC
        ";

        try {
            $stmt = $pdo->prepare($sqlEquipos);
            $stmt->execute([$torneoId]);
            /** @var list<array<string, mixed>> $equipos */
            $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('TeamPerformanceHandler::getRankingPorEquipos equipos: ' . $e->getMessage());

            return [];
        }

        if ($equipos === []) {
            $equipos = self::buildEquiposDesdeInscritos($pdo, $torneoId);
        }

        self::ordenarEquiposYAsignarPosiciones($equipos);

        $out = [];
        foreach ($equipos as $eq) {
            $codigo = (string) ($eq['codigo_equipo'] ?? '');
            if ($codigo === '') {
                continue;
            }

            $equipoId = $eq['equipo_id'] ?? null;
            $row = [
                'equipo_id' => $equipoId !== null && $equipoId !== '' ? (int) $equipoId : 0,
                'codigo_equipo' => $codigo,
                'nombre_equipo' => (string) ($eq['nombre_equipo'] ?? ('Equipo ' . $codigo)),
                'id_club' => (int) ($eq['id_club'] ?? 0),
                'club_nombre' => (string) ($eq['club_nombre'] ?? 'Sin Club'),
                'posicion' => (int) ($eq['posicion'] ?? 0),
                'ganados' => (int) ($eq['ganados'] ?? 0),
                'perdidos' => (int) ($eq['perdidos'] ?? 0),
                'efectividad' => (int) ($eq['efectividad'] ?? 0),
                'puntos' => (int) ($eq['puntos'] ?? 0),
                'sancion' => (int) ($eq['sancion'] ?? 0),
                'gff' => (int) ($eq['gff'] ?? 0),
            ];

            if ($incluirJugadores) {
                $jugadores = self::fetchJugadoresEquipo($pdo, $torneoId, $codigo);
                $row['jugadores'] = $jugadores;
                $row['total_jugadores'] = count($jugadores);
            } else {
                $row['total_jugadores'] = self::countJugadoresEquipo($pdo, $torneoId, $codigo);
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $equipos
     */
    private static function ordenarEquiposYAsignarPosiciones(array &$equipos): void
    {
        usort($equipos, static function ($a, $b) {
            $ganados_a = (int) ($a['ganados'] ?? 0);
            $ganados_b = (int) ($b['ganados'] ?? 0);
            if ($ganados_a !== $ganados_b) {
                return $ganados_b <=> $ganados_a;
            }

            $efec_a = (int) ($a['efectividad'] ?? 0);
            $efec_b = (int) ($b['efectividad'] ?? 0);
            if ($efec_a !== $efec_b) {
                return $efec_b <=> $efec_a;
            }

            $pts_a = (int) ($a['puntos'] ?? 0);
            $pts_b = (int) ($b['puntos'] ?? 0);
            if ($pts_a !== $pts_b) {
                return $pts_b <=> $pts_a;
            }

            return strcmp((string) ($a['codigo_equipo'] ?? ''), (string) ($b['codigo_equipo'] ?? ''));
        });

        $pos = 1;
        foreach ($equipos as &$equipo) {
            $equipo['posicion'] = $pos;
            ++$pos;
        }
        unset($equipo);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildEquiposDesdeInscritos(PDO $pdo, int $torneoId): array
    {
        $sqlCodigos = "
            SELECT DISTINCT i.codigo_equipo
            FROM inscritos i
            WHERE i.torneo_id = ? 
                AND i.codigo_equipo IS NOT NULL 
                AND i.codigo_equipo != ''
                AND i.estatus != 'retirado'
            ORDER BY i.codigo_equipo ASC
        ";
        $stmt = $pdo->prepare($sqlCodigos);
        $stmt->execute([$torneoId]);
        $codigos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $equipos = [];

        foreach ($codigos as $codigo) {
            $codigo = (string) $codigo;
            if ($codigo === '') {
                continue;
            }

            $sxG = \InscritosHelper::sqlExprColumnaNumerica('i.ganados');
            $sxPe = \InscritosHelper::sqlExprColumnaNumerica('i.perdidos');
            $sxE = \InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
            $sxP = \InscritosHelper::sqlExprColumnaNumerica('i.puntos');
            $sxS = \InscritosHelper::sqlExprColumnaNumerica('i.sancion');
            $sqlStats = "
                SELECT 
                    SUM($sxG) as ganados,
                    SUM($sxPe) as perdidos,
                    SUM($sxE) as efectividad,
                    SUM($sxP) as puntos,
                    SUM($sxS) as sancion,
                    MIN(i.id_club) as id_club
                FROM inscritos i
                WHERE i.torneo_id = ? 
                    AND i.codigo_equipo = ?
                    AND i.estatus != 'retirado'
            ";
            $stmtStats = $pdo->prepare($sqlStats);
            $stmtStats->execute([$torneoId, $codigo]);
            $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if (!$stats) {
                continue;
            }

            $sqlClub = "
                SELECT c.nombre as club_nombre
                FROM inscritos i
                LEFT JOIN clubes c ON i.id_club = c.id
                WHERE i.torneo_id = ? 
                    AND i.codigo_equipo = ?
                    AND i.estatus != 'retirado'
                LIMIT 1
            ";
            $stmtClub = $pdo->prepare($sqlClub);
            $stmtClub->execute([$torneoId, $codigo]);
            $clubData = $stmtClub->fetch(PDO::FETCH_ASSOC) ?: [];

            $equipos[] = [
                'equipo_id' => null,
                'codigo_equipo' => $codigo,
                'nombre_equipo' => 'Equipo ' . $codigo,
                'id_club' => (int) ($stats['id_club'] ?? 0),
                'club_nombre' => (string) ($clubData['club_nombre'] ?? 'Sin Club'),
                'posicion' => 0,
                'ganados' => (int) ($stats['ganados'] ?? 0),
                'perdidos' => (int) ($stats['perdidos'] ?? 0),
                'efectividad' => (int) ($stats['efectividad'] ?? 0),
                'puntos' => (int) ($stats['puntos'] ?? 0),
                'sancion' => (int) ($stats['sancion'] ?? 0),
                'gff' => 0,
            ];
        }

        return $equipos;
    }

    private static function countJugadoresEquipo(PDO $pdo, int $torneoId, string $codigoEquipo): int
    {
        $sql = "
            SELECT COUNT(DISTINCT id_usuario) as total
            FROM inscritos
            WHERE torneo_id = ? 
                AND codigo_equipo = ?
                AND estatus != 'retirado'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId, $codigoEquipo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchJugadoresEquipo(PDO $pdo, int $torneoId, string $codigoEquipo): array
    {
        $sqlJugadores = "
            SELECT 
                i.id,
                i.id_usuario,
                i.posicion,
                i.ganados,
                i.perdidos,
                i.efectividad,
                i.puntos,
                i.ptosrnk,
                0 as gff,
                COALESCE(i.zapatos, 0) as zapatos,
                COALESCE(i.chancletas, 0) as chancletas,
                COALESCE(i.sancion, 0) as sancion,
                COALESCE(i.tarjeta, 0) as tarjeta,
                u.nombre as nombre_completo,
                u.cedula,
                c.nombre as club_nombre
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? 
                AND i.codigo_equipo = ?
                AND i.estatus != 'retirado'
            ORDER BY 
                i.ganados DESC,
                i.efectividad DESC,
                i.puntos DESC,
                i.id_usuario ASC
        ";

        $stmt = $pdo->prepare($sqlJugadores);
        $stmt->execute([$torneoId, $codigoEquipo]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $posicionEquipo = 1;
        foreach ($jugadores as &$jug) {
            $jug['posicion_equipo'] = $posicionEquipo;
            $jug['posicion_display'] = $posicionEquipo;
            ++$posicionEquipo;
        }
        unset($jug);

        return $jugadores;
    }
}
