<?php

/**

 * Vista: listado de asociaciones (entidades) con atletas por estado y género.

 */

$resumen_entidades = $resumen_entidades ?? [];

$entidades_crud = $entidades_crud ?? [];

$csrf_token = class_exists('CSRF') ? CSRF::token() : '';

?>

<div class="container-fluid py-4">

    <div class="row mb-4">

        <div class="col">

            <h1 class="h3"><i class="fas fa-map-marked-alt text-primary me-2"></i>Asociaciones</h1>

            <nav aria-label="breadcrumb">

                <ol class="breadcrumb">

                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(AppHelpers::dashboard()) ?>">Inicio</a></li>

                    <li class="breadcrumb-item active">Asociaciones</li>

                </ol>

            </nav>

        </div>

    </div>



    <?php if (!empty($_GET['success'])): ?>

        <div class="alert alert-success py-2"><?= htmlspecialchars((string)$_GET['success']) ?></div>

    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>

        <div class="alert alert-danger py-2"><?= htmlspecialchars((string)$_GET['error']) ?></div>

    <?php endif; ?>



    <?php if (empty($resumen_entidades)): ?>

        <div class="card shadow-sm">

            <div class="card-body text-center py-5">

                <i class="fas fa-map-marked-alt fa-3x text-muted mb-3"></i>

                <p class="text-muted mb-0">No hay asociaciones registradas</p>

            </div>

        </div>

    <?php else: ?>

        <div class="card shadow-sm">

            <div class="card-header bg-light">

                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Resumen por asociación</h5>

                <p class="text-muted small mb-0 mt-1">Atletas (rol usuario) activos e inactivos, discriminados por género</p>

            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-hover mb-0 align-middle">

                        <thead class="table-light">

                            <tr>

                                <th><i class="fas fa-hashtag me-1"></i>Código</th>

                                <th><i class="fas fa-map-marker-alt me-1"></i>Asociación</th>

                                <th class="text-center" style="width:120px">Estado</th>

                                <th class="text-center">Activos</th>

                                <th class="text-center">Inactivos</th>

                                <th class="text-center">H. activos</th>

                                <th class="text-center">M. activas</th>

                                <th class="text-center">H. inactivos</th>

                                <th class="text-center">M. inactivas</th>

                                <th class="text-end">Acción</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($resumen_entidades as $row): ?>

                                <?php

                                $codigo = (int)($row['entidad_codigo'] ?? $row['entidad_id']);

                                $estado = (int)($row['estado'] ?? 1);

                                ?>

                                <tr class="<?= $estado === 1 ? '' : 'table-secondary' ?>">

                                    <td><span class="badge bg-dark"><?= $codigo ?></span></td>

                                    <td><strong><?= htmlspecialchars($row['entidad_nombre']) ?></strong></td>

                                    <td class="text-center">

                                        <?php include __DIR__ . '/_entidad_estado_switch.php'; ?>

                                    </td>

                                    <td class="text-center"><span class="badge bg-success"><?= (int)($row['atletas_activos'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-secondary"><?= (int)($row['atletas_inactivos'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-primary"><?= (int)($row['hombres_activos'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-danger"><?= (int)($row['mujeres_activos'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-dark"><?= (int)($row['hombres_inactivos'] ?? 0) ?></span></td>

                                    <td class="text-center"><span class="badge bg-warning text-dark"><?= (int)($row['mujeres_inactivos'] ?? 0) ?></span></td>

                                    <td class="text-end">

                                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('entidades', ['action' => 'detail', 'id' => $row['entidad_id']])) ?>" class="btn btn-sm btn-outline-primary">

                                            <i class="fas fa-eye me-1"></i>Ver detalle

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



    <div class="card shadow-sm mt-4" id="crud-entidades">

        <div class="card-header bg-light d-flex justify-content-between align-items-center">

            <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Gestión de asociaciones</h5>

            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formNuevaEntidad">

                <i class="fas fa-plus me-1"></i>Nueva asociación

            </button>

        </div>

        <div class="card-body">

            <div id="formNuevaEntidad" class="collapse mb-3">

                <form method="post" action="index.php?page=entidades" class="row g-2 border rounded p-3 bg-light">

                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <input type="hidden" name="crud_action" value="create">

                    <div class="col-md-2">

                        <label class="form-label">Código</label>

                        <input type="number" min="1" class="form-control" name="codigo" required>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">Nombre</label>

                        <input type="text" class="form-control" name="nombre" required maxlength="60">

                    </div>

                    <div class="col-md-2">

                        <label class="form-label">Estado</label>

                        <div class="form-check form-switch mt-2">

                            <input class="form-check-input" type="checkbox" name="estado" value="1" checked id="estadoNuevaEntidad" role="switch">

                            <label class="form-check-label" for="estadoNuevaEntidad">Activa</label>

                        </div>

                    </div>

                    <div class="col-md-2 d-flex align-items-end">

                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-save me-1"></i>Guardar</button>

                    </div>

                </form>

            </div>



            <div class="table-responsive">

                <table class="table table-sm table-hover align-middle mb-0">

                    <thead class="table-light">

                        <tr>

                            <th style="width:120px">Código</th>

                            <th>Nombre</th>

                            <th style="width:160px" class="text-center">Estado</th>

                            <th style="width:220px" class="text-end">Acciones</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($entidades_crud)): ?>

                            <tr>

                                <td colspan="4" class="text-center text-muted py-3">No hay asociaciones en el catálogo</td>

                            </tr>

                        <?php endif; ?>

                        <?php foreach ($entidades_crud as $e): ?>

                            <?php

                            $codigo = (int)($e['id'] ?? 0);

                            $estadoCrud = (int)($e['estado'] ?? 0);

                            ?>

                            <tr class="<?= $estadoCrud === 1 ? '' : 'table-secondary' ?>">

                                <td><span class="badge bg-dark"><?= $codigo ?></span></td>

                                <td><?= htmlspecialchars((string)($e['nombre'] ?? '')) ?></td>

                                <td class="text-center">

                                    <?php $estado = $estadoCrud; include __DIR__ . '/_entidad_estado_switch.php'; ?>

                                </td>

                                <td class="text-end">

                                    <button

                                        type="button"

                                        class="btn btn-sm btn-outline-primary"

                                        data-bs-toggle="collapse"

                                        data-bs-target="#editEntidad<?= $codigo ?>">

                                        <i class="fas fa-edit me-1"></i>Editar

                                    </button>

                                    <form method="post" action="index.php?page=entidades" class="d-inline" onsubmit="return confirm('¿Eliminar asociación <?= $codigo ?>?');">

                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                                        <input type="hidden" name="crud_action" value="delete">

                                        <input type="hidden" name="codigo" value="<?= $codigo ?>">

                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Eliminar</button>

                                    </form>

                                </td>

                            </tr>

                            <tr class="collapse" id="editEntidad<?= $codigo ?>">

                                <td colspan="4">

                                    <form method="post" action="index.php?page=entidades" class="row g-2 p-2 border rounded bg-light">

                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                                        <input type="hidden" name="crud_action" value="update">

                                        <input type="hidden" name="codigo" value="<?= $codigo ?>">

                                        <div class="col-md-2">

                                            <label class="form-label">Código</label>

                                            <input type="text" class="form-control" value="<?= $codigo ?>" disabled>

                                        </div>

                                        <div class="col-md-6">

                                            <label class="form-label">Nombre</label>

                                            <input type="text" class="form-control" name="nombre" value="<?= htmlspecialchars((string)($e['nombre'] ?? '')) ?>" required maxlength="60">

                                        </div>

                                        <div class="col-md-2">

                                            <label class="form-label">Estado</label>

                                            <div class="form-check form-switch mt-2">

                                                <input class="form-check-input" type="checkbox" name="estado" value="1" <?= $estadoCrud === 1 ? 'checked' : '' ?> id="estadoEntidad<?= $codigo ?>" role="switch">

                                                <label class="form-check-label" for="estadoEntidad<?= $codigo ?>">Activa</label>

                                            </div>

                                        </div>

                                        <div class="col-md-2 d-flex align-items-end">

                                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Actualizar</button>

                                        </div>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>



<script>

document.querySelectorAll('.entidad-estado-switch').forEach(function (input) {

    input.addEventListener('change', function () {

        var form = this.closest('form.entidad-toggle-form');

        if (form) {

            form.submit();

        }

    });

});

</script>

