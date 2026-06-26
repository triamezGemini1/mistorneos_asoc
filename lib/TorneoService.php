<?php
declare(strict_types=1);

require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/OrganizacionService.php';
require_once __DIR__ . '/Tournament/Handlers/RoundManagerHandler.php';

/**
 * Consultas de torneos vinculados a una organización.
 */
final class TorneoService
{
    private static ?bool $hasLockedColumn = null;

    private static function hasLockedColumn(\PDO $pdo): bool
    {
        if (self::$hasLockedColumn !== null) {
            return self::$hasLockedColumn;
        }
        try {
            $pdo->query('SELECT `locked` FROM `tournaments` LIMIT 0');
            self::$hasLockedColumn = true;
        } catch (\Throwable $ignored) {
            self::$hasLockedColumn = false;
        }

        return self::$hasLockedColumn;
    }

    /**
     * Estado de cierre formal del torneo (rondas pautadas completadas).
     *
     * @return array{puede_finalizar: bool, finalizado: bool, rondas_pautadas: int, ultima_ronda: int, mesas_incompletas: int}
     */
    public static function getFinalizeState(int $torneoId): array
    {
        $empty = [
            'puede_finalizar' => false,
            'finalizado' => false,
            'rondas_pautadas' => 0,
            'ultima_ronda' => 0,
            'mesas_incompletas' => 0,
        ];
        if ($torneoId <= 0) {
            return $empty;
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }

        $pdo = DB::pdo();
        $cols = self::hasLockedColumn($pdo) ? 'rondas, locked' : 'rondas';
        $stmt = $pdo->prepare("SELECT {$cols} FROM tournaments WHERE id = ? LIMIT 1");
        $stmt->execute([$torneoId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (! is_array($row)) {
            return $empty;
        }

        $totalRondas = (int) ($row['rondas'] ?? 0);
        $locked = self::hasLockedColumn($pdo) && (int) ($row['locked'] ?? 0) === 1;
        $vm = \Tournament\Handlers\RoundManagerHandler::verificarMesasPendientes($torneoId);
        $ultimaRonda = (int) ($vm['ultima_ronda'] ?? 0);
        $mesasInc = (int) ($vm['mesas_incompletas'] ?? 0);
        $completado = $totalRondas > 0 && $ultimaRonda >= $totalRondas && $mesasInc === 0;

        return [
            'puede_finalizar' => $completado && ! $locked,
            'finalizado' => $locked,
            'rondas_pautadas' => $totalRondas,
            'ultima_ronda' => $ultimaRonda,
            'mesas_incompletas' => $mesasInc,
        ];
    }

    /**
     * @param list<array<string, mixed>> $torneos
     * @return list<array<string, mixed>>
     */
    public static function enrichForHubAdmin(array $torneos): array
    {
        if ($torneos === []) {
            return [];
        }

        if (! class_exists('Auth', false)) {
            require_once __DIR__ . '/../config/auth.php';
        }

        foreach ($torneos as &$torneo) {
            $tid = (int) ($torneo['id'] ?? 0);
            $finalize = self::getFinalizeState($tid);
            $torneo['puede_finalizar'] = $finalize['puede_finalizar'];
            $torneo['finalizado'] = $finalize['finalizado'];
            $torneo['rondas_pautadas'] = $finalize['rondas_pautadas'];
            $torneo['ultima_ronda'] = $finalize['ultima_ronda'];
            $torneo['puede_editar'] = ! $finalize['finalizado'] && Auth::canModifyTournament($tid);
            $torneo['puede_ver'] = Auth::canAccessTournament($tid);
        }
        unset($torneo);

        return $torneos;
    }

    /**
     * Normaliza el filtro de listado del hub (realizados | en_proceso | pendientes).
     */
    public static function normalizeEstadoTorneos(?string $estado): string
    {
        $estado = strtolower(trim((string) $estado));
        if ($estado === 'por_realizar') {
            return 'pendientes';
        }
        if (in_array($estado, ['realizados', 'en_proceso', 'pendientes'], true)) {
            return $estado;
        }

        return 'en_proceso';
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function estadoTorneosSql(string $estado, \PDO $pdo, string $alias = 't'): array
    {
        $estado = self::normalizeEstadoTorneos($estado);
        $hasLocked = self::hasLockedColumn($pdo);
        $lockedOk = $hasLocked ? "({$alias}.locked IS NULL OR {$alias}.locked = 0)" : '1=1';
        $lockedDone = $hasLocked ? "{$alias}.locked = 1" : '0=1';

        switch ($estado) {
            case 'realizados':
                return ["(({$alias}.fechator IS NOT NULL AND {$alias}.fechator < CURDATE()) OR {$lockedDone})", []];
            case 'pendientes':
                return ["(({$alias}.fechator IS NULL OR {$alias}.fechator > CURDATE()) AND {$lockedOk})", []];
            case 'en_proceso':
            default:
                return ["({$alias}.fechator = CURDATE() AND {$lockedOk})", []];
        }
    }

    /**
     * Torneos de la asociación, opcionalmente filtrados por estado temporal.
     *
     * @return list<array<string, mixed>>
     */
    public static function getByOrg(int $orgId, ?string $estado = null): array
    {
        if ($orgId <= 0) {
            return [];
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }

        $pdo = DB::pdo();
        $org = OrganizacionService::getById($orgId);
        if ($org === null) {
            return [];
        }

        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            $orgRow = (array) $org;
            [$whereSql, $params] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
                $pdo,
                $orgRow,
                $hasCodOrg,
                't',
                false
            );

            if ($estado !== null && trim($estado) !== '') {
                [$estadoSql, $estadoParams] = self::estadoTorneosSql($estado, $pdo, 't');
                $whereSql = '(' . $whereSql . ') AND ' . $estadoSql;
                $params = array_merge($params, $estadoParams);
            }

            $lockedCol = self::hasLockedColumn($pdo) ? ', t.locked' : '';
            $sql = "SELECT t.id, t.nombre, t.fechator, t.estatus, t.modalidad, t.lugar, t.rondas{$lockedCol}
                    FROM tournaments t
                    WHERE {$whereSql}
                    ORDER BY t.fechator DESC, t.id DESC
                    LIMIT 200";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('TorneoService::getByOrg: ' . $e->getMessage());

            return [];
        }
    }
}
