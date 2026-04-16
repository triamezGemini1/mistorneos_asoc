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
$org_entidad = (int)($organizacion['entidad'] ?? 0);
$stmt = $pdo->prepare($has_cod_org
    ? "SELECT COUNT(DISTINCT id) FROM clubes WHERE (organizacion_id = ? OR organizacion_id = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND estatus = 1 AND (? = 0 OR COALESCE(entidad, 0) = ?)"
    : "SELECT COUNT(DISTINCT id) FROM clubes WHERE organizacion_id = ? AND estatus = 1 AND (? = 0 OR COALESCE(entidad, 0) = ?)");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref, $org_entidad, $org_entidad] : [$org_ref, $org_entidad, $org_entidad]);
$stats_clubes = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare($has_cod_org
    ? "SELECT COUNT(DISTINCT id) FROM tournaments WHERE (club_responsable = ? OR club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND (? = 0 OR COALESCE(entidad, 0) = ?) AND fechator >= CURDATE() AND estatus = 1"
    : "SELECT COUNT(DISTINCT id) FROM tournaments WHERE club_responsable = ? AND (? = 0 OR COALESCE(entidad, 0) = ?) AND fechator >= CURDATE() AND estatus = 1");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref, $org_entidad, $org_entidad] : [$org_ref, $org_entidad, $org_entidad]);
$stats_torneos_activos = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare($has_cod_org
    ? "SELECT COUNT(DISTINCT id) FROM tournaments WHERE (club_responsable = ? OR club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND (? = 0 OR COALESCE(entidad, 0) = ?) AND estatus = 1"
    : "SELECT COUNT(DISTINCT id) FROM tournaments WHERE club_responsable = ? AND (? = 0 OR COALESCE(entidad, 0) = ?) AND estatus = 1");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref, $org_entidad, $org_entidad] : [$org_ref, $org_entidad, $org_entidad]);
$stats_torneos_total = (int)$stmt->fetchColumn();

$club_match = $has_cod_org
    ? "(c.organizacion_id = ? OR c.organizacion_id = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1))"
    : "c.organizacion_id = ?";
$params_af_base = $has_cod_org ? [$org_ref, $org_ref] : [$org_ref];
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id) FROM usuarios u
    WHERE u.role = 'usuario' AND u.status = 0
    AND (
        (? > 0 AND COALESCE(NULLIF(u.organizacion_id, 0), COALESCE(u.entidad, 0)) = ?)
        OR EXISTS (
            SELECT 1 FROM clubes c
            WHERE c.id = u.club_id
              AND {$club_match}
              AND c.estatus = 1
              AND (? = 0 OR COALESCE(c.entidad, 0) = ?)
        )
    )
");
$stmt->execute(array_merge([$org_entidad, $org_entidad], $params_af_base, [$org_entidad, $org_entidad]));
$stats_afiliados = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare($has_cod_org
    ? "SELECT DISTINCT t.id, t.nombre, t.fechator, t.estatus
       FROM tournaments t
       WHERE (t.club_responsable = ? OR t.club_responsable = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1))
         AND (? = 0 OR COALESCE(t.entidad, 0) = ?)
       ORDER BY t.fechator DESC, t.id DESC
       LIMIT 12"
    : "SELECT DISTINCT t.id, t.nombre, t.fechator, t.estatus
       FROM tournaments t
       WHERE t.club_responsable = ?
         AND (? = 0 OR COALESCE(t.entidad, 0) = ?)
       ORDER BY t.fechator DESC, t.id DESC
       LIMIT 12");
$stmt->execute($has_cod_org ? [$org_ref, $org_ref, $org_entidad, $org_entidad] : [$org_ref, $org_entidad, $org_entidad]);
$torneos_org = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = [
    'clubes' => $stats_clubes,
    'torneos_total' => $stats_torneos_total,
    'torneos_activos' => $stats_torneos_activos,
    'afiliados' => $stats_afiliados,
];

include __DIR__ . '/../views/hub.php';
