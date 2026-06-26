<?php
declare(strict_types=1);

/**
 * Dashboard Inicio — administrador de asociación (estilo admin general).
 */

require_once __DIR__ . '/../../../config/auth.php';

Auth::requireRole(['admin_club']);

require_once __DIR__ . '/../../../lib/OrganizacionService.php';
require_once __DIR__ . '/../../../lib/OrganizacionDashboardStats.php';
require_once __DIR__ . '/../../../lib/AsociacionAdminNav.php';
require_once __DIR__ . '/../../../config/db.php';

$current_user = Auth::user();
$orgId = AsociacionAdminNav::resolveOrgId();
$organizacion = $orgId > 0 ? OrganizacionService::getById($orgId) : null;

$stats = [
    'clubes' => 0,
    'torneos' => 0,
    'torneos_activos' => 0,
    'afiliados' => 0,
    'usuarios' => 0,
    'inscripciones' => 0,
];
$genero = [
    'hombres' => 0,
    'mujeres' => 0,
    'sin_genero' => 0,
];
$nombreOrg = '';

if ($organizacion !== null) {
  $nombreOrg = trim((string) ($organizacion->nombre ?? ''));
  $pdo = DB::pdo();
  $flags = OrganizacionDashboardStats::columnFlags($pdo);
  $orgArray = [
      'id' => $orgId,
      'entidad' => (int) ($organizacion->entidad ?? 0),
      'cod_org' => (int) ($organizacion->cod_org ?? 0),
      'tipo_org' => (int) ($organizacion->tipo_org ?? 0),
  ];
  $snapshot = OrganizacionDashboardStats::snapshot($pdo, $orgArray, $flags['has_usuario_cod_org'] ?? false);
  $stats = array_merge($stats, $snapshot['stats'] ?? []);
  $genero = OrganizacionDashboardStats::affiliateGenderCounts(
      $pdo,
      $orgArray,
      $flags['has_usuario_cod_org'] ?? false
  );
}

extract([
    'stats' => $stats,
    'genero' => $genero,
    'nombre_org' => $nombreOrg,
    'org_id' => $orgId,
    'success_message' => $_GET['success'] ?? null,
    'error_message' => $_GET['error'] ?? null,
    'current_user' => $current_user,
]);

include __DIR__ . '/../views/home.php';
