<?php

declare(strict_types=1);

require_once __DIR__ . '/OrganizacionDashboardStats.php';

/**
 * Torneos agrupados por organización, separados por tipo (asociación vs particular).
 */
final class TorneosEstructuraService
{
    public const CONTEXT_ASOCIACIONES = 'asociaciones';
    public const CONTEXT_PARTICULARES = 'particulares';

    /** @var array<string, bool> */
    private static array $cache = [];

    public static function isValidContext(string $context): bool
    {
        return in_array($context, [self::CONTEXT_ASOCIACIONES, self::CONTEXT_PARTICULARES], true);
    }

    public static function contextLabel(string $context): string
    {
        return $context === self::CONTEXT_PARTICULARES
            ? 'Organizaciones particulares'
            : 'Asociaciones territoriales';
    }

    public static function tipoOrgFilterSql(PDO $pdo, string $context, string $orgAlias = 'org_res'): string
    {
        if (!self::hasTipoOrg($pdo)) {
            return $context === self::CONTEXT_ASOCIACIONES ? '1=1' : '1=0';
        }
        $tipo = $context === self::CONTEXT_PARTICULARES ? 1 : 0;

        return "COALESCE({$orgAlias}.tipo_org, 0) = {$tipo}";
    }

    public static function hasTipoOrg(PDO $pdo): bool
    {
        if (isset(self::$cache['tipo_org'])) {
            return self::$cache['tipo_org'];
        }
        try {
            $pdo->query('SELECT tipo_org FROM organizaciones LIMIT 0');
            self::$cache['tipo_org'] = true;
        } catch (Throwable $e) {
            self::$cache['tipo_org'] = false;
        }

        return self::$cache['tipo_org'];
    }

    public static function hasTournamentCodOrg(PDO $pdo): bool
    {
        if (isset(self::$cache['t_cod_org'])) {
            return self::$cache['t_cod_org'];
        }
        try {
            $pdo->query('SELECT cod_org FROM tournaments LIMIT 0');
            self::$cache['t_cod_org'] = true;
        } catch (Throwable $e) {
            self::$cache['t_cod_org'] = false;
        }

        return self::$cache['t_cod_org'];
    }

    public static function hasTournamentLocked(PDO $pdo): bool
    {
        if (isset(self::$cache['t_locked'])) {
            return self::$cache['t_locked'];
        }
        try {
            $pdo->query('SELECT locked FROM tournaments LIMIT 0');
            self::$cache['t_locked'] = true;
        } catch (Throwable $e) {
            self::$cache['t_locked'] = false;
        }

        return self::$cache['t_locked'];
    }

    /**
     * Resuelve organización responsable del torneo según tipo_org (sin mezclar particulares con asociación).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function orgResolveJoinSql(
        PDO $pdo,
        string $context,
        string $tAlias = 't',
        string $orgAlias = 'org_res',
        bool $innerJoin = false
    ): array {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tAlias)) {
            $tAlias = 't';
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $orgAlias)) {
            $orgAlias = 'org_res';
        }

        $joinKw = $innerJoin ? 'INNER JOIN' : 'LEFT JOIN';
        $hasTournamentCodOrg = self::hasTournamentCodOrg($pdo);
        $tipoFilter = self::tipoOrgFilterSql($pdo, $context, $orgAlias);

        if ($context === self::CONTEXT_PARTICULARES) {
            // Particular: solo PK/cod_org propio; entidad NO vincula torneos.
            $parts = [
                "{$orgAlias}.id = {$tAlias}.club_responsable",
            ];
            if ($hasTournamentCodOrg) {
                $parts[] = "(COALESCE({$tAlias}.cod_org, 0) > 0 AND {$orgAlias}.cod_org = {$tAlias}.cod_org)";
                $parts[] = "(COALESCE({$tAlias}.cod_org, 0) > 0 AND {$orgAlias}.id = {$tAlias}.cod_org)";
            }
            $parts[] = "EXISTS (
                SELECT 1 FROM clubes c_r
                WHERE c_r.id = {$tAlias}.club_responsable
                  AND c_r.estatus = 1
                  AND (
                    {$orgAlias}.id = c_r.cod_org
                    OR (COALESCE({$orgAlias}.cod_org, 0) > 0 AND {$orgAlias}.cod_org = c_r.cod_org)
                  )
            )";
        } else {
            // Asociación territorial: código federación (cod_org o entidad) vía club o referencia directa.
            $clubFed = OrganizacionDashboardStats::sqlClubMismaFederacionQueOrg($pdo, 'c_r', $orgAlias);
            $orgCanon = "COALESCE(NULLIF({$orgAlias}.cod_org, 0), NULLIF({$orgAlias}.entidad, 0))";
            $parts = [
                "{$orgAlias}.id = {$tAlias}.club_responsable",
                "EXISTS (
                    SELECT 1 FROM clubes c_r
                    WHERE c_r.id = {$tAlias}.club_responsable
                      AND c_r.estatus = 1
                      AND ({$clubFed})
                )",
            ];
            if ($hasTournamentCodOrg) {
                $parts[] = "(COALESCE({$tAlias}.cod_org, 0) > 0 AND {$orgAlias}.cod_org = {$tAlias}.cod_org)";
                $parts[] = "(COALESCE({$tAlias}.cod_org, 0) > 0 AND ({$orgCanon}) = {$tAlias}.cod_org)";
            }
        }

        $on = '(' . implode(' OR ', $parts) . ") AND {$orgAlias}.estatus = 1 AND ({$tipoFilter})";

        return ["{$joinKw} organizaciones {$orgAlias} ON {$on}", []];
    }

    /**
     * Prioridad de coincidencia torneo→organización (mayor = mejor).
     * Asociación: priorizar código de federación (cod_org/entidad), no confundir con PK.
     */
    private static function orgMatchScore(array $row): int
    {
        $clubResp = (int) ($row['club_responsable'] ?? 0);
        $tCodOrg = (int) ($row['torneo_cod_org'] ?? $row['cod_org'] ?? 0);
        $orgId = (int) ($row['org_id'] ?? 0);
        $orgCod = (int) ($row['org_cod_org'] ?? 0);
        $tipo = (int) ($row['org_tipo_org'] ?? 0);
        $orgEntidad = (int) ($row['entidad'] ?? 0);
        $canonical = (int) ($row['org_canonical'] ?? 0);

        if ($orgId <= 0) {
            return 0;
        }

        if ($tipo === 1) {
            if ($orgId === $clubResp) {
                return 100;
            }
            if ($tCodOrg > 0 && ($tCodOrg === $orgCod || $tCodOrg === $orgId)) {
                return 95;
            }

            return 50;
        }

        // Asociación territorial
        if ($tCodOrg > 0 && $canonical > 0 && $tCodOrg === $canonical) {
            return 98;
        }
        if ($tCodOrg > 0 && $orgCod > 0 && $tCodOrg === $orgCod) {
            return 95;
        }
        if ($tCodOrg > 0 && $orgEntidad > 0 && $tCodOrg === $orgEntidad) {
            return 93;
        }
        if ($clubResp > 0 && $canonical > 0 && $clubResp === $canonical) {
            return 90;
        }
        if ($clubResp > 0 && $orgCod > 0 && $clubResp === $orgCod) {
            return 88;
        }
        if ($clubResp > 0 && $orgEntidad > 0 && $clubResp === $orgEntidad) {
            return 86;
        }
        if ($orgId === $clubResp) {
            return 55;
        }

        return 40;
    }

    /**
     * Índice id => fila organización (con entidad_nombre y código canónico).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadOrganizacionesIndex(PDO $pdo, string $context): array
    {
        if (!self::isValidContext($context)) {
            return [];
        }
        $tipoSql = self::tipoOrgFilterSql($pdo, $context, 'o');
        $stmt = $pdo->query("
            SELECT o.id, o.nombre, o.cod_org, o.entidad, o.tipo_org, e.nombre AS entidad_nombre
            FROM organizaciones o
            LEFT JOIN entidad e ON e.id = o.entidad
            WHERE o.estatus = 1 AND {$tipoSql}
        ");
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $org) {
            $id = (int) ($org['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $org['org_canonical'] = OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($org);
            $index[$id] = $org;
        }

        return $index;
    }

    /**
     * Alinea nombre, cod_org y entidad del torneo con la fila canónica en organizaciones.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $orgsIndex
     * @return array<string, mixed>
     */
    public static function enrichTorneoOrganizacion(array $row, array $orgsIndex): array
    {
        $orgId = (int) ($row['org_id'] ?? 0);
        if ($orgId <= 0 || !isset($orgsIndex[$orgId])) {
            $row['org_nombre'] = 'Sin organización';
            $row['org_codigo_label'] = '';
            $row['entidad_nombre'] = '';

            return $row;
        }
        $org = $orgsIndex[$orgId];
        $row['org_id'] = $orgId;
        $row['org_nombre'] = (string) ($org['nombre'] ?? 'Sin organización');
        $row['org_cod_org'] = (int) ($org['cod_org'] ?? 0);
        $row['org_tipo_org'] = (int) ($org['tipo_org'] ?? 0);
        $row['entidad'] = (int) ($org['entidad'] ?? 0);
        $row['entidad_nombre'] = trim((string) ($org['entidad_nombre'] ?? ''));
        $row['org_canonical'] = (int) ($org['org_canonical'] ?? 0);
        $canonical = (int) $row['org_canonical'];
        if ($canonical > 0) {
            $row['org_codigo_label'] = (string) $canonical;
        } else {
            $row['org_codigo_label'] = (string) $orgId;
        }

        return $row;
    }

    /**
     * Un torneo puede coincidir con varias organizaciones por datos legacy; conservar la mejor.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function deduplicateTorneosById(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (!isset($byId[$tid]) || self::orgMatchScore($row) > self::orgMatchScore($byId[$tid])) {
                $byId[$tid] = $row;
            }
        }

        return array_values($byId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchTorneos(PDO $pdo, string $context, ?string $filtroCategoria = null, int $organizacionId = 0): array
    {
        if (!self::isValidContext($context)) {
            return [];
        }

        [$orgJoin] = self::orgResolveJoinSql($pdo, $context, 't', 'org_res', true);
        $hasLocked = self::hasTournamentLocked($pdo);

        $where = ['1=1'];
        $params = [];

        if ($organizacionId > 0) {
            $where[] = 'org_res.id = ?';
            $params[] = $organizacionId;
        }

        $torneoCodOrgSelect = self::hasTournamentCodOrg($pdo)
            ? 't.cod_org AS torneo_cod_org'
            : '0 AS torneo_cod_org';

        $sql = "
            SELECT
                t.id,
                t.nombre,
                t.fechator,
                t.rondas,
                t.estatus,
                t.club_responsable,
                {$torneoCodOrgSelect},
                t.locked,
                org_res.id AS org_id,
                org_res.nombre AS org_nombre,
                org_res.entidad AS entidad,
                org_res.tipo_org AS org_tipo_org,
                org_res.cod_org AS org_cod_org,
                (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = t.id) AS total_inscritos,
                (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = t.id AND i.estatus = 'confirmado') AS inscritos_confirmados
            FROM tournaments t
            {$orgJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY org_res.nombre ASC, t.fechator DESC, t.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orgsIndex = self::loadOrganizacionesIndex($pdo, $context);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $oid = (int) ($row['org_id'] ?? 0);
            if ($oid > 0 && isset($orgsIndex[$oid])) {
                $row['org_canonical'] = (int) ($orgsIndex[$oid]['org_canonical'] ?? 0);
                $row['org_tipo_org'] = (int) ($orgsIndex[$oid]['tipo_org'] ?? 0);
            }
        }
        unset($row);
        $rows = self::deduplicateTorneosById($rows);

        $hoy = date('Y-m-d');
        foreach ($rows as &$row) {
            $row = self::enrichTorneoOrganizacion($row, $orgsIndex);
            $locked = (int) ($row['locked'] ?? 0) === 1;
            $fecha = $row['fechator'] ?? null;
            $fechaOk = $fecha && strtotime((string) $fecha) <= strtotime($hoy);
            if ($locked || ($hasLocked && $fechaOk)) {
                $row['categoria'] = 'realizados';
            } elseif ($fechaOk) {
                $row['categoria'] = 'en_proceso';
            } else {
                $row['categoria'] = 'por_realizar';
            }
        }
        unset($row);

        if ($filtroCategoria !== null && in_array($filtroCategoria, ['realizados', 'en_proceso', 'por_realizar'], true)) {
            $rows = array_values(array_filter($rows, static fn (array $t): bool => ($t['categoria'] ?? '') === $filtroCategoria));
        }

        return $rows;
    }

    /**
     * Torneos realizados para reporte estadístico.
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchTorneosRealizados(PDO $pdo, string $context, int $organizacionId = 0): array
    {
        $all = self::fetchTorneos($pdo, $context, null, $organizacionId);
        $hasLocked = self::hasTournamentLocked($pdo);
        $hoy = date('Y-m-d');

        return array_values(array_filter($all, static function (array $t) use ($hasLocked, $hoy): bool {
            if ((int) ($t['locked'] ?? 0) === 1) {
                return true;
            }
            $fecha = $t['fechator'] ?? null;
            if (!$fecha) {
                return false;
            }

            return strtotime((string) $fecha) <= strtotime($hoy);
        }));
    }

    /**
     * @param list<array<string, mixed>> $torneos
     * @return array<int, array{org_id: int, org_nombre: string, entidad: int, torneos: list<array>, subtotal_eventos: int, subtotal_jugadores: int}>
     */
    public static function groupByOrganizacion(array $torneos): array
    {
        $por_org = [];
        foreach ($torneos as $tor) {
            $org_id = (int) ($tor['org_id'] ?? 0);
            $org_nombre = (string) ($tor['org_nombre'] ?? 'Sin organización');
            $jugadores = (int) ($tor['inscritos_confirmados'] ?? $tor['total_jugadores'] ?? 0);

            if (!isset($por_org[$org_id])) {
                $por_org[$org_id] = [
                    'org_id' => $org_id,
                    'org_nombre' => $org_nombre,
                    'org_codigo_label' => (string) ($tor['org_codigo_label'] ?? ''),
                    'org_cod_org' => (int) ($tor['org_cod_org'] ?? 0),
                    'entidad' => (int) ($tor['entidad'] ?? 0),
                    'entidad_nombre' => (string) ($tor['entidad_nombre'] ?? ''),
                    'org_tipo_org' => (int) ($tor['org_tipo_org'] ?? 0),
                    'torneos' => [],
                    'subtotal_eventos' => 0,
                    'subtotal_jugadores' => 0,
                ];
            }
            $por_org[$org_id]['torneos'][] = $tor;
            $por_org[$org_id]['subtotal_eventos']++;
            $por_org[$org_id]['subtotal_jugadores'] += $jugadores;
        }

        foreach ($por_org as &$org) {
            usort($org['torneos'], static function (array $a, array $b): int {
                $da = strtotime((string) ($a['fechator'] ?? ''));
                $db = strtotime((string) ($b['fechator'] ?? ''));

                return $db <=> $da;
            });
        }
        unset($org);

        uasort($por_org, static fn (array $a, array $b): int => strcasecmp($a['org_nombre'], $b['org_nombre']));

        return array_filter(
            $por_org,
            static fn (array $org): bool => (int) ($org['subtotal_eventos'] ?? 0) > 0
                && !empty($org['torneos'])
        );
    }

    /**
     * @param array<int, array<string, mixed>> $por_org
     * @return array<int, array{nombre: string, organizaciones: array<int, array<string, mixed>>, subtotal_eventos: int, subtotal_jugadores: int}>
     */
    public static function groupByEntidadThenOrganizacion(array $por_org, array $entidad_map): array
    {
        $por_entidad = [];
        foreach ($por_org as $org) {
            $entidad_id = (int) ($org['entidad'] ?? 0);
            $entidad_nombre = trim((string) ($org['entidad_nombre'] ?? ''));
            if ($entidad_nombre === '') {
                $entidad_nombre = $entidad_map[(string) $entidad_id] ?? ($entidad_id > 0 ? "Entidad {$entidad_id}" : 'Sin entidad');
            }
            if (!isset($por_entidad[$entidad_id])) {
                $por_entidad[$entidad_id] = [
                    'nombre' => $entidad_nombre,
                    'organizaciones' => [],
                    'subtotal_eventos' => 0,
                    'subtotal_jugadores' => 0,
                ];
            }
            $oid = (int) $org['org_id'];
            $por_entidad[$entidad_id]['organizaciones'][$oid] = $org;
            $por_entidad[$entidad_id]['subtotal_eventos'] += (int) $org['subtotal_eventos'];
            $por_entidad[$entidad_id]['subtotal_jugadores'] += (int) $org['subtotal_jugadores'];
        }

        foreach ($por_entidad as $entidad_id => &$ent) {
            $ent['organizaciones'] = array_filter(
                $ent['organizaciones'],
                static fn (array $org): bool => (int) ($org['subtotal_eventos'] ?? 0) > 0
                    && !empty($org['torneos'])
            );
            $ent['subtotal_eventos'] = 0;
            $ent['subtotal_jugadores'] = 0;
            foreach ($ent['organizaciones'] as $org) {
                $ent['subtotal_eventos'] += (int) ($org['subtotal_eventos'] ?? 0);
                $ent['subtotal_jugadores'] += (int) ($org['subtotal_jugadores'] ?? 0);
            }
        }
        unset($ent);

        $por_entidad = array_filter(
            $por_entidad,
            static fn (array $ent): bool => !empty($ent['organizaciones'])
                && (int) ($ent['subtotal_eventos'] ?? 0) > 0
        );

        uasort($por_entidad, static fn (array $a, array $b): int => strcasecmp($a['nombre'], $b['nombre']));
        foreach ($por_entidad as &$ent) {
            uasort($ent['organizaciones'], static fn (array $a, array $b): int => strcasecmp($a['org_nombre'], $b['org_nombre']));
        }
        unset($ent);

        return $por_entidad;
    }

    /**
     * Organizaciones del contexto que tienen al menos un torneo en el listado agrupado.
     *
     * @param array<int, array<string, mixed>> $por_organizacion
     * @return list<array<string, mixed>>
     */
    public static function organizacionesParaFiltro(array $por_organizacion, array $todas): array
    {
        if (empty($por_organizacion)) {
            return [];
        }
        $ids = array_flip(array_map(static fn (array $o): int => (int) ($o['org_id'] ?? 0), $por_organizacion));

        return array_values(array_filter(
            $todas,
            static fn (array $o): bool => isset($ids[(int) ($o['id'] ?? 0)])
        ));
    }

    /** @return array<string, string> id entidad => nombre */
    public static function loadEntidadMap(PDO $pdo): array
    {
        $map = [];
        try {
            $stmt = $pdo->query('SELECT id, nombre FROM entidad ORDER BY nombre ASC');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ent) {
                if (isset($ent['id'])) {
                    $map[(string) $ent['id']] = trim((string) ($ent['nombre'] ?? $ent['id']));
                }
            }
        } catch (Throwable $e) {
        }

        return $map;
    }

    /**
     * Etiqueta unificada: nombre + código federación (misma fila organizaciones).
     */
    public static function organizacionEtiqueta(array $org): string
    {
        $nombre = trim((string) ($org['org_nombre'] ?? $org['nombre'] ?? ''));
        $cod = trim((string) ($org['org_codigo_label'] ?? ''));
        if ($cod === '' && isset($org['org_cod_org'])) {
            $cod = (string) (int) $org['org_cod_org'];
        }
        if ($cod === '' && isset($org['cod_org'])) {
            $cod = (string) (int) $org['cod_org'];
        }
        if ($nombre === '') {
            return $cod !== '' ? "Org. cód. {$cod}" : 'Sin organización';
        }
        if ($cod !== '' && $cod !== (string) (int) ($org['org_id'] ?? $org['id'] ?? 0)) {
            return "{$nombre} (cód. {$cod})";
        }

        return $nombre;
    }
}
