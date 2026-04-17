<?php
/**
 * Vista principal del Dashboard
 * Solo presenta datos; las variables vienen del router (DashboardData::loadAll)
 * No incluye lógica SQL ni scripts inline (se cargan en el layout footer)
 */
$stats = $stats ?? [];
$admin_club_stats = $admin_club_stats ?? [];
$torneos_linea_acl = $torneos_linea_acl ?? ['por_realizar' => [], 'en_proceso' => [], 'realizados' => []];
$athletes_by_club = $athletes_by_club ?? [];
?>
<div class="fade-in">
    <div class="d-flex justify-content-between align-items-center mb-5 flex-wrap gap-3">
        <div>
            <h1 class="h2 mb-2 fw-bold">
                <i class="fas fa-chart-line me-2 text-primary"></i>Dashboard
            </h1>
            <p class="text-muted mb-1 fs-5">
                <i class="fas fa-user-circle me-2"></i>Bienvenido de vuelta, <strong><?= htmlspecialchars($_SESSION['user']['username']) ?></strong>
            </p>
            <p class="text-muted mb-0 small">
                <?php if ($user_role === 'admin_general'): ?>
                    <i class="fas fa-globe me-1"></i>Estadísticas generales del sistema
                <?php elseif ($user_role === 'admin_club'): ?>
                    <i class="fas fa-sitemap me-1"></i>Estadísticas de su organización
                <?php else: ?>
                    <i class="fas fa-trophy me-1"></i>Estadísticas de su ámbito de torneo
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas')) ?>" class="btn btn-primary">
                <i class="fas fa-bell me-2"></i>Enviar notificaciones
            </a>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end">
                    <div class="text-muted small">Hoy es</div>
                    <div class="fw-bold text-primary"><?= date('d/m/Y') ?></div>
                </div>
                <div class="vr"></div>
                <div>
                    <div class="text-muted small">Rol</div>
                    <span class="badge bg-primary fs-6 px-3 py-2">
                        <?= ucfirst(str_replace('_', ' ', $_SESSION['user']['role'])) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['debug']) && $_GET['debug'] === '1'): ?>
    <div class="alert alert-info">
        <h5>Debug Info:</h5>
        <pre><?php print_r($stats); ?></pre>
        <pre>User Role: <?= $user_role ?></pre>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-4 mb-5 fade-in">
        <?php if ($user_role === 'admin_general'): ?>
        <div class="col-12 mb-3">
            <h4 class="text-muted"><i class="fas fa-chart-line me-2"></i>Estadísticas Globales del Sistema</h4>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card primary">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_users'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-users me-1"></i>Total Usuarios</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card info">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_entidades'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-map-marked-alt me-1"></i>Total Entidades</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card info">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_organizaciones'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-sitemap me-1"></i>Total Organizaciones</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card info">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_admin_clubs'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-shield me-1"></i>Admin Organización</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card success">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_clubs'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-building me-1"></i>Clubes Afiliados</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card warning">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_afiliados'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-check me-1"></i>Total Afiliados</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card danger">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_hombres'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card purple">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_mujeres'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card secondary">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_admin_torneo'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-tie me-1"></i>Admin Torneo</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card dark">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_operadores'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-cog me-1"></i>Operadores</p>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($_SESSION['user']['role'] === 'admin_club'): ?>
        <?php
        $acl = $admin_club_stats ?? [];
        $hombres = (int)($acl['afiliados_by_gender']['hombres'] ?? $acl['total_hombres'] ?? 0);
        $mujeres = (int)($acl['afiliados_by_gender']['mujeres'] ?? $acl['total_mujeres'] ?? 0);
        $pr_acl = $torneos_linea_acl['por_realizar'] ?? [];
        $ep_acl = $torneos_linea_acl['en_proceso'] ?? [];
        $re_acl = $torneos_linea_acl['realizados'] ?? [];
        $torneos_por_realizar = count($pr_acl) + count($ep_acl);
        $torneos_realizados = count($re_acl);
        ?>
        <div class="col-12 mb-3">
            <h4 class="text-muted"><i class="fas fa-chart-line me-2"></i>Estadísticas de mi Organización</h4>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card success">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($acl['total_clubes'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-building me-1"></i>Clubes</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card warning">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($acl['total_afiliados'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-check me-1"></i>Total Afiliados</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card danger">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($hombres) ?></h3>
                        <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card purple">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($mujeres) ?></h3>
                        <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card secondary">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($acl['total_admin_torneo'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-tie me-1"></i>Admin Torneo</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card dark">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($acl['total_operadores'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-user-cog me-1"></i>Operadores</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card info">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($torneos_por_realizar) ?></h3>
                        <p class="mb-0"><i class="fas fa-clock me-1"></i>Torneos por realizar</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card primary">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($torneos_realizados) ?></h3>
                        <p class="mb-0"><i class="fas fa-trophy me-1"></i>Torneos realizados</p>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= number_format($stats['clubs'] ?? 0) ?></h3>
                        <p><i class="fas fa-building me-2"></i>Clubs Activos</p>
                    </div>
                    <div class="fs-1 opacity-25"><i class="fas fa-building"></i></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= number_format($stats['active_tournaments'] ?? 0) ?></h3>
                        <p><i class="fas fa-trophy me-2"></i>Torneos Activos</p>
                    </div>
                    <div class="fs-1 opacity-25"><i class="fas fa-trophy"></i></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= number_format($stats['tournaments'] ?? 0) ?></h3>
                        <p><i class="fas fa-trophy me-2"></i>Total Torneos</p>
                    </div>
                    <div class="fs-1 opacity-25"><i class="fas fa-trophy"></i></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="alert alert-info d-flex align-items-center">
                <i class="fas fa-map-marker-alt me-2"></i>
                <div><strong>Entidad de tus clubes:</strong> <?= htmlspecialchars($entidad_nombre_actual ?? 'No definida') ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
