<?php
/**
 * Gestión de Rondas (Desktop).
 * Botón "Generar Ronda" que invoca logica_torneo.php con estrategias como argumentos (no solo $_POST).
 */
declare(strict_types=1);
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';
require_once __DIR__ . '/../../desktop/core/db_bridge.php';

$pdo = DB_Local::pdo();
$entidad_id = DB::getEntidadId();
$torneos = [];
$torneo_estado = [];

try {
    $sql = "SELECT id, nombre, fechator, rondas, COALESCE(modalidad, 0) AS modalidad FROM tournaments ORDER BY id DESC";
    $params = [];
    if ($entidad_id > 0) {
        $sql = "SELECT id, nombre, fechator, rondas, COALESCE(modalidad, 0) AS modalidad FROM tournaments WHERE entidad = ? ORDER BY id DESC";
        $params = [$entidad_id];
    }
    $stmt = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $stmt->execute($params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tabla_partiresul = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='partiresul' LIMIT 1")->fetch();
    foreach ($torneos as $t) {
        $tid = (int)$t['id'];
        $modalidad = (int)($t['modalidad'] ?? 0);
        $es_equipos = ($modalidad === 3);
        $ultima_ronda = 0;
        $mesas_incompletas = 0;
        if ($tabla_partiresul) {
            $st = $pdo->prepare("SELECT COALESCE(MAX(partida), 0) FROM partiresul WHERE id_torneo = ?");
            $st->execute([$tid]);
            $ultima_ronda = (int)$st->fetchColumn();
            if ($ultima_ronda > 0) {
                // Solo mesas de juego (mesa > 0); mesa 0 = bye, no bloquea
                $st = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0 AND (registrado = 0 OR registrado IS NULL) GROUP BY partida, mesa)");
                $st->execute([$tid, $ultima_ronda]);
                $mesas_incompletas = (int)$st->fetchColumn();
            }
        }
        $generar_bloqueado = ($ultima_ronda > 0 && $mesas_incompletas > 0);
        $torneo_estado[$tid] = ['es_equipos' => $es_equipos, 'generar_bloqueado' => $generar_bloqueado, 'ultima_ronda' => $ultima_ronda];
    }
} catch (Throwable $e) {
}

$status_success = isset($_GET['status']) && $_GET['status'] === 'success';
$msg_success = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$msg_error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$preselect_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0);

$pageTitle = 'Gestión de Rondas';
$desktopActive = 'panel';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3">
    <h2 class="h4 mb-3"><i class="fas fa-random text-primary me-2"></i>Gestión de Rondas</h2>
    <p class="text-muted mb-4">Genere la siguiente ronda para un torneo. Debe tener todas las mesas de la ronda actual con resultados cargados.</p>

    <?php if ($msg_error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($msg_error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php endif; ?>
    <?php if ($status_success && $msg_success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($msg_success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-random me-2"></i>Generar Ronda</h5>
        </div>
        <div class="card-body">
            <form method="post" action="generar_ronda.php" id="formGenerarRonda">
                <input type="hidden" name="torneo_id" id="hiddenTorneoId" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Torneo</label>
                        <select class="form-select" id="selectTorneo" required>
                            <option value="">-- Seleccione torneo --</option>
                            <?php foreach ($torneos as $t):
                                $tid = (int)$t['id'];
                                $est = $torneo_estado[$tid] ?? [];
                                $bloq = !empty($est['generar_bloqueado']);
                            ?>
                            <option value="<?= $tid ?>" data-equipos="<?= !empty($est['es_equipos']) ? '1' : '0' ?>" data-bloqueado="<?= $bloq ? '1' : '0' ?>" data-ultima="<?= (int)($est['ultima_ronda'] ?? 0) ?>"<?= ($preselect_id > 0 && $tid === $preselect_id) ? ' selected' : '' ?>>
                                <?= htmlspecialchars($t['nombre'] ?? '') ?><?= $bloq ? ' (complete mesas de la ronda actual)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6" id="wrapEstrategiaIndividual">
                        <label class="form-label fw-bold">Estrategia (individual/parejas)</label>
                        <select name="estrategia_ronda2" class="form-select">
                            <option value="separar">Separar líderes (+ Suizo en rondas 3+)</option>
                            <option value="club_interclub_rr">Interclub: RR por club; R1 sin BYE (sobrantes → retirados)</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-none" id="wrapEstrategiaEquipos">
                        <label class="form-label fw-bold">Estrategia (equipos)</label>
                        <select name="estrategia_asignacion" class="form-select">
                            <option value="secuencial">Secuencial</option>
                            <option value="intercalada">Intercalada</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" id="btnGenerarRonda" disabled>
                            <i class="fas fa-random me-1"></i>Generar Ronda
                        </button>
                        <a href="torneos.php" class="btn btn-outline-secondary ms-2">Panel de Torneo</a>
                        <a href="captura.php" class="btn btn-outline-info ms-2">Captura de Resultados</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function() {
    var sel = document.getElementById('selectTorneo');
    var hidden = document.getElementById('hiddenTorneoId');
    var form = document.getElementById('formGenerarRonda');
    var btn = document.getElementById('btnGenerarRonda');
    var wrapInd = document.getElementById('wrapEstrategiaIndividual');
    var wrapEq = document.getElementById('wrapEstrategiaEquipos');

    function actualizar() {
        var opt = sel.options[sel.selectedIndex];
        var val = opt ? opt.value : '';
        if (hidden) hidden.value = val;
        var bloqueado = opt ? opt.getAttribute('data-bloqueado') === '1' : true;
        var equipos = opt ? opt.getAttribute('data-equipos') === '1' : false;
        if (btn) btn.disabled = !val || bloqueado;
        if (wrapInd) wrapInd.classList.toggle('d-none', equipos);
        if (wrapEq) wrapEq.classList.toggle('d-none', !equipos);
    }
    if (sel) {
        sel.addEventListener('change', actualizar);
        actualizar();
        var preselectId = <?= (int)$preselect_id ?>;
        if (preselectId > 0 && sel.querySelector('option[value="' + preselectId + '"]')) {
            sel.value = String(preselectId);
            actualizar();
        }
    }
    if (form) {
        form.addEventListener('submit', function(e) {
            if (sel.value === '' || btn.disabled) {
                e.preventDefault();
                alert('Seleccione un torneo y asegúrese de que todas las mesas de la ronda actual estén completas.');
            }
        });
    }
})();
</script>
</main></body></html>
