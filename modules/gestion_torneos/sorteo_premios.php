<?php
/**
 * Sorteos / premios entre inscritos confirmados del torneo.
 *
 * Contexto: $torneo, $torneo_id, $view_data (obtenerDatosPanel)
 */
if (!class_exists('CSRF', false)) {
    require_once __DIR__ . '/../../config/csrf.php';
}
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
$tid = (int) ($torneo_id ?? $torneo['id'] ?? 0);
$nombreTorneo = htmlspecialchars((string) ($torneo['nombre'] ?? 'Torneo'), ENT_QUOTES, 'UTF-8');
$csrf = CSRF::token();
$script = basename($_SERVER['PHP_SELF'] ?? '');
$useStandalone = in_array($script, ['admin_torneo.php', 'panel_torneo.php'], true);
$backUrl = $useStandalone
    ? $script . '?torneo_id=' . $tid . '&action=panel'
    : 'index.php?page=torneo_gestion&action=panel&torneo_id=' . $tid;
?>
<link rel="stylesheet" href="assets/css/raffle-premium.css">
<div class="rp-wrap">
    <nav class="rp-breadcrumb mb-3">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="rp-back"><i class="fas fa-arrow-left"></i> Volver al panel</a>
    </nav>
    <h1 class="rp-title"><i class="fas fa-dice"></i> Sorteos y premios</h1>
    <p class="rp-lead"><?= $nombreTorneo ?> — Se eligen al azar entre inscritos <strong>confirmados</strong>.</p>

    <div class="rp-grid">
        <section class="rp-card">
            <h2><i class="fas fa-cog"></i> Configurar sorteo</h2>
            <label class="rp-label">Descripción del premio</label>
            <input type="text" id="rp-premio" class="rp-input" value="Premio" maxlength="250" placeholder="Ej. Kit oficial, Camiseta…">

            <label class="rp-label">Cantidad de ganadores</label>
            <input type="number" id="rp-cantidad" class="rp-input" min="1" max="50" value="1">

            <label class="rp-check">
                <input type="checkbox" id="rp-excl-prev">
                Excluir a quienes ya salieron premiados en sorteos anteriores de este torneo
            </label>

            <label class="rp-label">Excluir IDs de usuario (opcional, separados por coma)</label>
            <input type="text" id="rp-excl-ids" class="rp-input" placeholder="Ej. 12, 45">

            <p class="rp-hint" id="rp-count-hint">Cargando inscritos…</p>

            <button type="button" class="rp-btn rp-btn-primary" id="rp-run">
                <i class="fas fa-random"></i> Ejecutar sorteo
            </button>
        </section>

        <section class="rp-card rp-card--display">
            <h2><i class="fas fa-trophy"></i> Resultado</h2>
            <div id="rp-stage" class="rp-stage" aria-live="polite">
                <p class="rp-placeholder">Pulse «Ejecutar sorteo» para mostrar ganadores aquí.</p>
            </div>
        </section>
    </div>

    <section class="rp-card rp-card--full mt-4">
        <h2><i class="fas fa-history"></i> Historial de sorteos</h2>
        <div id="rp-historial" class="rp-historial">Cargando…</div>
    </section>
</div>

<script>
window.RAFFLE_CFG = {
    torneo_id: <?= (int) $tid ?>,
    csrf_token: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    api: <?= json_encode(
        class_exists('AppHelpers', false)
            ? (rtrim(AppHelpers::getPublicUrl(), '/') . '/api/tournament_raffle.php')
            : '/api/tournament_raffle.php',
        JSON_UNESCAPED_SLASHES
    ) ?>
};
</script>
<script src="assets/js/raffle-engine.js" defer></script>
