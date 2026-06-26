<?php
declare(strict_types=1);

require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/OrganizacionService.php';

/**
 * Consultas de torneos vinculados a una organización.
 */
final class TorneoService
{
    /**
     * Torneos de la asociación (activos y recientes), ordenados por fecha.
     *
     * @return list<array<string, mixed>>
     */
    public static function getByOrg(int $orgId): array
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

            $sql = "SELECT t.id, t.nombre, t.fechator, t.estatus, t.modalidad, t.lugar
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
