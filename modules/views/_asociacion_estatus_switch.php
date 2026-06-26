<?php
declare(strict_types=1);
/** @var int $orgId */
/** @var int $estatus */
$orgId = (int) ($orgId ?? 0);
$activa = (int) ($estatus ?? 0) === 1;
$csrf_token = $csrf_token ?? (class_exists('CSRF') ? CSRF::token() : '');
?>
<form method="post" action="index.php?page=listado_asociaciones" class="asociacion-toggle-form d-inline-flex align-items-center justify-content-center gap-1">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="crud_action" value="toggle_estatus">
    <input type="hidden" name="org_id" value="<?= $orgId ?>">
    <div class="form-check form-switch m-0" title="<?= $activa ? 'Desactivar asociación' : 'Activar asociación' ?>">
        <input
            class="form-check-input asociacion-estatus-switch"
            type="checkbox"
            role="switch"
            id="asocSwitch<?= $orgId ?>"
            <?= $activa ? 'checked' : '' ?>
            aria-label="<?= $activa ? 'Asociación activa' : 'Asociación inactiva' ?>"
        >
        <label class="form-check-label visually-hidden" for="asocSwitch<?= $orgId ?>">
            <?= $activa ? 'Activa' : 'Inactiva' ?>
        </label>
    </div>
</form>
