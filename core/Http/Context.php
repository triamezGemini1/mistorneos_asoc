<?php

declare(strict_types=1);

namespace Core\Http;

use Auth;
use DB;
use PDO;
use Throwable;

/**
 * Resuelve el contexto institucional según el rol activo en sesión y la organización vinculada.
 */
final class Context
{
    public const INVITADO = 'Invitado';

    public const FEDERACION = 'Federacion';

    public const ASOCIACION = 'Asociacion';

    public const CLUB = 'Club';

    /** @var array{type: string, role: string|null, org: array<string, mixed>|null, club: array<string, mixed>|null}|null */
    private static ?array $resolved = null;

    /** @var array<string, bool> */
    private static array $columnCache = [];

    public static function resolve(): string
    {
        return self::resolveWithMeta()['type'];
    }

    /**
     * @return array{type: string, role: string|null, org: array<string, mixed>|null, club: array<string, mixed>|null}
     */
    public static function resolveWithMeta(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        if (!class_exists('Auth') || !Auth::user()) {
            return self::$resolved = [
                'type' => self::INVITADO,
                'role' => null,
                'org' => null,
                'club' => null,
            ];
        }

        $user = Auth::user();
        $role = self::activeRole($user);

        if ($role === 'admin_general') {
            return self::$resolved = [
                'type' => self::FEDERACION,
                'role' => $role,
                'org' => null,
                'club' => null,
            ];
        }

        if (in_array($role, ['admin_torneo', 'operador'], true)) {
            $clubContext = self::loadClubContextOrg($user);

            return self::$resolved = [
                'type' => self::CLUB,
                'role' => $role,
                'org' => $clubContext['org'] ?? null,
                'club' => $clubContext['club'] ?? null,
            ];
        }

        if ($role === 'usuario') {
            return self::$resolved = [
                'type' => self::INVITADO,
                'role' => $role,
                'org' => null,
                'club' => null,
            ];
        }

        if ($role === 'admin_club') {
            $org = self::loadAdminClubOrganizacion((int) Auth::id());
            if ($org === null) {
                return self::$resolved = [
                    'type' => self::INVITADO,
                    'role' => $role,
                    'org' => null,
                    'club' => null,
                ];
            }

            return self::$resolved = [
                'type' => self::classifyOrganizacion($org),
                'role' => $role,
                'org' => $org,
                'club' => null,
            ];
        }

        return self::$resolved = [
            'type' => self::INVITADO,
            'role' => $role,
            'org' => null,
            'club' => null,
        ];
    }

    public static function reset(): void
    {
        self::$resolved = null;
    }

    /**
     * Rol activo en sesión (respeta simulación de roles).
     *
     * @param array<string, mixed> $user
     */
    private static function activeRole(array $user): string
    {
        $role = trim((string) ($user['role'] ?? ''));

        return strtolower($role);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadAdminClubOrganizacion(int $userId): ?array
    {
        try {
            $cols = ['id', 'nombre', 'entidad', 'logo', 'estatus'];
            if (self::organizacionesHasColumn('tipo_org')) {
                $cols[] = 'tipo_org';
            }
            if (self::organizacionesHasColumn('cod_org')) {
                $cols[] = 'cod_org';
            }

            $sql = 'SELECT ' . implode(', ', $cols)
                . ' FROM organizaciones WHERE admin_user_id = ? AND estatus = 1 ORDER BY id ASC LIMIT 1';

            $stmt = DB::pdo()->prepare($sql);
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * JOIN clubes ↔ organizaciones (misma lógica que Auth::getDashboardOrganizacion).
     *
     * @param array<string, mixed> $user
     *
     * @return array{org: array<string, mixed>|null, club: array<string, mixed>|null}
     */
    private static function loadClubContextOrg(array $user): array
    {
        $clubId = (int) ($user['club_id'] ?? 0);
        if ($clubId <= 0) {
            return ['org' => null, 'club' => null];
        }

        try {
            $hasCodOrg = self::organizacionesHasColumn('cod_org');
            $orgJoin = $hasCodOrg
                ? 'LEFT JOIN organizaciones o ON (c.cod_org = o.id OR c.cod_org = o.cod_org) AND o.estatus = 1'
                : 'LEFT JOIN organizaciones o ON c.cod_org = o.id AND o.estatus = 1';

            $orgCols = ['o.id AS org_id', 'o.nombre AS org_nombre', 'o.logo AS org_logo', 'o.entidad AS org_entidad', 'o.estatus AS org_estatus'];
            if ($hasCodOrg) {
                $orgCols[] = 'o.cod_org AS org_cod_org';
            }
            if (self::organizacionesHasColumn('tipo_org')) {
                $orgCols[] = 'o.tipo_org AS org_tipo_org';
            }

            $select = array_merge(
                ['c.id AS club_id', 'c.nombre AS club_nombre', 'c.logo AS club_logo'],
                $orgCols
            );

            $sql = 'SELECT ' . implode(', ', $select) . " FROM clubes c {$orgJoin} WHERE c.id = ? LIMIT 1";
            $stmt = DB::pdo()->prepare($sql);
            $stmt->execute([$clubId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return ['org' => null, 'club' => null];
            }

            $club = [
                'id' => (int) ($row['club_id'] ?? 0),
                'nombre' => (string) ($row['club_nombre'] ?? ''),
                'logo' => $row['club_logo'] ?? null,
            ];

            $orgId = (int) ($row['org_id'] ?? 0);
            if ($orgId <= 0) {
                return [
                    'org' => [
                        'id' => null,
                        'nombre' => $club['nombre'],
                        'logo' => $club['logo'],
                    ],
                    'club' => $club,
                ];
            }

            $org = [
                'id' => $orgId,
                'nombre' => (string) ($row['org_nombre'] ?? ''),
                'logo' => $row['org_logo'] ?? null,
                'entidad' => (int) ($row['org_entidad'] ?? 0),
                'estatus' => (int) ($row['org_estatus'] ?? 1),
            ];
            if (isset($row['org_cod_org'])) {
                $org['cod_org'] = (int) $row['org_cod_org'];
            }
            if (isset($row['org_tipo_org'])) {
                $org['tipo_org'] = (int) $row['org_tipo_org'];
            }

            return ['org' => $org, 'club' => $club];
        } catch (Throwable $e) {
            return ['org' => null, 'club' => null];
        }
    }

    /**
     * @param array<string, mixed> $org
     */
    private static function classifyOrganizacion(array $org): string
    {
        $rootId = 0;
        if (class_exists('SegmentConfig', false)) {
            $rootId = (int) SegmentConfig::organizacionRaizId();
        }
        if ($rootId <= 0 && class_exists('FvdConfig', false)) {
            $rootId = (int) FvdConfig::organizacionId();
        }
        if ($rootId > 0 && (int) ($org['id'] ?? 0) === $rootId) {
            return self::FEDERACION;
        }

        if (isset($org['tipo_org'])) {
            return (int) $org['tipo_org'] === 1 ? self::CLUB : self::ASOCIACION;
        }

        return self::ASOCIACION;
    }

    private static function organizacionesHasColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }

        try {
            $quoted = DB::pdo()->quote($column);
            self::$columnCache[$column] = (bool) DB::pdo()
                ->query("SHOW COLUMNS FROM organizaciones LIKE {$quoted}")
                ->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$columnCache[$column] = false;
        }

        return self::$columnCache[$column];
    }
}
