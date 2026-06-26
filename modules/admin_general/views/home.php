<?php
/**
 * Vista: Dashboard Home para Admin General
 * Tarjetas en bloques de 3, layout 80% ancho, sección identidad de la app.
 */
require_once __DIR__ . '/../../../lib/app_helpers.php';

$stats = $stats ?? [];
$productName = class_exists('Branding', false) ? Branding::siteName() : 'MisTorneos ASOC';
?>
<div class="admin-general-home fade-in">
    <section class="admin-general-home-intro mb-4">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div class="d-flex align-items-start gap-3">
                <img
                    src="<?= htmlspecialchars(AppHelpers::getAppLogo()) ?>"
                    alt="<?= htmlspecialchars($productName) ?>"
                    height="48"
                    class="flex-shrink-0"
                    style="object-fit: contain;"
                >
                <div>
                    <h1 class="admin-general-home-intro__title mb-0"><?= htmlspecialchars($productName) ?></h1>
                    <p class="admin-general-home-intro__scope mb-1">Gestor de torneos para asociaciones</p>
                    <p class="admin-general-home-intro__text">
                        Plataforma sectorial para administrar asociaciones afiliadas, clubes, afiliados y torneos
                        con alcance territorial y control centralizado.
                    </p>
                </div>
            </div>
            <div class="admin-general-home-toolbar d-flex align-items-center gap-2 flex-wrap">
                <a href="<?= htmlspecialchars(AppHelpers::landingUrl()) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-globe me-1"></i>Landing
                </a>
                <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas')) ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-bell me-1"></i>Notificaciones
                </a>
                <div class="text-end small text-muted">
                    <div><?= date('d/m/Y') ?></div>
                    <span class="badge bg-primary">Admin General</span>
                </div>
            </div>
        </div>
        <p class="text-muted small mt-3 mb-0">
            Bienvenido, <strong><?= htmlspecialchars($current_user['username'] ?? '') ?></strong>
        </p>
    </section>

    <?php if (! empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (! empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="admin-general-stats-block mb-4">
        <h2 class="admin-general-stats-block__title">
            <i class="fas fa-chart-pie me-2"></i>Resumen general
        </h2>
        <div class="row g-3">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card primary">
                    <h3 class="mb-1"><?= number_format($stats['total_users'] ?? 0) ?></h3>
                    <p class="mb-0"><i class="fas fa-users me-1"></i>Total usuarios</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card info">
                    <h3 class="mb-1"><?= number_format($stats['total_entidades'] ?? 0) ?></h3>
                    <p class="mb-0"><i class="fas fa-map-marked-alt me-1"></i>Asociaciones</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="stat-card info">
                    <h3 class="mb-1"><?= number_format($stats['total_admin_clubs'] ?? 0) ?></h3>
                    <p class="mb-0"><i class="fas fa-user-shield me-1"></i>Admin organización</p>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-general-stats-block mb-4">
        <h2 class="admin-general-stats-block__title">
            <i class="fas fa-running me-2"></i>Atletas — estado
        </h2>
        <div class="row g-3">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card success">
                    <h3 class="mb-1"><?= number_format((int) ($stats['atletas_activos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-user-check me-1"></i>Activos</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card secondary">
                    <h3 class="mb-1"><?= number_format((int) ($stats['atletas_inactivos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-user-slash me-1"></i>Inactivos</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="stat-card danger">
                    <h3 class="mb-1"><?= number_format((int) ($stats['hombres_activos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres (activos)</p>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-general-stats-block mb-3">
        <h2 class="admin-general-stats-block__title">
            <i class="fas fa-venus-mars me-2"></i>Atletas — por género
        </h2>
        <div class="row g-3">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card purple">
                    <h3 class="mb-1"><?= number_format((int) ($stats['mujeres_activos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres (activas)</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card dark">
                    <h3 class="mb-1"><?= number_format((int) ($stats['hombres_inactivos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres (inactivos)</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="stat-card warning">
                    <h3 class="mb-1"><?= number_format((int) ($stats['mujeres_inactivos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres (inactivas)</p>
                </div>
            </div>
        </div>
    </section>
</div>
