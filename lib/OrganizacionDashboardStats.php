<?php

declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';

/**
 * Estadísticas de organización alineadas con el esquema real:
 * - usuarios: entidad (canónica) y club_id; columna organizacion_id opcional.
 * - clubes: organizacion_id (PK o cod_org) y/o entidad.
 * - tournaments: prioriza organizacion_id = PK de organizaciones; respaldo club_responsable / entidad.
 */
final class OrganizacionDashboardStats
{
    /** @var array{has_usuario_organizacion_id: bool, has_tournament_organizacion_id: bool}|null */
    private static ?array $columnFlags = null;

    /** @return array{has_usuario_organizacion_id: bool, has_tournament_organizacion_id: bool} */
    public static function columnFlags(PDO $pdo): array
    {
        if (self::$columnFlags !== null) {
            return self::$columnFlags;
        }
        self::$columnFlags = [
            'has_usuario_organizacion_id' => false,
            'has_tournament_organizacion_id' => false,
        ];
        // Comprobar columnas con SELECT LIMIT 0: más fiable que SHOW COLUMNS si el esquema difiere entre entornos.
        try {
            $pdo->query('SELECT `organizacion_id` FROM `usuarios` LIMIT 0');
            self::$columnFlags['has_usuario_organizacion_id'] = true;
        } catch (Throwable $ignored) {
        }
        try {
            $pdo->query('SELECT `organizacion_id` FROM `tournaments` LIMIT 0');
            self::$columnFlags['has_tournament_organizacion_id'] = true;
        } catch (Throwable $ignored) {
        }

        return self::$columnFlags;
    }

    /** @return int[] */
    private static function idsRef(int $org_pk, int $org_ref): array
    {
        return array_values(array_unique(array_filter([$org_pk, $org_ref], static fn (int $v): bool => $v > 0)));
    }

    /** WHERE sobre clubes (alias c) + parámetros */
    private static function clubScopeSqlAndParams(int $org_pk, int $org_ref, int $org_entidad): array
    {
        $ids = self::idsRef($org_pk, $org_ref);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "c.estatus = 1 AND (c.organizacion_id IN ({$ph}) OR (? > 0 AND COALESCE(c.entidad, 0) = ?))";

        return [$sql, array_merge($ids, [$org_entidad, $org_entidad])];
    }

    /**
     * IDs de clubes activos vinculados a la organización (misma lógica que el dashboard).
     *
     * @param array<string, mixed> $organizacion Fila de organizaciones (id, cod_org, entidad)
     * @return int[]
     */
    public static function clubIdsForOrganizacion(PDO $pdo, array $organizacion, bool $has_cod_org): array
    {
        $org_pk = (int) ($organizacion['id'] ?? 0);
        if ($org_pk <= 0) {
            return [];
        }
        $org_ref = (int) ($organizacion['cod_org'] ?? 0);
        if ($org_ref <= 0) {
            $org_ref = $org_pk;
        }
        $org_entidad = (int) ($organizacion['entidad'] ?? 0);
        [$clubWhere, $clubParams] = self::clubScopeSqlAndParams($org_pk, $org_ref, $org_entidad);
        $stmt = $pdo->prepare("SELECT DISTINCT c.id FROM clubes c WHERE {$clubWhere}");
        $stmt->execute($clubParams);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /** WHERE sobre tournaments (alias t) + parámetros; $activosProximos añade fechator >= CURDATE() */
    private static function torneoScopeSqlAndParams(
        PDO $pdo,
        bool $has_cod_org,
        int $org_pk,
        int $org_ref,
        int $org_entidad,
        bool $activosProximos
    ): array {
        $flags = self::columnFlags($pdo);
        $ids = self::idsRef($org_pk, $org_ref);
        $idParams = count($ids) > 0 ? $ids : [$org_pk];
        $ph = implode(',', array_fill(0, count($idParams), '?'));

        $entPart = '(? = 0 OR COALESCE(t.entidad, 0) = ?)';
        $entParams = [$org_entidad, $org_entidad];

        $fechaPart = $activosProximos ? ' AND t.fechator >= CURDATE()' : '';

        if ($flags['has_tournament_organizacion_id']) {
            $legacyClub = "t.club_responsable IN ({$ph})";
            $legacyParams = $idParams;
            if ($has_cod_org) {
                $legacyClub = "({$legacyClub} OR t.club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1))";
                $legacyParams = array_merge($idParams, [$org_ref]);
            }
            $sql = "{$entPart}{$fechaPart} AND t.estatus = 1 AND (
                (t.organizacion_id IS NOT NULL AND t.organizacion_id > 0 AND t.organizacion_id = ?)
                OR (
                    (t.organizacion_id IS NULL OR t.organizacion_id = 0)
                    AND ({$legacyClub})
                )
            )";
            $params = array_merge($entParams, [$org_pk], $legacyParams);

            return [$sql, $params];
        }

        if ($has_cod_org) {
            $sql = "{$entPart}{$fechaPart} AND t.estatus = 1 AND (
                t.club_responsable IN ({$ph})
                OR t.club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)
            )";
            $params = array_merge($entParams, $idParams, [$org_ref]);

            return [$sql, $params];
        }

        $sql = "{$entPart}{$fechaPart} AND t.estatus = 1 AND t.club_responsable IN ({$ph})";
        $params = array_merge($entParams, $idParams);

        return [$sql, $params];
    }

    private static function usuarioTerritorioSql(PDO $pdo): string
    {
        $flags = self::columnFlags($pdo);

        return $flags['has_usuario_organizacion_id']
            ? '(? > 0 AND COALESCE(NULLIF(u.organizacion_id, 0), COALESCE(u.entidad, 0)) = ?)'
            : '(? > 0 AND COALESCE(u.entidad, 0) = ?)';
    }

    /**
     * Expresión SQL (sin WHERE) para “territorio” del usuario: entidad, u opcionalmente organizacion_id.
     */
    public static function usuarioTerritorioCoalesceExpr(PDO $pdo, string $alias = 'u'): string
    {
        $flags = self::columnFlags($pdo);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 'u';
        }

        return $flags['has_usuario_organizacion_id']
            ? "COALESCE(NULLIF({$alias}.organizacion_id, 0), COALESCE({$alias}.entidad, 0))"
            : "COALESCE({$alias}.entidad, 0)";
    }

    /**
     * @param array<string, mixed> $organizacion
     * @return array{
     *   stats: array{clubes:int,torneos:int,torneos_activos:int,afiliados:int,usuarios:int,inscripciones:int},
     *   torneos_recientes: list<array<string,mixed>>
     * }
     */
    public static function snapshot(PDO $pdo, array $organizacion, bool $has_cod_org): array
    {
        self::columnFlags($pdo);

        $org_pk = (int) ($organizacion['id'] ?? 0);
        if ($org_pk <= 0) {
            return [
                'stats' => [
                    'clubes' => 0,
                    'torneos' => 0,
                    'torneos_activos' => 0,
                    'afiliados' => 0,
                    'usuarios' => 0,
                    'inscripciones' => 0,
                ],
                'torneos_recientes' => [],
            ];
        }
        $org_ref = (int) ($organizacion['cod_org'] ?? 0);
        if ($org_ref <= 0) {
            $org_ref = $org_pk;
        }
        $org_entidad = (int) ($organizacion['entidad'] ?? 0);

        [$clubWhere, $clubParams] = self::clubScopeSqlAndParams($org_pk, $org_ref, $org_entidad);
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM clubes c WHERE {$clubWhere}");
        $stmt->execute($clubParams);
        $stats_clubes = (int) $stmt->fetchColumn();

        [$torneoActWhere, $torneoActParams] = self::torneoScopeSqlAndParams($pdo, $has_cod_org, $org_pk, $org_ref, $org_entidad, true);
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE {$torneoActWhere}");
        $stmt->execute($torneoActParams);
        $stats_torneos_activos = (int) $stmt->fetchColumn();

        [$torneoTotWhere, $torneoTotParams] = self::torneoScopeSqlAndParams($pdo, $has_cod_org, $org_pk, $org_ref, $org_entidad, false);
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tournaments t WHERE {$torneoTotWhere}");
        $stmt->execute($torneoTotParams);
        $stats_torneos_total = (int) $stmt->fetchColumn();

        $uTerr = self::usuarioTerritorioSql($pdo);
        $terrParams = [$org_entidad, $org_entidad];

        $sqlAfiliados = "
            SELECT COUNT(DISTINCT u.id) FROM usuarios u
            WHERE u.role = 'usuario' AND u.status = 0
            AND (
                {$uTerr}
                OR EXISTS (
                    SELECT 1 FROM clubes c
                    WHERE c.id = u.club_id
                      AND ({$clubWhere})
                )
            )";
        $stmt = $pdo->prepare($sqlAfiliados);
        $stmt->execute(array_merge($terrParams, $clubParams));
        $stats_afiliados = (int) $stmt->fetchColumn();

        $sqlUsuarios = "
            SELECT COUNT(DISTINCT u.id) FROM usuarios u
            WHERE u.status = 0
            AND (
                {$uTerr}
                OR EXISTS (
                    SELECT 1 FROM clubes c
                    WHERE c.id = u.club_id
                      AND ({$clubWhere})
                )
            )";
        $stmt = $pdo->prepare($sqlUsuarios);
        $stmt->execute(array_merge($terrParams, $clubParams));
        $stats_usuarios = (int) $stmt->fetchColumn();

        $whereInsc = InscritosHelper::sqlWhereSoloConfirmadoConAlias('i');
        $sqlInsc = "
            SELECT COUNT(DISTINCT i.id) FROM inscritos i
            INNER JOIN tournaments t ON i.torneo_id = t.id
            WHERE {$torneoTotWhere}
              AND {$whereInsc}
        ";
        $stmt = $pdo->prepare($sqlInsc);
        $stmt->execute($torneoTotParams);
        $stats_inscripciones = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT DISTINCT t.id, t.nombre, t.fechator, t.estatus
             FROM tournaments t
             WHERE {$torneoTotWhere}
             ORDER BY t.fechator DESC, t.id DESC
             LIMIT 12"
        );
        $stmt->execute($torneoTotParams);
        $torneos_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'stats' => [
                'clubes' => $stats_clubes,
                'torneos' => $stats_torneos_total,
                'torneos_activos' => $stats_torneos_activos,
                'afiliados' => $stats_afiliados,
                'usuarios' => $stats_usuarios,
                'inscripciones' => $stats_inscripciones,
            ],
            'torneos_recientes' => $torneos_recientes,
        ];
    }

    /**
     * Desglose por sexo de afiliados (misma regla que snapshot: territorio + clubes de la organización).
     *
     * @param array<string, mixed> $organizacion
     * @return array{hombres: int, mujeres: int, sin_genero: int}
     */
    public static function affiliateGenderCounts(PDO $pdo, array $organizacion, bool $has_cod_org): array
    {
        self::columnFlags($pdo);

        $org_pk = (int) ($organizacion['id'] ?? 0);
        if ($org_pk <= 0) {
            return ['hombres' => 0, 'mujeres' => 0, 'sin_genero' => 0];
        }
        $org_ref = (int) ($organizacion['cod_org'] ?? 0);
        if ($org_ref <= 0) {
            $org_ref = $org_pk;
        }
        $org_entidad = (int) ($organizacion['entidad'] ?? 0);

        [$clubWhere, $clubParams] = self::clubScopeSqlAndParams($org_pk, $org_ref, $org_entidad);
        $uTerr = self::usuarioTerritorioSql($pdo);
        $terrParams = [$org_entidad, $org_entidad];

        $sql = "
            SELECT
                SUM(CASE WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) IN ('M', '1') OR u.sexo = 1 THEN 1 ELSE 0 END) AS hombres,
                SUM(CASE WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) IN ('F', '2') OR u.sexo = 2 THEN 1 ELSE 0 END) AS mujeres,
                SUM(CASE
                    WHEN u.sexo IS NULL OR TRIM(COALESCE(CAST(u.sexo AS CHAR), '')) = '' THEN 1
                    WHEN UPPER(TRIM(COALESCE(u.sexo, ''))) NOT IN ('M', 'F', '1', '2') AND u.sexo NOT IN (1, 2) THEN 1
                    ELSE 0
                END) AS sin_genero
            FROM usuarios u
            WHERE u.role = 'usuario' AND u.status = 0
            AND (
                {$uTerr}
                OR EXISTS (
                    SELECT 1 FROM clubes c
                    WHERE c.id = u.club_id
                      AND ({$clubWhere})
                )
            )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($terrParams, $clubParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'hombres' => (int) ($row['hombres'] ?? 0),
            'mujeres' => (int) ($row['mujeres'] ?? 0),
            'sin_genero' => (int) ($row['sin_genero'] ?? 0),
        ];
    }
}
