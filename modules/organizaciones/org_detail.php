<?php
$logo_url = !empty($organizacion['logo'])
    ? AppHelpers::imageUrl($organizacion['logo'])
    : AppHelpers::getAppLogo();
$clubes_paginados = $clubes_paginados ?? $clubes ?? [];
$clubes_page = isset($clubes_page) ? (int)$clubes_page : 1;
$clubes_total_pages = isset($clubes_total_pages) ? (int)$clubes_total_pages : 1;
$clubes_total_rows = isset($clubes_total_rows) ? (int)$clubes_total_rows : count($clubes ?? []);
$clubes_per_page = isset($clubes_per_page) ? (int)$clubes_per_page : 15;
$qsBase = 'index.php?page=organizaciones&id=' . (int)$organizacion['id'];
$stats_clubes = count($clubes);
$stats_afiliados = 0;
foreach ($clubes as $c) {
    $stats_afiliados += (int)($c['total_afiliados'] ?? 0);
}
$stats_afiliados_sin_club = isset($stats_afiliados_sin_club) ? (int)$stats_afiliados_sin_club : 0;
$stats_afiliados_total = isset($stats_afiliados_total) ? (int)$stats_afiliados_total : ($stats_afiliados + $stats_afiliados_sin_club);
$stats_hombres_total = isset($stats_hombres_total) ? (int)$stats_hombres_total : 0;
$stats_mujeres_total = isset($stats_mujeres_total) ? (int)$stats_mujeres_total : 0;
$stats_otros_total = isset($stats_otros_total) ? (int)$stats_otros_total : 0;
$stats_torneos = isset($org_dashboard_snap['stats']['torneos'])
    ? (int) $org_dashboard_snap['stats']['torneos']
    : 0;
$stats_operadores = isset($stats_operadores) ? (int)$stats_operadores : 0;
$stats_admin_torneo = isset($stats_admin_torneo) ? (int)$stats_admin_torneo : 0;
?>
<div class="container-fluid py-4" id="top-page">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
            <li class="breadcrumb-item"><a href="index.php?page=organizaciones">Organizaciones</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($organizacion['nombre']) ?></li>
        </ol>
    </nav>

    <?php $error_org = isset($_GET['error']) ? trim((string) $_GET['error']) : ''; ?>
    <?php if ($error_org !== ''): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_org) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <?php
    $org_estatus = (int)($organizacion['estatus'] ?? 1);
    $org_desactivada = $org_estatus === 0;
    if ($org_desactivada && !empty($is_admin_general)): ?>
        <div class="alert alert-warning alert-dismissible fade show mb-3">
            <i class="fas fa-ban me-2"></i>Esta organización está <strong>desactivada</strong>.
            <a href="index.php?page=mi_organizacion&action=reactivar&id=<?= (int)$organizacion['id'] ?>&return_to=organizaciones&entidad_id=<?= (int)($organizacion['entidad'] ?? 0) ?>" class="btn btn-sm btn-success ms-3" onclick="return confirm('¿Reactivar esta organización?');">
                <i class="fas fa-check-circle me-1"></i>Reactivar
            </a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <!-- Sección superior: Identificación en dos columnas -->
    <div class="row mb-4">
        <!-- Columna 1: Información de la organización -->
        <div class="col-md-6 mb-3 mb-md-0">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-building me-2"></i>Información de la Organización</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($organizacion['nombre']) ?>" class="rounded me-3 flex-shrink-0" style="width: 80px; height: 80px; object-fit: cover;">
                        <div class="flex-grow-1">
                            <h4 class="mb-1"><?= htmlspecialchars($organizacion['nombre']) ?></h4>
                            <?php
                            $org_cod_display = (int) ($organizacion['cod_org'] ?? 0);
                            ?>
                            <p class="small text-muted mb-2">
                                <span title="Clave numérica de la fila en la tabla organizaciones (por eso la URL lleva id=<?= (int) ($organizacion['id'] ?? 0) ?>)">ID organización (interno): <?= (int) ($organizacion['id'] ?? 0) ?></span>
                                <?php if ($org_cod_display > 0): ?>
                                    <span class="mx-1">·</span>
                                    <span title="Código de federación homologado (clubes/torneos); no es el mismo número que el ID de la fila">Código federación: <strong><?= $org_cod_display ?></strong></span>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($organizacion['entidad_nombre'])): ?>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($organizacion['entidad_nombre']) ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($organizacion['responsable'])): ?>
                                <p class="mb-1 small"><i class="fas fa-user me-1"></i><strong>Responsable:</strong> <?= htmlspecialchars($organizacion['responsable']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($organizacion['telefono'])): ?>
                                <p class="mb-1 small"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($organizacion['telefono']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($organizacion['email'])): ?>
                                <p class="mb-1 small"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($organizacion['email']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($organizacion['direccion'])): ?>
                                <p class="mb-0 small"><i class="fas fa-address-card me-1"></i><?= htmlspecialchars($organizacion['direccion']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Columna 2: Estadísticas -->
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Estadísticas</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-light rounded">
                                <i class="fas fa-sitemap fa-2x text-primary me-2"></i>
                                <div>
                                    <strong><?= $stats_clubes ?></strong>
                                    <span class="d-block small text-muted">Clubes</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-light rounded">
                                <i class="fas fa-trophy fa-2x text-success me-2"></i>
                                <div>
                                    <strong><?= $stats_torneos ?></strong>
                                    <span class="d-block small text-muted">Torneos</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-light rounded">
                                <i class="fas fa-users fa-2x text-info me-2"></i>
                                <div>
                                    <strong><?= $stats_afiliados_total ?></strong>
                                    <span class="d-block small text-muted">Afiliados</span>
                                    <small class="text-muted">M: <?= $stats_hombres_total ?> | F: <?= $stats_mujeres_total ?> | O: <?= $stats_otros_total ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-light rounded">
                                <i class="fas fa-user-cog fa-2x text-warning me-2"></i>
                                <div>
                                    <strong><?= $stats_admin_torneo ?></strong>
                                    <span class="d-block small text-muted">Admin. torneo</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-center p-2 bg-light rounded">
                                <i class="fas fa-user-tie fa-2x text-secondary me-2"></i>
                                <div>
                                    <strong><?= $stats_operadores ?></strong>
                                    <span class="d-block small text-muted">Operadores</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm" id="lista-clubes-org">
        <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>Clubes de la organización</h5>
            <?php if (empty($is_admin_general)): ?>
                <a href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::dashboard('clubes_asociados') : 'index.php?page=clubes_asociados') ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-cog me-1"></i>Gestionar altas de clubes
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if ($stats_afiliados_sin_club > 0): ?>
                <div class="alert alert-warning rounded-0 mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Afiliados sumados a la organización sin club asignado: <strong><?= (int)$stats_afiliados_sin_club ?></strong>
                </div>
            <?php endif; ?>
            <?php if (empty($clubes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-sitemap fa-2x mb-2"></i>
                    <p class="mb-0">No hay clubes registrados en esta organización</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <caption class="text-muted small caption-top px-2">
                            En los enlaces, <code>id</code> es el ID interno de esta organización y <code>club_id</code> el ID interno del club en la tabla <code>clubes</code>
                            (pueden coincidir numéricamente por casualidad). El <strong>código de federación</strong> (p. ej. 13) es otro dato; ver arriba «Código federación».
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th>Delegado</th>
                                <th class="text-center">Afiliados</th>
                                <th class="text-center"><i class="fas fa-mars text-primary" title="Hombres"></i></th>
                                <th class="text-center"><i class="fas fa-venus text-danger" title="Mujeres"></i></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clubes_paginados as $c): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($c['delegado'] ?? '-') ?></td>
                                    <td class="text-center"><span class="badge bg-info"><?= (int)($c['total_afiliados'] ?? 0) ?></span></td>
                                    <td class="text-center"><span class="badge bg-primary"><?= (int)($c['hombres'] ?? 0) ?></span></td>
                                    <td class="text-center"><span class="badge bg-danger"><?= (int)($c['mujeres'] ?? 0) ?></span></td>
                                    <td>
                                        <?php if ((int)($c['id'] ?? 0) > 0): ?>
                                            <a href="index.php?page=organizaciones&id=<?= (int)$organizacion['id'] ?>&club_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Club en base de datos: id interno <?= (int)$c['id'] ?> (distinto del código de federación de la org)">
                                                <i class="fas fa-eye me-1"></i>Ver detalle y afiliados
                                            </a>
                                            <a href="<?= htmlspecialchars(AppHelpers::dashboard('clubes_asociados', ['club_id' => $c['id']])) ?>" class="btn btn-sm btn-outline-secondary ms-1" title="Editar solo este club (mismo id que ves en la fila; datos de tu federación)">
                                                <i class="fas fa-edit me-1"></i>Editar Club
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Asociación sin ficha de club</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($clubes_total_pages > 1): ?>
                    <div class="border-top p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <small class="text-muted">
                            Mostrando <?= (int)(($clubes_page - 1) * $clubes_per_page + 1) ?>-<?= (int)min($clubes_page * $clubes_per_page, $clubes_total_rows) ?>
                            de <?= (int)$clubes_total_rows ?> clubes
                        </small>
                        <nav aria-label="Paginación de clubes">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $clubes_page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&clubes_page=1') ?>">«</a>
                                </li>
                                <li class="page-item <?= $clubes_page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&clubes_page=' . max(1, $clubes_page - 1)) ?>">‹</a>
                                </li>
                                <li class="page-item disabled"><span class="page-link"><?= (int)$clubes_page ?> / <?= (int)$clubes_total_pages ?></span></li>
                                <li class="page-item <?= $clubes_page >= $clubes_total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&clubes_page=' . min($clubes_total_pages, $clubes_page + 1)) ?>">›</a>
                                </li>
                                <li class="page-item <?= $clubes_page >= $clubes_total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&clubes_page=' . $clubes_total_pages) ?>">»</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3" id="bottom-page">
        <a href="index.php?page=organizaciones" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver al listado</a>
        <?php if ($is_admin_general): ?>
            <a href="index.php?page=mi_organizacion&id=<?= (int)$organizacion['id'] ?>" class="btn btn-outline-primary ms-2"><i class="fas fa-edit me-1"></i>Editar organización</a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-dark ms-2" onclick="window.scrollTo({top:0,behavior:'smooth'})">
            <i class="fas fa-arrow-up me-1"></i>Ir al inicio
        </button>
        <button type="button" class="btn btn-outline-dark ms-2" onclick="window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'})">
            <i class="fas fa-arrow-down me-1"></i>Ir al final
        </button>
        <button type="button" class="btn btn-outline-secondary ms-2" onclick="history.back()">
            <i class="fas fa-reply me-1"></i>Regresar
        </button>
    </div>
</div>
