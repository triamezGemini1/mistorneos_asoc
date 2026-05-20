<?php
/** @var string $context $context_label $es_particulares $qs_base $filtro $organizacion_id $organizaciones_filtro $por_organizacion $total_eventos $total_jugadores $has_tipo_org $entidad_map $is_admin_general $scope_solo_mi_org */
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3 mb-1">
                <i class="fas fa-trophy text-warning me-2"></i>Torneos — <?= htmlspecialchars($context_label) ?>
            </h1>
            <p class="text-muted mb-2">Listado agrupado por organización. Cada torneo depende de su organización responsable.</p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
                    <li class="breadcrumb-item">
                        <a href="index.php?page=<?= $es_particulares ? 'organizaciones_particulares' : 'organizaciones' ?>">
                            <?= $es_particulares ? 'Org. particulares' : 'Asociaciones' ?>
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Torneos</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex flex-wrap gap-2 align-items-start">
            <a href="<?= htmlspecialchars($qs_base . '&vista=reporte') ?>" class="btn btn-outline-info btn-sm">
                <i class="fas fa-chart-line me-1"></i>Reporte
            </a>
            <a href="index.php?page=torneo_gestion&action=index" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-cog me-1"></i>Gestión operativa
            </a>
            <?php if ($is_admin_general): ?>
                <?php if ($es_particulares): ?>
                    <a href="index.php?page=organizaciones_particulares" class="btn btn-outline-primary btn-sm">Ver particulares</a>
                <?php else: ?>
                    <a href="index.php?page=organizaciones" class="btn btn-outline-primary btn-sm">Ver asociaciones</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$has_tipo_org): ?>
        <div class="alert alert-warning">Falta columna <code>tipo_org</code> en organizaciones.</div>
    <?php else: ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="torneos_estructura">
                <input type="hidden" name="context" value="<?= htmlspecialchars($context) ?>">
                <?php if (!$scope_solo_mi_org): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Organización</label>
                    <select name="organizacion_id" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <?php foreach ($organizaciones_filtro as $o): ?>
                            <option value="<?= (int) $o['id'] ?>" <?= $organizacion_id === (int) $o['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(TorneosEstructuraService::organizacionEtiqueta([
                                    'org_id' => (int) ($o['id'] ?? 0),
                                    'org_nombre' => (string) ($o['nombre'] ?? ''),
                                    'org_codigo_label' => (string) OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($o),
                                    'entidad_nombre' => (string) ($o['entidad_nombre'] ?? ''),
                                ])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                    <?php if ($organizacion_id > 0 || $filtro): ?>
                        <a href="<?= htmlspecialchars($qs_base) ?>" class="btn btn-sm btn-outline-secondary ms-1">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>
            <div class="btn-group btn-group-sm mt-3 flex-wrap">
                <a href="<?= htmlspecialchars($qs_base . ($organizacion_id ? '&organizacion_id=' . $organizacion_id : '')) ?>" class="btn btn-outline-secondary <?= !$filtro ? 'active' : '' ?>">Todos</a>
                <a href="<?= htmlspecialchars($qs_base . '&filtro=por_realizar' . ($organizacion_id ? '&organizacion_id=' . $organizacion_id : '')) ?>" class="btn btn-outline-info <?= $filtro === 'por_realizar' ? 'active' : '' ?>">Por realizar</a>
                <a href="<?= htmlspecialchars($qs_base . '&filtro=en_proceso' . ($organizacion_id ? '&organizacion_id=' . $organizacion_id : '')) ?>" class="btn btn-outline-primary <?= $filtro === 'en_proceso' ? 'active' : '' ?>">En proceso</a>
                <a href="<?= htmlspecialchars($qs_base . '&filtro=realizados' . ($organizacion_id ? '&organizacion_id=' . $organizacion_id : '')) ?>" class="btn btn-outline-success <?= $filtro === 'realizados' ? 'active' : '' ?>">Realizados</a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm"><div class="card-body text-center">
                <div class="h4 mb-0"><?= (int) $total_eventos ?></div>
                <div class="small text-muted">Torneos</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm"><div class="card-body text-center">
                <div class="h4 mb-0"><?= count($por_organizacion) ?></div>
                <div class="small text-muted">Organizaciones con torneos</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm"><div class="card-body text-center">
                <div class="h4 mb-0"><?= (int) $total_jugadores ?></div>
                <div class="small text-muted">Inscritos confirmados</div>
            </div></div>
        </div>
    </div>

    <?php if (empty($por_organizacion)): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No hay torneos para este criterio.</div>
    <?php else: ?>
        <?php foreach ($por_organizacion as $org): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-building me-2 text-primary"></i><?= htmlspecialchars(TorneosEstructuraService::organizacionEtiqueta($org)) ?>
                        </h5>
                        <?php
                        $entNom = trim((string) ($org['entidad_nombre'] ?? ''));
                        if ($entNom === '' && !empty($org['entidad']) && isset($entidad_map[(string) $org['entidad']])) {
                            $entNom = (string) $entidad_map[(string) $org['entidad']];
                        }
                        if ($entNom !== ''): ?>
                            <small class="text-muted">Entidad ref.: <?= htmlspecialchars($entNom) ?></small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="badge bg-secondary me-1"><?= (int) $org['subtotal_eventos'] ?> torneos</span>
                        <?php if ((int) $org['org_id'] > 0): ?>
                            <a href="index.php?page=organizaciones&id=<?= (int) $org['org_id'] ?><?= $es_particulares ? '&from=particulares' : '' ?>" class="btn btn-sm btn-outline-primary">Ver organización</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Estado</th>
                                    <th>Nombre</th>
                                    <th>Fecha</th>
                                    <th class="text-center">Inscritos</th>
                                    <th class="text-center">Rondas</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($org['torneos'] as $t):
                                    $cat = $t['categoria'] ?? '';
                                    $fecha = !empty($t['fechator']) ? date('d/m/Y', strtotime((string) $t['fechator'])) : '—';
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($cat === 'por_realizar'): ?><span class="badge bg-info">Por realizar</span>
                                            <?php elseif ($cat === 'en_proceso'): ?><span class="badge bg-primary">En proceso</span>
                                            <?php else: ?><span class="badge bg-success">Realizado</span><?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($t['nombre'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($fecha) ?></td>
                                        <td class="text-center"><?= (int) ($t['inscritos_confirmados'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int) ($t['rondas'] ?? 0) ?></td>
                                        <td class="text-end">
                                            <a href="index.php?page=torneo_gestion&action=panel&torneo_id=<?= (int) $t['id'] ?>" class="btn btn-sm btn-outline-success">Panel</a>
                                            <a href="index.php?page=torneo_gestion&action=view&id=<?= (int) $t['id'] ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
