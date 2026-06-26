<?php
declare(strict_types=1);

/**
 * PDF — Ficha resumida del afiliado (hub de asociación).
 * GET: org_id, user_id
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
AuthService::requireAuth();

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/AsociacionAuth.php';
require_once __DIR__ . '/../lib/AfiliadoService.php';
require_once __DIR__ . '/../lib/OrganizacionService.php';
require_once __DIR__ . '/../lib/report_generator.php';

$orgId = filter_input(INPUT_GET, 'org_id', FILTER_VALIDATE_INT);
$userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

if (! is_int($orgId) || $orgId <= 0 || ! is_int($userId) || $userId <= 0) {
    http_response_code(400);
    exit('Parámetros inválidos.');
}

$authUser = AsociacionAuth::userFromSession(Auth::user(), $orgId);
if (! AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $orgId, $authUser)) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$afiliado = AfiliadoService::getByIdInOrg($orgId, $userId);
if ($afiliado === null) {
    http_response_code(404);
    exit('Afiliado no encontrado en esta asociación.');
}

$org = OrganizacionService::getById($orgId);
$orgNombre = $org !== null ? (string) ($org->nombre ?? '') : '';

$html = '
<h1 style="font-size:16pt;margin-bottom:4px;">Ficha del afiliado</h1>
<p style="font-size:10pt;color:#555;margin-top:0;">' . htmlspecialchars($orgNombre, ENT_QUOTES, 'UTF-8') . '</p>
<table style="width:100%;border-collapse:collapse;font-size:11pt;margin-top:16px;">
<tr><td style="padding:6px;border:1px solid #ccc;width:35%;"><strong>Nombre</strong></td>
    <td style="padding:6px;border:1px solid #ccc;">' . htmlspecialchars((string) $afiliado['nombre'], ENT_QUOTES, 'UTF-8') . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ccc;"><strong>Cédula</strong></td>
    <td style="padding:6px;border:1px solid #ccc;">' . htmlspecialchars((string) ($afiliado['cedula'] ?: '—'), ENT_QUOTES, 'UTF-8') . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ccc;"><strong>Email</strong></td>
    <td style="padding:6px;border:1px solid #ccc;">' . htmlspecialchars((string) ($afiliado['email'] ?: '—'), ENT_QUOTES, 'UTF-8') . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ccc;"><strong>Club asociado</strong></td>
    <td style="padding:6px;border:1px solid #ccc;">' . htmlspecialchars((string) $afiliado['club_nombre'], ENT_QUOTES, 'UTF-8') . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ccc;"><strong>Estatus</strong></td>
    <td style="padding:6px;border:1px solid #ccc;">' . htmlspecialchars((string) $afiliado['estatus_label'], ENT_QUOTES, 'UTF-8') . '</td></tr>
<tr><td style="padding:6px;border:1px solid #ccc;"><strong>Última actividad</strong></td>
    <td style="padding:6px;border:1px solid #ccc;">' . htmlspecialchars((string) $afiliado['ultima_actividad_fmt'], ENT_QUOTES, 'UTF-8') . '</td></tr>
</table>
<p style="font-size:9pt;color:#777;margin-top:20px;">Generado el ' . date('d/m/Y H:i') . '</p>';

try {
    $report = new ReportGenerator('Ficha afiliado — ' . (string) $afiliado['nombre'], 'portrait');
    $report->setContent($html);
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $afiliado['nombre']) ?: 'afiliado';
    $report->generate('ficha_afiliado_' . $slug . '_' . $userId . '.pdf', true);
} catch (Throwable $e) {
    error_log('afiliado_ficha_pdf: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo generar el PDF.');
}
