<?php
/**
 * Vista: Dashboard Home para Admin General
 * Solo tarjetas de estadísticas (sin tablas de torneos o entidades).
 */
require_once __DIR__ . '/../../../lib/app_helpers.php';
$stats = $stats ?? [];
?>
<div class="fade-in">
    <div class="d-flex justify-content-between align-items-center mb-5 flex-wrap gap-3">
        <div>
            <h1 class="h2 mb-2 fw-bold">
                <i class="fas fa-chart-line me-2 text-primary"></i>Dashboard
            </h1>
            <p class="text-muted mb-1 fs-5">
                <i class="fas fa-user-circle me-2"></i>Bienvenido de vuelta, <strong><?= htmlspecialchars($current_user['username'] ?? '') ?></strong>
            </p>
            <p class="text-muted mb-0 small">
                <i class="fas fa-globe me-1"></i>Estadísticas generales del sistema
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('importacion_torneo_externo')) ?>" class="btn btn-outline-secondary">
                <i class="fas fa-file-import me-2"></i>Carga externa transparente
            </a>
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
                    <span class="badge bg-primary fs-6 px-3 py-2">Admin General</span>
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

    <!-- Stats Cards -->
    <div class="row g-4 mb-5 fade-in">
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
                        <p class="mb-0"><i class="fas fa-map-marked-alt me-1"></i>Entidades</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
            <div class="stat-card info">
                <div class="d-flex flex-column align-items-start">
                    <div class="w-100">
                        <h3 class="mb-1"><?= number_format($stats['total_organizaciones'] ?? 0) ?></h3>
                        <p class="mb-0"><i class="fas fa-sitemap me-1"></i>Organizaciones</p>
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
    </div>
</div>
