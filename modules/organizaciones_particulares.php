<?php
/**
 * Listado de organizaciones particulares (tipo_org = 1).
 * No forman parte de las asociaciones territoriales; entidad = referencia geográfica.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';

// Permisos verificados en public/index.php antes del layout.

$pdo = DB::pdo();
$has_cod_org = false;
try {
    $has_cod_org = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}

$organizacion_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($organizacion_id > 0) {
    header('Location: index.php?page=organizaciones&id=' . $organizacion_id . '&from=particulares');
    exit;
}

$sql_solo_particulares = OrganizacionDashboardStats::sqlWhereSoloParticulares($pdo, 'o');
$has_tipo_org = $sql_solo_particulares !== '1=0';

$filtro_entidad = isset($_GET['entidad_id']) ? (int) $_GET['entidad_id'] : 0;
$entidades_options = [];
$particulares = [];

if ($has_tipo_org) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        $codeCol = 'id';
        $nameCol = 'nombre';
        foreach ($cols as $col) {
            $f = strtolower($col['Field'] ?? '');
            if (in_array($f, ['codigo', 'id'], true)) {
                $codeCol = $col['Field'];
            }
            if ($f === 'nombre') {
                $nameCol = $col['Field'];
            }
        }
        $stmtEnt = $pdo->query("SELECT {$codeCol} AS id, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
        $entidades_options = $stmtEnt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ignored) {
        $entidades_options = [];
    }

    $sql = "
        SELECT o.*, e.nombre AS entidad_nombre, u.nombre AS admin_nombre, u.email AS admin_email, u.username AS admin_username
        FROM organizaciones o
        LEFT JOIN entidad e ON e.id = o.entidad
        LEFT JOIN usuarios u ON u.id = o.admin_user_id
        WHERE {$sql_solo_particulares}
    ";
    $params = [];
    if ($filtro_entidad > 0) {
        $sql .= ' AND o.entidad = ?';
        $params[] = $filtro_entidad;
    }
    $sql .= ' ORDER BY o.estatus DESC, e.nombre ASC, o.nombre ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $particulares = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($particulares as &$row) {
        $snap = OrganizacionDashboardStats::snapshot($pdo, $row, $has_cod_org);
        $row['total_clubes'] = (int) ($snap['stats']['clubes'] ?? 0);
        $row['total_torneos'] = (int) ($snap['stats']['torneos'] ?? 0);
        $row['total_afiliados'] = (int) ($snap['stats']['afiliados'] ?? 0);
        $gender = OrganizacionDashboardStats::affiliateGenderCounts($pdo, $row, $has_cod_org);
        $row['hombres'] = (int) ($gender['hombres'] ?? 0);
        $row['mujeres'] = (int) ($gender['mujeres'] ?? 0);
    }
    unset($row);
}

include __DIR__ . '/organizaciones/listado_particulares.php';
