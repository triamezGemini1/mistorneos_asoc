<?php
/**
 * Pestaña Clubes — listado nominal de clubes de la asociación.
 *
 * @var array<string, mixed> $viewData
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../lib/ClubService.php';
require_once __DIR__ . '/../../../../lib/AsociacionHubNavigation.php';

$orgId = (int) ($viewData['org_id'] ?? 0);
$hubOut = AsociacionHubNavigation::outboundParams($orgId, 'clubes');
$puedeAdmin = ! empty($viewData['puede_administrar']);
$clubes = ClubService::getByOrg($orgId);

$clubHref = static function (string $page, array $params) use ($dashboard_href): string {
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href($page, $params);
    }

    return 'index.php?page=' . rawurlencode($page) . '&' . http_build_query($params);
};
?>
<div class="card shadow-sm">
    <div class="card-header bg-light d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-users me-2"></i>Clubes</h2>
        <?php if ($clubes !== []): ?>
            <span class="badge estacion-count-badge"><?= count($clubes) ?> club(es)</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($clubes === []): ?>
            <p class="estacion-empty-state mb-0">
                No existen clubes registrados nominalmente en esta asociación.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre del Club</th>
                            <th>Delegado</th>
                            <th class="text-center">Total Afiliados</th>
                            <?php if ($puedeAdmin): ?>
                            <th class="text-end">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clubes as $club):
                            $clubId = (int) ($club['id'] ?? 0);
                            $nombre = (string) ($club['nombre'] ?? '');
                            $delegado = trim((string) ($club['delegado'] ?? ''));
                            $totalAfiliados = (int) ($club['total_afiliados'] ?? 0);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= htmlspecialchars($delegado !== '' ? $delegado : '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <span class="badge bg-info"><?= $totalAfiliados ?></span>
                            </td>
                            <?php if ($puedeAdmin): ?>
                            <td class="text-end text-nowrap">
                                <a href="<?= htmlspecialchars($clubHref('clubes_asociados', array_merge(['club_id' => $clubId], $hubOut)), ENT_QUOTES, 'UTF-8') ?>"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-edit me-1"></i>Editar
                                </a>
                                <a href="<?= htmlspecialchars($clubHref('organizaciones', array_merge([
                                    'id' => $orgId,
                                    'club_id' => $clubId,
                                ], $hubOut)), ENT_QUOTES, 'UTF-8') ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-cog me-1"></i>Gestionar
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
