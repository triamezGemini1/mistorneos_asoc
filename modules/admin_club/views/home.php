<?php
declare(strict_types=1);

/**
 * Vista: Dashboard Inicio para Admin de Asociación.
 */
require_once __DIR__ . '/../../../lib/app_helpers.php';

$stats = $stats ?? [];
$genero = $genero ?? [];
$productName = class_exists('Branding', false) ? Branding::siteName() : 'La Estación del Dominó';
$scopeLabel = ($nombre_org ?? '') !== '' ? (string) $nombre_org : 'Su asociación';
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
                    <h1 class="admin-general-home-intro__title mb-0"><?= htmlspecialchars($scopeLabel) ?></h1>
                    <p class="admin-general-home-intro__scope mb-1">Panel de administración</p>
                    <p class="admin-general-home-intro__text">
                        Gestione clubes afiliados, torneos y afiliados de su asociación desde un único panel.
                    </p>
                </div>
            </div>
            <div class="admin-general-home-toolbar d-flex align-items-center gap-2 flex-wrap">
                <a href="<?= htmlspecialchars(AppHelpers::landingUrl()) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-globe me-1"></i>Portal público
                </a>
                <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas')) ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-bell me-1"></i>Notificaciones
                </a>
                <div class="text-end small text-muted">
                    <div><?= date('d/m/Y') ?></div>
                    <span class="badge bg-primary">Admin Asociación</span>
                </div>
            </div>
        </div>
        <p class="text-muted small mt-3 mb-0">
            Bienvenido, <strong><?= htmlspecialchars($current_user['username'] ?? '') ?></strong>
        </p>
    </section>

    <?php if (! empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars((string) $success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (! empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars((string) $error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="admin-general-stats-block mb-4">
        <h2 class="admin-general-stats-block__title">
            <i class="fas fa-chart-pie me-2"></i>Resumen de la asociación
        </h2>
        <div class="row g-3">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card primary">
                    <h3 class="mb-1"><?= number_format((int) ($stats['clubes'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-users me-1"></i>Clubes</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card info">
                    <h3 class="mb-1"><?= number_format((int) ($stats['torneos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-trophy me-1"></i>Torneos</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="stat-card success">
                    <h3 class="mb-1"><?= number_format((int) ($stats['afiliados'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-id-card me-1"></i>Afiliados</p>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-general-stats-block mb-4">
        <h2 class="admin-general-stats-block__title">
            <i class="fas fa-running me-2"></i>Actividad
        </h2>
        <div class="row g-3">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card warning">
                    <h3 class="mb-1"><?= number_format((int) ($stats['torneos_activos'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-play-circle me-1"></i>Torneos activos</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card secondary">
                    <h3 class="mb-1"><?= number_format((int) ($stats['inscripciones'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-clipboard-list me-1"></i>Inscripciones</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="stat-card dark">
                    <h3 class="mb-1"><?= number_format((int) ($stats['usuarios'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-user-friends me-1"></i>Usuarios vinculados</p>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-general-stats-block mb-3">
        <h2 class="admin-general-stats-block__title">
            <i class="fas fa-venus-mars me-2"></i>Afiliados por género
        </h2>
        <div class="row g-3">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card danger">
                    <h3 class="mb-1"><?= number_format((int) ($genero['hombres'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="stat-card purple">
                    <h3 class="mb-1"><?= number_format((int) ($genero['mujeres'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div class="stat-card secondary">
                    <h3 class="mb-1"><?= number_format((int) ($genero['sin_genero'] ?? 0)) ?></h3>
                    <p class="mb-0"><i class="fas fa-question-circle me-1"></i>Sin género registrado</p>
                </div>
            </div>
        </div>
    </section>
</div>
