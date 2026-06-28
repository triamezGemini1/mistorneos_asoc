<?php
/** @var string $context_label $es_particulares $qs_base $organizacion_id $organizaciones_filtro $por_entidad $por_organizacion $total_eventos $total_jugadores $has_tipo_org $context $scope_solo_mi_org */
?>
<div class="container-fluid ds-estadisticas-torneos-13 py-4">
    <div class="report-page-title-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-chart-line me-2"></i>Reporte de torneos — <?= htmlspecialchars($context_label) ?>
            </h1>
            <p class="text-muted mb-2">Torneos realizados agrupados por organización<?= $es_particulares ? ' particular' : ' (asociación)' ?>.</p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
                    <li class="breadcrumb-item">
                        <a href="index.php?page=<?= $es_particulares ? 'organizaciones_particulares' : 'organizaciones' ?>">
                            <?= $es_particulares ? 'Particulares' : 'Asociaciones' ?>
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Reporte torneos</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($qs_base) ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-list me-1"></i>Listado torneos
            </a>
            <span class="badge bg-primary align-self-center"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if (!$has_tipo_org): ?>
        <div class="alert alert-warning">Falta columna <code>tipo_org</code>.</div>
    <?php elseif (empty($por_organizacion)): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No hay torneos realizados registrados.</div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-primary"><div class="card-body text-center">
                    <h2 class="text-primary mb-0"><?= number_format($total_eventos) ?></h2>
                    <p class="text-muted mb-0 small">Torneos realizados</p>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border-success"><div class="card-body text-center">
                    <h2 class="text-success mb-0"><?= number_format($total_jugadores) ?></h2>
                    <p class="text-muted mb-0 small">Participantes confirmados</p>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border-info"><div class="card-body text-center">
                    <h2 class="text-info mb-0"><?= count($por_organizacion) ?></h2>
                    <p class="text-muted mb-0 small">Organizaciones</p>
                </div></div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body py-3">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="torneos_estructura">
                    <input type="hidden" name="context" value="<?= htmlspecialchars($context) ?>">
                    <input type="hidden" name="vista" value="reporte">
                    <?php if (!$scope_solo_mi_org): ?>
                    <div class="col-md-5">
                        <label class="form-label small mb-1">Organización</label>
                        <select name="organizacion_id" class="form-select form-select-sm">
                            <option value="">Todas</option>
                            <?php foreach ($organizaciones_filtro as $o): ?>
                                <option value="<?= (int) $o['id'] ?>" <?= $organizacion_id === (int) $o['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(TorneosEstructuraService::organizacionEtiqueta([
                                        'org_id' => (int) ($o['id'] ?? 0),
                                        'org_nombre' => (string) ($o['nombre'] ?? ''),
                                        'org_codigo_label' => (string) OrganizacionDashboardStats::federacionCodigoDesdeOrganizacion($o),
                                    ])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>Por organización</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if (!$es_particulares): ?><th>Entidad (ref.)</th><?php endif; ?>
                                <th>Organización (nombre · cód.)</th>
                                <th>Torneo</th>
                                <th>Fecha</th>
                                <th class="text-center">Rondas</th>
                                <th class="text-center">Participantes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($por_entidad as $entidad_id => $ent_data):
                                $ent_nombre = $ent_data['nombre'];
                                $ent_actual = null;
                                foreach ($ent_data['organizaciones'] as $org_id => $data_org):
                                    if (empty($data_org['torneos']) || (int) ($data_org['subtotal_eventos'] ?? 0) <= 0) {
                                        continue;
                                    }
                                    $org_etiqueta = TorneosEstructuraService::organizacionEtiqueta($data_org);
                                    $org_actual = null;
                                    foreach ($data_org['torneos'] as $ev):
                                        $show_ent = !$es_particulares && $ent_actual !== $entidad_id;
                                        $show_org = $org_actual !== $org_id;
                                        if ($show_ent) {
                                            $ent_actual = $entidad_id;
                                        }
                                        if ($show_org) {
                                            $org_actual = $org_id;
                                        }
                                        $fecha_fmt = !empty($ev['fechator']) ? date('d/m/Y', strtotime((string) $ev['fechator'])) : '—';
                                        ?>
                                        <tr>
                                            <?php if (!$es_particulares): ?>
                                                <td><?= $show_ent ? '<strong>' . htmlspecialchars($ent_nombre) . '</strong>' : '' ?></td>
                                            <?php endif; ?>
                                            <td><?= $show_org ? htmlspecialchars($org_etiqueta) : '' ?></td>
                                            <td><?= htmlspecialchars((string) ($ev['nombre'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars($fecha_fmt) ?></td>
                                            <td class="text-center"><?= (int) ($ev['rondas'] ?? 0) ?></td>
                                            <td class="text-center"><?= number_format((int) ($ev['inscritos_confirmados'] ?? 0)) ?></td>
                                        </tr>
                                        <?php
                                        $show_ent = false;
                                        $show_org = false;
                                    endforeach;
                                    if ((int) ($data_org['subtotal_eventos'] ?? 0) <= 0) {
                                        continue;
                                    }
                                    ?>
                                    <tr class="table-info">
                                        <?php if (!$es_particulares): ?><td></td><?php endif; ?>
                                        <td colspan="<?= $es_particulares ? 1 : 1 ?>"></td>
                                        <td colspan="2"><em>Subtotal: <?= htmlspecialchars($org_etiqueta) ?></em></td>
                                        <td class="text-center">—</td>
                                        <td class="text-center"><strong><?= number_format((int) $data_org['subtotal_jugadores']) ?></strong> (<?= (int) $data_org['subtotal_eventos'] ?> torneos)</td>
                                    </tr>
                                <?php endforeach;
                                if (!$es_particulares): ?>
                                    <tr class="table-secondary">
                                        <td></td>
                                        <td colspan="2"><strong>Subtotal entidad: <?= htmlspecialchars($ent_nombre) ?></strong></td>
                                        <td colspan="2"></td>
                                        <td class="text-center"><strong><?= number_format((int) $ent_data['subtotal_jugadores']) ?></strong> (<?= (int) $ent_data['subtotal_eventos'] ?>)</td>
                                    </tr>
                                <?php endif;
                            endforeach;
                            ?>
                            <tr class="table-dark text-white">
                                <?php if (!$es_particulares): ?><td></td><?php endif; ?>
                                <td colspan="3"><strong>TOTAL <?= $es_particulares ? 'PARTICULARES' : 'ASOCIACIONES' ?></strong></td>
                                <td class="text-center">—</td>
                                <td class="text-center"><strong><?= number_format($total_jugadores) ?></strong> (<?= $total_eventos ?> torneos)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
