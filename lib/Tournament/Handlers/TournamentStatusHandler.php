<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use TorneoMesaAsignacionResolver;

require_once __DIR__ . '/RoundManagerHandler.php';
require_once __DIR__ . '/../../InscritosHelper.php';
require_once __DIR__ . '/../../PartiresulEstatusSql.php';
require_once __DIR__ . '/../../Core/TorneoMesaAsignacionResolver.php';

/**
 * Estado consolidado del torneo para el panel (ronda activa vía MAX(partida), contadores, flags).
 */
final class TournamentStatusHandler
{
    private function __construct()
    {
    }

    /**
     * Listado agregado por ronda (misma consulta que antes en obtenerRondasGeneradas).
     *
     * @return list<array<string, mixed>>
     */
    public static function getRondasGeneradas(int $torneoId): array
    {
        if ($torneoId <= 0) {
            return [];
        }
        $pdo = \DB::pdo();

        $sql = "SELECT 
                partida as num_ronda,
                COUNT(DISTINCT mesa) as total_mesas,
                COUNT(*) as total_jugadores,
                COUNT(CASE WHEN mesa = 0 THEN 1 END) as jugadores_bye,
                MAX(fecha_partida) as fecha_generacion
            FROM partiresul
            WHERE id_torneo = ?
            GROUP BY partida
            ORDER BY partida ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Resumen del panel: última ronda = MAX(partida) vía RoundManagerHandler, contadores y flags.
     *
     * @return array<string, mixed>
     */
    public static function getTournamentSummary(int $torneoId): array
    {
        if (function_exists('ensureTournamentsCorreccionesCierreColumn')) {
            ensureTournamentsCorreccionesCierreColumn();
        }

        $pdo = \DB::pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
        $stmt->execute([$torneoId]);
        $torneo = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $rondas_generadas = self::getRondasGeneradas($torneoId);

        $vm = RoundManagerHandler::verificarMesasPendientes($torneoId);
        $ultima_ronda = (int) $vm['ultima_ronda'];
        $proxima_ronda = $ultima_ronda + 1;
        $mesas_incompletas = (int) $vm['mesas_incompletas'];
        $puede_generar = (bool) $vm['puede_generar_ronda'];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?');
        $stmt->execute([$torneoId]);
        $total_inscritos = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND ' . \InscritosHelper::SQL_WHERE_SOLO_CONFIRMADO);
        $stmt->execute([$torneoId]);
        $inscritos_confirmados = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND registrado = 1');
        $stmt->execute([$torneoId]);
        $total_partidas = $stmt->fetchColumn();

        $total_mesas_ronda = 0;
        $ultima_ronda_tiene_resultados = false;
        $mesas_cerradas_ronda = 0;
        if ($ultima_ronda > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(DISTINCT mesa) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0');
            $stmt->execute([$torneoId, $ultima_ronda]);
            $total_mesas_ronda = (int) $stmt->fetchColumn();
            $mesas_cerradas_ronda = max(0, $total_mesas_ronda - $mesas_incompletas);

            $ultima_ronda_tiene_resultados = TorneoMesaAsignacionResolver::rondaTieneResultadosEnMesas($torneoId, $ultima_ronda);
        }

        $ultima_actualizacion_resultados = null;
        $correcciones_cierre_at = $torneo['correcciones_cierre_at'] ?? null;
        if (empty($correcciones_cierre_at) || $correcciones_cierre_at === '0000-00-00 00:00:00') {
            $correcciones_cierre_at = null;
        }

        $organizacion_nombre = 'N/A';
        $organizacion_logo = null;
        if (!empty($torneo['club_responsable'])) {
            $stmt = $pdo->prepare('SELECT nombre, logo FROM organizaciones WHERE id = ?');
            $stmt->execute([$torneo['club_responsable']]);
            $org = $stmt->fetch(\PDO::FETCH_ASSOC);
            $organizacion_nombre = $org['nombre'] ?? 'N/A';
            $organizacion_logo = !empty($org['logo']) ? $org['logo'] : null;
        }
        $torneo['organizacion_nombre'] = $organizacion_nombre;
        $torneo['organizacion_logo'] = $organizacion_logo;

        $actas_pendientes_count = 0;
        try {
            $cols_pr = $pdo->query('SHOW COLUMNS FROM partiresul')->fetchAll(\PDO::FETCH_COLUMN);
            if (in_array('estatus', $cols_pr, true)) {
                $has_origen = in_array('origen_dato', $cols_pr, true);
                $wherePv = \PartiresulEstatusSql::wherePendienteVerificacionSinAlias($pdo);
                $sql = "
                SELECT COUNT(DISTINCT CONCAT(partida,'-',mesa))
                FROM partiresul
                WHERE id_torneo = ? AND mesa > 0 AND {$wherePv}"
                    . ($has_origen ? " AND origen_dato = 'qr'" : '') . '
            ';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$torneoId]);
                $actas_pendientes_count = (int) $stmt->fetchColumn();
            }
        } catch (\Exception $e) {
            /* ignorar */
        }

        $mesas_verificadas_count = 0;
        $mesas_digitadas_count = 0;
        try {
            $cols_pr = $pdo->query('SHOW COLUMNS FROM partiresul')->fetchAll(\PDO::FETCH_COLUMN);
            $has_origen = in_array('origen_dato', $cols_pr, true);
            if ($has_origen) {
                $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT CONCAT(partida,'-',mesa))
                FROM partiresul
                WHERE id_torneo = ? AND mesa > 0 AND registrado = 1 AND origen_dato = 'qr'
            ");
                $stmt->execute([$torneoId]);
                $mesas_verificadas_count = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT CONCAT(partida,'-',mesa))
                FROM partiresul
                WHERE id_torneo = ? AND mesa > 0 AND registrado = 1 AND origen_dato = 'admin'
            ");
                $stmt->execute([$torneoId]);
                $mesas_digitadas_count = (int) $stmt->fetchColumn();
            }
        } catch (\Exception $e) {
            /* ignorar */
        }

        $modalidadT = (int) ($torneo['modalidad'] ?? 0);
        $contInsc = \InscritosHelper::contadoresResumenInscripcionTorneo($pdo, $torneoId, $modalidadT);
        $total_equipos = (int) ($contInsc['equipos_activos'] ?? 0);
        $total_jugadores_inscritos = (int) ($contInsc['jugadores_confirmados'] ?? 0);

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
            'mesas_cerradas_ronda' => $mesas_cerradas_ronda,
            'ultima_ronda_tiene_resultados' => $ultima_ronda_tiene_resultados,
            'ultima_actualizacion_resultados' => $ultima_actualizacion_resultados,
            'correcciones_cierre_at' => $correcciones_cierre_at,
            'estadisticas' => [
                'confirmados' => $inscritos_confirmados,
                'solventes' => 0,
                'total_partidas' => $total_partidas,
                'mesas_ronda' => $total_mesas_ronda,
                'mesas_cerradas_ronda' => $mesas_cerradas_ronda,
                'mesas_abiertas_ronda' => $mesas_incompletas,
                'total_equipos' => $total_equipos,
                'total_jugadores_inscritos' => $total_jugadores_inscritos,
            ],
            'actas_pendientes_count' => $actas_pendientes_count,
            'mesas_verificadas_count' => $mesas_verificadas_count,
            'mesas_digitadas_count' => $mesas_digitadas_count,
        ];
    }
}
