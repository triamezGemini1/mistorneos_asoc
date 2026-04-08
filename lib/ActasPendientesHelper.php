<?php

declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';

/**
 * Helper para contar actas pendientes de verificación (QR).
 * Usado en layout (badge) y dashboard.
 */
class ActasPendientesHelper
{
    /**
     * Cuenta total de mesas con estatus = pendiente_verificacion
     * según los torneos a los que el usuario tiene acceso.
     * Solo aplicable a admin_club y admin_general.
     *
     * @return int
     */
    public static function contar(): int
    {
        try {
            $pdo = DB::pdo();
            $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('estatus', $cols)) {
                return 0;
            }
            $has_origen = in_array('origen_dato', $cols);
            $tournament_filter = Auth::getTournamentFilterForRole('t');
            $where_t = !empty($tournament_filter['where']) ? 'AND ' . $tournament_filter['where'] : '';
            $params = $tournament_filter['params'];
            $wherePv = PartiresulEstatusSql::qualifiedWherePendienteVerificacion($pdo, 'pr');
            $extra = $has_origen ? " AND pr.origen_dato = 'qr'" : '';
            $sql = "
                SELECT COUNT(DISTINCT CONCAT(pr.id_torneo, '-', pr.partida, '-', pr.mesa))
                FROM partiresul pr
                INNER JOIN tournaments t ON pr.id_torneo = t.id
                WHERE pr.mesa > 0 AND {$wherePv} {$extra}
                AND t.estatus = 1
                {$where_t}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Obtiene el timestamp del último envío de acta pendiente (QR).
     * Retorna null si no hay actas pendientes o no existe fecha_partida.
     *
     * @return string|null Formato Y-m-d H:i:s o null
     */
    public static function ultimoEnvio(): ?string
    {
        try {
            $pdo = DB::pdo();
            $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('estatus', $cols) || !in_array('fecha_partida', $cols)) {
                return null;
            }
            $has_origen = in_array('origen_dato', $cols);
            $tournament_filter = Auth::getTournamentFilterForRole('t');
            $where_t = !empty($tournament_filter['where']) ? 'AND ' . $tournament_filter['where'] : '';
            $params = $tournament_filter['params'];
            $wherePv = PartiresulEstatusSql::qualifiedWherePendienteVerificacion($pdo, 'pr');
            $extra = $has_origen ? " AND pr.origen_dato = 'qr'" : '';
            $sql = "
                SELECT MAX(pr.fecha_partida) as ultimo
                FROM partiresul pr
                INNER JOIN tournaments t ON pr.id_torneo = t.id
                WHERE pr.mesa > 0 AND {$wherePv} {$extra}
                AND t.estatus = 1
                {$where_t}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $ts = $stmt->fetchColumn();
            return $ts ? (string) $ts : null;
        } catch (Exception $e) {
            return null;
        }
    }
}
