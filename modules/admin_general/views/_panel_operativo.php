<?php
/**
 * Panel operativo admin general — Paso 1: accesos en columnas (estilo compacto tipo panel de torneo).
 * Enlaces alineados con el menú lateral existente; badges con datos reales cuando hay tabla.
 */
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../../lib/app_helpers.php';
}
$pb = $panel_badges ?? [
    'solicitudes_afiliacion_total' => 0,
    'solicitudes_afiliacion_pendiente' => 0,
    'comentarios_pendientes' => 0,
];
$orgPk = (int) ($fvd_org_id ?? 1);
$actasP = (int) ($actas_pendientes ?? 0);

$u = static function (string $page, array $q = []): string {
    return htmlspecialchars(AppHelpers::dashboard($page, $q));
};

$b = static function (int $n, string $tone = 'danger'): string {
    if ($n <= 0) {
        return '<span class="badge bg-secondary ms-auto">' . $n . '</span>';
    }
    $cls = $tone === 'danger' ? 'bg-danger' : ($tone === 'warning' ? 'bg-warning text-dark' : 'bg-primary');
    return '<span class="badge ' . $cls . ' ms-auto">' . number_format($n) . '</span>';
};
?>
<div class="admin-general-panel-operativo mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1 fw-bold text-body">
                <i class="fas fa-th-large me-2 text-primary"></i>Panel operativo
            </h2>
            <p class="text-muted small mb-0">Accesos rápidos por área (mismo alcance que el menú lateral). Paso 1 del diseño.</p>
        </div>
    </div>

    <div class="row g-3">
        <!-- SERVICIOS -->
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h3 class="h6 text-uppercase text-primary mb-0 fw-bold"><i class="fas fa-concierge-bell me-2"></i>Servicios</h3>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="<?= $u('organizaciones', ['id' => $orgPk]) ?>" class="btn btn-outline-primary text-start d-flex align-items-center">
                        <i class="fas fa-building me-2"></i> Mi organización
                    </a>
                    <a href="<?= $u('clubs') ?>" class="btn btn-outline-primary text-start d-flex align-items-center">
                        <i class="fas fa-sitemap me-2"></i> Asociaciones
                    </a>
                    <a href="<?= $u('users') ?>" class="btn btn-outline-primary text-start d-flex align-items-center">
                        <i class="fas fa-running me-2"></i> Atletas y usuarios
                    </a>
                </div>
            </div>
        </div>

        <!-- SUPERVISIÓN -->
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm h-100 border-0 border-start border-danger border-3">
                <div class="card-header bg-danger bg-opacity-10 border-bottom py-3">
                    <h3 class="h6 text-uppercase text-danger mb-0 fw-bold"><i class="fas fa-eye me-2"></i>Supervisión</h3>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="<?= $u('affiliate_requests', ['filter' => 'todas']) ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-list me-2"></i> Todas <?= $b((int) $pb['solicitudes_afiliacion_total'], 'secondary') ?>
                    </a>
                    <a href="<?= $u('affiliate_requests', ['filter' => 'pendiente']) ?>" class="btn btn-outline-warning text-start d-flex align-items-center">
                        <i class="fas fa-user-plus me-2"></i> Afiliaciones <?= $b((int) $pb['solicitudes_afiliacion_pendiente'], 'warning') ?>
                    </a>
                    <a href="<?= $u('torneo_gestion', ['action' => 'verificar_actas_index']) ?>" class="btn btn-outline-danger text-start d-flex align-items-center" title="Actas / QR pendientes de verificación">
                        <i class="fas fa-id-card me-2"></i> Carnets / actas QR <?= $b($actasP, 'danger') ?>
                    </a>
                    <a href="<?= $u('admin_atletas_sync') ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-exchange-alt me-2"></i> Traspasos / sync atletas
                    </a>
                    <a href="<?= $u('comments', ['estatus' => 'pendiente']) ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-globe me-2"></i> Solicitudes portal <?= $b((int) $pb['comentarios_pendientes'], 'secondary') ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- OPERACIONES -->
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-primary bg-opacity-10 border-bottom py-3">
                    <h3 class="h6 text-uppercase text-primary mb-0 fw-bold"><i class="fas fa-cogs me-2"></i>Operaciones</h3>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="<?= $u('torneo_gestion', ['action' => 'index']) ?>" class="btn btn-outline-primary text-start d-flex align-items-center">
                        <i class="fas fa-trophy me-2"></i> Torneos
                    </a>
                    <a href="<?= $u('auditoria') ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-chart-bar me-2"></i> Informes y auditoría
                    </a>
                    <a href="<?= $u('control_admin') ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-tachometer-alt me-2"></i> Control administrativo
                    </a>
                    <a href="<?= $u('notificaciones_masivas') ?>" class="btn btn-outline-primary text-start d-flex align-items-center">
                        <i class="fas fa-bell me-2"></i> Notificaciones masivas
                    </a>
                </div>
            </div>
        </div>

        <!-- FINANZAS -->
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h3 class="h6 text-uppercase text-body mb-0 fw-bold"><i class="fas fa-coins me-2 text-warning"></i>Finanzas</h3>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="<?= $u('finances') ?>" class="btn btn-outline-warning text-start d-flex align-items-center">
                        <i class="fas fa-balance-scale me-2"></i> Estado de cuentas
                    </a>
                    <a href="<?= $u('importacion_torneo_externo') ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-file-import me-2"></i> Torneos / importación externa
                    </a>
                    <a href="<?= $u('calendario') ?>" class="btn btn-outline-secondary text-start d-flex align-items-center">
                        <i class="fas fa-calendar-alt me-2"></i> Calendario
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
