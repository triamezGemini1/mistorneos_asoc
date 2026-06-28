<?php
/**
 * Vista: Inscripciones del Torneo
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$tid_panel = (int) ($torneo['id'] ?? 0);
$url_panel = class_exists('AppHelpers')
    ? AppHelpers::urlPanelTorneoReturn($tid_panel)
    : ($base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . $tid_panel);
$total_inscritos = isset($total_inscritos) ? (int)$total_inscritos : 0;
$confirmados = isset($confirmados) ? (int)$confirmados : 0;
$contadores_inscripcion = isset($contadores_inscripcion) && is_array($contadores_inscripcion) ? $contadores_inscripcion : ['inscritos_total' => $total_inscritos, 'jugadores_confirmados' => $confirmados, 'equipos_activos' => 0];
$torneo_costo = (float) ($torneo['costo'] ?? 0);
$hombres = isset($hombres) ? (int)$hombres : 0;
$mujeres = isset($mujeres) ? (int)$mujeres : 0;
$resumen_clubes = $resumen_clubes ?? [];
$puede_confirmar_retirar = isset($puede_confirmar_retirar) ? $puede_confirmar_retirar : true;
$pendientes_confirmar = isset($pendientes_confirmar) ? (int)$pendientes_confirmar : 0;
$total_retirados = isset($total_retirados) ? (int)$total_retirados : 0;
$csrf = class_exists('CSRF') ? CSRF::token() : '';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="breadcrumb-modern mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo $base_url; ?>">Gestión de Torneos</a></li>
        <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($url_panel); ?>"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></a></li>
        <li class="breadcrumb-item active">Inscripciones</li>
    </ol>
</nav>

<!-- Header del Torneo -->
<div class="card-modern mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <div class="d-flex justify-content-between align-items-center p-4">
        <div>
            <h2 class="mb-2" style="color: white; font-weight: 700;">
                <i class="fas fa-users me-2"></i>
                Inscripciones - <?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?>
            </h2>
            <div class="d-flex gap-4 flex-wrap" style="opacity: 0.9; font-size: 0.9rem;">
                <span><i class="fas fa-calendar-alt me-1"></i> <?php echo date('d/m/Y', strtotime($torneo['fechator'] ?? 'now')); ?></span>
                <span><i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($torneo['club_nombre'] ?? 'N/A'); ?></span>
            </div>
            <?php require __DIR__ . '/../../resources/views/partials/torneo_inscripcion_badges_bs5.php'; ?>
        </div>
        <div class="text-end">
            <a href="<?php echo htmlspecialchars($url_panel); ?>" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-2"></i> Retornar al Panel
            </a>
        </div>
    </div>
</div>

<!-- Botón retorno al panel (visible debajo del header) -->
<div class="mb-3">
    <a href="<?php echo htmlspecialchars($url_panel); ?>" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Volver al panel del torneo
    </a>
</div>

<!-- Estadísticas Rápidas -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="stat-label" style="opacity: 0.9;">Total Inscritos</div>
            <div class="stat-value" style="font-size: 2.5rem;"><?php echo $total_inscritos ?? 0; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
            <div class="stat-label" style="opacity: 0.9;">Pagados</div>
            <div class="stat-value" style="font-size: 2.5rem;"><?php echo $confirmados ?? 0; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
            <div class="stat-label" style="opacity: 0.9;">Hombres</div>
            <div class="stat-value" style="font-size: 2.5rem;"><?php echo $hombres ?? 0; ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
            <div class="stat-label" style="opacity: 0.9;">Mujeres</div>
            <div class="stat-value" style="font-size: 2.5rem;"><?php echo $mujeres ?? 0; ?></div>
        </div>
    </div>
</div>

<!-- Botón Agregar Jugador (solo si el torneo no ha iniciado) -->
<?php if (!$torneo_iniciado): ?>
<div class="row mb-4">
    <div class="col-12">
        <a href="index.php?page=registrants&action=crear&torneo_id=<?php echo $torneo['id']; ?>" 
           class="btn btn-success btn-lg">
            <i class="fas fa-user-plus me-2"></i> Inscribir Nuevo Jugador
        </a>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info mb-4">
    <i class="fas fa-info-circle me-2"></i>
    <strong>El torneo ya ha iniciado.</strong> No se pueden agregar nuevos jugadores.
    <?php
    $retirados = $retirados ?? [];
    $es_modalidad_equipos = isset($torneo['modalidad']) && (int)$torneo['modalidad'] === 3;
    if ($total_retirados > 0 && !$es_modalidad_equipos):
        $url_sustituir = $base_url . ($use_standalone ? '?' : '&') . 'action=sustituir_jugador&torneo_id=' . (int)$torneo['id'];
    ?>
    <span class="ms-2">
        <a href="<?= htmlspecialchars($url_sustituir) ?>" class="btn btn-warning btn-sm ms-2">
            <i class="fas fa-user-exchange me-1"></i> Sustituir jugador retirado (<?= (int)$total_retirados ?>)
        </a>
    </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Resumen por Clubes -->
<?php if (!empty($resumen_clubes)): ?>
<div class="card-modern mb-4" style="box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 10px;">
    <div class="card-header-modern p-3" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0.05) 100%); border-bottom: 2px solid #e5e7eb;">
        <h5 class="mb-0 fw-bold" style="color: #1f2937;">
            <i class="fas fa-building me-2" style="color: #6366f1;"></i>
            Resumen por Clubes
        </h5>
    </div>
    <div class="card-body-modern p-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead style="background: #f9fafb;">
                    <tr>
                        <th style="border: none; padding: 12px; font-weight: 600;">Club</th>
                        <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Total</th>
                        <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Hombres</th>
                        <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Mujeres</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resumen_clubes as $club): ?>
                        <tr style="transition: background 0.2s;">
                            <td style="border: none; padding: 12px;">
                                <strong><?php echo htmlspecialchars($club['nombre']); ?></strong>
                            </td>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <span class="badge bg-primary"><?php echo $club['total']; ?></span>
                            </td>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <span class="badge bg-info"><?php echo $club['hombres']; ?></span>
                            </td>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <span class="badge bg-warning"><?php echo $club['mujeres']; ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Listado de Inscritos -->
<div class="card-modern" style="box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 10px;">
    <div class="card-header-modern p-3 inscripciones-listado-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0 fw-bold">
            <i class="fas fa-list me-2"></i>
            Listado de Inscritos (<?php echo $total_inscritos; ?>)
        </h5>
        <?php if (!empty($puede_confirmar_retirar) && $pendientes_confirmar > 0): ?>
        <form method="post" action="" class="mb-0" onsubmit="return confirm('¿Confirmar las <?php echo (int)$pendientes_confirmar; ?> inscripción(es) pendiente(s)?');">
            <input type="hidden" name="action" value="confirmar_inscripciones_torneo">
            <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? (class_exists('CSRF') ? CSRF::token() : ''), ENT_QUOTES); ?>">
            <button type="submit" class="btn btn-success btn-sm">
                <i class="fas fa-check-double me-1"></i> Confirmar inscripciones (<?php echo (int)$pendientes_confirmar; ?>)
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body-modern p-4">
        <?php if (empty($inscritos)): ?>
            <div class="alert alert-info text-center py-5">
                <i class="fas fa-info-circle fa-3x mb-3" style="opacity: 0.5;"></i>
                <h5>No hay inscritos registrados</h5>
                <p class="text-muted mb-0">
                    <?php if (!$torneo_iniciado): ?>
                        Puedes comenzar inscribiendo jugadores usando el botón superior.
                    <?php else: ?>
                        El torneo ya ha iniciado y no se pueden agregar nuevos inscritos.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php if ($total_retirados > 0): ?>
            <p class="small text-muted mb-3"><i class="fas fa-info-circle me-1"></i> Los jugadores retirados (<?php echo (int)$total_retirados; ?>) no se muestran en este listado.</p>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="border-radius: 8px; overflow: hidden;">
                    <thead style="background: #f9fafb;">
                        <tr>
                            <th style="border: none; padding: 12px; font-weight: 600;">#</th>
                            <th style="border: none; padding: 12px; font-weight: 600;">Jugador</th>
                            <th style="border: none; padding: 12px; font-weight: 600;">Username</th>
                            <th style="border: none; padding: 12px; font-weight: 600;">Club</th>
                            <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Género</th>
                            <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Estado</th>
                            <?php if ($torneo_costo > 0): ?>
                            <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Pago</th>
                            <?php endif; ?>
                            <th style="border: none; padding: 12px; font-weight: 600; text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $contador = 1;
                        $club_actual = '';
                        foreach ($inscritos as $inscrito): 
                            $nuevo_club = $inscrito['nombre_club'] ?? 'Sin Club';
                            if ($club_actual !== $nuevo_club):
                                $club_actual = $nuevo_club;
                        ?>
                        <tr style="background: rgba(99, 102, 241, 0.05);">
                            <td colspan="<?= $torneo_costo > 0 ? 8 : 7 ?>" style="border: none; padding: 8px 12px; font-weight: 600; color: #6366f1;">
                                <i class="fas fa-building me-2"></i><?php echo htmlspecialchars($club_actual); ?>
                            </td>
                        </tr>
                        <?php endif;
                            $estatus = $inscrito['estatus'] ?? 0;
                            $es_pagado = InscritosHelper::esConfirmado($estatus);
                            $es_pendiente = !$es_pagado;
                        ?>
                        <tr style="transition: background 0.2s;">
                            <td style="border: none; padding: 12px;"><?php echo $contador++; ?></td>
                            <td style="border: none; padding: 12px;">
                                <strong><?php echo htmlspecialchars($inscrito['nombre_completo'] ?? 'N/A'); ?></strong>
                            </td>
                            <td style="border: none; padding: 12px;">
                                <?php echo htmlspecialchars($inscrito['username'] ?? '-'); ?>
                            </td>
                            <td style="border: none; padding: 12px;">
                                <?php echo htmlspecialchars($inscrito['nombre_club'] ?? 'Sin Club'); ?>
                            </td>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <?php 
                                $sexo = $inscrito['sexo'] ?? '';
                                if ($sexo == 1 || strtoupper($sexo) === 'M') {
                                    echo '<span class="badge bg-info"><i class="fas fa-mars me-1"></i>M</span>';
                                } elseif ($sexo == 2 || strtoupper($sexo) === 'F') {
                                    echo '<span class="badge bg-warning"><i class="fas fa-venus me-1"></i>F</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">-</span>';
                                }
                                ?>
                            </td>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <?php 
                                if ($es_pagado) {
                                    echo '<span class="badge bg-success">Confirmado</span>';
                                } elseif ($es_pendiente) {
                                    echo '<span class="badge bg-warning text-dark">Pendiente</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">' . htmlspecialchars((string) $estatus) . '</span>';
                                }
                                ?>
                            </td>
                            <?php if ($torneo_costo > 0): ?>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <?php if (!empty($puede_confirmar_retirar)): ?>
                                <form method="post" action="" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_pago_inscrito">
                                    <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                    <input type="hidden" name="inscripcion_id" value="<?php echo (int)($inscrito['id'] ?? 0); ?>">
                                    <input type="hidden" name="pagado" value="<?php echo $es_pagado ? '0' : '1'; ?>" class="input-pagado-val">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? ''); ?>">
                                    <div class="form-check form-switch d-inline-flex align-items-center justify-content-center mb-0">
                                        <input type="checkbox" class="form-check-input" role="switch" <?php echo $es_pagado ? 'checked' : ''; ?>
                                               onchange="var f=this.form;f.querySelector('.input-pagado-val').value=this.checked?'1':'0';f.submit();">
                                        <label class="form-check-label small ms-1"><?php echo $es_pagado ? 'Pagado' : 'Pendiente'; ?></label>
                                    </div>
                                </form>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td style="border: none; padding: 12px; text-align: center;">
                                <?php if (!empty($puede_confirmar_retirar)): ?>
                                <div class="btn-group btn-group-sm flex-wrap justify-content-center">
                                    <?php if ($es_pendiente): ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="action" value="cambiar_estatus_inscrito">
                                        <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int)($inscrito['id'] ?? 0); ?>">
                                        <input type="hidden" name="estatus" value="<?php echo (int) InscritosHelper::ESTATUS_CONFIRMADO_NUM; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? ''); ?>">
                                        <button type="submit" class="btn btn-success btn-sm" title="Confirmar inscripción">
                                            <i class="fas fa-check"></i> Confirmar
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($torneo_costo > 0 && $es_pendiente): ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="action" value="enviar_recordatorio_pago_inscrito">
                                        <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int)($inscrito['id'] ?? 0); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? ''); ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm" title="WhatsApp + notificación al atleta">
                                            <i class="fab fa-whatsapp"></i> Recordatorio
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" action="" class="d-inline" onsubmit="return confirm('¿Retirar a este jugador del torneo?');">
                                        <input type="hidden" name="action" value="cambiar_estatus_inscrito">
                                        <input type="hidden" name="torneo_id" value="<?php echo (int)($torneo['id'] ?? 0); ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int)($inscrito['id'] ?? 0); ?>">
                                        <input type="hidden" name="estatus" value="<?php echo (int) InscritosHelper::ESTATUS_RETIRADO_NUM; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? ''); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Retirar del evento">
                                            <i class="fas fa-user-minus"></i> Retirar
                                        </button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small" title="Opciones bloqueadas (torneo cerrado)">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="mt-4 pt-3 border-top">
            <a href="<?php echo htmlspecialchars($url_panel); ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Retornar al panel del torneo
            </a>
        </div>
    </div>
</div>

<style>
.stat-card {
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.stat-label {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    line-height: 1;
}

.card-modern {
    background: white;
    border: 1px solid #e5e7eb;
    transition: transform 0.2s, box-shadow 0.2s;
}

.card-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
}

.card-header-modern {
    border-bottom: 2px solid #e5e7eb;
}

.card-body-modern {
    padding: 1.5rem;
}

.breadcrumb-modern {
    background: transparent;
    padding: 0;
    margin-bottom: 1.5rem;
}

.breadcrumb-modern .breadcrumb-item a {
    color: #6366f1;
    text-decoration: none;
}

.breadcrumb-modern .breadcrumb-item a:hover {
    text-decoration: underline;
}
</style>

<?php if (!empty($_SESSION['whatsapp_redirect_inscripcion'])): ?>
<script>
(function () {
    var url = <?php echo json_encode((string) $_SESSION['whatsapp_redirect_inscripcion'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    if (url) { window.open(url, '_blank', 'noopener'); }
})();
</script>
<?php unset($_SESSION['whatsapp_redirect_inscripcion']); endif; ?>

