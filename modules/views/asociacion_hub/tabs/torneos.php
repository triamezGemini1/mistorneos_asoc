<?php
/**
 * Pestaña Torneos — listado con filtros: realizados, en proceso, pendientes.
 *
 * @var array<string, mixed> $viewData
 */
declare(strict_types=1);

$torneos = is_array($viewData['torneos'] ?? null) ? $viewData['torneos'] : [];
$puedeAdmin = ! empty($viewData['puede_administrar']);
$estadoActivo = (string) ($viewData['estado_torneos'] ?? 'en_proceso');

require_once __DIR__ . '/../../../../config/csrf.php';
require_once __DIR__ . '/../../../../config/auth.php';
require_once __DIR__ . '/../../../../lib/AsociacionHubNavigation.php';
require_once __DIR__ . '/../../../../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../../../../config/db.php';

$puedeCrearTorneo = AsociacionAdminHelper::puedeCrearYAdministrarTorneos(
    DB::pdo(),
    (int) Auth::id(),
    (string) (Auth::user()['role'] ?? '')
);

$orgId = (int) ($viewData['org_id'] ?? 0);
$hubOut = AsociacionHubNavigation::outboundParams($orgId, 'torneos', $estadoActivo);

$hubTabHref = static function (string $estado) use ($orgId, $dashboard_href): string {
    $params = [
        'org_id' => $orgId,
        'tab' => 'torneos',
        'estado' => AsociacionHubNavigation::normalizeEstadoTorneos($estado),
    ];
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href('asociacion_hub', $params);
    }

    return 'index.php?page=asociacion_hub&' . http_build_query($params);
};

$torneoGestionHref = static function (string $action, int $torneoId) use ($dashboard_href, $hubOut): string {
    $params = array_merge(['action' => $action, 'torneo_id' => $torneoId], $hubOut);
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href('torneo_gestion', $params);
    }

    return 'index.php?page=torneo_gestion&' . http_build_query($params);
};

$tournamentFormHref = static function (string $action, ?int $torneoId = null) use ($dashboard_href, $hubOut, $orgId): string {
    $params = array_merge($hubOut, ['action' => $action]);
    if ($action === 'new') {
        $params['organizacion_id'] = $orgId;
    }
    if ($torneoId !== null && $torneoId > 0) {
        $params['id'] = $torneoId;
    }
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href('tournaments', $params);
    }

    return 'index.php?page=tournaments&' . http_build_query($params);
};

$torneoGestionPostHref = static function () use ($dashboard_href): string {
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href('torneo_gestion');
    }

    return 'index.php?page=torneo_gestion';
};

$filtros = [
    'realizados' => ['label' => 'Realizados', 'icon' => 'fa-check-circle', 'class' => 'btn-outline-secondary'],
    'en_proceso' => ['label' => 'En proceso', 'icon' => 'fa-play-circle', 'class' => 'btn-outline-primary'],
    'pendientes' => ['label' => 'Pendientes', 'icon' => 'fa-calendar-plus', 'class' => 'btn-outline-success'],
];
?>
<div class="card shadow-sm asoc-report-card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-trophy me-2"></i>Torneos</h2>
        <div class="d-flex align-items-center gap-2">
            <span class="badge asoc-report-count-badge"><?= count($torneos) ?> evento(s)</span>
            <?php if ($puedeCrearTorneo): ?>
            <a href="<?= htmlspecialchars($tournamentFormHref('new'), ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-sm btn-success">
                <i class="fas fa-plus me-1"></i>Crear torneo
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body border-bottom pb-3">
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($filtros as $estadoKey => $meta):
                $activo = $estadoActivo === $estadoKey;
                $btnClass = $activo ? str_replace('outline-', '', (string) $meta['class']) : (string) $meta['class'];
            ?>
            <a href="<?= htmlspecialchars($hubTabHref($estadoKey), ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-sm <?= htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') ?><?= $activo ? ' active' : '' ?>">
                <i class="fas <?= htmlspecialchars((string) $meta['icon'], ENT_QUOTES, 'UTF-8') ?> me-1"></i>
                <?= htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body p-0 pt-0">
        <?php if ($torneos === []): ?>
            <div class="estacion-empty-state mb-0">
                <p class="mb-2">No hay torneos en la categoría «<?= htmlspecialchars($filtros[$estadoActivo]['label'] ?? $estadoActivo, ENT_QUOTES, 'UTF-8') ?>».</p>
                <?php if ($puedeCrearTorneo && $estadoActivo === 'pendientes'): ?>
                <a href="<?= htmlspecialchars($tournamentFormHref('new'), ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-sm btn-success">
                    <i class="fas fa-plus me-1"></i>Crear torneo
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Fecha</th>
                            <th>Lugar</th>
                            <th class="text-center">Rondas</th>
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
                            $finalizado = ! empty($t['finalizado']);
                            $puedeFinalizar = ! empty($t['puede_finalizar']);
                            $puedeEditar = ! empty($t['puede_editar'])
                                || ($puedeCrearTorneo && ! $finalizado && ! empty($t['puede_ver']));
                            $puedeVer = ! empty($t['puede_ver']);
                            $rondasPautadas = (int) ($t['rondas_pautadas'] ?? 0);
                            $ultimaRonda = (int) ($t['ultima_ronda'] ?? 0);
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
                                <?php if ($rondasPautadas > 0): ?>
                                    <span class="badge bg-light text-dark border"><?= $ultimaRonda ?>/<?= $rondasPautadas ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($finalizado): ?>
                                    <span class="badge bg-dark">Finalizado</span>
                                <?php elseif ($activo): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($puedeAdmin): ?>
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <?php if ($puedeVer): ?>
                                    <a href="<?= htmlspecialchars($tournamentFormHref('view', $tid), ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-info"
                                       title="Ver detalle">
                                        <i class="fas fa-eye me-1"></i>Ver
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($puedeEditar): ?>
                                    <a href="<?= htmlspecialchars($tournamentFormHref('edit', $tid), ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-primary"
                                       title="Editar torneo">
                                        <i class="fas fa-edit me-1"></i>Editar
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($torneoGestionHref('panel', $tid), ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-outline-success"
                                       title="Panel de gestión">
                                        <i class="fas fa-cogs me-1"></i>Panel
                                    </a>
                                    <?php if ($puedeFinalizar): ?>
                                    <form method="POST"
                                          action="<?= htmlspecialchars($torneoGestionPostHref(), ENT_QUOTES, 'UTF-8') ?>"
                                          class="d-inline"
                                          onsubmit="return confirm('¿Finalizar el torneo? No se podrán modificar datos después.');">
                                        <?= CSRF::input() ?>
                                        <input type="hidden" name="action" value="cerrar_torneo">
                                        <input type="hidden" name="torneo_id" value="<?= $tid ?>">
                                        <input type="hidden" name="from" value="<?= htmlspecialchars((string) ($hubOut['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="hub_org_id" value="<?= (int) ($hubOut['hub_org_id'] ?? 0) ?>">
                                        <input type="hidden" name="hub_tab" value="<?= htmlspecialchars((string) ($hubOut['hub_tab'] ?? 'torneos'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="hub_estado" value="<?= htmlspecialchars((string) ($hubOut['hub_estado'] ?? 'en_proceso'), ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Finalizar torneo">
                                            <i class="fas fa-lock me-1"></i>Finalizar
                                        </button>
                                    </form>
                                    <?php elseif ($finalizado): ?>
                                    <span class="btn btn-sm btn-outline-secondary disabled" title="Torneo ya finalizado">
                                        <i class="fas fa-check me-1"></i>Cerrado
                                    </span>
                                    <?php endif; ?>
                                </div>
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
