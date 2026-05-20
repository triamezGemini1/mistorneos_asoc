<?php
/** @var int $codigo */
/** @var int $estado */
$codigo = (int)($codigo ?? 0);
$activa = (int)($estado ?? 0) === 1;
$csrf_token = $csrf_token ?? (class_exists('CSRF') ? CSRF::token() : '');
?>
<form method="post" action="index.php?page=entidades" class="entidad-toggle-form d-inline-flex align-items-center justify-content-center gap-1">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="crud_action" value="toggle_estado">
    <input type="hidden" name="codigo" value="<?= $codigo ?>">
    <div class="form-check form-switch m-0" title="<?= $activa ? 'Desactivar asociación' : 'Activar asociación' ?>">
        <input
            class="form-check-input entidad-estado-switch"
            type="checkbox"
            role="switch"
            id="entidadSwitch<?= $codigo ?>"
            <?= $activa ? 'checked' : '' ?>
            aria-label="<?= $activa ? 'Asociación activa' : 'Asociación inactiva' ?>"
        >
        <label class="form-check-label visually-hidden" for="entidadSwitch<?= $codigo ?>">
            <?= $activa ? 'Activa' : 'Inactiva' ?>
        </label>
    </div>
    <i class="fas <?= $activa ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary' ?>" aria-hidden="true"></i>
</form>
