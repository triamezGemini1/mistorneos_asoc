<?php
/**
 * Torneos por estructura: asociaciones (tipo_org=0) u organizaciones particulares (tipo_org=1).
 * Vista lista (agrupado por organización) o reporte (torneos realizados).
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/TorneosEstructuraService.php';
require_once __DIR__ . '/../lib/OrganizacionDashboardStats.php';

// Permisos verificados en public/index.php antes del layout.
$is_admin_general = Auth::isAdminGeneral();
$is_admin_club = Auth::isAdminClub();
$scope_solo_mi_org = !$is_admin_general && $is_admin_club;

$pdo = DB::pdo();
$context = isset($_GET['context']) && TorneosEstructuraService::isValidContext((string) $_GET['context'])
    ? (string) $_GET['context']
    : TorneosEstructuraService::CONTEXT_ASOCIACIONES;

$vista = isset($_GET['vista']) && $_GET['vista'] === 'reporte' ? 'reporte' : 'lista';
$filtro = isset($_GET['filtro']) && in_array($_GET['filtro'], ['realizados', 'en_proceso', 'por_realizar'], true)
    ? $_GET['filtro']
    : null;
$organizacion_id = isset($_GET['organizacion_id']) ? (int) $_GET['organizacion_id'] : 0;

if ($scope_solo_mi_org) {
    $org_pk = (int) (Auth::getUserOrganizacionId() ?? 0);
    if ($org_pk <= 0) {
        echo '<div class="alert alert-warning m-3">No tiene una organización asignada. Contacte al administrador.</div>';
        return;
    }
    $stmtOrg = $pdo->prepare('SELECT * FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
    $stmtOrg->execute([$org_pk]);
    $mi_org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
    if (!$mi_org) {
        echo '<div class="alert alert-warning m-3">Organización no encontrada o inactiva.</div>';
        return;
    }
    $organizacion_id = $org_pk;
    $context = OrganizacionDashboardStats::isOrganizacionParticular($mi_org)
        ? TorneosEstructuraService::CONTEXT_PARTICULARES
        : TorneosEstructuraService::CONTEXT_ASOCIACIONES;
}

$entidad_map = TorneosEstructuraService::loadEntidadMap($pdo);
$has_tipo_org = TorneosEstructuraService::hasTipoOrg($pdo);

$organizaciones_filtro = [];
if ($has_tipo_org) {
    $tipoSql = TorneosEstructuraService::tipoOrgFilterSql($pdo, $context, 'o');
    $stmtOrg = $pdo->query("
        SELECT o.id, o.nombre, o.entidad, e.nombre AS entidad_nombre
        FROM organizaciones o
        LEFT JOIN entidad e ON e.id = o.entidad
        WHERE o.estatus = 1 AND {$tipoSql}
        ORDER BY e.nombre ASC, o.nombre ASC
    ");
    $organizaciones_filtro = $stmtOrg->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($vista === 'reporte') {
    $torneos = TorneosEstructuraService::fetchTorneosRealizados($pdo, $context, $organizacion_id);
} else {
    $torneos = TorneosEstructuraService::fetchTorneos($pdo, $context, $filtro, $organizacion_id);
}

$por_organizacion = TorneosEstructuraService::groupByOrganizacion($torneos);
$por_entidad = TorneosEstructuraService::groupByEntidadThenOrganizacion($por_organizacion, $entidad_map);
$organizaciones_filtro = TorneosEstructuraService::organizacionesParaFiltro($por_organizacion, $organizaciones_filtro);

$total_eventos = count($torneos);
$total_jugadores = 0;
foreach ($torneos as $t) {
    $total_jugadores += (int) ($t['inscritos_confirmados'] ?? 0);
}

$context_label = TorneosEstructuraService::contextLabel($context);
$es_particulares = $context === TorneosEstructuraService::CONTEXT_PARTICULARES;
$page_title = ($vista === 'reporte' ? 'Reporte de torneos' : 'Torneos') . ' — ' . $context_label;

$qs_base = 'index.php?page=torneos_estructura&context=' . urlencode($context);
if ($organizacion_id > 0 && $is_admin_general) {
    $qs_base .= '&organizacion_id=' . $organizacion_id;
}

include __DIR__ . '/torneos_estructura/' . ($vista === 'reporte' ? 'reporte.php' : 'lista.php');
