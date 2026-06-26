<?php
declare(strict_types=1);

/**
 * Entrada amigable: /public/asociacion_hub → index.php?page=asociacion_hub&org_id=…
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/HubNavigation.php';

AuthService::requireAuth();

$orgId = filter_input(INPUT_GET, 'org_id', FILTER_VALIDATE_INT);
if (! is_int($orgId) || $orgId <= 0) {
    $orgId = HubNavigation::resolveOrgIdForCurrentUser();
}

$params = ['page' => 'asociacion_hub'];
if ($orgId > 0) {
    $params['org_id'] = $orgId;
}
$tab = trim((string) ($_GET['tab'] ?? ''));
if ($tab !== '') {
    $params['tab'] = $tab;
}
$estado = trim((string) ($_GET['estado'] ?? ''));
if ($estado !== '') {
    $params['estado'] = $estado;
}

header('Location: ' . AppHelpers::url('index.php', $params), true, 302);
exit;
