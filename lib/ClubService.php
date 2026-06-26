<?php
declare(strict_types=1);

require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/OrganizacionService.php';

/**
 * Consultas de clubes vinculados a una organización (asociación afiliada).
 */
final class ClubService
{
    private static ?bool $usuariosHasClubId = null;

    private static function usuariosHasClubIdColumn(PDO $pdo): bool
    {
        if (self::$usuariosHasClubId !== null) {
            return self::$usuariosHasClubId;
        }
        try {
            self::$usuariosHasClubId = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'club_id'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$usuariosHasClubId = false;
        }

        return self::$usuariosHasClubId;
    }

    private static function afiliadosCountSql(PDO $pdo, string $clubAlias = 'c'): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $clubAlias)) {
            $clubAlias = 'c';
        }

        if (self::usuariosHasClubIdColumn($pdo)) {
            return "(SELECT COUNT(*) FROM usuarios u
                     WHERE u.entidad = {$clubAlias}.id OR u.club_id = {$clubAlias}.id)";
        }

        return "(SELECT COUNT(*) FROM usuarios u WHERE u.entidad = {$clubAlias}.id)";
    }

    private static function clubesHasDelegadoColumn(PDO $pdo): bool
    {
        try {
            return (bool) $pdo->query("SHOW COLUMNS FROM clubes LIKE 'delegado'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Clubes nominalmente vinculados a la asociación, con delegado y total de afiliados.
     *
     * @return list<array{id: int, nombre: string, delegado: string, total_afiliados: int}>
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

        $orgRow = (array) $org;
        $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);

        try {
            [$clubWhere, $clubParams] = OrganizacionDashboardStats::clubScopeWhereForOrganizacion($pdo, $orgRow);
            if ($clubWhere === '1=0') {
                return [];
            }

            $delegadoExpr = self::clubesHasDelegadoColumn($pdo) ? 'COALESCE(c.delegado, \'\')' : '\'\'';
            $afiliadosExpr = self::afiliadosCountSql($pdo, 'c');

            $sql = "SELECT c.id, c.nombre, {$delegadoExpr} AS delegado,
                           {$afiliadosExpr} AS total_afiliados
                    FROM clubes c
                    WHERE {$clubWhere}
                    ORDER BY c.nombre ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($clubParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $idsPermitidos = OrganizacionDashboardStats::clubIdsForOrganizacion($pdo, $orgRow, $hasCodOrg);
            if ($idsPermitidos !== []) {
                $permitidos = array_flip($idsPermitidos);
                $rows = array_values(array_filter($rows, static function (array $row) use ($permitidos): bool {
                    $clubId = (int) ($row['id'] ?? 0);

                    return $clubId > 0 && isset($permitidos[$clubId]);
                }));
            }

            $clubes = [];
            $seen = [];
            foreach ($rows as $row) {
                $clubId = (int) ($row['id'] ?? 0);
                if ($clubId <= 0 || isset($seen[$clubId])) {
                    continue;
                }
                $seen[$clubId] = true;
                $clubes[] = [
                    'id' => $clubId,
                    'nombre' => (string) ($row['nombre'] ?? ''),
                    'delegado' => trim((string) ($row['delegado'] ?? '')),
                    'total_afiliados' => (int) ($row['total_afiliados'] ?? 0),
                ];
            }

            return $clubes;
        } catch (Throwable $e) {
            error_log('ClubService::getByOrg: ' . $e->getMessage());

            return [];
        }
    }
}
