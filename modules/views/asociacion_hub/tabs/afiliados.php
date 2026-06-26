<?php
/**
 * Pestaña Afiliados — listado de atletas de la asociación (solo admin).
 *
 * @var array<string, mixed> $viewData
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../lib/AsociacionAuth.php';
require_once __DIR__ . '/../../../../lib/AfiliadoService.php';

require_once __DIR__ . '/../../../../lib/AsociacionHubNavigation.php';

$org_id = (int) ($viewData['org_id'] ?? 0);
$hubOut = AsociacionHubNavigation::outboundParams($org_id, 'afiliados');
$user = $viewData['auth_user'] ?? null;

if (! AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $org_id, $user)) {
    die('Acceso denegado');
}

$afiliados = AfiliadoService::getByOrg($org_id);
$puedeGestionar = ! empty($viewData['puede_administrar']) || ! empty($viewData['es_super_admin']);

$hubHref = static function (string $page, array $params) use ($dashboard_href): string {
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href($page, $params);
    }

    return 'index.php?page=' . rawurlencode($page) . '&' . http_build_query($params);
};

$pdfBase = class_exists('AppHelpers', false)
    ? rtrim(AppHelpers::getPublicUrl(), '/') . '/afiliado_ficha_pdf.php'
    : 'afiliado_ficha_pdf.php';

$pdfHref = static function (int $userId) use ($pdfBase, $org_id): string {
    return $pdfBase . '?' . http_build_query([
        'org_id' => $org_id,
        'user_id' => $userId,
    ]);
};
?>
<div class="card shadow-sm">
    <div class="card-header bg-light d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-id-card me-2"></i>Afiliados</h2>
        <?php if ($afiliados !== []): ?>
            <span class="badge estacion-count-badge"><?= count($afiliados) ?> afiliado(s)</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($afiliados === []): ?>
            <p class="estacion-empty-state mb-0">No se encontraron afiliados registrados.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Club Asociado</th>
                            <th class="text-center">Estatus</th>
                            <th>Última Actividad</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($afiliados as $af):
                            $userId = (int) ($af['id'] ?? 0);
                            $clubId = (int) ($af['club_id'] ?? 0);
                            $activo = ($af['estatus'] ?? '') === 'activo';
                            $fichaUrl = $clubId > 0
                                ? $hubHref('clubs', array_merge([
                                    'action' => 'afiliado_detail',
                                    'club_id' => $clubId,
                                    'user_id' => $userId,
                                ], $hubOut))
                                : '#';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string) ($af['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= htmlspecialchars((string) ($af['club_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <?php if ($activo): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-primary">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($af['ultima_actividad_fmt'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($puedeGestionar && $clubId > 0): ?>
                                    <a href="<?= htmlspecialchars($fichaUrl, ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-primary"
                                       title="Ficha técnica">
                                        <i class="fas fa-user me-1"></i>Ficha
                                    </a>
                                    <a href="<?= htmlspecialchars($pdfHref($userId), ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       title="Descargar PDF"
                                       target="_blank"
                                       rel="noopener">
                                        <i class="fas fa-file-pdf me-1"></i>PDF
                                    </a>
                                <?php elseif ($puedeGestionar): ?>
                                    <a href="<?= htmlspecialchars($pdfHref($userId), ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       title="Descargar PDF"
                                       target="_blank"
                                       rel="noopener">
                                        <i class="fas fa-file-pdf me-1"></i>PDF
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
