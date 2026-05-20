<?php
/**
 * Listado de entidades con resumen: organizaciones, clubes, afiliados, torneos por entidad.
 * Opción "Ver detalle" lleva a organizaciones&entidad_id=X para desglosar organizaciones de esa entidad.
 */
$page_title = 'Organizaciones por entidad';
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3"><i class="fas fa-map-marked-alt text-primary me-2"></i>Asociaciones territoriales</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
                    <li class="breadcrumb-item active">Organizaciones por entidad</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex flex-wrap gap-2">
            <a href="index.php?page=torneos_estructura&context=asociaciones" class="btn btn-outline-warning btn-sm">
                <i class="fas fa-trophy me-1"></i>Torneos
            </a>
            <a href="index.php?page=torneos_estructura&context=asociaciones&vista=reporte" class="btn btn-outline-info btn-sm">
                <i class="fas fa-chart-line me-1"></i>Reporte
            </a>
            <a href="index.php?page=organizaciones_particulares" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-user-tie me-1"></i>Org. particulares
            </a>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas')) ?>" class="btn btn-outline-primary">
                <i class="fas fa-bell me-1"></i>Enviar notificaciones
            </a>
        </div>
    </div>

    <?php if (empty($resumen_entidades)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-building fa-3x text-muted mb-3"></i>
                <p class="text-muted mb-0">No hay datos por entidad</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Resumen por entidad</h5>
                <p class="text-muted small mb-0 mt-1">Solo asociaciones (tipo_org = 0). Los afiliados particulares están en su listado aparte.</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><i class="fas fa-map-marker-alt me-1"></i>Entidad</th>
                                <th class="text-center">Organizaciones</th>
                                <th class="text-center">Clubes</th>
                                <th class="text-center">Afiliados</th>
                                <th class="text-center">Torneos</th>
                                <th class="text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resumen_entidades as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['entidad_nombre']) ?></strong></td>
                                    <td class="text-center"><span class="badge bg-primary"><?= (int)$row['total_organizaciones'] ?></span></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?= (int)$row['total_clubes'] ?></span></td>
                                    <td class="text-center"><span class="badge bg-info"><?= (int)$row['total_afiliados'] ?></span></td>
                                    <td class="text-center"><span class="badge bg-success"><?= (int)$row['total_torneos'] ?></span></td>
                                    <td class="text-end">
                                        <a href="index.php?page=organizaciones&entidad_id=<?= (int)$row['entidad_id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-arrow-right me-1"></i>Ver detalle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
