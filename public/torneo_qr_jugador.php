<?php
declare(strict_types=1);
/**
 * Vista móvil por QR: token corto (torneo + id jugador) → mesa actual, resumen, clasificación.
 * Parámetros: t=token [&ronda=N] [&fmt=json] (json solo actualiza bloque mesa).
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/TorneoJugadorQrToken.php';
require_once __DIR__ . '/../lib/PublicTorneoPortalHelper.php';
require_once __DIR__ . '/../lib/PublicInfoTorneoMesasService.php';
require_once __DIR__ . '/../lib/TorneoQrJugadorMesaPartial.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$decoded = $token !== '' ? TorneoJugadorQrToken::decode($token) : null;

if ($decoded === null) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Enlace no válido</title></head><body style="font-family:sans-serif;padding:1.2rem;max-width:28rem;margin:auto;">';
    echo '<p>El enlace del código QR no es válido o está incompleto.</p></body></html>';
    exit;
}

$torneo_id = $decoded['torneo_id'];
$id_usuario = $decoded['id_usuario'];
$pdo = DB::pdo();

$torneo = PublicTorneoPortalHelper::getTorneoParaQrJugador($pdo, $torneo_id);
if (!$torneo) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Torneo</title></head><body style="font-family:sans-serif;padding:1.2rem;">';
    echo '<p>El torneo no está disponible.</p></body></html>';
    exit;
}

if (!PublicInfoTorneoMesasService::estaInscrito($pdo, $torneo_id, $id_usuario)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso</title></head><body style="font-family:sans-serif;padding:1.2rem;">';
    echo '<p>No figura como inscrito activo en este torneo.</p></body></html>';
    exit;
}

$rondas_disponibles = PublicInfoTorneoMesasService::rondasConDatos($pdo, $torneo_id);
if ($rondas_disponibles === []) {
    $rondas_disponibles = range(1, max(1, (int) ($torneo['rondas'] ?? 1)));
}
$default_ronda = PublicInfoTorneoMesasService::ultimaRondaConPartidas($pdo, $torneo_id);
if (!in_array($default_ronda, $rondas_disponibles, true)) {
    $rondas_disponibles[] = $default_ronda;
    sort($rondas_disponibles, SORT_NUMERIC);
}

$ronda = (int) ($_GET['ronda'] ?? 0);
if ($ronda <= 0 || !in_array($ronda, $rondas_disponibles, true)) {
    $ronda = $default_ronda;
}

$modalidad = (int) ($torneo['modalidad'] ?? 0);
$es_equipos = ($modalidad === 3);
$viewerId = $id_usuario;

$asignacion = PublicInfoTorneoMesasService::resumenAsignacion($pdo, $torneo_id, $ronda, $viewerId);
$mesa_html = TorneoQrJugadorMesaPartial::renderBody($asignacion, $viewerId, $es_equipos, $ronda);

if (($_GET['fmt'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'ronda' => $ronda,
        'mesa_html' => $mesa_html,
        'updated_at' => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$resumen_pack = null;
try {
    $resumen_pack = PublicTorneoPortalHelper::fetchResumenParticipacion($pdo, $torneo_id, $viewerId);
} catch (Throwable $e) {
    error_log('torneo_qr_jugador resumen: ' . $e->getMessage());
    $resumen_pack = ['jugador' => [], 'resumen' => [], 'partidas' => [], 'posicion' => 0];
}
$jug = $resumen_pack['jugador'] ?? [];
$res = $resumen_pack['resumen'] ?? [];
$mi_codigo_equipo = (string) ($jug['codigo_equipo'] ?? '');

$clasificacion_rows = [];
$ranking_equipos = [];
if ($es_equipos) {
    require_once __DIR__ . '/../lib/Tournament/Handlers/TeamPerformanceHandler.php';
    try {
        $ranking_equipos = \Tournament\Handlers\TeamPerformanceHandler::getRankingPorEquipos($torneo_id, 'resumido');
    } catch (Throwable $e) {
        $ranking_equipos = [];
    }
} else {
    $st = $pdo->prepare(
        'SELECT i.id_usuario, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.ptosrnk,
                COALESCE(u.nombre, u.username) AS nombre_jugador, c.nombre AS club_nombre
         FROM inscritos i
         LEFT JOIN usuarios u ON i.id_usuario = u.id
         LEFT JOIN clubes c ON i.id_club = c.id
         WHERE i.torneo_id = ?
         AND (i.estatus IN (1, 2, \'1\', \'2\', \'confirmado\', \'solvente\'))
         ORDER BY i.ptosrnk DESC, i.efectividad DESC, i.ganados DESC, i.puntos DESC, i.id_usuario ASC'
    );
    $st->execute([$torneo_id]);
    $clasificacion_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$public_base = rtrim(AppHelpers::getPublicUrl(), '/');
$page_url = $public_base . '/torneo_qr_jugador.php?' . http_build_query(['t' => $token, 'ronda' => $ronda]);
$url_resumen_full = $public_base . '/resumen_jugador.php?' . http_build_query(['torneo_id' => $torneo_id, 'id_usuario' => $viewerId]);
$url_clas = $public_base . '/clasificacion.php?' . http_build_query(['torneo_id' => $torneo_id, 'highlight_user' => $viewerId]);
$nombre_torneo = (string) ($torneo['nombre'] ?? '');
$nombre_yo = (string) ($jug['nombre'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5">
    <meta name="theme-color" content="#0c4a6e">
    <title><?= htmlspecialchars($nombre_torneo, ENT_QUOTES, 'UTF-8') ?> — Mi mesa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #f0f9ff;
            --card: #fff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #bae6fd;
            --accent: #0284c7;
            --yo: #b91c1c;
            --max: 520px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 12px 12px 88px;
            line-height: 1.45;
        }
        .wrap { max-width: var(--max); margin: 0 auto; }
        h1 { font-size: 1.05rem; margin: 0 0 4px; }
        .yo-line {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--yo);
            margin-bottom: 12px;
        }
        .yo-line small { display: block; font-weight: 600; color: var(--muted); font-size: 0.82rem; margin-top: 4px; }
        .card {
            background: var(--card);
            border-radius: 14px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 14px rgba(3, 105, 161, 0.08);
            margin-bottom: 14px;
            overflow: hidden;
        }
        .card-head {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            padding: 10px 14px;
            font-weight: 800;
            font-size: 0.95rem;
        }
        .card-body { padding: 12px 14px; }
        .panel { display: none; }
        .panel.active { display: block; }
        .ronda-select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 2px solid #cbd5e1;
            font-size: 1rem;
            margin-bottom: 10px;
        }
        .alert-warn { padding: 10px; border-radius: 10px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .bye-box { text-align: center; padding: 10px; }
        .mesa-num { margin: 0 0 8px; font-weight: 800; color: #0369a1; font-size: 1.05rem; }
        .pareja-tit { font-weight: 700; margin: 8px 0 4px; font-size: 0.9rem; }
        .pareja-tit.a { color: #0369a1; }
        .pareja-tit.b { color: #047857; }
        ul.jugadores { list-style: none; margin: 0; padding: 0; }
        ul.jugadores li {
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 1rem;
        }
        ul.jugadores li:last-child { border-bottom: none; }
        ul.jugadores li.info-mesa-yo {
            color: var(--yo);
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .club-hint { font-size: 0.85em; color: var(--muted); font-weight: 500; }
        .resultados { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 0.9rem; }
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 10px 0; }
        .stat-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .stat-box .k { font-size: 0.72rem; color: var(--muted); text-transform: uppercase; }
        .stat-box .v { font-size: 1.2rem; font-weight: 800; color: #0369a1; }
        .stat-box.yo .v { color: var(--yo); }
        .tab-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -4px; }
        table.data-tab { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        table.data-tab th, table.data-tab td {
            padding: 8px 6px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }
        table.data-tab th { background: #f1f5f9; font-weight: 700; }
        tr.tab-yo { background: #fef2f2; color: var(--yo); font-weight: 800; }
        tr.eq-yo { background: #fef2f2; color: var(--yo); font-weight: 800; }
        .bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 100;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: center;
            padding: 10px 8px calc(10px + env(safe-area-inset-bottom));
            background: rgba(255,255,255,0.97);
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 16px rgba(15,23,42,0.08);
        }
        .bn-btn {
            flex: 1 1 calc(50% - 8px);
            min-width: 140px;
            max-width: 240px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px 10px;
            font-size: 0.88rem;
            font-weight: 800;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            color: #fff;
            background: var(--accent);
        }
        .bn-btn.secondary { background: #64748b; }
        .bn-btn.outline { background: #e0f2fe; color: #0c4a6e; border: 2px solid #0284c7; }
        .bn-btn:disabled { opacity: 0.6; cursor: wait; }
        .link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            margin-top: 10px;
            font-weight: 800;
            border-radius: 12px;
            background: #0ea5e9;
            color: #fff;
            text-decoration: none;
        }
        .link-btn.alt { background: #0f172a; }
    </style>
</head>
<body>
<div class="wrap">
    <h1><i class="fas fa-chess-board"></i> <?= htmlspecialchars($nombre_torneo, ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="yo-line">
        <?= htmlspecialchars($nombre_yo !== '' ? $nombre_yo : 'Jugador', ENT_QUOTES, 'UTF-8') ?>
        <small>ID jugador <?= (int) $viewerId ?><?= (int) ($torneo['locked'] ?? 0) === 1 ? ' · Torneo finalizado (solo lectura)' : '' ?></small>
    </div>

    <div id="panel-mesa" class="panel active">
        <div class="card">
            <div class="card-head"><i class="fas fa-map-pin"></i> Tu mesa</div>
            <div class="card-body">
                <form method="get" action="" id="form-ronda">
                    <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <label for="sel-ronda" class="visually-hidden">Ronda</label>
                    <select class="ronda-select" id="sel-ronda" name="ronda" onchange="this.form.submit()">
                        <?php foreach ($rondas_disponibles as $rn): ?>
                            <option value="<?= (int) $rn ?>"<?= (int) $rn === (int) $ronda ? ' selected' : '' ?>>Ronda <?= (int) $rn ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div id="mesa-live"><?= $mesa_html ?></div>
            </div>
        </div>
    </div>

    <div id="panel-resumen" class="panel">
        <div class="card">
            <div class="card-head"><i class="fas fa-chart-line"></i> Resumen rápido</div>
            <div class="card-body">
                <div class="stat-grid">
                    <div class="stat-box yo"><span class="k">Posición</span><div class="v"><?= (int) ($res['posicion'] ?? $resumen_pack['posicion'] ?? 0) ?>º</div></div>
                    <div class="stat-box"><span class="k">Puntos</span><div class="v"><?= (int) ($res['puntos'] ?? 0) ?></div></div>
                    <div class="stat-box"><span class="k">Efectividad</span><div class="v"><?= (int) ($res['efectividad'] ?? 0) ?>%</div></div>
                    <div class="stat-box"><span class="k">G / P</span><div class="v"><?= (int) ($res['ganados'] ?? 0) ?> / <?= (int) ($res['perdidos'] ?? 0) ?></div></div>
                </div>
                <a class="link-btn" href="<?= htmlspecialchars($url_resumen_full, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-external-link-alt"></i> Resumen completo del jugador</a>
            </div>
        </div>
    </div>

    <div id="panel-clas" class="panel">
        <div class="card">
            <div class="card-head"><i class="fas fa-trophy"></i> <?= $es_equipos ? 'Resultados por equipos' : 'Clasificación general' ?></div>
            <div class="card-body">
                <?php if ($es_equipos): ?>
                    <div class="tab-wrap">
                        <table class="data-tab">
                            <thead><tr><th>Pos</th><th>Equipo</th><th>Club</th><th>Pts</th></tr></thead>
                            <tbody>
                            <?php foreach ($ranking_equipos as $eq):
                                $cod = (string) ($eq['codigo_equipo'] ?? '');
                                $yoEq = ($mi_codigo_equipo !== '' && $cod === $mi_codigo_equipo);
                            ?>
                                <tr class="<?= $yoEq ? 'eq-yo' : '' ?>">
                                    <td><?= (int) ($eq['posicion'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars((string) ($eq['nombre_equipo'] ?? $cod), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($eq['club_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) ($eq['puntos'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="tab-wrap">
                        <table class="data-tab">
                            <thead><tr><th>#</th><th>Jugador</th><th>Pts</th><th>Ef.</th></tr></thead>
                            <tbody>
                            <?php
                            $idx = 0;
                            foreach ($clasificacion_rows as $row):
                                $idx++;
                                $uidr = (int) ($row['id_usuario'] ?? 0);
                                $yo = ($uidr === $viewerId);
                            ?>
                                <tr class="<?= $yo ? 'tab-yo' : '' ?>">
                                    <td><?= $idx ?></td>
                                    <td><?= htmlspecialchars((string) ($row['nombre_jugador'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int) ($row['puntos'] ?? 0) ?></td>
                                    <td><?= (int) ($row['efectividad'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <a class="link-btn alt" href="<?= htmlspecialchars($url_clas, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-list-ol"></i> Ver página completa de clasificación</a>
            </div>
        </div>
    </div>
</div>

<nav class="bottom-nav" aria-label="Acciones">
    <button type="button" class="bn-btn outline" data-panel="mesa"><i class="fas fa-map-pin"></i> Mesa</button>
    <button type="button" class="bn-btn outline" data-panel="resumen"><i class="fas fa-user"></i> Resumen</button>
    <button type="button" class="bn-btn outline" data-panel="clas"><i class="fas fa-trophy"></i> <?= $es_equipos ? 'Equipos' : 'Clasif.' ?></button>
    <button type="button" class="bn-btn secondary" id="btn-refresh" title="Actualizar datos de la mesa"><i class="fas fa-sync-alt"></i> Actualizar</button>
</nav>

<style>.visually-hidden { position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0; }</style>
<script>
(function () {
    var token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var panels = document.querySelectorAll('.panel');
    function showPanel(name) {
        panels.forEach(function (p) { p.classList.toggle('active', p.id === 'panel-' + name); });
        document.querySelectorAll('.bottom-nav [data-panel]').forEach(function (b) {
            b.style.opacity = b.getAttribute('data-panel') === name ? '1' : '0.75';
        });
    }
    document.querySelectorAll('.bottom-nav [data-panel]').forEach(function (btn) {
        btn.addEventListener('click', function () { showPanel(btn.getAttribute('data-panel')); });
    });
    var sel = document.getElementById('sel-ronda');
    var mesaLive = document.getElementById('mesa-live');
    var refreshBtn = document.getElementById('btn-refresh');
    function refreshMesa() {
        if (!sel || !mesaLive) return;
        var ronda = sel.value;
        refreshBtn.disabled = true;
        var url = 'torneo_qr_jugador.php?fmt=json&t=' + encodeURIComponent(token) + '&ronda=' + encodeURIComponent(ronda);
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && data.mesa_html) {
                    mesaLive.innerHTML = data.mesa_html;
                }
            })
            .catch(function () {})
            .finally(function () { refreshBtn.disabled = false; });
    }
    refreshBtn.addEventListener('click', function (e) {
        e.preventDefault();
        refreshMesa();
    });
})();
</script>
</body>
</html>
