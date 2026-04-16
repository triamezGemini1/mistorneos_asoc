<?php
/**
 * Vista: Formulario editar organización (admin_club y admin_general)
 */
?>
<!-- Sección superior: Identificación en dos columnas -->
<div class="row mb-4">
    <!-- Columna 1: Información de la organización -->
    <div class="col-md-6 mb-3 mb-md-0">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-building me-2"></i>Información de la Organización</h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start mb-3">
                    <?php if ($organizacion['logo']): 
                        $logo_url_card = AppHelpers::url('view_image.php', ['path' => $organizacion['logo']]);
                    ?>
                        <img src="<?= htmlspecialchars($logo_url_card) ?>" alt="Logo" class="rounded-circle me-3 flex-shrink-0" style="width: 80px; height: 80px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-light rounded-circle me-3 flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                            <i class="fas fa-building fa-2x text-muted"></i>
                        </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <h4 class="mb-1"><?= htmlspecialchars($organizacion['nombre']) ?></h4>
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
                <?php if ($is_admin_general && !empty($organizacion['admin_nombre'])): ?>
                    <hr class="my-3">
                    <div>
                        <small class="text-muted d-block mb-1"><i class="fas fa-user-shield me-1"></i>Administrador</small>
                        <p class="mb-0"><strong><?= htmlspecialchars($organizacion['admin_nombre']) ?></strong><br>
                        <span class="small text-muted"><?= htmlspecialchars($organizacion['admin_email'] ?? '') ?></span></p>
                    </div>
                <?php endif; ?>
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
                <p class="text-muted small mb-3">Resumen global según la entidad de la organización y el vínculo canónico (cod_org / club_responsable y clubes asociados).</p>
                <div class="row g-3">
                    <div class="col-6 col-lg-4">
                        <div class="card bg-primary text-white text-center">
                            <div class="card-body py-3">
                                <h3 class="mb-0"><?= (int)($stats['clubes'] ?? 0) ?></h3>
                                <small>Clubes activos</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="card bg-success text-white text-center">
                            <div class="card-body py-3">
                                <h3 class="mb-0"><?= (int)($stats['torneos'] ?? 0) ?></h3>
                                <small>Torneos (publicados)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="card bg-success bg-opacity-75 text-white text-center">
                            <div class="card-body py-3">
                                <h3 class="mb-0"><?= (int)($stats['torneos_activos'] ?? 0) ?></h3>
                                <small>Próximos / en curso</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="card bg-info text-white text-center">
                            <div class="card-body py-3">
                                <h3 class="mb-0"><?= (int)($stats['afiliados'] ?? 0) ?></h3>
                                <small>Afiliados (rol usuario)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="card bg-secondary text-white text-center">
                            <div class="card-body py-3">
                                <h3 class="mb-0"><?= (int)($stats['usuarios'] ?? 0) ?></h3>
                                <small>Usuarios en territorio</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="card bg-dark text-white text-center">
                            <div class="card-body py-3">
                                <h3 class="mb-0"><?= (int)($stats['inscripciones'] ?? 0) ?></h3>
                                <small>Inscripciones confirmadas</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulario de edición -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-edit me-2"></i>Editar Información
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="actualizar">
                    <input type="hidden" name="organizacion_id" value="<?= $organizacion['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nombre de la Organización <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($organizacion['nombre']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Responsable / Presidente</label>
                            <input type="text" name="responsable" class="form-control" value="<?= htmlspecialchars($organizacion['responsable'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($organizacion['telefono'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($organizacion['email'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Dirección</label>
                        <textarea name="direccion" class="form-control" rows="2"><?= htmlspecialchars($organizacion['direccion'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <input type="file" name="logo" id="logo-organizacion" class="form-control" accept="image/*" data-preview-target="organizacion-logo-preview">
                        <small class="text-muted d-block mt-1">Formatos permitidos: JPG, PNG, GIF, WEBP. Máximo 2MB.</small>
                        <div id="organizacion-logo-preview" class="mt-2"></div>
                        <?php if (!empty($organizacion['logo'])): 
                            $logo_url = AppHelpers::url('view_image.php', ['path' => $organizacion['logo']]);
                        ?>
                            <div class="mt-2 pt-2 border-top">
                                <small class="text-success d-block mb-2"><i class="fas fa-check me-1"></i>Logo actual (click para ampliar):</small>
                                <a href="<?= htmlspecialchars($logo_url) ?>" class="d-inline-block">
                                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo actual" class="img-thumbnail" style="max-height: 120px; object-fit: contain; cursor: pointer;">
                                </a>
                                <small class="text-muted d-block mt-1"><?= htmlspecialchars(basename($organizacion['logo'])) ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <?php if ($is_admin_general): ?>
                            <a href="index.php?page=mi_organizacion" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Volver a la lista
                            </a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(class_exists('AppHelpers') ? AppHelpers::dashboard('home') : 'index.php?page=home') ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Volver al inicio
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
