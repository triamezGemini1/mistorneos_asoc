<?php
/**
 * Vista: Panel de Control de Torneo
 */
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-2">
                <i class="fas fa-cog text-primary"></i> Panel de Control
                <small class="text-muted">- <?php echo htmlspecialchars($torneo['nombre']); ?></small>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=torneo_gestion&action=index">Gestión de Torneos</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($torneo['nombre']); ?></li>
                </ol>
            </nav>
            
            <!-- Información del Torneo -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3">
                    <div class="row align-items-center small">
                        <div class="col-auto">
                            <i class="fas fa-sitemap text-info me-1"></i>
                            <strong>Organización:</strong> 
                            <span class="badge bg-info"><?php echo htmlspecialchars($torneo['organizacion_nombre'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar text-primary me-1"></i>
                            <strong>Fecha:</strong> <?php echo date('d/m/Y', strtotime($torneo['fechator'])); ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users text-success me-1"></i>
                            <strong>Modalidad:</strong> 
                            <?php 
                            $modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
                            echo $modalidades[$torneo['modalidad'] ?? 1] ?? 'N/A'; 
                            ?>
                        </div>
                        <?php if (!empty($torneo['lugar'])): ?>
                        <div class="col-auto">
                            <i class="fas fa-map-marker-alt text-danger me-1"></i>
                            <strong>Lugar:</strong> <?php echo htmlspecialchars($torneo['lugar']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <?php $isLocked = (int)($torneo['locked'] ?? 0) === 1; ?>
    <?php 
    $mesas_verificadas_count = isset($mesas_verificadas_count) ? (int)$mesas_verificadas_count : 0;
    $mesas_digitadas_count = isset($mesas_digitadas_count) ? (int)$mesas_digitadas_count : 0;
    if (($mesas_verificadas_count + $mesas_digitadas_count) > 0): ?>
        <div class="alert alert-light border mb-3">
            <strong><i class="fas fa-chart-bar mr-2"></i>Auditoría de Resultados:</strong>
            <span class="badge bg-success mr-2"><i class="fas fa-camera mr-1"></i>Verificadas (QR): <?= $mesas_verificadas_count ?></span>
            <span class="badge bg-info"><i class="fas fa-keyboard mr-1"></i>Digitadas (admin): <?= $mesas_digitadas_count ?></span>
        </div>
    <?php endif; ?>
    <?php if ($isLocked): ?>
        <div class="alert alert-secondary">
            <i class="fas fa-lock mr-2"></i>
            <strong>Torneo cerrado:</strong> solo se permite consultar e imprimir. Las acciones de modificación están deshabilitadas.
        </div>
    <?php endif; ?>


    <style>
        .card {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        .card-body {
            padding: 0.75rem !important;
        }
        .btn-sm {
            font-size: 0.8125rem;
            font-weight: 500;
            letter-spacing: 0.01em;
            padding: 0.5rem 0.75rem;
            line-height: 1.4;
        }
    </style>
    
    <!-- 6 Gadgets Compactos -->
    <div class="row g-3 mb-4">
        <!-- Gadget 1: Gestionar Inscripciones -->
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <div class="bg-light rounded p-2 mb-2 text-center">
                        <div class="h5 mb-0 text-primary fw-bold"><?php echo $total_inscritos; ?></div>
                        <small class="text-muted"><?php echo $inscritos_confirmados; ?> confirmados</small>
                    </div>
                    <?php if ($isLocked): ?>
                        <!-- Torneo finalizado: inscripciones cerradas -->
                        <button type="button" class="btn btn-sm btn-secondary w-100" disabled>
                            <i class="fas fa-lock mr-1"></i> Gestionar Inscripciones (Cerrado)
                        </button>
                    <?php else: ?>
                        <?php if (in_array((int)($torneo['modalidad'] ?? 0), [2, 3], true)): ?>
                            <?php $es_parejas_panel = (int)($torneo['modalidad'] ?? 0) === 2; ?>
                            <!-- Modalidad Parejas (2) o Equipos (3): mismo flujo inscripción -->
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=gestionar_inscripciones_equipos&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-primary w-100">
                                <i class="fas fa-users mr-1"></i> <?php echo $es_parejas_panel ? 'Inscripciones por Parejas' : 'Inscripciones por Equipos'; ?>
                            </a>
                        <?php else: ?>
                            <!-- Modalidad Individual/Parejas: Redirigir a inscripciones normales -->
                            <a href="index.php?page=registrants&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-primary w-100">
                                <i class="fas fa-clipboard-list mr-1"></i> Gestionar Inscripciones
                            </a>
                            <?php
                            $rondas_panel = [];
                            try {
                                $st = DB::pdo()->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) AS ultima FROM partiresul WHERE id_torneo = ? AND mesa > 0");
                                $st->execute([$torneo['id']]);
                                $rondas_panel = $st->fetchColumn();
                            } catch (Exception $e) {}
                            $torneo_iniciado_panel = !empty($rondas_panel) && (int)$rondas_panel >= 1;
                            if ($torneo_iniciado_panel): ?>
                            <a href="index.php?page=torneo_gestion&action=sustituir_jugador&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-warning w-100 mt-1">
                                <i class="fas fa-user-exchange mr-1"></i> Sustituir jugador retirado
                            </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gadget 2: Inscripción en Sitio -->
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <?php
                    $script_actual = basename($_SERVER['PHP_SELF'] ?? '');
                    $use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
                    $base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
                    ?>
                    <?php if ($isLocked): ?>
                        <button type="button" class="btn btn-sm btn-secondary w-100" disabled>
                            <i class="fas fa-lock mr-1"></i> Inscripción en Sitio (Cerrado)
                        </button>
                    <?php else: ?>
                        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=inscribir_sitio&torneo_id=<?php echo $torneo['id']; ?>" 
                           class="btn btn-sm btn-warning w-100">
                            <i class="fas fa-user-check mr-1"></i> Inscripción en Sitio
                        </a>
                    <?php endif; ?>
                    <a href="index.php?page=tournament_admin&torneo_id=<?php echo (int)$torneo['id']; ?>&action=activar_participantes" 
                       class="btn btn-sm btn-outline-primary w-100 mt-1">
                        <i class="fas fa-user-check mr-1"></i> Activar participantes
                    </a>
                </div>
            </div>
        </div>

        <!-- Gadget Equipos/Parejas (modalidad 2 = Parejas, 3 = Equipos) -->
        <?php if (in_array((int)($torneo['modalidad'] ?? 0), [2, 3], true)): ?>
        <?php $es_parejas_gadget = (int)($torneo['modalidad'] ?? 0) === 2; ?>
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <div class="bg-light rounded p-2 mb-2 text-center">
                        <div class="h5 mb-0 text-indigo fw-bold"><?php echo $total_equipos ?? 0; ?></div>
                        <small class="text-muted"><?php echo $es_parejas_gadget ? 'Parejas inscritas' : 'Equipos inscritos'; ?></small>
                    </div>
                    <?php if ($isLocked): ?>
                        <button type="button" class="btn btn-sm btn-secondary w-100" disabled>
                            <i class="fas fa-lock mr-1"></i> <?php echo $es_parejas_gadget ? 'Gestionar Parejas (Cerrado)' : 'Gestionar Equipos (Cerrado)'; ?>
                        </button>
                    <?php else: ?>
                        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=<?php echo $es_parejas_gadget ? 'gestionar_inscripciones_equipos' : 'equipos'; ?>&torneo_id=<?php echo $torneo['id']; ?>" 
                           class="btn btn-sm btn-indigo w-100 text-white" style="background-color: #6610f2;">
                            <i class="fas fa-users mr-1"></i> <?php echo $es_parejas_gadget ? 'Gestionar Parejas' : 'Gestionar Equipos'; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Gadget 3: Generar Ronda -->
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <?php if ($proxima_ronda <= $torneo['rondas']): ?>
                        <div class="bg-light rounded p-2 mb-2 text-center">
                            <small class="text-muted d-block">Próxima:</small>
                            <div class="h4 mb-0 text-primary fw-bold"><?php echo $proxima_ronda; ?></div>
                            <small class="text-muted">de <?php echo $torneo['rondas']; ?></small>
                        </div>
                        <?php if (!$puede_generar_ronda && ($ultima_ronda ?? 0) > 0): ?>
                            <div class="alert alert-warning py-2 px-2 mb-2" style="font-size: 0.75rem;">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <?php echo $mesas_incompletas ?? 0; ?> mesa(s) pendiente(s)
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="index.php?page=torneo_gestion&action=generar_ronda">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                            <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                            <input type="hidden" name="num_ronda" value="<?php echo $proxima_ronda; ?>">
                            <button type="submit" 
                                    <?php echo (!$puede_generar_ronda || $isLocked) ? 'disabled' : ''; ?>
                                    class="btn btn-sm w-100 <?php echo $puede_generar_ronda ? 'btn-success' : 'btn-secondary'; ?>">
                                <i class="fas fa-<?php echo $puede_generar_ronda ? 'play' : 'lock'; ?> mr-1"></i>
                                Generar Ronda <?php echo $proxima_ronda; ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-success text-center py-2" style="font-size: 0.85rem;">
                            <i class="fas fa-check-circle mr-1"></i>
                            <div class="fw-bold">¡Torneo Completado!</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gadget 4: Ver Mesas Ronda Actual -->
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <?php if (($ultima_ronda ?? 0) > 0): ?>
                        <div class="bg-light rounded p-2 mb-2 text-center">
                            <small class="text-muted d-block">Ronda <?php echo $ultima_ronda; ?></small>
                            <?php if (isset($estadisticas['mesas_ronda'])): ?>
                                <div class="h4 mb-0 text-success fw-bold"><?php echo $estadisticas['mesas_ronda']; ?></div>
                                <small class="text-muted">mesas</small>
                            <?php endif; ?>
                        </div>
                        <a href="index.php?page=torneo_gestion&action=mesas&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                           class="btn btn-sm btn-info w-100">
                            <i class="fas fa-eye mr-1"></i> Ver Mesas
                        </a>
                    <?php else: ?>
                        <div class="alert alert-info text-center py-2" style="font-size: 0.85rem;">
                            <i class="fas fa-info-circle mr-1"></i>
                            <div>Sin rondas</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gadget 5: Agregar Mesa -->
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <?php if (($ultima_ronda ?? 0) > 0): ?>
                        <div class="bg-light rounded p-2 mb-2 text-center">
                            <small class="text-muted">Ronda <?php echo $ultima_ronda; ?></small>
                        </div>
                        <a href="index.php?page=torneo_gestion&action=agregar_mesa&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                           class="btn btn-sm btn-info w-100 <?php echo $isLocked ? 'disabled' : ''; ?>"
                           <?php echo $isLocked ? 'aria-disabled="true" onclick="return false;" style="pointer-events:none; opacity:0.6;"' : ''; ?>>
                            <i class="fas fa-plus-circle mr-1"></i> Agregar Mesa
                        </a>
                    <?php else: ?>
                        <div class="alert alert-info text-center py-2" style="font-size: 0.85rem;">
                            <i class="fas fa-info-circle mr-1"></i>
                            <div>Sin rondas</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gadget 6: Cuadrícula / Hojas / Reportes -->
        <div class="col-12 col-md-6 col-lg-6">
            <div class="card">
                <div class="card-body p-2">
                    <?php if (($ultima_ronda ?? 0) > 0): ?>
                        <?php
                        if (! class_exists('AppHelpers', false)) {
                            require_once dirname(__DIR__, 4) . '/lib/app_helpers.php';
                        }
                        $url_ranking_atletas_public_panel = rtrim(AppHelpers::getPublicUrl(), '/') . '/ranking_atletas.php';
                        ?>
                        <div class="d-grid gap-1">
                            <a href="index.php?page=torneo_gestion&action=cuadricula&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                               class="btn btn-sm btn-purple" style="font-size: 0.75rem;">
                                <i class="fas fa-th mr-1"></i> Cuadrícula
                            </a>
                            <a href="index.php?page=torneo_gestion&action=hojas_anotacion&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ultima_ronda; ?>" 
                               class="btn btn-sm btn-primary" style="font-size: 0.75rem;">
                                <i class="fas fa-print mr-1"></i> Hojas Anotación
                            </a>
                            <a href="index.php?page=torneo_gestion&action=posiciones&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-success" style="font-size: 0.75rem;">
                                <i class="fas fa-trophy mr-1"></i> Posiciones
                            </a>
                            <?php 
                            $es_modalidad_equipos_panel = isset($torneo['modalidad']) && (int)$torneo['modalidad'] === 3;
                            $podios_action_panel = $es_modalidad_equipos_panel ? 'podios_equipos' : 'podios';
                            ?>
                            <a href="index.php?page=torneo_gestion&action=<?php echo $podios_action_panel; ?>&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-warning" style="font-size: 0.75rem;">
                                <i class="fas fa-medal mr-1"></i> Podios
                            </a>
                            <a href="index.php?page=torneo_gestion&action=resultados_por_club&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-info" style="font-size: 0.75rem;">
                                <i class="fas fa-building mr-1"></i> Resultados Clubes
                            </a>
                            <a href="index.php?page=torneo_gestion&action=resultados_reportes&torneo_id=<?php echo $torneo['id']; ?>" 
                               class="btn btn-sm btn-secondary" style="font-size: 0.75rem;">
                                <i class="fas fa-file-pdf mr-1"></i> Reportes PDF/Excel
                            </a>
                            <a href="<?php echo htmlspecialchars($url_ranking_atletas_public_panel, ENT_QUOTES, 'UTF-8'); ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="btn btn-sm btn-info" style="font-size: 0.75rem;"
                               title="Ranking histórico público (femenino/masculino)">
                                <i class="fas fa-medal mr-1"></i> Ranking atletas (público)
                            </a>
                            <?php 
                            $isLocked = (int)($torneo['locked'] ?? 0) === 1;
                            $puedeCerrar = !$isLocked && ($ultima_ronda ?? 0) > 0 && ($mesas_incompletas ?? 1) == 0;
                            ?>
                            <form method="POST" action="index.php?page=torneo_gestion" class="d-inline"
                                  onsubmit="event.preventDefault(); confirmarCierreTorneoBasico(event);">
                                <input type="hidden" name="action" value="cerrar_torneo">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                                <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $isLocked ? 'btn-secondary' : 'btn-dark'; ?>" 
                                        style="font-size: 0.75rem;" <?php echo $puedeCerrar ? '' : 'disabled'; ?>>
                                    <i class="fas fa-lock mr-1"></i> <?php echo $isLocked ? 'Torneo Cerrado' : 'Cerrar Torneo'; ?>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center py-2" style="font-size: 0.85rem;">
                            <i class="fas fa-info-circle mr-1"></i>
                            <div>Sin rondas</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Rondas Generadas -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list mr-2"></i> Rondas Generadas</h5>
                    <?php if (!$isLocked && $puede_generar_ronda && $proxima_ronda <= $torneo['rondas']): ?>
                        <form method="POST" action="index.php?page=torneo_gestion&action=generar_ronda" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                            <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                            <input type="hidden" name="num_ronda" value="<?php echo $proxima_ronda; ?>">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="fas fa-plus mr-1"></i> Generar Ronda <?php echo $proxima_ronda; ?>
                            </button>
                        </form>
                    <?php elseif ($isLocked): ?>
                        <span class="badge badge-secondary">
                            <i class="fas fa-lock mr-1"></i> Torneo Cerrado
                        </span>
                    <?php elseif (!$puede_generar_ronda && $mesas_incompletas > 0): ?>
                        <span class="badge badge-warning">
                            Faltan resultados en <?php echo $mesas_incompletas; ?> mesa(s) de la ronda <?php echo $ultima_ronda; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($rondas_generadas)): ?>
                        <p class="text-muted text-center py-4">
                            <i class="fas fa-info-circle mr-2"></i>
                            Aún no se han generado rondas para este torneo.
                            <?php if ($proxima_ronda <= $torneo['rondas']): ?>
                                Puedes generar la primera ronda usando el botón superior.
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Ronda</th>
                                        <th>Mesas</th>
                                        <th>Jugadores</th>
                                        <th>BYE</th>
                                        <th>Fecha</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rondas_generadas as $ronda): ?>
                                        <tr>
                                            <td><strong>Ronda <?php echo $ronda['num_ronda']; ?></strong></td>
                                            <td><?php echo $ronda['total_mesas']; ?></td>
                                            <td><?php echo $ronda['total_jugadores']; ?></td>
                                            <td><?php echo $ronda['jugadores_bye']; ?></td>
                                            <td><?php echo $ronda['fecha_generacion'] ? date('d/m/Y H:i', strtotime($ronda['fecha_generacion'])) : 'N/A'; ?></td>
                                            <td>
                                                <a href="index.php?page=torneo_gestion&action=mesas&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda['num_ronda']; ?>" 
                                                   class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye mr-1"></i> Ver Mesas
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarCierreTorneoBasico(event) {
    Swal.fire({
        title: 'Cerrar torneo (irreversible)',
        html: '<p>Esta acción cerrará definitivamente el torneo y no permitirá más cambios.</p><p><strong>Sugerencia:</strong> Espere 15 minutos tras finalizar (0 mesas pendientes) antes de cerrar, para atender reclamos.</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cerrar definitivamente',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#111827',
        cancelButtonColor: '#6c757d',
        reverseButtons: true,
        focusCancel: true
    }).then((res) => {
        if (res.isConfirmed) {
            event.target.submit();
        }
    });
}
</script>
