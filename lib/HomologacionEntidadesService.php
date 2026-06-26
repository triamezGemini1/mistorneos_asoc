<?php
declare(strict_types=1);

/**
 * Homologación de asociaciones territoriales con el catálogo `entidad`:
 * - Nombres desde entidad
 * - cod_org = entidad
 * - PK organizaciones.id = entidad (una org canónica por territorio)
 */
final class HomologacionEntidadesService
{
    /** @var array{code: string, name: string, state: string|null}|null */
    private static ?array $entidadCols = null;

    /** @var bool|null */
    private static ?bool $hasTipoOrg = null;

    /**
     * @return array{code: string, name: string, state: string|null}
     */
    public static function entidadColumns(PDO $pdo): array
    {
        if (self::$entidadCols !== null) {
            return self::$entidadCols;
        }
        $codeCol = null;
        $nameCol = null;
        $stateCol = null;
        foreach ($pdo->query('SHOW COLUMNS FROM entidad')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $field = strtolower((string) ($col['Field'] ?? ''));
            if ($codeCol === null && in_array($field, ['id', 'codigo', 'cod_entidad', 'code'], true)) {
                $codeCol = (string) $col['Field'];
            }
            if ($nameCol === null && in_array($field, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) {
                $nameCol = (string) $col['Field'];
            }
            if ($stateCol === null && in_array($field, ['estado', 'estatus', 'status', 'activo'], true)) {
                $stateCol = (string) $col['Field'];
            }
        }
        if ($codeCol === null || $nameCol === null) {
            throw new RuntimeException('No se detectaron columnas código/nombre en entidad');
        }
        self::$entidadCols = ['code' => $codeCol, 'name' => $nameCol, 'state' => $stateCol];

        return self::$entidadCols;
    }

    public static function hasTipoOrgColumn(PDO $pdo): bool
    {
        if (self::$hasTipoOrg !== null) {
            return self::$hasTipoOrg;
        }
        try {
            $pdo->query('SELECT tipo_org FROM organizaciones LIMIT 0');
            self::$hasTipoOrg = true;
        } catch (Throwable $ignored) {
            self::$hasTipoOrg = false;
        }

        return self::$hasTipoOrg;
    }

    private static function sqlNorm(string $expr): string
    {
        return "LOWER(TRIM({$expr})) COLLATE utf8mb4_unicode_ci";
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listEntidades(PDO $pdo, int $minCode = 1, int $maxCode = 99): array
    {
        $cols = self::entidadColumns($pdo);
        $where = "{$cols['code']} BETWEEN ? AND ?";
        $params = [$minCode, $maxCode];
        if ($cols['state'] !== null) {
            $where .= " AND COALESCE({$cols['state']}, 1) = 1";
        }
        $sql = "SELECT {$cols['code']} AS codigo, {$cols['name']} AS nombre
                FROM entidad WHERE {$where} ORDER BY {$cols['code']} ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOrganizacionesAsociacion(PDO $pdo): array
    {
        $whereTipo = self::hasTipoOrgColumn($pdo) ? 'COALESCE(tipo_org, 0) = 0' : '1=1';
        $sql = "SELECT id, nombre, entidad, cod_org, admin_user_id, estatus
                FROM organizaciones WHERE {$whereTipo} AND COALESCE(entidad, 0) > 0
                ORDER BY entidad ASC, id ASC";

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Elige la org territorial canónica por código de entidad (excluye pruebas duplicadas).
     *
     * @param list<array<string, mixed>> $orgs
     * @return array<int, array<string, mixed>>
     */
    public static function pickCanonicalByEntidad(array $orgs, array $entidadNombrePorCodigo): array
    {
        /** @var array<int, list<array<string, mixed>>> $byEnt */
        $byEnt = [];
        foreach ($orgs as $org) {
            $ent = (int) ($org['entidad'] ?? 0);
            if ($ent <= 0) {
                continue;
            }
            $byEnt[$ent][] = $org;
        }

        $canonical = [];
        foreach ($byEnt as $ent => $candidates) {
            $entNombre = self::sqlNormValue((string) ($entidadNombrePorCodigo[$ent] ?? ''));
            $scored = [];
            foreach ($candidates as $org) {
                $nombre = self::sqlNormValue((string) ($org['nombre'] ?? ''));
                $isPrueba = str_contains($nombre, 'prueba') || str_contains($nombre, 'test');
                $nameMatch = $entNombre !== '' && $nombre === $entNombre;
                $scored[] = [
                    'org' => $org,
                    'score' => ($isPrueba ? 100 : 0) + ($nameMatch ? 0 : 10) + ((int) ($org['id'] ?? 0) / 100000),
                ];
            }
            usort($scored, static function (array $a, array $b): int {
                return $a['score'] <=> $b['score'];
            });
            $canonical[$ent] = $scored[0]['org'];
        }

        return $canonical;
    }

    private static function sqlNormValue(string $s): string
    {
        return mb_strtolower(trim($s), 'UTF-8');
    }

    /**
     * Actualiza nombres y cod_org desde entidad (sin cambiar PK).
     *
     * @return array{nombres_org: int, cod_org: int, nombres_club: int}
     */
    public static function syncNombresYCodigos(PDO $pdo, bool $dryRun = true): array
    {
        $cols = self::entidadColumns($pdo);
        $whereTipo = self::hasTipoOrgColumn($pdo) ? 'AND COALESCE(o.tipo_org, 0) = 0' : '';
        $stats = ['nombres_org' => 0, 'cod_org' => 0, 'nombres_club' => 0];

        $normOrg = self::sqlNorm('o.nombre');
        $normEnt = self::sqlNorm('e.' . $cols['name']);
        $sqlOrg = "
            SELECT o.id, o.nombre, o.entidad, o.cod_org, e.{$cols['name']} AS ent_nombre
            FROM organizaciones o
            INNER JOIN entidad e ON e.{$cols['code']} = o.entidad
            WHERE COALESCE(o.entidad, 0) > 0 {$whereTipo}
              AND (
                {$normOrg} <> {$normEnt}
                OR COALESCE(o.cod_org, 0) <> o.entidad
              )
        ";
        $rowsOrg = $pdo->query($sqlOrg)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rowsOrg as $row) {
            $nombreEnt = trim((string) ($row['ent_nombre'] ?? ''));
            if ($nombreEnt === '') {
                continue;
            }
            if (! $dryRun) {
                $st = $pdo->prepare(
                    'UPDATE organizaciones SET nombre = ?, cod_org = entidad WHERE id = ?'
                );
                $st->execute([$nombreEnt, (int) $row['id']]);
            }
            $stats['nombres_org']++;
            if ((int) ($row['cod_org'] ?? 0) !== (int) ($row['entidad'] ?? 0)) {
                $stats['cod_org']++;
            }
        }

        $normClub = self::sqlNorm('c.nombre');
        $sqlClub = "
            SELECT c.id, c.nombre, c.entidad, e.{$cols['name']} AS ent_nombre
            FROM clubes c
            INNER JOIN entidad e ON e.{$cols['code']} = c.entidad
            WHERE COALESCE(c.entidad, 0) > 0
              AND COALESCE(c.cod_org, 0) = c.entidad
              AND {$normClub} = {$normEnt}
        ";
        $rowsClub = $pdo->query($sqlClub)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rowsClub as $row) {
            $nombreEnt = trim((string) ($row['ent_nombre'] ?? ''));
            if ($nombreEnt === '') {
                continue;
            }
            if (! $dryRun) {
                $st = $pdo->prepare('UPDATE clubes SET nombre = ? WHERE id = ?');
                $st->execute([$nombreEnt, (int) $row['id']]);
            }
            $stats['nombres_club']++;
        }

        return $stats;
    }

    /**
     * Informe de orgs cuya PK no coincide con el código territorial.
     *
     * @return list<array<string, mixed>>
     */
    public static function auditIdDesalineados(PDO $pdo): array
    {
        $canonical = self::pickCanonicalByEntidad(
            self::listOrganizacionesAsociacion($pdo),
            self::entidadNombreMap($pdo)
        );
        $out = [];
        foreach ($canonical as $ent => $org) {
            $id = (int) ($org['id'] ?? 0);
            if ($id !== (int) $ent) {
                $out[] = [
                    'org_id_actual' => $id,
                    'entidad' => (int) $ent,
                    'org_id_objetivo' => (int) $ent,
                    'nombre' => (string) ($org['nombre'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public static function entidadNombreMap(PDO $pdo): array
    {
        $map = [];
        foreach (self::listEntidades($pdo) as $e) {
            $map[(int) $e['codigo']] = trim((string) ($e['nombre'] ?? ''));
        }

        return $map;
    }

    /**
     * Reasigna organizaciones.id = entidad para orgs canónicas territoriales.
     *
     * @return array{moved: int, fks_updated: int, map: array<int, int>}
     */
    public static function reorganizarIdsOrganizaciones(PDO $pdo, bool $dryRun = true): array
    {
        $canonical = self::pickCanonicalByEntidad(
            self::listOrganizacionesAsociacion($pdo),
            self::entidadNombreMap($pdo)
        );

        $toMove = [];
        foreach ($canonical as $ent => $org) {
            $oldId = (int) ($org['id'] ?? 0);
            $newId = (int) $ent;
            if ($oldId > 0 && $oldId !== $newId) {
                $toMove[$oldId] = $newId;
            }
        }

        if ($toMove === []) {
            return ['moved' => 0, 'fks_updated' => 0, 'map' => []];
        }

        $finalIds = array_values($toMove);
        $oldIds = array_keys($toMove);
        $conflict = $pdo->prepare(
            'SELECT id, nombre, entidad FROM organizaciones
             WHERE id IN (' . implode(',', array_fill(0, count($finalIds), '?')) . ')
               AND id NOT IN (' . implode(',', array_fill(0, count($oldIds), '?')) . ')'
        );
        $conflict->execute(array_merge($finalIds, $oldIds));
        $blockers = $conflict->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($blockers !== []) {
            $msg = "IDs destino ocupados por otras organizaciones (p. ej. particulares):\n";
            foreach ($blockers as $b) {
                $msg .= "  id={$b['id']} ent={$b['entidad']} {$b['nombre']}\n";
            }
            throw new RuntimeException(trim($msg));
        }

        $fks = self::columnasReferenciaOrganizacionId($pdo);
        $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM organizaciones')->fetchColumn();
        $offset = max($maxId, 100000) + 5000000;

        $mapOldToTemp = [];
        $mapTempToFinal = [];
        foreach ($toMove as $oldId => $newId) {
            $temp = $oldId + $offset;
            $mapOldToTemp[$oldId] = $temp;
            $mapTempToFinal[$temp] = $newId;
        }

        if ($dryRun) {
            return ['moved' => count($toMove), 'fks_updated' => count($fks) * count($toMove), 'map' => $toMove];
        }

        $run = static function (PDO $pdo, string $sql, array $params = []): void {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        };

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $idsCsv = implode(',', array_map('intval', array_keys($toMove)));
            $pdo->exec("UPDATE organizaciones SET id = id + {$offset} WHERE id IN ({$idsCsv})");

            $fkUpdates = 0;
            foreach ($fks as $fk) {
                $tbl = $fk['TABLE_NAME'];
                $col = $fk['COLUMN_NAME'];
                foreach ($mapOldToTemp as $oldId => $tempId) {
                    $run($pdo, "UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` = ?", [$tempId, $oldId]);
                    $fkUpdates += (int) ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() ?: 0);
                }
            }

            foreach ($mapTempToFinal as $tempId => $finalId) {
                $run($pdo, 'UPDATE organizaciones SET id = ? WHERE id = ?', [$finalId, $tempId]);
            }

            foreach ($fks as $fk) {
                $tbl = $fk['TABLE_NAME'];
                $col = $fk['COLUMN_NAME'];
                foreach ($mapTempToFinal as $tempId => $finalId) {
                    $run($pdo, "UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` = ?", [$finalId, $tempId]);
                    $fkUpdates += (int) ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() ?: 0);
                }
            }

            $nextAi = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM organizaciones')->fetchColumn();
            $pdo->exec('ALTER TABLE organizaciones AUTO_INCREMENT = ' . max(1, $nextAi));
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return ['moved' => count($toMove), 'fks_updated' => $fkUpdates, 'map' => $toMove];
    }

    /**
     * @return list<array{TABLE_NAME: string, COLUMN_NAME: string}>
     */
    public static function columnasReferenciaOrganizacionId(PDO $pdo): array
    {
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (! is_string($schema) || $schema === '') {
            return [];
        }
        $st = $pdo->prepare(
            "SELECT DISTINCT TABLE_NAME, COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME = 'organizaciones' AND REFERENCED_COLUMN_NAME = 'id'
               AND TABLE_NAME <> 'organizaciones'
             ORDER BY TABLE_NAME, COLUMN_NAME"
        );
        $st->execute([$schema, $schema]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $t = (string) ($r['TABLE_NAME'] ?? '');
            $c = (string) ($r['COLUMN_NAME'] ?? '');
            if (preg_match('/^[A-Za-z0-9_]+$/', $t) && preg_match('/^[A-Za-z0-9_]+$/', $c)) {
                $out[] = ['TABLE_NAME' => $t, 'COLUMN_NAME' => $c];
            }
        }

        $candidatos = [
            ['tournaments', 'club_responsable'],
            ['tournaments', 'cod_org'],
            ['solicitudes_afiliacion', 'organizacion_id'],
        ];
        foreach ($candidatos as [$tbl, $col]) {
            try {
                $pdo->query("SELECT `{$col}` FROM `{$tbl}` LIMIT 0");
            } catch (Throwable $ignored) {
                continue;
            }
            $exists = false;
            foreach ($out as $x) {
                if ($x['TABLE_NAME'] === $tbl && $x['COLUMN_NAME'] === $col) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $out[] = ['TABLE_NAME' => $tbl, 'COLUMN_NAME' => $col];
            }
        }

        return $out;
    }

    /**
     * @return array{byId: array<int, array<string, mixed>>, particularIds: array<int, true>}
     */
    public static function orgIndex(PDO $pdo): array
    {
        $byId = [];
        $particularIds = [];
        $hasTipo = self::hasTipoOrgColumn($pdo);
        $sql = $hasTipo
            ? 'SELECT id, entidad, cod_org, tipo_org, nombre FROM organizaciones'
            : 'SELECT id, entidad, cod_org, nombre FROM organizaciones';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $org) {
            $id = (int) ($org['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = $org;
            if ($hasTipo && (int) ($org['tipo_org'] ?? 0) === 1) {
                $particularIds[$id] = true;
                $c = (int) ($org['cod_org'] ?? 0);
                if ($c > 0) {
                    $particularIds[$c] = true;
                }
            }
        }

        return ['byId' => $byId, 'particularIds' => $particularIds];
    }

    /**
     * @return array<int, int> entidad => organizacion.id canónica
     */
    public static function canonicalOrgIdByEntidad(PDO $pdo): array
    {
        $canonical = self::pickCanonicalByEntidad(
            self::listOrganizacionesAsociacion($pdo),
            self::entidadNombreMap($pdo)
        );
        $map = [];
        foreach ($canonical as $ent => $org) {
            $map[(int) $ent] = (int) ($org['id'] ?? 0);
        }

        return $map;
    }

    /**
     * Torneos cuyo responsable o cod_org no coincide con la org territorial de tournaments.entidad.
     *
     * @return list<array<string, mixed>>
     */
    public static function auditTorneosDesalineados(PDO $pdo): array
    {
        $index = self::orgIndex($pdo);
        $byEnt = self::canonicalOrgIdByEntidad($pdo);
        $out = [];
        $rows = $pdo->query(
            'SELECT id, nombre, club_responsable, cod_org, entidad FROM tournaments ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $t) {
            $tid = (int) ($t['id'] ?? 0);
            $resp = (int) ($t['club_responsable'] ?? 0);
            $cod = (int) ($t['cod_org'] ?? 0);
            $ent = (int) ($t['entidad'] ?? 0);
            $respOrg = $index['byId'][$resp] ?? null;
            $isParticular = $respOrg !== null
                && self::hasTipoOrgColumn($pdo)
                && (int) ($respOrg['tipo_org'] ?? 0) === 1;

            if ($isParticular) {
                if ($cod !== $resp) {
                    $out[] = [
                        'id' => $tid,
                        'nombre' => (string) ($t['nombre'] ?? ''),
                        'entidad' => $ent,
                        'club_responsable' => $resp,
                        'cod_org' => $cod,
                        'objetivo_resp' => $resp,
                        'objetivo_cod' => $resp,
                        'motivo' => 'particular',
                    ];
                }
                continue;
            }

            if ($ent <= 0 || ! isset($byEnt[$ent])) {
                if ($resp > 0 && $cod !== $resp) {
                    $out[] = [
                        'id' => $tid,
                        'nombre' => (string) ($t['nombre'] ?? ''),
                        'entidad' => $ent,
                        'club_responsable' => $resp,
                        'cod_org' => $cod,
                        'objetivo_resp' => $resp,
                        'objetivo_cod' => $resp,
                        'motivo' => 'cod_org≠responsable',
                    ];
                }
                continue;
            }

            $target = $byEnt[$ent];
            if ($resp !== $target || $cod !== $target) {
                $out[] = [
                    'id' => $tid,
                    'nombre' => (string) ($t['nombre'] ?? ''),
                    'entidad' => $ent,
                    'club_responsable' => $resp,
                    'cod_org' => $cod,
                    'objetivo_resp' => $target,
                    'objetivo_cod' => $target,
                    'motivo' => 'territorial',
                ];
            }
        }

        return $out;
    }

    /**
     * Clubes con cod_org distinto al código territorial cuando no pertenecen a org particular.
     *
     * @return list<array<string, mixed>>
     */
    public static function auditClubesCodOrgDesalineados(PDO $pdo): array
    {
        $index = self::orgIndex($pdo);
        $out = [];
        $rows = $pdo->query(
            'SELECT id, nombre, entidad, cod_org FROM clubes WHERE COALESCE(entidad, 0) > 0 ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $c) {
            $ent = (int) ($c['entidad'] ?? 0);
            $cod = (int) ($c['cod_org'] ?? 0);
            if ($ent <= 0 || $cod === $ent) {
                continue;
            }
            if (isset($index['particularIds'][$cod])) {
                continue;
            }
            $out[] = [
                'id' => (int) ($c['id'] ?? 0),
                'nombre' => (string) ($c['nombre'] ?? ''),
                'entidad' => $ent,
                'cod_org' => $cod,
                'objetivo_cod' => $ent,
            ];
        }

        return $out;
    }

    /**
     * Alinea tournaments (club_responsable, cod_org) y clubes.cod_org territorial.
     *
     * @return array{torneos: int, clubes: int}
     */
    public static function syncTorneosYClubes(PDO $pdo, bool $dryRun = true): array
    {
        $stats = ['torneos' => 0, 'clubes' => 0];

        $updT = $pdo->prepare(
            'UPDATE tournaments SET club_responsable = ?, cod_org = ? WHERE id = ?'
        );
        $updC = $pdo->prepare('UPDATE clubes SET cod_org = ? WHERE id = ?');

        foreach (self::auditTorneosDesalineados($pdo) as $row) {
            if (! $dryRun) {
                $updT->execute([
                    (int) $row['objetivo_resp'],
                    (int) $row['objetivo_cod'],
                    (int) $row['id'],
                ]);
            }
            $stats['torneos']++;
        }

        foreach (self::auditClubesCodOrgDesalineados($pdo) as $row) {
            if (! $dryRun) {
                $updC->execute([(int) $row['objetivo_cod'], (int) $row['id']]);
            }
            $stats['clubes']++;
        }

        return $stats;
    }
}
