<?php
/**
 * Registro de Resultados (Desktop) — Replica del formulario web con sidebar y tabla completa.
 * Carga: torneo, rondas, mesas (completadas/pendientes), jugadores de la mesa actual.
 * POST a save_resultados.php (formato jugadores[] o resultados[]).
 */
declare(strict_types=1);
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';
require_once __DIR__ . '/../../desktop/core/db_bridge.php';

$pdo = DB_Local::pdo();
$entidad_id = DB::getEntidadId();
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$ronda = isset($_GET['partida']) ? (int)$_GET['partida'] : 0;
$mesa_actual = isset($_GET['mesa']) ? (int)$_GET['mesa'] : 0;
$error_message = isset($_GET['error']) ? (string)$_GET['error'] : '';
$success_message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'resultados_guardados') $success_message = 'Resultados guardados correctamente.';
    elseif ($_GET['msg'] === 'reasignado') $success_message = 'Mesa reasignada correctamente.';
}

$torneo = null;
$todasLasRondas = [];
$todasLasMesas = [];
$jugadores = [];
$observacionesMesa = '';
$mesasCompletadas = 0;
$mesasPendientes = 0;
$totalMesas = 0;
$mesaAnterior = null;
$mesaSiguiente = null;
$tabla_partiresul_existe = false;

$ent_sql = $entidad_id > 0 ? ' AND pr.entidad_id = ?' : '';
$ent_sql_pr = $entidad_id > 0 ? ' AND entidad_id = ?' : '';
$ent_bind = $entidad_id > 0 ? [$entidad_id] : [];

try {
    $tabla_partiresul_existe = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='partiresul' LIMIT 1")->fetch();
} catch (Throwable $e) {
}

if ($torneo_id > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nombre, puntos FROM tournaments WHERE id = ?");
        $st->execute([$torneo_id]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

if ($torneo_id > 0 && $tabla_partiresul_existe) {
    try {
        $st = $pdo->prepare("SELECT DISTINCT partida FROM partiresul WHERE id_torneo = ?" . $ent_sql_pr . " ORDER BY partida ASC");
        $st->execute(array_merge([$torneo_id], $ent_bind));
        $todasLasRondas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }

    if ($ronda === 0 && !empty($todasLasRondas)) {
        $primera = (int)($todasLasRondas[0]['partida'] ?? 0);
        if ($primera > 0) {
            header('Location: captura.php?torneo_id=' . $torneo_id . '&partida=' . $primera);
            exit;
        }
    }

    if ($ronda > 0) {
        $st = $pdo->prepare("
            SELECT pr.mesa as numero, MAX(pr.registrado) as registrado
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0" . $ent_sql . "
            GROUP BY pr.mesa
            ORDER BY CAST(pr.mesa AS INTEGER) ASC
        ");
        $st->execute(array_merge([$torneo_id, $ronda], $ent_bind));
        $raw_mesas = $st->fetchAll(PDO::FETCH_ASSOC);
        $todasLasMesas = [];
        foreach ($raw_mesas as $m) {
            $num = (int)$m['numero'];
            if ($num > 0) {
                $todasLasMesas[] = [
                    'numero' => $num,
                    'registrado' => (int)($m['registrado'] ?? 0),
                    'tiene_resultados' => ((int)($m['registrado'] ?? 0)) > 0,
                ];
            }
        }
        usort($todasLasMesas, function ($a, $b) { return $a['numero'] - $b['numero']; });

        $mesasExistentes = array_column($todasLasMesas, 'numero');
        $maxMesa = !empty($mesasExistentes) ? max($mesasExistentes) : 0;
        if ($mesa_actual > 0 && !in_array($mesa_actual, $mesasExistentes, true) && !empty($mesasExistentes)) {
            $mesa_actual = min($mesasExistentes);
        }
        if ($mesa_actual === 0 && !empty($mesasExistentes)) {
            $mesa_actual = min($mesasExistentes);
        }
        if ($mesa_actual > $maxMesa && $maxMesa > 0) {
            $mesa_actual = $maxMesa;
        }

        if ($mesa_actual > 0) {
            $sql = "SELECT pr.id, pr.id_usuario, pr.secuencia, pr.resultado1, pr.resultado2, pr.sancion, pr.ff, pr.tarjeta, pr.chancleta, pr.zapato, pr.observaciones,
                    u.nombre as nombre_completo,
                    i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos as puntos_acumulados, i.sancion as sancion_acumulada
                    FROM partiresul pr
                    INNER JOIN usuarios u ON pr.id_usuario = u.id
                    LEFT JOIN inscritos i ON i.id_usuario = u.id AND i.torneo_id = pr.id_torneo
                    WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?" . $ent_sql . "
                    ORDER BY pr.secuencia ASC";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$torneo_id, $ronda, $mesa_actual], $ent_bind));
            $jugadores = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($jugadores as &$j) {
                $j['inscrito'] = [
                    'posicion' => (int)($j['posicion'] ?? 0),
                    'ganados' => (int)($j['ganados'] ?? 0),
                    'perdidos' => (int)($j['perdidos'] ?? 0),
                    'efectividad' => (int)($j['efectividad'] ?? 0),
                    'puntos' => (int)($j['puntos_acumulados'] ?? 0),
                ];
            }
            unset($j);
            if (!empty($jugadores)) {
                $observacionesMesa = trim((string)($jugadores[0]['observaciones'] ?? ''));
            }
        }

        $mesasCompletadas = 0;
        foreach ($todasLasMesas as $m) {
            if (!empty($m['tiene_resultados'])) $mesasCompletadas++;
        }
        $totalMesas = count($todasLasMesas);
        $mesasPendientes = $totalMesas - $mesasCompletadas;

        foreach ($todasLasMesas as $idx => $m) {
            if ((int)$m['numero'] === $mesa_actual) {
                if ($idx > 0) $mesaAnterior = (int)$todasLasMesas[$idx - 1]['numero'];
                if ($idx < count($todasLasMesas) - 1) $mesaSiguiente = (int)$todasLasMesas[$idx + 1]['numero'];
                break;
            }
        }
    }
}

$pageTitle = 'Registro de Resultados';
$desktopActive = 'panel';
require_once __DIR__ . '/desktop_layout.php';

if ($error_message) {
    echo '<div class="container-fluid py-2"><div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div></div>';
}
if ($success_message) {
    echo '<div class="container-fluid py-2"><div class="alert alert-success">' . htmlspecialchars($success_message) . '</div></div>';
}
if (!$tabla_partiresul_existe) {
    echo '<div class="container-fluid py-3"><div class="alert alert-warning">No existe la tabla partiresul. Genere rondas desde Gestión de Rondas.</div></div>';
} elseif ($torneo_id <= 0) {
    echo '<div class="container-fluid py-3"><div class="alert alert-info">Seleccione un torneo desde el panel (Ingresar Resultados con torneo ya elegido).</div></div>';
} else {
?>
<style>
.registrar-resultados-wrap { width: 96%; max-width: 100%; margin: 0 auto; }
.mesa-item { transition: all 0.2s; cursor: pointer; padding: 0.5rem 0.75rem !important; font-size: 0.9rem; }
.mesa-item:hover { transform: translateX(4px); }
.mesa-activa { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: bold; }
.mesa-completada { background: #10b981; color: white; }
.mesa-pendiente { background: #f59e0b; color: white; }
.tarjeta-btn { width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; border-radius: 0.5rem; border: 2px solid #666; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; background: #fff !important; }
.tarjeta-btn:hover { transform: scale(1.1); }
.tarjeta-btn.activo { border-width: 3px; box-shadow: 0 0 0 2px #000; }
.sidebar-sticky { position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; }
.lista-mesas-scroll { max-height: min(60vh, calc(10 * 2.35rem)); overflow-y: auto; }
#formResultados tbody tr td { padding: 0.35rem 0.5rem; vertical-align: middle; }
.columna-id { width: 3rem; }
.columna-puntos { min-width: 5rem; }
</style>

<div class="container-fluid registrar-resultados-wrap py-2">
    <div class="row align-items-start">
        <!-- Sidebar: Navegación de Partidas -->
        <div class="col-md-2 col-lg-2" id="sidebar-mesas">
            <div class="card sidebar-sticky border shadow-sm">
                <div class="card-header py-2" style="background-color: #1565c0; color: #fff;">
                    <h6 class="mb-0"><i class="fas fa-clipboard-list me-1"></i>Navegación de Partidas</h6>
                    <?php if ($torneo): ?>
                    <small class="d-block mt-1 opacity-90"><?= htmlspecialchars($torneo['nombre'] ?? '') ?></small>
                    <?php endif; ?>
                </div>
                <div class="card-body p-2 border-bottom bg-light">
                    <label class="form-label small mb-1">Seleccionar Partida/Ronda:</label>
                    <select id="selector-ronda" class="form-select form-select-sm" onchange="cambiarRonda(this.value)">
                        <?php foreach ($todasLasRondas as $r): ?>
                        <option value="<?= (int)($r['partida'] ?? 0) ?>" <?= ($r['partida'] ?? 0) == $ronda ? 'selected' : '' ?>>Ronda <?= (int)($r['partida'] ?? 0) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="card-body p-2 border-bottom bg-light">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="badge bg-success px-2 py-2 rounded"><?= $mesasCompletadas ?> Completadas</span>
                        <span class="badge bg-warning text-dark px-2 py-2 rounded"><?= $mesasPendientes ?> Pendientes</span>
                    </div>
                    <div class="small text-muted">Total: <strong><?= $totalMesas ?></strong> mesas</div>
                </div>
                <div class="card-body p-2">
                    <h6 class="small fw-bold mb-2">Mesas (Ronda <?= $ronda ?>)</h6>
                    <div class="<?= count($todasLasMesas) > 10 ? 'lista-mesas-scroll' : '' ?>">
                        <div class="list-group list-group-flush">
                            <?php foreach ($todasLasMesas as $m): 
                                $esActiva = (int)$m['numero'] === $mesa_actual;
                                $clase = $esActiva ? 'mesa-activa' : ($m['tiene_resultados'] ? 'mesa-completada' : 'mesa-pendiente');
                            ?>
                            <a href="captura.php?torneo_id=<?= $torneo_id ?>&partida=<?= $ronda ?>&mesa=<?= (int)$m['numero'] ?>" 
                               class="mesa-item list-group-item list-group-item-action <?= $clase ?> rounded mb-1 text-decoration-none">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong>Mesa <?= (int)$m['numero'] ?></strong>
                                    <i class="far fa-circle small"></i>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Área principal: Registro de Resultados -->
        <div class="col-md-10 col-lg-10">
            <div class="card border shadow-sm">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2" style="background-color: #e3f2fd; color: #1565c0;">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Registro de Resultados</h5>
                        <span class="badge bg-primary">Ronda <?= $ronda ?></span>
                        <span class="badge bg-success">Mesa <?= $mesa_actual ?></span>
                        <div class="input-group input-group-sm" style="width: auto;">
                            <label for="input_ir_mesa" class="input-group-text mb-0">Ir a Mesa Nro:</label>
                            <input type="number" id="input_ir_mesa" class="form-control" style="width: 5rem;" value="<?= $mesa_actual ?>" min="1" max="<?= !empty($todasLasMesas) ? max(array_column($todasLasMesas, 'numero')) : 1 ?>" step="1" inputmode="numeric" aria-label="Número de mesa" onkeydown="if(event.key==='Enter'){ event.preventDefault(); irAMesa(); }">
                        </div>
                        <?php if (!empty($jugadores) && count($jugadores) === 4): ?>
                        <a href="reasignar_mesa.php?torneo_id=<?= $torneo_id ?>&partida=<?= $ronda ?>&mesa=<?= $mesa_actual ?>" class="btn btn-sm" style="background-color: #20c997; color: white;"><i class="fas fa-exchange-alt me-1"></i>Reasignar Mesa</a>
                        <?php endif; ?>
                        <?php if ($torneo): ?>
                        <span class="text-muted small"><?= htmlspecialchars($torneo['nombre'] ?? '') ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="panel_torneo.php?torneo_id=<?= $torneo_id ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Panel</a>
                </div>
                <div class="card-body">

                    <?php if (empty($jugadores) || count($jugadores) !== 4): ?>
                    <div class="alert alert-warning">No se encontraron los 4 jugadores de esta mesa. Seleccione otra mesa o genere las rondas.</div>
                    <?php else: ?>
                    <!-- Solo se guardan los valores que el operador introduce o selecciona explícitamente (puntos, sanción, falta, tarjeta, pena, observaciones). -->
                    <form method="POST" action="save_resultados.php" id="formResultados" onsubmit="return syncPuntosAntesDeEnviar();">
                        <input type="hidden" name="guardar_resultados" value="1">
                        <input type="hidden" name="torneo_id" value="<?= $torneo_id ?>">
                        <input type="hidden" name="partida" value="<?= $ronda ?>">
                        <input type="hidden" name="mesa" value="<?= $mesa_actual ?>">
                        <input type="hidden" name="formato" value="jugadores">

                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-sm">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center columna-id">Pareja</th>
                                        <th class="columna-nombre">Nombre</th>
                                        <th class="text-center columna-puntos">Puntos</th>
                                        <th class="text-center">Sanción</th>
                                        <th class="text-center">Falta</th>
                                        <th class="text-center">Tarjeta</th>
                                        <th class="text-center">Pena</th>
                                        <th class="text-center small">Estadísticas (Pos | Gan | Per | Efect)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $parejaA = [];
                                    $parejaB = [];
                                    foreach ($jugadores as $j) {
                                        if ((int)($j['secuencia'] ?? 0) <= 2) $parejaA[] = $j;
                                        else $parejaB[] = $j;
                                    }
                                    $indice = 0;
                                    foreach ([$parejaA, $parejaB] as $parejaKey => $pareja):
                                        $letraPareja = $parejaKey === 0 ? 'A' : 'B';
                                        $puntosPareja = $parejaKey === 0 ? (int)($pareja[0]['resultado1'] ?? 0) : (int)($pareja[0]['resultado1'] ?? 0);
                                    ?>
                                    <?php foreach ($pareja as $idx => $jugador): 
                                        $i = $indice;
                                        $indice++;
                                    ?>
                                    <tr class="<?= $letraPareja === 'A' ? 'table-info' : 'table-success' ?>">
                                        <td class="text-center fw-bold"><?= (int)($jugador['id_usuario'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A') ?></td>
                                        <?php if ($idx === 0): ?>
                                        <td rowspan="2" class="text-center align-middle">
                                            <input type="number" id="puntos_pareja_<?= $letraPareja ?>" class="form-control form-control-sm text-center fw-bold" 
                                                   value="<?= $puntosPareja ?>" min="0" max="999"
                                                   oninput="distribuirPuntos('<?= $letraPareja ?>');"
                                                   onchange="distribuirPuntos('<?= $letraPareja ?>');">
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <input type="number" name="jugadores[<?= $i ?>][sancion]" class="form-control form-control-sm text-center" 
                                                   value="<?= min((int)($jugador['sancion'] ?? 0), 80) ?>" min="0" max="80">
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="jugadores[<?= $i ?>][ff]" value="1" <?= !empty($jugador['ff']) ? 'checked' : '' ?>>
                                        </td>
                                        <td class="text-center">
                                            <?php $tv = (int)($jugador['tarjeta'] ?? 0); ?>
                                            <div class="d-flex justify-content-center gap-1">
                                                <button type="button" class="tarjeta-btn <?= $tv === 1 ? 'activo' : '' ?>" data-t="1" data-i="<?= $i ?>" title="Amarilla">🟨</button>
                                                <button type="button" class="tarjeta-btn <?= $tv === 3 ? 'activo' : '' ?>" data-t="3" data-i="<?= $i ?>" title="Roja">🟥</button>
                                                <button type="button" class="tarjeta-btn <?= $tv === 4 ? 'activo' : '' ?>" data-t="4" data-i="<?= $i ?>" title="Negra">⬛</button>
                                                <input type="hidden" name="jugadores[<?= $i ?>][tarjeta]" id="tarjeta_<?= $i ?>" value="<?= $tv ?>">
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <label class="me-2"><input type="radio" name="pena_<?= $i ?>" value="chancleta" class="form-check-input" <?= !empty($jugador['chancleta']) ? 'checked' : '' ?>> 🥿</label>
                                            <label class="mb-0"><input type="radio" name="pena_<?= $i ?>" value="zapato" class="form-check-input" <?= !empty($jugador['zapato']) ? 'checked' : '' ?>> 👞</label>
                                            <input type="hidden" name="jugadores[<?= $i ?>][chancleta]" id="chancleta_<?= $i ?>" value="<?= (int)($jugador['chancleta'] ?? 0) ?>">
                                            <input type="hidden" name="jugadores[<?= $i ?>][zapato]" id="zapato_<?= $i ?>" value="<?= (int)($jugador['zapato'] ?? 0) ?>">
                                        </td>
                                        <td class="text-center small">
                                            <?= (int)($jugador['inscrito']['posicion'] ?? 0) ?> | <?= (int)($jugador['inscrito']['ganados'] ?? 0) ?> | <?= (int)($jugador['inscrito']['perdidos'] ?? 0) ?> | <?= (int)($jugador['inscrito']['efectividad'] ?? 0) ?>
                                        </td>
                                        <input type="hidden" name="jugadores[<?= $i ?>][id]" value="<?= (int)($jugador['id'] ?? 0) ?>">
                                        <input type="hidden" name="jugadores[<?= $i ?>][id_usuario]" value="<?= (int)($jugador['id_usuario'] ?? 0) ?>">
                                        <input type="hidden" name="jugadores[<?= $i ?>][secuencia]" value="<?= (int)($jugador['secuencia'] ?? 0) ?>">
                                        <input type="hidden" name="jugadores[<?= $i ?>][resultado1]" id="resultado1_<?= $i ?>" value="<?= (int)($jugador['resultado1'] ?? 0) ?>">
                                        <input type="hidden" name="jugadores[<?= $i ?>][resultado2]" id="resultado2_<?= $i ?>" value="<?= (int)($jugador['resultado2'] ?? 0) ?>">
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Observaciones</label>
                            <textarea id="observaciones" name="observaciones" class="form-control" rows="2" placeholder="Observaciones sobre la partida (opcional)"><?= htmlspecialchars($observacionesMesa) ?></textarea>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex gap-2 align-items-center">
                                <?php if ($mesaAnterior !== null): ?>
                                <a href="captura.php?torneo_id=<?= $torneo_id ?>&partida=<?= $ronda ?>&mesa=<?= $mesaAnterior ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Mesa Anterior</a>
                                <?php endif; ?>
                                <?php if ($mesaSiguiente !== null): ?>
                                <a href="captura.php?torneo_id=<?= $torneo_id ?>&partida=<?= $ronda ?>&mesa=<?= $mesaSiguiente ?>" class="btn btn-primary btn-sm">Mesa Siguiente <i class="fas fa-arrow-right ms-1"></i></a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-warning btn-sm" onclick="limpiarFormulario()"><i class="fas fa-broom me-1"></i>Limpiar</button>
                                <button type="button" class="btn btn-sm" style="background-color: #667eea; color: white;" onclick="buscarMesa()"><i class="fas fa-search me-1"></i>Buscar Mesa</button>
                            </div>
                            <button type="submit" id="btn_guardar_resultados" class="btn btn-success"><i class="fas fa-save me-1"></i>GUARDAR</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function cambiarRonda(v) {
    var url = new URL(window.location.href);
    url.searchParams.set('partida', v);
    url.searchParams.delete('mesa');
    window.location = url.toString();
}
function irAMesa() {
    var inp = document.getElementById('input_ir_mesa');
    if (!inp) return;
    var val = (inp.value && String(inp.value).trim()) ? String(inp.value).trim() : '';
    var n = parseInt(val, 10);
    var max = parseInt(inp.getAttribute('max'), 10) || 999;
    if (isNaN(n) || n < 1 || n > max) return;
    var url = new URL(window.location.href);
    url.searchParams.set('mesa', n);
    url.searchParams.delete('msg');
    url.searchParams.delete('error');
    window.location = url.toString();
}
function buscarMesa() {
    var inp = document.getElementById('input_ir_mesa');
    if (inp) { inp.focus(); inp.select(); }
}
function syncPuntosAntesDeEnviar() {
    distribuirPuntos('todas');
    return true;
}
function distribuirPuntos(pareja) {
    if (pareja === 'todas') { distribuirPuntos('A'); distribuirPuntos('B'); return; }
    var puntosA = parseInt(document.getElementById('puntos_pareja_A').value, 10) || 0;
    var puntosB = parseInt(document.getElementById('puntos_pareja_B').value, 10) || 0;
    if (pareja === 'A') {
        document.getElementById('resultado1_0').value = document.getElementById('resultado1_1').value = puntosA;
        document.getElementById('resultado2_0').value = document.getElementById('resultado2_1').value = puntosB;
        document.getElementById('resultado1_2').value = document.getElementById('resultado1_3').value = puntosB;
        document.getElementById('resultado2_2').value = document.getElementById('resultado2_3').value = puntosA;
    } else {
        document.getElementById('resultado1_2').value = document.getElementById('resultado1_3').value = puntosB;
        document.getElementById('resultado2_2').value = document.getElementById('resultado2_3').value = puntosA;
        document.getElementById('resultado1_0').value = document.getElementById('resultado1_1').value = puntosA;
        document.getElementById('resultado2_0').value = document.getElementById('resultado2_1').value = puntosB;
    }
}
document.querySelectorAll('.tarjeta-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var i = this.getAttribute('data-i');
        var t = parseInt(this.getAttribute('data-t'), 10);
        if (i === null) return;
        document.querySelectorAll('.tarjeta-btn[data-i="' + i + '"]').forEach(function(b) { b.classList.remove('activo'); });
        this.classList.add('activo');
        var hid = document.getElementById('tarjeta_' + i);
        if (hid) hid.value = t;
    });
});
document.querySelectorAll('input[name^="pena_"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var name = this.name;
        var i = name.replace('pena_', '');
        var ch = document.getElementById('chancleta_' + i);
        var z = document.getElementById('zapato_' + i);
        if (ch) ch.value = this.value === 'chancleta' ? 1 : 0;
        if (z) z.value = this.value === 'zapato' ? 1 : 0;
    });
});
function limpiarFormulario() {
    var f = document.getElementById('formResultados');
    if (!f) return;
    document.getElementById('puntos_pareja_A').value = 0;
    document.getElementById('puntos_pareja_B').value = 0;
    distribuirPuntos('todas');
    f.querySelectorAll('input[name$="[sancion]"]').forEach(function(el) { el.value = 0; });
    f.querySelectorAll('input[type="checkbox"]').forEach(function(el) { el.checked = false; });
    f.querySelectorAll('.tarjeta-btn').forEach(function(b) { b.classList.remove('activo'); });
    f.querySelectorAll('input[id^="tarjeta_"]').forEach(function(h) { h.value = 0; });
    var obs = document.getElementById('observaciones');
    if (obs) obs.value = '';
    var ch = f.querySelectorAll('input[id^="chancleta_"]');
    var z = f.querySelectorAll('input[id^="zapato_"]');
    ch.forEach(function(el) { el.value = 0; });
    z.forEach(function(el) { el.value = 0; });
    f.querySelectorAll('input[name^="pena_"]').forEach(function(r) { r.checked = false; });
}
// Ciclo Enter establecido solo: 1 Búsqueda → 2 Resultado A → 3 Resultado B → 4 GUARDAR. Sanciones y demás se usan a solicitud del operador (clic o Tab).
function siguienteEnCicloEnter(ev) {
    if (ev.key !== 'Enter') return;
    var t = ev.target;
    var busqueda = document.getElementById('input_ir_mesa');
    var pa = document.getElementById('puntos_pareja_A');
    var pb = document.getElementById('puntos_pareja_B');
    var btnGuardar = document.getElementById('btn_guardar_resultados');
    var orden = [];
    if (busqueda) orden.push(busqueda);
    if (pa) orden.push(pa);
    if (pb) orden.push(pb);
    if (btnGuardar) orden.push(btnGuardar);
    var idx = orden.indexOf(t);
    if (idx === -1) return;
    ev.preventDefault();
    if (idx === 0 && t === busqueda) {
        irAMesa();
        return;
    }
    if (idx < orden.length - 1) {
        orden[idx + 1].focus();
        if (orden[idx + 1].select) orden[idx + 1].select();
    } else {
        if (orden[idx].type === 'submit') orden[idx].click();
    }
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name^="pena_"]').forEach(function(radio) {
        if (radio.checked) radio.dispatchEvent(new Event('change'));
    });
    var params = new URLSearchParams(window.location.search);
    var vieneDeGuardar = params.get('msg') === 'resultados_guardados';
    if (vieneDeGuardar) {
        var busqueda = document.getElementById('input_ir_mesa');
        if (busqueda) {
            busqueda.focus();
            busqueda.select();
        }
        var pa = document.getElementById('puntos_pareja_A');
        var pb = document.getElementById('puntos_pareja_B');
        if (pa) pa.value = 0;
        if (pb) pb.value = 0;
        distribuirPuntos('todas');
    } else {
        var firstPuntos = document.getElementById('puntos_pareja_A');
        if (firstPuntos) firstPuntos.focus();
    }
    document.addEventListener('keydown', function(ev) {
        var t = ev.target;
        if (t.id === 'input_ir_mesa' || t.id === 'puntos_pareja_A' || t.id === 'puntos_pareja_B' || t.id === 'btn_guardar_resultados') {
            siguienteEnCicloEnter(ev);
        }
    });
});
</script>
<?php
}
?>
</main></body></html>
