<?php
/**
 * Gestión de Staff: activar/desactivar administradores (toggle is_active).
 * Solo el Master Admin (MASTER_ADMIN_EMAIL o MASTER_ADMIN_ID en config_sync.php) puede usar los interruptores.
 * Al desactivar se marca sync_status = 0 para que export_permissions.php suba el cambio al servidor.
 */
declare(strict_types=1);
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';

if (file_exists(__DIR__ . '/config_sync.php')) {
    require __DIR__ . '/config_sync.php';
}

$current = $_SESSION['desktop_user'] ?? [];
$currentId = (int)($current['id'] ?? 0);
$currentEmail = trim((string)($current['email'] ?? ''));
$masterEmail = defined('MASTER_ADMIN_EMAIL') ? trim((string)MASTER_ADMIN_EMAIL) : '';
$masterId = defined('MASTER_ADMIN_ID') ? (int)MASTER_ADMIN_ID : 0;
$isMasterAdmin = ($masterEmail !== '' && $currentEmail !== '' && strcasecmp($currentEmail, $masterEmail) === 0)
    || ($masterId > 0 && $currentId === $masterId);

$pdo = DB_Local::pdo();
$staff = [];
try {
    $staff = $pdo->query("
        SELECT u.id, u.username, u.nombre, u.email, u.role, u.is_active, u.sync_status, u.club_id,
               o.nombre AS organizacion_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON c.id = u.club_id
        LEFT JOIN organizaciones o ON o.id = c.cod_org
        WHERE u.role IN ('admin_general','admin_torneo','admin_club','operador')
        ORDER BY u.role = 'admin_general' DESC, u.username
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $staff = $pdo->query("
        SELECT id, username, nombre, email, role, is_active, sync_status, club_id
        FROM usuarios
        WHERE role IN ('admin_general','admin_torneo','admin_club','operador')
        ORDER BY role = 'admin_general' DESC, username
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($staff as &$s) {
        $s['organizacion_nombre'] = '';
    }
    unset($s);
}

$pageTitle = 'Gestión de Administradores';
$desktopActive = 'staff';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3">
    <h2 class="h4 mb-3"><i class="fas fa-users-cog text-primary me-2"></i>Gestión de Administradores</h2>
    <p class="text-muted"><?= $isMasterAdmin ? 'Activa o desactiva administradores. Los cambios se suben con "Exportar permisos".' : 'Solo el Master Admin puede modificar el estado. Puedes exportar permisos si hay cambios pendientes.' ?></p>

    <?php if (isset($_GET['export']) && $_GET['export'] === '1'): ?>
    <div class="alert alert-success py-2">Permisos exportados correctamente a la web. <?= isset($_GET['n']) ? (int)$_GET['n'] . ' registro(s) actualizado(s).' : '' ?></div>
    <?php endif; ?>

    <?php if (!$isMasterAdmin): ?>
    <div class="alert alert-info py-2">Configura <code>MASTER_ADMIN_EMAIL</code> o <code>MASTER_ADMIN_ID</code> en <code>config_sync.php</code> para designar al Master Admin.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <table class="table table-hover mb-0">
                <thead><tr><th>Nombre</th><th>Organización</th><th>Rol</th><th>Estado</th><?php if ($isMasterAdmin): ?><th>Acción</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($staff as $s):
                        $id = (int)$s['id'];
                        $isActive = (int)($s['is_active'] ?? 1) === 1;
                        $esYo = $id === $currentId;
                        $org = $s['organizacion_nombre'] ?? '';
                    ?>
                    <tr data-id="<?= $id ?>">
                        <td><?= htmlspecialchars($s['nombre'] ?? $s['username']) ?></td>
                        <td><?= htmlspecialchars($org) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($s['role']) ?></span></td>
                        <td><span class="badge bg-<?= $isActive ? 'success' : 'danger' ?>"><?= $isActive ? 'Activo' : 'Desactivado' ?></span></td>
                        <?php if ($isMasterAdmin): ?>
                        <td>
                            <?php if (!$esYo): ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input staff-toggle" type="checkbox" data-id="<?= $id ?>" data-username="<?= htmlspecialchars($s['username']) ?>" <?= $isActive ? 'checked' : '' ?>>
                                <label class="form-check-label small"><?= $isActive ? 'Activo' : 'Desactivado' ?></label>
                            </div>
                            <?php else: ?>
                            <span class="text-muted small">(tú)</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($isMasterAdmin): ?>
    <div class="mt-3">
        <a href="export_permissions.php" class="btn btn-success"><i class="fas fa-cloud-upload-alt me-1"></i>Exportar permisos a la web</a>
        <span class="text-muted small ms-2">Sube los cambios de is_active al servidor para que el bloqueo sea efectivo de inmediato.</span>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="confirmToggleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar cambio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmToggleBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="confirmToggleBtn">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var modal = document.getElementById('confirmToggleModal');
    if (!modal) return;
    var m = new bootstrap.Modal(modal);
    var bodyEl = document.getElementById('confirmToggleBody');
    var btnConfirm = document.getElementById('confirmToggleBtn');
    var pendingId = null;
    var pendingActive = null;

    document.querySelectorAll('.staff-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var id = this.getAttribute('data-id');
            var username = this.getAttribute('data-username');
            var willBeActive = this.checked;
            pendingId = id;
            pendingActive = willBeActive ? 1 : 0;
            bodyEl.textContent = willBeActive
                ? '¿Activar al administrador "' + username + '"? Podrá acceder de nuevo.'
                : '¿Desactivar al administrador "' + username + '"? No podrá acceder hasta que se reactive. Se marcará para sincronizar con la web.';
            m.show();
        });
    });

    btnConfirm.addEventListener('click', function () {
        if (pendingId === null) return;
        var active = pendingActive;
        fetch('save_staff_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: parseInt(pendingId, 10), is_active: active })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            m.hide();
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'Error');
            }
        })
        .catch(function () { m.hide(); alert('Error de conexión'); });
        pendingId = null;
    });
})();
</script>
</main></body></html>
