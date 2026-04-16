<?php

declare(strict_types=1);

/**
 * OrganizacionesData - Estadísticas de entidades y organizaciones para el dashboard admin_general.
 * Separa la lógica SQL de las vistas. Usado por el home simplificado (solo tarjetas).
 */
class OrganizacionesData
{
    private static function hasCodOrg(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }
    /**
     * Estadísticas globales para el dashboard admin_general (solo tarjetas).
     * Retorna: total_entidades, total_organizaciones, total_usuarios, total_admin_clubs,
     *          total_clubs, total_afiliados, total_hombres, total_mujeres,
     *          total_admin_torneo, total_operadores.
     *
     * @return array<string, int>
     */
    public static function loadStatsGlobales(): array
    {
        $stats = [
            'total_entidades' => 0,
            'total_organizaciones' => 0,
            'total_users' => 0,
            'total_admin_clubs' => 0,
            'total_clubs' => 0,
            'total_afiliados' => 0,
            'total_hombres' => 0,
            'total_mujeres' => 0,
            'total_admin_torneo' => 0,
            'total_operadores' => 0,
        ];

        try {
            $pdo = DB::pdo();

            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT entidad) FROM organizaciones
                WHERE entidad IS NOT NULL AND entidad != 0
            ");
            $stats['total_entidades'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM organizaciones WHERE estatus = 1");
            $stats['total_organizaciones'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
            $stats['total_users'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'admin_club' AND status = 0");
            $stats['total_admin_clubs'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM clubes WHERE estatus = 1");
            $stats['total_clubs'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'usuario' AND status = 0");
            $stats['total_afiliados'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("
                SELECT COUNT(*) FROM usuarios
                WHERE role = 'usuario' AND status = 0 AND (sexo = 'M' OR UPPER(sexo) = 'M')
            ");
            $stats['total_hombres'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("
                SELECT COUNT(*) FROM usuarios
                WHERE role = 'usuario' AND status = 0 AND (sexo = 'F' OR UPPER(sexo) = 'F')
            ");
            $stats['total_mujeres'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'admin_torneo' AND status = 0");
            $stats['total_admin_torneo'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role = 'operador' AND status = 0");
            $stats['total_operadores'] = (int)$stmt->fetchColumn();

            require_once __DIR__ . '/StatisticsHelper.php';
            $helperStats = StatisticsHelper::generateStatistics();
            if (!isset($helperStats['error'])) {
                $stats['total_admin_clubs'] = (int)($helperStats['total_admin_clubs'] ?? $stats['total_admin_clubs']);
                $stats['total_admin_torneo'] = (int)($helperStats['total_admin_torneo'] ?? $stats['total_admin_torneo']);
                $stats['total_operadores'] = (int)($helperStats['total_operadores'] ?? $stats['total_operadores']);
                $stats['total_clubs'] = (int)($helperStats['total_clubs'] ?? $stats['total_clubs']);
            }
        } catch (Exception $e) {
            error_log("OrganizacionesData loadStatsGlobales: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Mapa id/codigo => nombre de entidades (para selects y listados).
     *
     * @return array<int|string, string>
     */
    public static function loadEntidadMap(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $map = [];
        try {
            $stmt = DB::pdo()->query("SELECT id AS codigo, nombre FROM entidad ORDER BY nombre ASC");
            $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            try {
                $stmt = DB::pdo()->query("SELECT codigo, nombre FROM entidad ORDER BY nombre ASC");
                $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e2) {
                error_log("OrganizacionesData loadEntidadMap: " . $e->getMessage());
            }
        }
        $cached = $map;
        return $map;
    }

    /**
     * Listado de entidades con resumen (organizaciones, clubes, afiliados, torneos).
     * Para página Entidades > index.
     *
     * @return list<array{entidad_id: int|string, entidad_nombre: string, total_organizaciones: int, total_clubes: int, total_afiliados: int, total_torneos: int}>
     */
    public static function loadResumenEntidades(): array
    {
        try {
            $pdo = DB::pdo();
        } catch (Exception $e) {
            return [];
        }

        $resumen = [];
        $entidades = self::loadEntidadRows($pdo);
        $hasCodOrg = self::hasCodOrg($pdo);
        foreach ($entidades as $e) {
            $cod = (int)($e['codigo'] ?? 0);
            if ($cod <= 0) {
                continue;
            }
            $nombre = (string)($e['nombre'] ?? ('Entidad ' . $cod));

            $stOrg = $pdo->prepare("SELECT COUNT(*) FROM organizaciones WHERE entidad = ?");
            $stOrg->execute([$cod]);
            $total_organizaciones = (int)$stOrg->fetchColumn();

            $stClub = $pdo->prepare("SELECT COUNT(*) FROM clubes WHERE entidad = ? AND estatus = 1");
            $stClub->execute([$cod]);
            $total_clubes = (int)$stClub->fetchColumn();

            $stAfi = $pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN UPPER(COALESCE(sexo,'M')) = 'M' THEN 1 ELSE 0 END) AS hombres,
                    SUM(CASE WHEN UPPER(COALESCE(sexo,'M')) = 'F' THEN 1 ELSE 0 END) AS mujeres
                FROM usuarios
                WHERE entidad = ?
            ");
            $stAfi->execute([$cod]);
            $rowAfi = $stAfi->fetch(PDO::FETCH_ASSOC) ?: [];

            $stTor = $pdo->prepare("
                SELECT COUNT(*)
                FROM tournaments t
                INNER JOIN organizaciones o ON " . ($hasCodOrg
                    ? "(o.id = t.club_responsable OR o.cod_org = t.club_responsable)"
                    : "o.id = t.club_responsable") . "
                WHERE o.entidad = ?
            ");
            $stTor->execute([$cod]);
            $total_torneos = (int)$stTor->fetchColumn();

            $resumen[] = [
                'entidad_id' => $cod,
                'entidad_codigo' => $cod,
                'entidad_nombre' => $nombre,
                'total_organizaciones' => $total_organizaciones,
                'total_clubes' => $total_clubes,
                'total_afiliados' => (int)($rowAfi['total'] ?? 0),
                'hombres' => (int)($rowAfi['hombres'] ?? 0),
                'mujeres' => (int)($rowAfi['mujeres'] ?? 0),
                'total_torneos' => $total_torneos,
            ];
        }
        return $resumen;
    }

    private static function loadEntidadRows(PDO $pdo): array
    {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
            $codeCol = $nameCol = null;
            foreach ($cols as $c) {
                $f = strtolower($c['Field'] ?? '');
                if (!$codeCol && in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) {
                    $codeCol = $c['Field'];
                }
                if (!$nameCol && in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) {
                    $nameCol = $c['Field'];
                }
            }
            if ($codeCol && $nameCol) {
                $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$codeCol} ASC");
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Exception $e) {
        }
        return [];
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }
}
