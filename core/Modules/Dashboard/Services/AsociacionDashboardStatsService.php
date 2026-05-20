<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Services;

use DB;
use OrganizacionDashboardStats;
use PDO;
use Throwable;

/**
 * Estadísticas de asociación: delega territorio en OrganizacionDashboardStats (sin duplicar SQL).
 */
final class AsociacionDashboardStatsService
{
    /**
     * @param array<string, mixed> $organizacion Fila de organizaciones (id, nombre, cod_org, entidad, …)
     *
     * @return array{
     *   orgNombre: string,
     *   clubesCount: int,
     *   atletasCount: int,
     *   torneosActivos: int,
     *   clubes: list<array{id: int, nombre: string, delegado: string, estatus: string}>
     * }
     */
    public function fetch(array $organizacion): array
    {
        $orgNombre = (string) ($organizacion['nombre'] ?? 'Asociación');
        $orgId = (int) ($organizacion['id'] ?? 0);

        if ($orgId <= 0) {
            return $this->emptyPayload($orgNombre);
        }

        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
        require_once $appRoot . '/lib/OrganizacionDashboardStats.php';

        $pdo = DB::pdo();
        $hasCodOrg = DashboardSchemaHelper::hasOrganizacionesColumn('cod_org');

        $snapshot = OrganizacionDashboardStats::snapshot($pdo, $organizacion, $hasCodOrg);
        $stats = $snapshot['stats'] ?? [];

        return [
            'orgNombre' => $orgNombre,
            'clubesCount' => (int) ($stats['clubes'] ?? 0),
            'atletasCount' => (int) ($stats['afiliados'] ?? 0),
            'torneosActivos' => (int) ($stats['torneos_activos'] ?? 0),
            'clubes' => $this->fetchClubesList($pdo, $organizacion),
        ];
    }

    /**
     * Q5 — Listado de clubes del ámbito territorial.
     *
     * @param array<string, mixed> $organizacion
     *
     * @return list<array{id: int, nombre: string, delegado: string, estatus: string}>
     */
    private function fetchClubesList(PDO $pdo, array $organizacion): array
    {
        [$clubWhere, $clubParams] = OrganizacionDashboardStats::clubScopeWhereForOrganizacion($pdo, $organizacion);

        if ($clubWhere === '1=0') {
            return [];
        }

        $delegadoCol = DashboardSchemaHelper::hasColumn('clubes', 'delegado') ? 'c.delegado' : "''";

        try {
            $sql = "SELECT c.id, c.nombre, COALESCE({$delegadoCol}, '') AS delegado,
                           CASE WHEN c.estatus = 1 THEN 'Activo' ELSE 'Inactivo' END AS estatus
                    FROM clubes c
                    WHERE {$clubWhere}
                    ORDER BY c.nombre ASC
                    LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($clubParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $clubes = [];
        foreach ($rows as $row) {
            $clubes[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => (string) ($row['nombre'] ?? ''),
                'delegado' => (string) ($row['delegado'] ?? ''),
                'estatus' => (string) ($row['estatus'] ?? ''),
            ];
        }

        return $clubes;
    }

    /**
     * @return array{
     *   orgNombre: string,
     *   clubesCount: int,
     *   atletasCount: int,
     *   torneosActivos: int,
     *   clubes: list<array{id: int, nombre: string, delegado: string, estatus: string}>
     * }
     */
    private function emptyPayload(string $orgNombre): array
    {
        return [
            'orgNombre' => $orgNombre,
            'clubesCount' => 0,
            'atletasCount' => 0,
            'torneosActivos' => 0,
            'clubes' => [],
        ];
    }
}
