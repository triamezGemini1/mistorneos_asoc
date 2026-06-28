<?php
declare(strict_types=1);
/** @var int $clubId */
/** @var int $estatus */
$clubId = (int) ($clubId ?? 0);
$activo = (int) ($estatus ?? 0) === 1;
$csrf_token = $csrf_token ?? (class_exists('CSRF') ? CSRF::token() : '');
if (! isset($clubes_post_url)) {
    if (! class_exists('ClubNavigation', false)) {
        require_once __DIR__ . '/../../lib/ClubNavigation.php';
    }
    $clubes_post_url = ClubNavigation::clubesAsociadosPostUrl();
}
?>
<form method="post" action="<?= htmlspecialchars((string) $clubes_post_url, ENT_QUOTES, 'UTF-8') ?>" class="club-toggle-form d-inline-flex align-items-center justify-content-center gap-1">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="toggle_estatus">
    <input type="hidden" name="club_id" value="<?= $clubId ?>">
    <?php
    if (!empty($hub_hidden_params) && is_array($hub_hidden_params)) {
        foreach ($hub_hidden_params as $hk => $hv) {
            echo '<input type="hidden" name="' . htmlspecialchars((string) $hk, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $hv, ENT_QUOTES, 'UTF-8') . '">';
        }
    }
    ?>
    <div class="form-check form-switch m-0" title="<?= $activo ? 'Desactivar club' : 'Activar club' ?>">
        <input
            class="form-check-input club-estatus-switch"
            type="checkbox"
            role="switch"
            id="clubSwitch<?= $clubId ?>"
            <?= $activo ? 'checked' : '' ?>
            aria-label="<?= $activo ? 'Club activo' : 'Club inactivo' ?>"
        >
        <label class="form-check-label visually-hidden" for="clubSwitch<?= $clubId ?>">
            <?= $activo ? 'Activo' : 'Inactivo' ?>
        </label>
    </div>
</form>
