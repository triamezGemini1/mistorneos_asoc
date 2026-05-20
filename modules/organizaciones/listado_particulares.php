<?php
/**
 * Listado admin: organizaciones particulares (afiliados independientes).
 */
$page_title = 'Organizaciones particulares';
$totales = [
    'activas' => 0,
    'clubes' => 0,
    'afiliados' => 0,
    'torneos' => 0,
];
foreach ($particulares as $p) {
    if ((int) ($p['estatus'] ?? 0) === 1) {
        $totales['activas']++;
    }
    $totales['clubes'] += (int) ($p['total_clubes'] ?? 0);
    $totales['afiliados'] += (int) ($p['total_afiliados'] ?? 0);
    $totales['torneos'] += (int) ($p['total_torneos'] ?? 0);
}
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">
                <i class="fas fa-user-tie text-secondary me-2"></i>Organizaciones particulares
            </h1>
            <p class="text-muted mb-2">
                Afiliados independientes. La <strong>entidad</strong> indica el territorio de referencia (asociación geográfica),
                pero <strong>no</strong> son clubes ni miembros de esa asociación.
            </p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
                    <li class="breadcrumb-item"><a href="index.php?page=organizaciones">Asociaciones</a></li>
                    <li class="breadcrumb-item active">Particulares</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex flex-wrap gap-2 align-items-start">
            <a href="index.php?page=torneos_estructura&context=particulares" class="btn btn-outline-warning btn-sm">
                <i class="fas fa-trophy me-1"></i>Torneos
            </a>
            <a href="index.php?page=torneos_estructura&context=particulares&vista=reporte" class="btn btn-outline-info btn-sm">
                <i class="fas fa-chart-line me-1"></i>Reporte
            </a>
            <a href="index.php?page=organizaciones" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-building me-1"></i>Asociaciones territoriales
            </a>
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('affiliate_requests')) ?>" class="btn btn-outline-secondary">
                <i class="fas fa-inbox me-1"></i>Solicitudes de afiliación
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars((string) $_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <?php if (!$has_tipo_org): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            La tabla <code>organizaciones</code> no tiene la columna <code>tipo_org</code>.
            Ejecute las migraciones del esquema para distinguir asociaciones (0) de particulares (1).
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Activas</div>
                        <div class="h4 mb-0"><?= (int) $totales['activas'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Clubes propios</div>
                        <div class="h4 mb-0"><?= (int) $totales['clubes'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Afiliados</div>
                        <div class="h4 mb-0"><?= (int) $totales['afiliados'] ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Torneos</div>
                        <div class="h4 mb-0"><?= (int) $totales['torneos'] ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body py-3">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="organizaciones_particulares">
                    <div class="col-md-4">
                        <label for="filtro_entidad" class="form-label small mb-1">Entidad de referencia</label>
                        <select name="entidad_id" id="filtro_entidad" class="form-select form-select-sm">
                            <option value="">Todas las entidades</option>
                            <?php foreach ($entidades_options as $ent): ?>
                                <option value="<?= (int) ($ent['id'] ?? 0) ?>" <?= $filtro_entidad === (int) ($ent['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($ent['nombre'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtrar</button>
                        <?php if ($filtro_entidad > 0): ?>
                            <a href="index.php?page=organizaciones_particulares" class="btn btn-sm btn-outline-secondary ms-1">Limpiar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($particulares)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-user-tie fa-3x text-muted mb-3"></i>
                    <p class="text-muted mb-0">No hay organizaciones particulares<?= $filtro_entidad > 0 ? ' en esta entidad' : '' ?>.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Listado</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Organización</th>
                                    <th>Entidad ref.</th>
                                    <th>Responsable</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center">Clubes</th>
                                    <th class="text-center">Afiliados</th>
                                    <th class="text-center">Torneos</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($particulares as $org):
                                    $activa = (int) ($org['estatus'] ?? 1) === 1;
                                    $orgId = (int) ($org['id'] ?? 0);
                                    $codRef = (int) ($org['cod_org'] ?? 0);
                                    if ($codRef <= 0) {
                                        $codRef = $orgId;
                                    }
                                ?>
                                    <tr class="<?= $activa ? '' : 'table-secondary' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars((string) ($org['nombre'] ?? '')) ?></strong>
                                            <div class="small text-muted">ID <?= $orgId ?><?php if ($has_cod_org): ?> · cod_org <?= $codRef ?><?php endif; ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($org['entidad_nombre'])): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars((string) $org['entidad_nombre']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($org['admin_nombre'])): ?>
                                                <?= htmlspecialchars((string) $org['admin_nombre']) ?>
                                                <?php if (!empty($org['admin_username'])): ?>
                                                    <div class="small text-muted">@<?= htmlspecialchars((string) $org['admin_username']) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Sin asignar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $activa ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>' ?>
                                        </td>
                                        <td class="text-center"><span class="badge bg-secondary"><?= (int) ($org['total_clubes'] ?? 0) ?></span></td>
                                        <td class="text-center">
                                            <span class="badge bg-info"><?= (int) ($org['total_afiliados'] ?? 0) ?></span>
                                            <div class="small text-muted"><?= (int) ($org['hombres'] ?? 0) ?>M / <?= (int) ($org['mujeres'] ?? 0) ?>F</div>
                                        </td>
                                        <td class="text-center"><span class="badge bg-success"><?= (int) ($org['total_torneos'] ?? 0) ?></span></td>
                                        <td class="text-end">
                                            <a href="index.php?page=organizaciones&id=<?= $orgId ?>&from=particulares" class="btn btn-sm btn-outline-primary" title="Ver detalle">
                                                <i class="fas fa-eye me-1"></i>Ver
                                            </a>
                                            <a href="index.php?page=mi_organizacion&id=<?= $orgId ?>&return_to=particulares" class="btn btn-sm btn-outline-secondary ms-1" title="Editar">
                                                <i class="fas fa-edit"></i>
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
    <?php endif; ?>
</div>
