<?php
/**
 * Pestaña Torneos — listado responsive de eventos de la asociación.
 *
 * @var array<string, mixed> $viewData
 */
declare(strict_types=1);

$torneos = is_array($viewData['torneos'] ?? null) ? $viewData['torneos'] : [];
$puedeAdmin = ! empty($viewData['puede_administrar']);

require_once __DIR__ . '/../../../../lib/AsociacionHubNavigation.php';

$orgId = (int) ($viewData['org_id'] ?? 0);
$hubOut = AsociacionHubNavigation::outboundParams($orgId, 'torneos');

$torneoHref = static function (string $action, int $torneoId) use ($dashboard_href, $hubOut): string {
    $params = array_merge(['action' => $action, 'torneo_id' => $torneoId], $hubOut);
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href('torneo_gestion', $params);
    }

    return 'index.php?page=torneo_gestion&' . http_build_query($params);
};
?>
<div class="card shadow-sm">
    <div class="card-header bg-light d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-trophy me-2"></i>Torneos</h2>
        <span class="badge estacion-count-badge"><?= count($torneos) ?> evento(s)</span>
    </div>
    <div class="card-body p-0">
        <?php if ($torneos === []): ?>
            <p class="estacion-empty-state mb-0">Sin torneos registrados para esta asociación.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Fecha</th>
                            <th>Lugar</th>
                            <th class="text-center">Estado</th>
                            <?php if ($puedeAdmin): ?>
                            <th class="text-end">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($torneos as $t):
                            $tid = (int) ($t['id'] ?? 0);
                            $fechaRaw = (string) ($t['fechator'] ?? '');
                            $fecha = $fechaRaw !== '' ? date('d/m/Y', strtotime($fechaRaw)) : '—';
                            $estatus = (int) ($t['estatus'] ?? 0);
                            $activo = $estatus === 1;
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) ($t['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (! empty($t['modalidad'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars((string) $t['modalidad'], ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($t['lugar'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center">
                                <?php if ($activo): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Estatus <?= $estatus ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($puedeAdmin): ?>
                            <td class="text-end text-nowrap">
                                <a href="<?= htmlspecialchars($torneoHref('panel', $tid), ENT_QUOTES, 'UTF-8') ?>"
                                   class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-cogs me-1"></i>Panel
                                </a>
                                <a href="<?= htmlspecialchars($torneoHref('posiciones', $tid), ENT_QUOTES, 'UTF-8') ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-list-ol me-1"></i>Posiciones
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
