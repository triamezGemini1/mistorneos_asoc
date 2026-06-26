<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use PDO;

/**
 * Fase 1 — Data fetching del panel de torneo (antes en obtenerDatosPanel).
 * Requiere config/db.php cargado (clase DB).
 */
final class TournamentDataHandler
{
    /**
     * Datos iniciales para la vista panel-moderno: torneo enriquecido, rondas, conteos, flags.
     *
     * @return array<string, mixed> Mismo shape que devolvía obtenerDatosPanel() en torneo_gestion.php
     */
    public static function getInitialData(int $torneoId): array
    {
        $pdo = \DB::pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
        $stmt->execute([$torneoId]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        $torneo = is_array($fetched) ? $fetched : [];

        $rondas_generadas = self::fetchRondasGeneradas($pdo, $torneoId);
        $ultima_ronda = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
        $proxima_ronda = $ultima_ronda + 1;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?');
        $stmt->execute([$torneoId]);
        $total_inscritos = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND estatus IN (1, 2)');
        $stmt->execute([$torneoId]);
        $inscritos_confirmados = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND registrado = 1');
        $stmt->execute([$torneoId]);
        $total_partidas = $stmt->fetchColumn();

        $puede_generar = true;
        $mesas_incompletas = 0;
        $total_mesas_ronda = 0;
        if ($ultima_ronda > 0) {
            $mesas_incompletas = self::contarMesasIncompletas($pdo, $torneoId, $ultima_ronda);
            $puede_generar = $mesas_incompletas === 0;

            $stmt = $pdo->prepare('SELECT COUNT(DISTINCT mesa) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0');
            $stmt->execute([$torneoId, $ultima_ronda]);
            $total_mesas_ronda = $stmt->fetchColumn();
        }

        $organizacion_nombre = 'N/A';
        $organizacion_logo = null;
        if (!empty($torneo['club_responsable'])) {
            $stmt = $pdo->prepare('SELECT nombre, logo FROM organizaciones WHERE id = ?');
            $stmt->execute([$torneo['club_responsable']]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            $organizacion_nombre = $org['nombre'] ?? 'N/A';
            $organizacion_logo = !empty($org['logo']) ? $org['logo'] : null;
        }
        $torneo['organizacion_nombre'] = $organizacion_nombre;
        $torneo['organizacion_logo'] = $organizacion_logo;

        $total_equipos = 0;
        $total_jugadores_inscritos = 0;
        if ((int) ($torneo['modalidad'] ?? 0) === 3) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM equipos WHERE id_torneo = ?');
            $stmt->execute([$torneoId]);
            $total_equipos = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo != '' AND codigo_equipo != '000-000' AND estatus != 4");
            $stmt->execute([$torneoId]);
            $total_jugadores_inscritos = (int) $stmt->fetchColumn();
        }

        return [
            'torneo' => $torneo,
            'rondas' => $rondas_generadas,
            'rondas_generadas' => $rondas_generadas,
            'ultimaRonda' => $ultima_ronda,
            'ultima_ronda' => $ultima_ronda,
            'proximaRonda' => $proxima_ronda,
            'proxima_ronda' => $proxima_ronda,
            'totalInscritos' => $total_inscritos,
            'total_inscritos' => $total_inscritos,
            'inscritos_confirmados' => $inscritos_confirmados,
            'total_equipos' => $total_equipos,
            'total_jugadores_inscritos' => $total_jugadores_inscritos,
            'puedeGenerarRonda' => $puede_generar,
            'puede_generar_ronda' => $puede_generar,
            'mesasIncompletas' => $mesas_incompletas,
            'mesas_incompletas' => $mesas_incompletas,
            'estadisticas' => [
                'confirmados' => $inscritos_confirmados,
                'solventes' => 0,
                'total_partidas' => $total_partidas,
                'mesas_ronda' => $total_mesas_ronda,
                'total_equipos' => $total_equipos,
                'total_jugadores_inscritos' => $total_jugadores_inscritos,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchRondasGeneradas(PDO $pdo, int $torneoId): array
    {
        $sql = 'SELECT 
                partida as num_ronda,
                COUNT(DISTINCT mesa) as total_mesas,
                COUNT(*) as total_jugadores,
                COUNT(CASE WHEN mesa = 0 THEN 1 END) as jugadores_bye,
                MAX(fecha_partida) as fecha_generacion
            FROM partiresul
            WHERE id_torneo = ?
            GROUP BY partida
            ORDER BY partida ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function contarMesasIncompletas(PDO $pdo, int $torneoId, int $ronda): int
    {
        require_once __DIR__ . '/../../PartiresulEstatusSql.php';

        return \PartiresulEstatusSql::contarMesasIncompletas($pdo, $torneoId, $ronda);
    }
}
