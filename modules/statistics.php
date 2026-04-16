<?php
/**
 * Módulo de Estadísticas
 * Muestra estadísticas completas según el nivel de acceso del usuario
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/StatisticsHelper.php';

// Verificar autenticación y rol
Auth::requireRole(['admin_general', 'admin_club']);

// Obtener estadísticas con manejo de errores
try {
    $stats = StatisticsHelper::generateStatistics();
    $user_role = Auth::user()['role'] ?? '';
    
    // Debug: verificar si las estadísticas están vacías
    if (empty($stats)) {
        error_log("StatisticsHelper::generateStatistics() retornó array vacío");
    }
} catch (Exception $e) {
    error_log("Error en statistics.php: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    $stats = [];
    $user_role = Auth::user()['role'] ?? '';
}

?>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-chart-bar me-2"></i>Estadísticas</h1>
            <p class="text-muted mb-0">Resumen completo del sistema</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if (isset($stats['error'])): ?>
        <div class="alert alert-danger">
            <h5>Error al generar estadísticas</h5>
            <p><?= htmlspecialchars($stats['error']) ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($stats) || (isset($stats['admins_by_club']) && empty($stats['admins_by_club']) && isset($stats['supervised_clubs']) && empty($stats['supervised_clubs']))): ?>
        <div class="alert alert-info">
            <h5><i class="fas fa-info-circle me-2"></i>No hay datos para mostrar</h5>
            <p>Rol actual: <strong><?= htmlspecialchars($user_role) ?></strong></p>
            <p>El sistema no encontró datos estadísticos para mostrar en este momento.</p>
            <ul>
                <li>Para <strong>admin_general</strong>: Verifique que existan administradores de club registrados.</li>
                <li>Para <strong>admin_club</strong>: Verifique que tenga clubes asignados y usuarios afiliados.</li>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($user_role === 'admin_general'): ?>
        <!-- ESTADÍSTICAS PARA ADMIN GENERAL -->
        
        <!-- Totales Generales -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h2 class="text-primary mb-0"><?= number_format($stats['total_users'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Total Usuarios</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h2 class="text-success mb-0"><?= number_format($stats['total_active_users'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Usuarios Activos</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h2 class="text-info mb-0"><?= number_format($stats['total_active_clubs'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Clubes Activos</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h2 class="text-warning mb-0"><?= number_format($stats['total_admin_clubs'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Admin. de organización</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h2 class="text-secondary mb-0"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Admin. Torneo</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <h2 class="text-dark mb-0"><?= number_format($stats['total_operadores'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Operadores</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estadísticas por Administrador de organización -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Estadísticas por Administrador de organización</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['admins_by_club'])): ?>
                    <p class="text-muted text-center py-3">No hay administradores de club registrados</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead>
                                <tr>
                                    <th>Administrador</th>
                                    <th class="text-center">Clubes</th>
                                    <th class="text-center">Total Usuarios</th>
                                    <th class="text-center">Hombres</th>
                                    <th class="text-center">Mujeres</th>
                                    <th class="text-center">Inscripciones Activas</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['admins_by_club'] as $admin): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($admin['admin_nombre']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($admin['admin_username']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= $admin['supervised_clubs_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <strong><?= number_format($admin['total_users']) ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= number_format($admin['users_by_gender']['hombres']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= number_format($admin['users_by_gender']['mujeres']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= number_format($admin['active_inscriptions']['total']) ?></span>
                                        </td>
                                        <td>
                                            <a href="?page=admin_clubs&view=detail&admin_id=<?= $admin['admin_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> Ver Detalle
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-info">
                                    <td colspan="2"><strong>TOTALES</strong></td>
                                    <td class="text-center">
                                        <strong><?= number_format(array_sum(array_column($stats['admins_by_club'], 'total_users'))) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format(array_sum(array_column(array_column($stats['admins_by_club'], 'users_by_gender'), 'hombres'))) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format(array_sum(array_column(array_column($stats['admins_by_club'], 'users_by_gender'), 'mujeres'))) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format(array_sum(array_column(array_column($stats['admins_by_club'], 'active_inscriptions'), 'total'))) ?></strong>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detalle de Clubes por Administrador -->
        <?php foreach ($stats['admins_by_club'] as $admin): ?>
            <?php if (!empty($admin['clubs'])): ?>
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-building me-2"></i>
                            Clubes de <?= htmlspecialchars($admin['admin_nombre']) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Club</th>
                                        <th class="text-center">Total Afiliados</th>
                                        <th class="text-center">Hombres</th>
                                        <th class="text-center">Mujeres</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin['clubs'] as $club): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($club['nombre']) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?= number_format($club['total_afiliados']) ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?= number_format($club['hombres']) ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-danger"><?= number_format($club['mujeres']) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

    <?php elseif ($user_role === 'admin_club'): ?>
        <!-- ESTADÍSTICAS PARA ADMIN CLUB -->
        
        <!-- Totales Generales -->
        <div class="row g-4 mb-4">
            <div class="col-md-2">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h2 class="text-primary mb-0"><?= number_format($stats['total_afiliados'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Total Afiliados</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h2 class="text-success mb-0"><?= number_format($stats['afiliados_by_gender']['hombres'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Hombres</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <h2 class="text-danger mb-0"><?= number_format($stats['afiliados_by_gender']['mujeres'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Mujeres</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h2 class="text-info mb-0"><?= number_format(count($stats['supervised_clubs'] ?? [])) ?></h2>
                        <p class="text-muted mb-0">Clubes Gestionados</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h2 class="text-secondary mb-0"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Admin. Torneo</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <h2 class="text-dark mb-0"><?= number_format($stats['total_operadores'] ?? 0) ?></h2>
                        <p class="text-muted mb-0">Operadores</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Afiliados por Club y Género -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Afiliados por Club y Género</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['supervised_clubs'])): ?>
                    <p class="text-muted text-center py-3">No hay clubes gestionados</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Club</th>
                                    <th class="text-center">Total Afiliados</th>
                                    <th class="text-center">Hombres</th>
                                    <th class="text-center">Mujeres</th>
                                    <th class="text-center">Sin Género</th>
                                    <th class="text-center">Distribución</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['supervised_clubs'] as $club): ?>
                                    <?php 
                                    $total = $club['total_afiliados'];
                                    $hombres = $club['hombres'];
                                    $mujeres = $club['mujeres'];
                                    $sin_genero = $club['sin_genero'] ?? 0;
                                    $porc_hombres = $total > 0 ? round(($hombres / $total) * 100) : 0;
                                    $porc_mujeres = $total > 0 ? round(($mujeres / $total) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($club['nombre']) ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary fs-6"><?= number_format($total) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= number_format($hombres) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= number_format($mujeres) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?= number_format($sin_genero) ?></span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 25px;">
                                                <?php if ($hombres > 0): ?>
                                                    <div class="progress-bar bg-primary" role="progressbar" 
                                                         style="width: <?= $porc_hombres ?>%" 
                                                         title="Hombres: <?= $hombres ?>">
                                                        <?= $porc_hombres ?>%
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($mujeres > 0): ?>
                                                    <div class="progress-bar bg-danger" role="progressbar" 
                                                         style="width: <?= $porc_mujeres ?>%" 
                                                         title="Mujeres: <?= $mujeres ?>">
                                                        <?= $porc_mujeres ?>%
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($sin_genero > 0): ?>
                                                    <div class="progress-bar bg-secondary" role="progressbar" 
                                                         style="width: <?= round(($sin_genero / $total) * 100) ?>%" 
                                                         title="Sin género: <?= $sin_genero ?>">
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block mt-1">
                                                H: <?= $hombres ?> (<?= $porc_hombres ?>%) | 
                                                M: <?= $mujeres ?> (<?= $porc_mujeres ?>%)
                                                <?php if ($sin_genero > 0): ?>
                                                    | Sin género: <?= $sin_genero ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-info">
                                    <td><strong>TOTAL</strong></td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['total_afiliados']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['afiliados_by_gender']['hombres']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['afiliados_by_gender']['mujeres']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['afiliados_by_gender']['sin_genero'] ?? 0) ?></strong>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Administradores de Torneo y Operadores -->
        <?php if (!empty($stats['admins_torneo']) || !empty($stats['operadores'])): ?>
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-user-cog me-2"></i>Administradores de Torneo y Operadores</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($stats['admins_torneo'])): ?>
                <h6 class="text-muted mb-2">Administradores de Torneo (<?= count($stats['admins_torneo']) ?>)</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Club</th>
                                <th class="text-center">Torneos</th>
                                <th class="text-center">Inscritos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['admins_torneo'] as $at): ?>
                            <tr>
                                <td><?= htmlspecialchars($at['admin_nombre'] ?? 'N/A') ?></td>
                                <td><code><?= htmlspecialchars($at['admin_username'] ?? '') ?></code></td>
                                <td><?= htmlspecialchars($at['club_nombre'] ?? 'N/A') ?></td>
                                <td class="text-center"><span class="badge bg-primary"><?= (int)($at['total_torneos'] ?? 0) ?></span></td>
                                <td class="text-center"><span class="badge bg-success"><?= (int)($at['total_inscritos'] ?? 0) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if (!empty($stats['operadores'])): ?>
                <h6 class="text-muted mb-2">Operadores (<?= count($stats['operadores']) ?>)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Club</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['operadores'] as $op): ?>
                            <tr>
                                <td><?= htmlspecialchars($op['operador_nombre'] ?? 'N/A') ?></td>
                                <td><code><?= htmlspecialchars($op['operador_username'] ?? '') ?></code></td>
                                <td><?= htmlspecialchars($op['club_nombre'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Inscripciones a Torneos Activos por Club y Género -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Inscripciones a Torneos Activos por Club y Género</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['active_inscriptions']['by_club'])): ?>
                    <p class="text-muted text-center py-3">No hay inscripciones en torneos activos</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Club</th>
                                    <th class="text-center">Total Inscripciones</th>
                                    <th class="text-center">Hombres</th>
                                    <th class="text-center">Mujeres</th>
                                    <th class="text-center">Distribución</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['active_inscriptions']['by_club'] as $inscription): ?>
                                    <?php 
                                    $total = $inscription['total'];
                                    $hombres = $inscription['hombres'];
                                    $mujeres = $inscription['mujeres'];
                                    $porc_hombres = $total > 0 ? round(($hombres / $total) * 100) : 0;
                                    $porc_mujeres = $total > 0 ? round(($mujeres / $total) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($inscription['club_nombre']) ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success fs-6"><?= number_format($total) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= number_format($hombres) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= number_format($mujeres) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($total > 0): ?>
                                                <div class="progress" style="height: 25px;">
                                                    <?php if ($hombres > 0): ?>
                                                        <div class="progress-bar bg-primary" role="progressbar" 
                                                             style="width: <?= $porc_hombres ?>%" 
                                                             title="Hombres: <?= $hombres ?>">
                                                            <?= $porc_hombres ?>%
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($mujeres > 0): ?>
                                                        <div class="progress-bar bg-danger" role="progressbar" 
                                                             style="width: <?= $porc_mujeres ?>%" 
                                                             title="Mujeres: <?= $mujeres ?>">
                                                            <?= $porc_mujeres ?>%
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted d-block mt-1">
                                                    H: <?= $hombres ?> (<?= $porc_hombres ?>%) | 
                                                    M: <?= $mujeres ?> (<?= $porc_mujeres ?>%)
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">Sin inscripciones</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-success">
                                    <td><strong>TOTAL</strong></td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['active_inscriptions']['total']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['active_inscriptions']['by_gender']['hombres']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($stats['active_inscriptions']['by_gender']['mujeres']) ?></strong>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

