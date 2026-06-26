<?php
declare(strict_types=1);

require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/OrganizacionService.php';

/**
 * Consultas de afiliados (atletas) vinculados a una organización.
 */
final class AfiliadoService
{
    private static ?bool $usuariosHasOrganizacionId = null;

    private static ?bool $partiresulHasFecha = null;

    private static function usuariosHasOrganizacionIdColumn(PDO $pdo): bool
    {
        if (self::$usuariosHasOrganizacionId !== null) {
            return self::$usuariosHasOrganizacionId;
        }
        try {
            self::$usuariosHasOrganizacionId = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'organizacion_id'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$usuariosHasOrganizacionId = false;
        }

        return self::$usuariosHasOrganizacionId;
    }

    private static function partiresulTieneFechaPartida(PDO $pdo): bool
    {
        if (self::$partiresulHasFecha !== null) {
            return self::$partiresulHasFecha;
        }
        try {
            $pt = $pdo->query("SHOW TABLES LIKE 'partiresul'");
            if (! $pt || $pt->rowCount() === 0) {
                self::$partiresulHasFecha = false;

                return false;
            }
            $fc = $pdo->query("SHOW COLUMNS FROM partiresul WHERE Field = 'fecha_partida'");
            self::$partiresulHasFecha = (bool) ($fc && $fc->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            self::$partiresulHasFecha = false;
        }

        return self::$partiresulHasFecha;
    }

    /**
     * @param array<string, mixed> $orgRow
     * @return array{0: string, 1: list<mixed>}
     */
    private static function filtroOrganizacionSql(PDO $pdo, array $orgRow): array
    {
        $orgPk = (int) ($orgRow['id'] ?? 0);
        if ($orgPk <= 0) {
            return ['1=0', []];
        }

        if (self::usuariosHasOrganizacionIdColumn($pdo)) {
            return ['u.organizacion_id = ?', [$orgPk]];
        }

        $flags = OrganizacionDashboardStats::columnFlags($pdo);
        if ($flags['has_usuario_cod_org']) {
            $canonical = OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($orgRow);
            $orgRef = (int) ($orgRow['cod_org'] ?? 0);
            if ($orgRef <= 0) {
                $orgRef = $orgPk;
            }
            $ids = array_values(array_unique(array_filter([$canonical, $orgRef, $orgPk], static fn (int $v): bool => $v > 0)));

            if (count($ids) === 1) {
                return ['COALESCE(NULLIF(u.cod_org, 0), 0) = ?', [$ids[0]]];
            }

            $ph = implode(',', array_fill(0, count($ids), '?'));

            return ["COALESCE(NULLIF(u.cod_org, 0), 0) IN ({$ph})", $ids];
        }

        $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        $clubIds = OrganizacionDashboardStats::clubIdsForOrganizacion($pdo, $orgRow, $hasCodOrg);
        if ($clubIds !== []) {
            $ph = implode(',', array_fill(0, count($clubIds), '?'));

            return ["(u.entidad IN ({$ph}) OR u.club_id IN ({$ph}))", array_merge($clubIds, $clubIds)];
        }

        $orgEntidad = (int) ($orgRow['entidad'] ?? 0);
        if ($orgEntidad > 0) {
            $flags = OrganizacionDashboardStats::columnFlags($pdo);
            if ($flags['has_usuario_cod_org']) {
                return [
                    '(? > 0 AND COALESCE(NULLIF(u.cod_org, 0), COALESCE(u.entidad, 0)) = ?)',
                    [$orgEntidad, $orgEntidad],
                ];
            }

            return ['(? > 0 AND COALESCE(u.entidad, 0) = ?)', [$orgEntidad, $orgEntidad]];
        }

        return ['1=0', []];
    }

    private static function ultimaActividadExpr(PDO $pdo): string
    {
        if (self::partiresulTieneFechaPartida($pdo)) {
            return 'COALESCE(
                (SELECT MAX(pr.fecha_partida) FROM partiresul pr WHERE pr.id_usuario = u.id),
                u.last_login,
                u.updated_at,
                u.created_at
            )';
        }

        return 'COALESCE(u.last_login, u.updated_at, u.created_at)';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function esActivo(array $row): bool
    {
        if (array_key_exists('is_active', $row) && (int) $row['is_active'] === 0) {
            return false;
        }

        $status = $row['status'] ?? 0;
        if ($status === 1 || $status === '1' || $status === 'inactive' || $status === 'rejected') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $clubId = (int) ($row['club_id_resuelto'] ?? 0);
        if ($clubId <= 0) {
            $clubId = (int) ($row['club_id'] ?? 0);
        }
        if ($clubId <= 0) {
            $clubId = (int) ($row['entidad'] ?? 0);
        }

        $ultima = $row['ultima_actividad'] ?? null;
        $ultimaFmt = '—';
        if ($ultima !== null && (string) $ultima !== '' && (string) $ultima !== '0000-00-00 00:00:00') {
            $ts = strtotime((string) $ultima);
            $ultimaFmt = $ts !== false ? date('d/m/Y H:i', $ts) : (string) $ultima;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'cedula' => (string) ($row['cedula'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'club_id' => $clubId,
            'club_nombre' => trim((string) ($row['club_nombre'] ?? '')) !== ''
                ? (string) $row['club_nombre']
                : '—',
            'estatus' => self::esActivo($row) ? 'activo' : 'inactivo',
            'estatus_label' => self::esActivo($row) ? 'Activo' : 'Inactivo',
            'ultima_actividad' => $ultima,
            'ultima_actividad_fmt' => $ultimaFmt,
        ];
    }

    /**
     * Afiliados/atletas de la organización (filtro por organizacion_id / cod_org / clubes del ámbito).
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

        $orgRow = (array) $org;
        [$orgFilterSql, $orgFilterParams] = self::filtroOrganizacionSql($pdo, $orgRow);
        $ultimaExpr = self::ultimaActividadExpr($pdo);

        try {
            $sql = "SELECT u.id, u.nombre, u.cedula, u.email, u.status, u.is_active,
                           u.club_id, u.entidad,
                           COALESCE(NULLIF(u.club_id, 0), u.entidad) AS club_id_resuelto,
                           COALESCE(c.nombre, '') AS club_nombre,
                           {$ultimaExpr} AS ultima_actividad
                    FROM usuarios u
                    LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(u.club_id, 0), u.entidad)
                    WHERE u.role = 'usuario'
                      AND ({$orgFilterSql})
                    ORDER BY u.nombre ASC
                    LIMIT 500";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($orgFilterParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $afiliados = [];
            foreach ($rows as $row) {
                $afiliados[] = self::mapRow($row);
            }

            return $afiliados;
        } catch (Throwable $e) {
            error_log('AfiliadoService::getByOrg: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Un afiliado dentro del ámbito de la organización (validación para ficha/PDF).
     *
     * @return array<string, mixed>|null
     */
    public static function getByIdInOrg(int $orgId, int $userId): ?array
    {
        if ($orgId <= 0 || $userId <= 0) {
            return null;
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }

        $pdo = DB::pdo();
        $org = OrganizacionService::getById($orgId);
        if ($org === null) {
            return null;
        }

        $orgRow = (array) $org;
        [$orgFilterSql, $orgFilterParams] = self::filtroOrganizacionSql($pdo, $orgRow);
        $ultimaExpr = self::ultimaActividadExpr($pdo);

        try {
            $sql = "SELECT u.id, u.nombre, u.cedula, u.email, u.status, u.is_active,
                           u.club_id, u.entidad,
                           COALESCE(NULLIF(u.club_id, 0), u.entidad) AS club_id_resuelto,
                           COALESCE(c.nombre, '') AS club_nombre,
                           {$ultimaExpr} AS ultima_actividad
                    FROM usuarios u
                    LEFT JOIN clubes c ON c.id = COALESCE(NULLIF(u.club_id, 0), u.entidad)
                    WHERE u.id = ?
                      AND u.role = 'usuario'
                      AND ({$orgFilterSql})
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$userId], $orgFilterParams));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (! $row) {
                return null;
            }

            return self::mapRow($row);
        } catch (Throwable $e) {
            error_log('AfiliadoService::getByIdInOrg: ' . $e->getMessage());

            return null;
        }
    }
}
