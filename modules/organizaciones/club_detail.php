<?php
$logo_club = $club['logo']
    ? AppHelpers::url('view_image.php', ['path' => $club['logo']])
    : null;
$afiliados_page = isset($afiliados_page) ? (int)$afiliados_page : 1;
$afiliados_per_page = isset($afiliados_per_page) ? (int)$afiliados_per_page : 15;
$afiliados_total_rows = isset($afiliados_total_rows) ? (int)$afiliados_total_rows : count($afiliados ?? []);
$afiliados_total_pages = isset($afiliados_total_pages) ? (int)$afiliados_total_pages : 1;
$sexo = isset($sexo) ? (string)$sexo : 'todos';
$afiliados_resumen = $afiliados_resumen ?? ['total' => 0, 'hombres' => 0, 'mujeres' => 0];
$qsBase = 'index.php?page=organizaciones&id=' . (int)$organizacion['id'] . '&club_id=' . (int)$club['id'] . '&sexo=' . urlencode($sexo);
?>
<div class="container-fluid py-4" id="top-page">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php?page=home">Inicio</a></li>
            <li class="breadcrumb-item"><a href="index.php?page=organizaciones">Organizaciones</a></li>
            <li class="breadcrumb-item"><a href="index.php?page=organizaciones&id=<?= (int)$organizacion['id'] ?>"><?= htmlspecialchars($organizacion['nombre']) ?></a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($club['nombre']) ?></li>
        </ol>
    </nav>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <?php if ($logo_club): ?>
                    <div class="col-auto">
                        <img src="<?= htmlspecialchars($logo_club) ?>" alt="" class="rounded" style="width: 80px; height: 80px; object-fit: cover;">
                    </div>
                <?php endif; ?>
                <div class="col">
                    <h2 class="h4 mb-2"><?= htmlspecialchars($club['nombre']) ?></h2>
                    <p class="text-muted mb-1">Club de <?= htmlspecialchars($organizacion['nombre']) ?></p>
                    <?php if (!empty($club['delegado'])): ?>
                        <p class="mb-1"><i class="fas fa-user me-1"></i>Delegado: <?= htmlspecialchars($club['delegado']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($club['telefono'])): ?>
                        <p class="mb-1"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($club['telefono']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($club['direccion'])): ?>
                        <p class="mb-0 small"><i class="fas fa-address-card me-1"></i><?= htmlspecialchars($club['direccion']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Afiliados (<?= (int)$afiliados_total_rows ?>)</h5>
            <div class="btn-group btn-group-sm" role="group" aria-label="Filtro por género">
                <a href="index.php?page=organizaciones&id=<?= (int)$organizacion['id'] ?>&club_id=<?= (int)$club['id'] ?>&sexo=todos" class="btn <?= $sexo === 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>">Todo</a>
                <a href="index.php?page=organizaciones&id=<?= (int)$organizacion['id'] ?>&club_id=<?= (int)$club['id'] ?>&sexo=m" class="btn <?= $sexo === 'm' ? 'btn-primary' : 'btn-outline-primary' ?>">Hombres</a>
                <a href="index.php?page=organizaciones&id=<?= (int)$organizacion['id'] ?>&club_id=<?= (int)$club['id'] ?>&sexo=f" class="btn <?= $sexo === 'f' ? 'btn-primary' : 'btn-outline-primary' ?>">Mujeres</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="p-3 border-bottom bg-light">
                <span class="badge bg-secondary me-2">Total: <?= (int)($afiliados_resumen['total'] ?? 0) ?></span>
                <span class="badge bg-primary me-2">Hombres: <?= (int)($afiliados_resumen['hombres'] ?? 0) ?></span>
                <span class="badge bg-danger">Mujeres: <?= (int)($afiliados_resumen['mujeres'] ?? 0) ?></span>
            </div>
            <?php if (empty($afiliados)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-users fa-2x mb-2"></i>
                    <p class="mb-0">
                        <?php if ($sexo !== 'todos'): ?>
                            No se encontraron afiliados con el filtro seleccionado. Pruebe con «Todo» o revise los criterios.
                        <?php else: ?>
                            No se encontraron afiliados para este club.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th>Cédula</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($afiliados as $a): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($a['nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['cedula'] ?? '-') ?></td>
                                    <td>
                                        <?php if (!empty($a['email'])): ?><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($a['email']) ?><br><?php endif; ?>
                                        <?php if (!empty($a['celular'])): ?><i class="fas fa-phone me-1"></i><?= htmlspecialchars($a['celular']) ?><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= (int)($a['status'] ?? 1) === 0 ? 'success' : 'secondary' ?>">
                                            <?= (int)($a['status'] ?? 1) === 0 ? 'Activo' : 'Inactivo' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($afiliados_total_pages > 1): ?>
                    <div class="border-top p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <small class="text-muted">
                            Mostrando <?= (int)(($afiliados_page - 1) * $afiliados_per_page + 1) ?>-<?= (int)min($afiliados_page * $afiliados_per_page, $afiliados_total_rows) ?>
                            de <?= (int)$afiliados_total_rows ?> afiliados
                        </small>
                        <nav aria-label="Paginación de afiliados">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $afiliados_page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&afiliados_page=1') ?>">«</a>
                                </li>
                                <li class="page-item <?= $afiliados_page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&afiliados_page=' . max(1, $afiliados_page - 1)) ?>">‹</a>
                                </li>
                                <li class="page-item disabled"><span class="page-link"><?= (int)$afiliados_page ?> / <?= (int)$afiliados_total_pages ?></span></li>
                                <li class="page-item <?= $afiliados_page >= $afiliados_total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&afiliados_page=' . min($afiliados_total_pages, $afiliados_page + 1)) ?>">›</a>
                                </li>
                                <li class="page-item <?= $afiliados_page >= $afiliados_total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($qsBase . '&afiliados_page=' . $afiliados_total_pages) ?>">»</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3" id="bottom-page">
        <a href="index.php?page=organizaciones&id=<?= (int)$organizacion['id'] ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Volver a la organización</a>
        <button type="button" class="btn btn-outline-dark ms-2" onclick="window.scrollTo({top:0,behavior:'smooth'})">
            <i class="fas fa-arrow-up me-1"></i>Primera página
        </button>
        <button type="button" class="btn btn-outline-dark ms-2" onclick="window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'})">
            <i class="fas fa-arrow-down me-1"></i>Última página
        </button>
        <button type="button" class="btn btn-outline-secondary ms-2" onclick="history.back()">
            <i class="fas fa-reply me-1"></i>Regresar
        </button>
    </div>
</div>
