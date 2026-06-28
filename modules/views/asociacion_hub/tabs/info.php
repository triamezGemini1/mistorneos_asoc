<?php
/**
 * Pestaña Información — vista pública del hub + edición (admin).
 *
 * @var array<string, mixed> $viewData
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/csrf.php';
require_once __DIR__ . '/../../../../lib/AsociacionAuth.php';
require_once __DIR__ . '/../../../../lib/OrganizacionService.php';

$orgId = (int) ($viewData['org_id'] ?? 0);
$asociacion = $viewData['asociacion'] ?? null;
$puedeAdmin = ! empty($viewData['puede_administrar']);
$authUser = $viewData['auth_user'] ?? null;
$puedeEditar = $puedeAdmin
    && AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $orgId, $authUser);

$entidadId = $asociacion !== null ? (int) ($asociacion->entidad ?? 0) : 0;
$nombre = (string) ($viewData['nombre_asociacion'] ?? ($asociacion->nombre ?? ''));
$email = $asociacion !== null ? trim((string) ($asociacion->email ?? '')) : '';
$telefono = $asociacion !== null ? trim((string) ($asociacion->telefono ?? '')) : '';
$direccion = $asociacion !== null ? trim((string) ($asociacion->direccion ?? '')) : '';
$logo = $asociacion !== null ? trim((string) ($asociacion->logo ?? '')) : '';
$logoPreviewUrl = OrganizacionService::logoPublicUrl($logo);

$formAction = is_callable($dashboard_href ?? null)
    ? $dashboard_href('asociacion_hub', ['org_id' => $orgId])
    : 'index.php?page=asociacion_hub&org_id=' . $orgId;
?>
<div class="card shadow-sm asoc-report-card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0"><i class="fas fa-info-circle me-2"></i>Información general</h2>
        <?php if ($puedeEditar): ?>
        <button type="button"
                class="btn btn-sm btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#estacionEditOrgModal">
            <i class="fas fa-edit me-1"></i>Editar
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3 col-md-2">Nombre</dt>
            <dd class="col-sm-9 col-md-10"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></dd>

            <dt class="col-sm-3 col-md-2">Email</dt>
            <dd class="col-sm-9 col-md-10"><?= htmlspecialchars($email !== '' ? $email : '—', ENT_QUOTES, 'UTF-8') ?></dd>

            <dt class="col-sm-3 col-md-2">Teléfono</dt>
            <dd class="col-sm-9 col-md-10"><?= htmlspecialchars($telefono !== '' ? $telefono : '—', ENT_QUOTES, 'UTF-8') ?></dd>

            <dt class="col-sm-3 col-md-2">Dirección</dt>
            <dd class="col-sm-9 col-md-10"><?= htmlspecialchars($direccion !== '' ? $direccion : '—', ENT_QUOTES, 'UTF-8') ?></dd>

            <dt class="col-sm-3 col-md-2">Logo</dt>
            <dd class="col-sm-9 col-md-10">
                <?php if ($logoPreviewUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($logoPreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Logo de <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>"
                         class="estacion-org-logo-preview rounded border bg-white p-1"
                         style="max-height: 80px; max-width: 200px; object-fit: contain;">
                <?php elseif ($logo !== ''): ?>
                    <span class="d-block text-break small"><?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?></span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>

            <?php if ($entidadId > 0): ?>
            <dt class="col-sm-3 col-md-2">Entidad (ref.)</dt>
            <dd class="col-sm-9 col-md-10">#<?= $entidadId ?></dd>
            <?php endif; ?>

            <?php if (! empty($viewData['puede_ver_detalles'])): ?>
            <dt class="col-sm-3 col-md-2">Acceso</dt>
            <dd class="col-sm-9 col-md-10">
                <span class="badge bg-success">Afiliado / miembro</span>
                <?php if ($puedeAdmin): ?>
                    <span class="badge bg-primary ms-1">Administración</span>
                <?php endif; ?>
            </dd>
            <?php else: ?>
            <dt class="col-sm-3 col-md-2">Acceso</dt>
            <dd class="col-sm-9 col-md-10">Vista pública. Inicie sesión como afiliado para ver torneos y clubes.</dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<?php if ($puedeEditar): ?>
<div class="modal fade" id="estacionEditOrgModal" tabindex="-1" aria-labelledby="estacionEditOrgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post"
                  action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
                  enctype="multipart/form-data"
                  novalidate>
                <?= CSRF::input() ?>
                <input type="hidden" name="org_id" value="<?= $orgId ?>">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="estacionEditOrgModalLabel">
                        <i class="fas fa-building me-2"></i>Editar asociación
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="org_nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   id="org_nombre"
                                   name="nombre"
                                   required
                                   maxlength="255"
                                   value="<?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="org_email" class="form-label">Email</label>
                            <input type="email"
                                   class="form-control"
                                   id="org_email"
                                   name="email"
                                   maxlength="100"
                                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="org_telefono" class="form-label">Teléfono</label>
                            <input type="tel"
                                   class="form-control"
                                   id="org_telefono"
                                   name="telefono"
                                   maxlength="50"
                                   value="<?= htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-12">
                            <label for="org_direccion" class="form-label">Dirección</label>
                            <input type="text"
                                   class="form-control"
                                   id="org_direccion"
                                   name="direccion"
                                   maxlength="255"
                                   value="<?= htmlspecialchars($direccion, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Logo</label>
                            <div class="d-flex flex-wrap align-items-start gap-3 mb-2">
                                <div class="text-center">
                                    <div class="small text-muted mb-1">Actual</div>
                                    <img id="org_logo_preview_current"
                                         src="<?= $logoPreviewUrl !== '' ? htmlspecialchars($logoPreviewUrl, ENT_QUOTES, 'UTF-8') : '' ?>"
                                         alt="Logo actual"
                                         class="rounded border bg-white p-1 <?= $logoPreviewUrl === '' ? 'd-none' : '' ?>"
                                         style="max-height: 100px; max-width: 180px; object-fit: contain;">
                                    <div id="org_logo_preview_empty" class="text-muted small border rounded p-3 <?= $logoPreviewUrl !== '' ? 'd-none' : '' ?>">
                                        Sin logo
                                    </div>
                                </div>
                                <div class="text-center">
                                    <div class="small text-muted mb-1">Nueva vista previa</div>
                                    <img id="org_logo_preview_new"
                                         alt="Vista previa del nuevo logo"
                                         class="rounded border bg-white p-1 d-none"
                                         style="max-height: 100px; max-width: 180px; object-fit: contain;">
                                    <div id="org_logo_preview_new_empty" class="text-muted small border rounded p-3">
                                        Seleccione un archivo
                                    </div>
                                </div>
                            </div>
                            <input type="file"
                                   class="form-control"
                                   id="org_logo_file"
                                   name="logo"
                                   accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">Formatos: JPG, PNG, GIF o WEBP. Máx. recomendado 2 MB.</div>
                        </div>
                        <div class="col-12">
                            <label for="org_logo_url" class="form-label">O URL del logo (opcional)</label>
                            <input type="url"
                                   class="form-control"
                                   id="org_logo_url"
                                   name="logo_url"
                                   maxlength="255"
                                   placeholder="https://…"
                                   value="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-text">Si sube un archivo, tiene prioridad sobre la URL.</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var input = document.getElementById('org_logo_file');
    var previewNew = document.getElementById('org_logo_preview_new');
    var previewNewEmpty = document.getElementById('org_logo_preview_new_empty');
    if (!input || !previewNew) {
        return;
    }
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) {
            previewNew.classList.add('d-none');
            previewNew.removeAttribute('src');
            if (previewNewEmpty) {
                previewNewEmpty.classList.remove('d-none');
            }
            return;
        }
        if (!file.type.match(/^image\//)) {
            previewNew.classList.add('d-none');
            if (previewNewEmpty) {
                previewNewEmpty.textContent = 'Archivo no es imagen';
                previewNewEmpty.classList.remove('d-none');
            }
            return;
        }
        var reader = new FileReader();
        reader.onload = function (e) {
            previewNew.src = e.target.result;
            previewNew.classList.remove('d-none');
            if (previewNewEmpty) {
                previewNewEmpty.classList.add('d-none');
            }
        };
        reader.readAsDataURL(file);
    });
})();
</script>
<?php endif; ?>
