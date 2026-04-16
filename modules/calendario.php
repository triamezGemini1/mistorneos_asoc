<?php
/**
 * Calendario de torneos dentro del dashboard.
 * Misma lógica y datos que el calendario del landing; accesible para administradores.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

Auth::requireRole(['admin_general', 'admin_club', 'admin_torneo', 'operador']);

$pdo = DB::pdo();
$role_cal = (string) (Auth::user()['role'] ?? '');
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

// Alcance por rol: admin_club / admin_torneo solo su ámbito (Auth); admin_general y operador sin recorte aquí
$tournament_scope_sql = '';
$tournament_scope_params = [];
if ($role_cal === 'admin_club' || $role_cal === 'admin_torneo') {
    $tf = Auth::getTournamentFilterForRole('t');
    if (! empty($tf['where'])) {
        $tournament_scope_sql = ' AND (' . $tf['where'] . ')';
        $tournament_scope_params = $tf['params'];
    }
}

// Misma consulta que landing: eventos para el calendario
$eventos_calendario = [];
try {
    $sqlCal = "
        SELECT 
            t.*,
            o.nombre as organizacion_nombre,
            o.logo as organizacion_logo,
            o.telefono as club_telefono,
            u.nombre as admin_nombre,
            u.celular as admin_celular,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus IS NULL OR estatus != 'retirado')) as total_inscritos
        FROM tournaments t
        {$org_join}
        LEFT JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
        WHERE t.estatus = 1
        {$tournament_scope_sql}
        ORDER BY t.fechator ASC
    ";
    $stmt = $pdo->prepare($sqlCal);
    $stmt->execute($tournament_scope_params);
    $eventos_calendario = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Calendario (dashboard): " . $e->getMessage());
}

function limpiarNombreTorneoCal($nombre) {
    if (empty($nombre)) return $nombre;
    $nombre = preg_replace('/\bmasivos?\b/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos\s*/i', ' ', $nombre);
    $nombre = preg_replace('/^Masivos\s+/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos$/i', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    return trim($nombre);
}

// Índice por fecha (Y-m-d) para el JS del calendario
$eventos_por_fecha = [];
$base_url_public = rtrim(AppHelpers::getPublicUrl(), '/') . '/';

foreach ($eventos_calendario as $ev) {
    $fecha_key = date('Y-m-d', strtotime($ev['fechator']));
    if (!isset($eventos_por_fecha[$fecha_key])) {
        $eventos_por_fecha[$fecha_key] = [];
    }
    $ev['nombre_limpio'] = limpiarNombreTorneoCal($ev['nombre'] ?? '');
    $ev['logo_url'] = !empty($ev['organizacion_logo'])
        ? $base_url_public . 'view_image.php?path=' . rawurlencode($ev['organizacion_logo'])
        : '';
    $eventos_por_fecha[$fecha_key][] = $ev;
}

$page_title = 'Calendario de Torneos';
include __DIR__ . '/calendario/calendario_view.php';
