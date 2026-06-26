<?php
declare(strict_types=1);

/**
 * Listado de asociaciones afiliadas — acceso admin general.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/OrganizacionService.php';
require_once __DIR__ . '/../lib/AsociacionHubNavigation.php';

Auth::requireRole(['admin_general']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $sessionToken === '' || ! hash_equals($sessionToken, $csrf)) {
        $_SESSION['error'] = 'Token CSRF inválido.';
        header('Location: ' . AppHelpers::dashboard('listado_asociaciones'));
        exit;
    }

    $postAction = (string) ($_POST['crud_action'] ?? '');
    if ($postAction === 'toggle_estatus') {
        $orgId = (int) ($_POST['org_id'] ?? 0);
        $nuevoEstatus = (int) ($_POST['nuevo_estatus'] ?? 0) === 1 ? 1 : 0;

        if ($orgId <= 0) {
            $_SESSION['error'] = 'Asociación no válida.';
        } elseif (OrganizacionService::setEstatus($orgId, $nuevoEstatus)) {
            $_SESSION['success'] = $nuevoEstatus === 1
                ? 'Asociación activada correctamente.'
                : 'Asociación desactivada correctamente.';
        } else {
            $_SESSION['error'] = 'No se pudo actualizar el estado de la asociación.';
        }
        header('Location: ' . AppHelpers::dashboard('listado_asociaciones'));
        exit;
    }
}

if (! function_exists('dashboard_href') && class_exists('AppHelpers')) {
    $dashboard_href = static function (string $page, array $params = []): string {
        return AppHelpers::dashboard($page, $params);
    };
}

$asociaciones = OrganizacionService::getAllAfiliadas();
$csrf_token = CSRF::token();
$activasCount = 0;
foreach ($asociaciones as $org) {
    if ((int) ($org['estatus'] ?? 0) === 1) {
        $activasCount++;
    }
}
$hubHref = static function (int $orgId) use ($dashboard_href): string {
    $returnUrl = class_exists('AppHelpers', false)
        ? AppHelpers::dashboard('listado_asociaciones')
        : 'index.php?page=listado_asociaciones';
    $params = ['org_id' => $orgId, 'return_url' => $returnUrl];
    if (is_callable($dashboard_href)) {
        return $dashboard_href('asociacion_hub', $params);
    }

    return 'index.php?page=asociacion_hub&' . http_build_query($params);
};
?>
<div class="container-fluid py-4">
    <div class="estacion-hub-header mb-4">
        <h1 class="h3 mb-1">
            <i class="fas fa-handshake me-2"></i>Asociaciones Afiliadas
        </h1>
        <p class="estacion-hub-subtitle small">Active o desactive asociaciones y entre al hub de cada una.</p>
    </div>

    <?php if (! empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string) $_SESSION['success'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (! empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string) $_SESSION['error'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if ($asociaciones === []): ?>
        <div class="card shadow-sm">
            <div class="card-body estacion-empty-state">
                <i class="fas fa-building fa-3x mb-3 d-block"></i>
                <p class="mb-0">No hay asociaciones registradas.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fas fa-list me-2"></i>Listado</span>
                <span class="badge estacion-count-badge">
                    <?= $activasCount ?> activa(s) · <?= count($asociaciones) ?> total
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:100px">Estado</th>
                                <th>Nombre</th>
                                <th>Entidad</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asociaciones as $org): ?>
                                <?php
                                $orgId = (int) ($org['id'] ?? 0);
                                $nombre = (string) ($org['nombre'] ?? '');
                                $entidadNombre = (string) ($org['entidad_nombre'] ?? '—');
                                $activa = (int) ($org['estatus'] ?? 0) === 1;
                                ?>
                                <tr class="<?= $activa ? '' : 'table-secondary opacity-75' ?>">
                                    <td class="text-center">
                                        <?php
                                        $estatus = (int) ($org['estatus'] ?? 0);
                                        include __DIR__ . '/views/_asociacion_estatus_switch.php';
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if (! $activa): ?>
                                            <span class="badge bg-secondary ms-1">Inactiva</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($entidadNombre, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <?php if ($activa): ?>
                                            <a href="<?= htmlspecialchars($hubHref($orgId), ENT_QUOTES, 'UTF-8') ?>"
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-door-open me-1"></i>Entrar al Hub
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                    title="Active la asociación para entrar al hub">
                                                <i class="fas fa-door-closed me-1"></i>Hub no disponible
                                            </button>
                                        <?php endif; ?>
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
<script>
document.querySelectorAll('.asociacion-estatus-switch').forEach(function (input) {
    input.addEventListener('change', function () {
        var form = this.closest('form.asociacion-toggle-form');
        if (!form) {
            return;
        }
        var hidden = form.querySelector('input[name="nuevo_estatus"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'nuevo_estatus';
            form.appendChild(hidden);
        }
        hidden.value = this.checked ? '1' : '0';
        form.submit();
    });
});
</script>
