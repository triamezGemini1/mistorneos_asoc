<?php
/**
 * Panel de control simplificado (Desktop): torneos, inscritos, mesas y puntajes sobre SQLite.
 */
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';
require_once __DIR__ . '/../../desktop/core/db_bridge.php';

$pdo = DB_Local::pdo();
$entidad_id = DB::getEntidadId();
$torneos = [];
$inscritos_por_torneo = [];
$mesas_pendientes = 0;
$torneo_estado = []; // por torneo_id: inscripciones_bloqueado, generar_ronda_bloqueado, es_equipos
try {
    $sqlT = "SELECT id, nombre, fechator, estatus, rondas, COALESCE(modalidad, 0) AS modalidad FROM tournaments ORDER BY id DESC";
    if ($entidad_id > 0) {
        $stmtT = $pdo->prepare("SELECT id, nombre, fechator, estatus, rondas, COALESCE(modalidad, 0) AS modalidad FROM tournaments WHERE entidad = ? ORDER BY id DESC");
        $stmtT->execute([$entidad_id]);
        $torneos = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $torneos = $pdo->query($sqlT)->fetchAll(PDO::FETCH_ASSOC);
    }
    $tabla_partiresul = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='partiresul' LIMIT 1")->fetch();
    foreach ($torneos as $t) {
        $tid = (int)$t['id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?");
        $stmt->execute([$tid]);
        $inscritos_por_torneo[$tid] = (int)$stmt->fetchColumn();
        $modalidad = (int)($t['modalidad'] ?? 0);
        $es_equipos = ($modalidad === 3);
        $ultima_ronda = 0;
        $mesas_incompletas_ultima = 0;
        if ($tabla_partiresul) {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(partida), 0) FROM partiresul WHERE id_torneo = ?");
            $stmt->execute([$tid]);
            $ultima_ronda = (int)$stmt->fetchColumn();
            if ($ultima_ronda > 0) {
                // Solo mesas de juego (mesa > 0); mesa 0 = bye, no cuenta como pendiente
                require_once __DIR__ . '/../../lib/PartiresulEstatusSql.php';
                $mesas_incompletas_ultima = PartiresulEstatusSql::contarMesasIncompletas($pdo, $tid, $ultima_ronda);
            }
        }
        // Inscripciones: individual → bloqueado al iniciar 2ª ronda; equipos → bloqueado al iniciar torneo (1ª ronda generada)
        $inscripciones_bloqueado = $es_equipos ? ($ultima_ronda >= 1) : ($ultima_ronda >= 2);
        // Generar Ronda: bloqueado hasta que todas las mesas de la última ronda estén cargadas
        $generar_ronda_bloqueado = ($ultima_ronda > 0 && $mesas_incompletas_ultima > 0);
        $torneo_estado[$tid] = [
            'inscripciones_bloqueado' => $inscripciones_bloqueado,
            'generar_ronda_bloqueado' => $generar_ronda_bloqueado,
            'es_equipos' => $es_equipos,
        ];
    }
    if ($tabla_partiresul) {
        require_once __DIR__ . '/../../lib/PartiresulEstatusSql.php';
        $mesas_pendientes = 0;
        $stTorneos = $pdo->query('SELECT DISTINCT id_torneo, partida FROM partiresul WHERE CAST(mesa AS SIGNED) > 0');
        while ($rowT = $stTorneos->fetch(PDO::FETCH_ASSOC)) {
            $mesas_pendientes += PartiresulEstatusSql::contarMesasIncompletas(
                $pdo,
                (int) ($rowT['id_torneo'] ?? 0),
                (int) ($rowT['partida'] ?? 0)
            );
        }
    }
} catch (Throwable $e) {
}
$status_success = isset($_GET['status']) && $_GET['status'] === 'success';
$msg_success = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$msg_error = isset($_GET['error']) ? (string)$_GET['error'] : '';

$pageTitle = 'Panel de Torneo';
$desktopActive = 'panel';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3">
    <h2 class="h4 mb-3"><i class="fas fa-trophy text-primary me-2"></i>Panel de control (local)</h2>
    <p class="text-muted">Torneos e inscritos en la base SQLite. Mesas y puntajes en local.</p>

    <div class="alert alert-light border mb-3 d-flex align-items-center">
        <i class="fas fa-external-link-alt text-primary me-2"></i>
        <div>
            <strong>Panel por torneo (modelo web):</strong> mismo flujo que la web en 3 bloques (Gestión de Mesas, Operaciones, Resultados).
            <a href="panel_torneo.php" class="btn btn-sm btn-primary ms-2">Abrir panel por torneo</a>
        </div>
    </div>

    <div class="card mb-4 border-primary">
        <div class="card-header bg-light py-2"><strong>Ciclo de 8 pasos</strong> (todas las acciones guardan en SQLite local)</div>
        <div class="card-body py-2 small">
            <ol class="mb-0 list-inline">
                <li class="list-inline-item me-2"><a href="./crear_torneo.php">1. Crear Torneo</a></li>
                <li class="list-inline-item me-2"><a href="./inscribir.php">2. Inscripción</a></li>
                <li class="list-inline-item me-2">3. Generar Ronda (botón abajo; usa core/MesaAsignacionService)</li>
                <li class="list-inline-item me-2"><a href="./cuadricula.php">4. Cuadrícula por ID</a></li>
                <li class="list-inline-item me-2"><a href="./imprimir_hojas.php">5. Imprimir Hojas</a></li>
                <li class="list-inline-item me-2"><a href="./resultados.php">6. Ingresar Resultados</a></li>
                <li class="list-inline-item me-2">7. Clasificación (automática al guardar en 6)</li>
                <li class="list-inline-item me-2">8. Generar Ronda X+1 (bloqueado si hay mesas pendientes)</li>
            </ol>
        </div>
    </div>

    <?php if ($msg_error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($msg_error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php endif; ?>
    <?php if ($status_success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?= $msg_success ? htmlspecialchars($msg_success) : 'Mesa completada. Resultados guardados correctamente. El contador de mesas pendientes se ha actualizado.' ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-list me-2"></i>Torneos</h5>
                    <p class="mb-0 display-6"><?= count($torneos) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-users me-2"></i>Inscritos (total)</h5>
                    <p class="mb-0 display-6"><?= array_sum($inscritos_por_torneo) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-table me-2"></i>Mesas pendientes</h5>
                    <p class="mb-2 display-6" id="mesas-pendientes"><?= $mesas_pendientes ?></p>
                    <p class="mb-2 text-muted small">mesa(s) pendiente(s) de registrar</p>
                    <a href="./resultados.php" class="btn btn-primary btn-sm"><i class="fas fa-edit me-1"></i>Ingresar Resultados</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-light"><strong>Torneos locales</strong></div>
        <div class="card-body p-0">
            <div class="desktop-table-card-wrap">
                <table class="table table-hover mb-0">
                    <thead><tr><th>ID</th><th>Nombre</th><th>Fecha</th><th>Rondas</th><th>Modalidad</th><th>Inscritos</th><th>Estatus</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($torneos as $t):
                            $tid = (int)$t['id'];
                            $activo = (int)($t['estatus'] ?? 0) === 1;
                            $es_eq = ($torneo_estado[$tid]['es_equipos'] ?? false);
                        ?>
                        <tr>
                            <td data-label="ID"><?= $tid ?></td>
                            <td data-label="Nombre"><?= htmlspecialchars($t['nombre']) ?></td>
                            <td data-label="Fecha"><?= htmlspecialchars($t['fechator'] ?? '') ?></td>
                            <td data-label="Rondas"><?= (int)($t['rondas'] ?? 0) ?></td>
                            <td data-label="Modalidad"><?= $es_eq ? 'Equipos' : 'Individual' ?></td>
                            <td data-label="Inscritos"><?= $inscritos_por_torneo[$tid] ?? 0 ?></td>
                            <td data-label="Estatus">
                                <span class="badge bg-<?= $activo ? 'success' : 'secondary' ?> me-1"><?= $activo ? 'Activo' : 'Inactivo' ?></span>
                                <button type="button" class="btn btn-sm btn-outline-<?= $activo ? 'secondary' : 'success' ?> btn-toggle-estado" data-torneo-id="<?= $tid ?>" data-estatus="<?= $activo ? 0 : 1 ?>" data-nombre="<?= htmlspecialchars($t['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= $activo ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </td>
                            <td data-label="">
                                <a href="panel_torneo.php?torneo_id=<?= $tid ?>" class="btn btn-sm btn-outline-primary" title="Panel por torneo (modelo web)"><i class="fas fa-external-link-alt"></i> Panel</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($torneos)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No hay torneos. Importe desde la web.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header bg-light"><strong>Acciones de operaciones</strong></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="me-2 text-muted small">Torneo:</span>
                <select id="accion-torneo-id" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="0">-- Seleccione --</option>
                    <?php foreach ($torneos as $t):
                        $tid = (int)$t['id'];
                        $est = $torneo_estado[$tid] ?? [];
                    ?>
                    <option value="<?= $tid ?>" data-inscripciones="<?= !empty($est['inscripciones_bloqueado']) ? '1' : '0' ?>" data-generar="<?= !empty($est['generar_ronda_bloqueado']) ? '1' : '0' ?>" data-equipos="<?= !empty($est['es_equipos']) ? '1' : '0' ?>"><?= htmlspecialchars($t['nombre'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
                <form method="post" action="./generar_ronda.php" class="d-inline" id="formGenerarRonda">
                    <input type="hidden" name="torneo_id" id="hidden-torneo-id" value="">
                    <input type="hidden" name="estrategia_ronda2" value="separar">
                    <input type="hidden" name="estrategia_asignacion" value="secuencial">
                    <button type="submit" class="btn btn-outline-primary btn-sm" id="btnGenerarRonda" title="Se desbloquea cuando todas las mesas de la ronda actual están cargadas"><i class="fas fa-random me-1"></i>Generar Ronda</button>
                </form>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnActualizarEstadisticas" title="Actualiza estadísticas de inscritos desde partiresul"><i class="fas fa-calculator me-1"></i>Actualizar Estadísticas</button>
                <a href="./imprimir_hojas.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Imprimir Hojas</a>
                <a href="./inscribir.php" class="btn btn-outline-secondary btn-sm" id="btnInscripciones1" title="Paso 2: Inscripción al torneo (SQLite). Se bloquea según reglas del ciclo."><i class="fas fa-user-plus me-1"></i>Inscripciones</a>
                <a href="./inscribir.php" class="btn btn-outline-secondary btn-sm" id="btnInscripciones2" title="Paso 2: Inscripción al torneo (SQLite)."><i class="fas fa-edit me-1"></i>Inscr. en sitio</a>
            </div>
            <p class="text-muted small mt-2 mb-0">Generar Ronda: activo solo cuando todas las mesas de la ronda actual están cargadas. Inscripciones: individual = bloqueado desde 2ª ronda; equipos = bloqueado al iniciar torneo.</p>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header bg-light"><strong>Resultados / Reportes</strong></div>
        <div class="card-body">
            <p class="text-muted small mb-2">Seleccione un torneo arriba. Según modalidad se muestran reportes de equipos (resumido/detallado) o reporte general individual.</p>
            <div id="reportes-individual" class="reportes-tipo d-none">
                <a href="./reporte_resultados_general.php" class="btn btn-outline-secondary btn-sm me-2 link-reporte" data-base="reporte_resultados_general.php"><i class="fas fa-file-alt me-1"></i>Reporte general (individual)</a>
            </div>
            <div id="reportes-equipos" class="reportes-tipo d-none">
                <a href="./reporte_equipos_resumido.php" class="btn btn-outline-secondary btn-sm me-2 link-reporte" data-base="reporte_equipos_resumido.php"><i class="fas fa-list me-1"></i>Equipos resumido</a>
                <a href="./reporte_equipos_detallado.php" class="btn btn-outline-secondary btn-sm me-2 link-reporte" data-base="reporte_equipos_detallado.php"><i class="fas fa-th-list me-1"></i>Equipos detallado</a>
                <a href="./reporte_resultados_general.php" class="btn btn-outline-secondary btn-sm link-reporte" data-base="reporte_resultados_general.php"><i class="fas fa-file-alt me-1"></i>Reporte general</a>
            </div>
            <p id="reportes-sin-torneo" class="text-muted small mb-0">Seleccione un torneo en la sección de arriba para ver los enlaces de reportes.</p>
        </div>
    </div>
</div>
<div class="d-none" id="torneo-estado-json"><?= htmlspecialchars(json_encode($torneo_estado), ENT_QUOTES, 'UTF-8') ?></div>
<script>
(function() {
    document.querySelectorAll('.btn-toggle-estado').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-torneo-id');
            var estatus = this.getAttribute('data-estatus');
            var nombre = this.getAttribute('data-nombre') || '';
            if (!id || estatus === null) return;
            btn.disabled = true;
            fetch('./save_estado_torneo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ torneo_id: parseInt(id, 10), estatus: parseInt(estatus, 10) })
            }).then(function(r) { return r.json(); }).then(function(data) {
                if (data.ok) location.reload();
                else { alert(data.error || 'Error'); btn.disabled = false; }
            }).catch(function() { btn.disabled = false; });
        });
    });

    function actualizarMesasPendientes() {
        var el = document.getElementById('mesas-pendientes');
        if (!el) return;
        fetch('./pending_mesas.php').then(function(r) { return r.json(); }).then(function(data) {
            el.textContent = data.pendientes !== undefined ? data.pendientes : el.textContent;
        }).catch(function() {});
    }
    setInterval(actualizarMesasPendientes, 10000);

    var selTorneo = document.getElementById('accion-torneo-id');
    var hiddenTorneo = document.getElementById('hidden-torneo-id');
    var formGenerar = document.getElementById('formGenerarRonda');
    var btnGenerar = document.getElementById('btnGenerarRonda');
    var btnInsc1 = document.getElementById('btnInscripciones1');
    var btnInsc2 = document.getElementById('btnInscripciones2');
    var reportesInd = document.getElementById('reportes-individual');
    var reportesEq = document.getElementById('reportes-equipos');
    var reportesSin = document.getElementById('reportes-sin-torneo');

    function actualizarEstadoPorTorneo() {
        var opt = selTorneo ? selTorneo.options[selTorneo.selectedIndex] : null;
        var id = opt ? opt.value : '0';
        if (hiddenTorneo) hiddenTorneo.value = id;
        var inscripcionesBloq = opt ? opt.getAttribute('data-inscripciones') === '1' : true;
        var generarBloq = opt ? opt.getAttribute('data-generar') === '1' : true;
        var esEquipos = opt ? opt.getAttribute('data-equipos') === '1' : false;
        if (btnGenerar) btnGenerar.disabled = id === '0' || generarBloq;
        if (btnInsc1) {
            btnInsc1.classList.toggle('disabled', id !== '0' && inscripcionesBloq);
            btnInsc1.style.pointerEvents = (id !== '0' && inscripcionesBloq) ? 'none' : '';
        }
        if (btnInsc2) {
            btnInsc2.classList.toggle('disabled', id !== '0' && inscripcionesBloq);
            btnInsc2.style.pointerEvents = (id !== '0' && inscripcionesBloq) ? 'none' : '';
        }
        if (reportesInd) reportesInd.classList.add('d-none');
        if (reportesEq) reportesEq.classList.add('d-none');
        if (reportesSin) reportesSin.classList.remove('d-none');
        if (id !== '0') {
            if (reportesSin) reportesSin.classList.add('d-none');
            if (esEquipos && reportesEq) {
                reportesEq.classList.remove('d-none');
                reportesEq.querySelectorAll('.link-reporte').forEach(function(a) {
                    a.href = a.getAttribute('data-base') + '?torneo_id=' + encodeURIComponent(id);
                });
            } else if (!esEquipos && reportesInd) {
                reportesInd.classList.remove('d-none');
                reportesInd.querySelectorAll('.link-reporte').forEach(function(a) {
                    a.href = a.getAttribute('data-base') + '?torneo_id=' + encodeURIComponent(id);
                });
            }
        }
    }
    if (selTorneo) {
        selTorneo.addEventListener('change', actualizarEstadoPorTorneo);
        actualizarEstadoPorTorneo();
    }
    if (formGenerar && hiddenTorneo && selTorneo) {
        formGenerar.addEventListener('submit', function(e) {
            var id = selTorneo.value;
            hiddenTorneo.value = id;
            if (id === '0' || id === '') {
                e.preventDefault();
                alert('Seleccione un torneo');
                return false;
            }
            if (btnGenerar && btnGenerar.disabled) {
                e.preventDefault();
                alert('Generar Ronda está bloqueado hasta que todas las mesas de la ronda actual estén cargadas.');
                return false;
            }
        });
    }
    var btnEstadisticas = document.getElementById('btnActualizarEstadisticas');
    if (btnEstadisticas && selTorneo) {
        btnEstadisticas.addEventListener('click', function() {
            var id = selTorneo.value;
            if (id === '0' || id === '') {
                alert('Seleccione un torneo');
                return;
            }
            btnEstadisticas.disabled = true;
            fetch('./actualizar_estadisticas.php?torneo_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        location.href = 'panel_torneo.php?torneo_id=' + encodeURIComponent(id) + '&msg=estadisticas_actualizadas';
                    } else {
                        alert(data.error || 'Error');
                        btnEstadisticas.disabled = false;
                    }
                })
                .catch(function() {
                    location.href = './actualizar_estadisticas.php?torneo_id=' + encodeURIComponent(id);
                });
        });
    }
})();
</script>
</main></body></html>
