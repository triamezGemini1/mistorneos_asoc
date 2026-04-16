<?php
/**
 * Action: Hub de Organización - Resumen para admin_club
 * Carga datos de la organización del usuario y delega a la vista.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../lib/app_helpers.php';

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

// Estadísticas
$org_ref = (int)($organizacion['cod_org'] ?? 0);
if ($org_ref <= 0) {
    $org_ref = (int)($organizacion['id'] ?? 0);
}
$stmt = $pdo->prepare($has_cod_org
    ? "SELECT COUNT(*) FROM clubes WHERE (organizacion_id = ? OR organizacion_id = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND estatus = 1"
    : "SELECT COUNT(*) FROM clubes WHERE organizacion_id = ? AND estatus = 1");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref] : [$org_ref]);
$stats_clubes = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare($has_cod_org
    ? "SELECT COUNT(*) FROM tournaments WHERE (club_responsable = ? OR club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND fechator >= CURDATE() AND estatus = 1"
    : "SELECT COUNT(*) FROM tournaments WHERE club_responsable = ? AND fechator >= CURDATE() AND estatus = 1");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref] : [$org_ref]);
$stats_torneos_activos = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM usuarios u
    INNER JOIN clubes c ON u.club_id = c.id
    WHERE " . ($has_cod_org ? "(c.organizacion_id = ? OR c.organizacion_id = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1))" : "c.organizacion_id = ?") . " AND c.estatus = 1 AND u.role = 'usuario' AND u.status = 0
");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref] : [$org_ref]);
$stats_afiliados = (int)$stmt->fetchColumn();

$stats = [
    'clubes' => $stats_clubes,
    'torneos_activos' => $stats_torneos_activos,
    'afiliados' => $stats_afiliados,
];

include __DIR__ . '/../views/hub.php';
