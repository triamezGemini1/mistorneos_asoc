<?php
/**
 * Action: Hub de Organización - Resumen para admin_club
 * Carga datos de la organización del usuario y delega a la vista.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../lib/app_helpers.php';
require_once __DIR__ . '/../../../lib/OrganizacionDashboardStats.php';

$current_user = Auth::user();
$organizacion_id = Auth::getUserOrganizacionId();

if (!$organizacion_id) {
    header('Location: index.php?page=mi_organizacion&error=' . urlencode('No tiene una organización asignada'));
    exit;
}

$pdo = DB::pdo();
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_where = $has_cod_org ? "(o.id = ? OR o.cod_org = ?)" : "o.id = ?";

$stmt = $pdo->prepare("
    SELECT o.*, e.nombre as entidad_nombre
    FROM organizaciones o
    LEFT JOIN entidad e ON o.entidad = e.id
    WHERE {$org_where} AND o.estatus = 1
");
$stmt->execute($has_cod_org ? [$organizacion_id, $organizacion_id] : [$organizacion_id]);
$organizacion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$organizacion) {
    header('Location: index.php?page=mi_organizacion&error=' . urlencode('Organización no encontrada'));
    exit;
}

$snap = OrganizacionDashboardStats::snapshot($pdo, $organizacion, $has_cod_org);
$stats = [
    'clubes' => $snap['stats']['clubes'],
    'torneos_total' => $snap['stats']['torneos'],
    'torneos_activos' => $snap['stats']['torneos_activos'],
    'afiliados' => $snap['stats']['afiliados'],
];
$torneos_org = $snap['torneos_recientes'];

include __DIR__ . '/../views/hub.php';
