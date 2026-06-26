<?php
/**
 * Resumen individual del jugador (público).
 * Muestra toda la trayectoria de partidas con toda la información una por una.
 * Acceso: resumen_jugador.php?torneo_id=X&id_usuario=Y
 */

// Evitar caché en dispositivos para que siempre se vea la versión actual
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/ResumenParticipacionHelper.php';
require_once __DIR__ . '/../lib/ResultadosAsociacionContext.php';
require_once __DIR__ . '/includes/branding_init.php';

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$id_usuario = isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : 0;

if ($torneo_id <= 0 || $id_usuario <= 0) {
    $base = rtrim(AppHelpers::getPublicUrl(), '/');
    header('Location: ' . $base . '/landing-spa.php');
    exit;
}

$pdo = DB::pdo();
$orgCtx = ResultadosAsociacionContext::fromGet($pdo);
$torneo = null;
$inscrito = null;
$resumen = [];
$partidas = [];

try {
    $stmt = $pdo->prepare("SELECT id, nombre, fechator, COALESCE(modalidad, 1) AS modalidad FROM tournaments WHERE id = ? AND estatus = 1");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        header('Location: ' . rtrim(AppHelpers::getPublicUrl(), '/') . '/landing-spa.php');
        exit;
    }

    if ($orgCtx->isScoped() && ! $orgCtx->torneoPertenece($pdo, $torneo_id)) {
        header('Location: ' . $orgCtx->urlEventos());
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT i.*, COALESCE(u.nombre, u.username) AS nombre_completo, u.cedula, c.nombre AS club_nombre
        FROM inscritos i
        LEFT JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ? AND i.id_usuario = ?
        AND (i.estatus IN (1, 2, '1', '2', 'confirmado', 'solvente'))
        LIMIT 1
    ");
    $stmt->execute([$torneo_id, $id_usuario]);
    $inscrito = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inscrito) {
        header('Location: ' . rtrim(AppHelpers::getPublicUrl(), '/') . '/clasificacion.php' . $orgCtx->buildQs(['torneo_id' => $torneo_id]));
        exit;
    }

    $resumen = [
        'nombre' => $inscrito['nombre_completo'] ?? '',
        'cedula' => $inscrito['cedula'] ?? '',
        'club' => $inscrito['club_nombre'] ?? '—',
        'puntos' => (int) ($inscrito['puntos'] ?? 0),
        'efectividad' => (int) ($inscrito['efectividad'] ?? 0),
        'ganados' => (int) ($inscrito['ganados'] ?? 0),
        'perdidos' => (int) ($inscrito['perdidos'] ?? 0),
        'ptosrnk' => (int) ($inscrito['ptosrnk'] ?? 0),
    ];

    $stmt = $pdo->prepare("
        SELECT partida, mesa, secuencia, resultado1, resultado2, efectividad, ff, tarjeta, sancion, chancleta, zapato, observaciones, registrado
        FROM partiresul
        WHERE id_torneo = ? AND id_usuario = ?
        ORDER BY partida ASC, CAST(mesa AS UNSIGNED) ASC
    ");
    $stmt->execute([$torneo_id, $id_usuario]);
    $partidas_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $partidas = [];
    foreach ($partidas_raw as $p) {
        $mesa = (int)$p['mesa'];
        $sec = (int)($p['secuencia'] ?? 0);
        $r1 = (int)($p['resultado1'] ?? 0);
        $r2 = (int)($p['resultado2'] ?? 0);
        $compañero = '';
        $contrario1 = '';
        $contrario2 = '';
        $ganada = 0;
        if ($mesa > 0) {
            $joinU = ResumenParticipacionHelper::sqlJoinUsuariosDesdePartiresul('pr', 'u', 'LEFT');
            $exprNom = ResumenParticipacionHelper::sqlExprNombreUsuarioPartiresul('u', 'pr');
            $stmt_mesa = $pdo->prepare("
                SELECT pr.id_usuario, pr.secuencia, {$exprNom} AS nombre
                FROM partiresul pr
                {$joinU}
                WHERE pr.id_torneo = ? AND pr.partida = ? AND CAST(pr.mesa AS SIGNED) = ?
                ORDER BY pr.secuencia ASC
            ");
            $stmt_mesa->execute([$torneo_id, $p['partida'], $p['mesa']]);
            $en_mesa = $stmt_mesa->fetchAll(PDO::FETCH_ASSOC);
            $idsProp = ResumenParticipacionHelper::normalizarIdsPartiresulJugador((int) $id_usuario, (int) $id_usuario, 0);
            $resMesa = ResumenParticipacionHelper::resolverCompaneroYContrarios($en_mesa, $idsProp, $sec);
            if ($resMesa['companero'] !== null) {
                $compañero = $resMesa['companero']['nombre'];
            } else {
                $hist = ResumenParticipacionHelper::buscarCompaneroEnHistorialParejas($pdo, $torneo_id, (int) $p['partida'], $idsProp);
                if ($hist !== null) {
                    $compañero = $hist['nombre'];
                }
            }
            $idxC = 0;
            foreach ($resMesa['contrarios'] as $cont) {
                if ($idxC === 0) {
                    $contrario1 = $cont['nombre'];
                } else {
                    $contrario2 = $cont['nombre'];
                }
                $idxC++;
            }
            $ganada = (in_array($sec, [1, 2]) && $r1 > $r2) || (in_array($sec, [3, 4]) && $r2 > $r1) ? 1 : 0;
        }
        $p['compañero'] = $compañero ?: '—';
        $p['contrario1'] = $contrario1 ?: '—';
        $p['contrario2'] = $contrario2 ?: '—';
        $p['ganada'] = $ganada;
        $partidas[] = $p;
    }
} catch (Throwable $e) {
    error_log('resumen_jugador.php: ' . $e->getMessage());
}

$base_public = rtrim(AppHelpers::getPublicUrl(), '/');
$url_retorno = $orgCtx->isScoped()
    ? $orgCtx->urlEventoResultados($torneo_id)
    : $base_public . '/clasificacion.php?torneo_id=' . $torneo_id;
$logo_url = AppHelpers::getAppLogo();
$torneo_nombre = $torneo['nombre'] ?? 'Torneo';
$nombre_jugador = $resumen['nombre'] ?? $inscrito['nombre_completo'] ?? '—';
$esModalidadEquipos = (int)($torneo['modalidad'] ?? 1) === 3;
$posicion = ResumenParticipacionHelper::resolverPosicionMostrada($inscrito, $esModalidadEquipos);
$sum_resultado1 = $sum_resultado2 = $sum_efectividad = 0;
foreach ($partidas as $p) {
    $sum_resultado1 += (int)($p['resultado1'] ?? 0);
    $sum_resultado2 += (int)($p['resultado2'] ?? 0);
    $sum_efectividad += (int)($p['efectividad'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0f172a">
    <title>Resumen — <?= htmlspecialchars($nombre_jugador) ?> · <?= htmlspecialchars($torneo_nombre) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            font-size: 15px;
            padding: 12px;
            padding-bottom: 2rem;
        }
        .wrap { max-width: 480px; margin: 0 auto; }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .header img { height: 36px; width: auto; }
        .btn-retorno {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .btn-retorno:hover { background: #334155; color: #38bdf8; }
        h1 { font-size: 1.1rem; margin: 0 0 4px 0; color: #94a3b8; font-weight: 600; }
        .sub { font-size: 0.85rem; color: #64748b; margin-bottom: 16px; }
        .card {
            background: #1e293b;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 14px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .card h2 { font-size: 0.95rem; color: #94a3b8; margin: 0 0 12px 0; font-weight: 600; }
        .info-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .info-row:last-child { border-bottom: 0; }
        .info-label { color: #94a3b8; }
        .info-value { font-weight: 500; }
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 12px;
        }
        .stat-box {
            text-align: center;
            padding: 14px 10px;
            border-radius: 10px;
            background: rgba(255,255,255,0.05);
        }
        .stat-box .num { font-size: 1.4rem; font-weight: 700; display: block; }
        .stat-box .lbl { font-size: 0.75rem; color: #94a3b8; margin-top: 2px; }
        .stat-box.primary .num { color: #38bdf8; }
        .stat-box.success .num { color: #4ade80; }
        .stat-box.danger .num { color: #f87171; }
        .stat-box.warning .num { color: #fbbf24; }
        .page-title { text-align: center; font-size: 1.25rem; font-weight: 700; margin: 0 0 16px 0; color: #f1f5f9; }
        .stats-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px; background: rgba(255,255,255,0.06); border-radius: 10px; border: 1px solid rgba(255,255,255,0.08); }
        .stats-row .stat-item { display: flex; align-items: baseline; gap: 6px; }
        .stats-row .stat-item .label { font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; }
        .stats-row .stat-item .value { font-size: 1rem; font-weight: 700; color: #f1f5f9; }
        .stats-row.stats-row-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .stats-row.stats-row-2 .stat-item { flex-direction: column; align-items: center; gap: 2px; padding: 8px; background: rgba(0,0,0,0.15); border-radius: 8px; }
        .stats-row.stats-row-2 .stat-item .value { font-size: 1.1rem; }
        @media (min-width: 360px) { .stats-row.stats-row-2 { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 480px) { .stats-row.stats-row-2 { grid-template-columns: repeat(5, 1fr); } }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -12px 12px; padding: 0 12px; }
        table { width: 100%; min-width: 560px; border-collapse: collapse; font-size: 0.8rem; }
        th, td { padding: 8px 6px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); }
        th { color: #94a3b8; font-weight: 600; font-size: 0.75rem; white-space: nowrap; }
        td { color: #f1f5f9; }
        td.num, th.num { text-align: center; }
        .tfoot-row { background: rgba(34, 197, 94, 0.2); font-weight: 700; }
        .tfoot-row td { padding: 10px 6px; color: #f1f5f9; }
        .empty { text-align: center; padding: 2rem; color: #64748b; }
        .nombre-cell { max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (min-width: 481px) {
            body { padding: 20px; }
            .wrap { box-shadow: 0 0 0 1px rgba(255,255,255,0.06); border-radius: 16px; padding: 20px; background: #0f172a; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="header">
            <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn-retorno"><i class="fas fa-arrow-left"></i> Retorno</a>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($brand_name) ?>">
        </header>

        <h1 class="page-title">Resumen Individual</h1>
        <p class="sub" style="text-align: center;"><?= htmlspecialchars($torneo_nombre) ?></p>

        <div class="card">
            <!-- Fila 1: ID y Nombre -->
            <div class="stats-row">
                <div class="stat-item"><span class="label">Número</span><span class="value"><?= (int)($id_usuario) ?></span></div>
                <div class="stat-item"><span class="label">Nombre</span><span class="value"><?= htmlspecialchars($nombre_jugador) ?></span></div>
            </div>
            <!-- Fila 2: Posición, Ganados, Perdidos, Efectividad, Puntos -->
            <div class="stats-row stats-row-2">
                <div class="stat-item"><span class="label">Posición</span><span class="value"><?= $posicion ?: '—' ?></span></div>
                <div class="stat-item"><span class="label">Ganados</span><span class="value"><?= (int)($resumen['ganados'] ?? 0) ?></span></div>
                <div class="stat-item"><span class="label">Perdidos</span><span class="value"><?= (int)($resumen['perdidos'] ?? 0) ?></span></div>
                <div class="stat-item"><span class="label">Efectividad</span><span class="value"><?= (int)($resumen['efectividad'] ?? 0) ?></span></div>
                <div class="stat-item"><span class="label">Puntos</span><span class="value"><?= (int)($resumen['puntos'] ?? 0) ?></span></div>
            </div>

            <!-- Tabla trayectoria de partidas -->
            <?php if (empty($partidas)): ?>
                <p class="empty">Aún no hay partidas registradas.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="num">Partida</th>
                                <th class="num">Mesa</th>
                                <th>Compañero</th>
                                <th>Contrario 1</th>
                                <th>Contrario 2</th>
                                <th class="num">R1</th>
                                <th class="num">R2</th>
                                <th class="num">Efectiv.</th>
                                <th class="num">Ganados</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 0; foreach ($partidas as $p): $n++;
                                $mesa_raw = $p['mesa'] ?? 0;
                                $mesa = (int)$mesa_raw;
                                $es_bye = ($mesa === 0 || $mesa_raw === '0' || (string)$mesa_raw === '0');
                            ?>
                            <tr>
                                <td class="num"><?= $n ?></td>
                                <td class="num"><?= $es_bye ? 'BYE' : $mesa ?></td>
                                <td class="nombre-cell" title="<?= htmlspecialchars($p['compañero'] ?? '—') ?>"><?= htmlspecialchars($p['compañero'] ?? '—') ?></td>
                                <td class="nombre-cell" title="<?= htmlspecialchars($p['contrario1'] ?? '—') ?>"><?= htmlspecialchars($p['contrario1'] ?? '—') ?></td>
                                <td class="nombre-cell" title="<?= htmlspecialchars($p['contrario2'] ?? '—') ?>"><?= htmlspecialchars($p['contrario2'] ?? '—') ?></td>
                                <td class="num"><?= (int)($p['resultado1'] ?? 0) ?></td>
                                <td class="num"><?= (int)($p['resultado2'] ?? 0) ?></td>
                                <td class="num"><?= (int)($p['efectividad'] ?? 0) ?></td>
                                <td class="num"><?= (int)($p['ganada'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="tfoot-row">
                                <td colspan="5" class="num"><strong>TOTALES / SUMAS</strong></td>
                                <td class="num"><?= $sum_resultado1 ?></td>
                                <td class="num"><?= $sum_resultado2 ?></td>
                                <td class="num"><?= $sum_efectividad ?></td>
                                <td class="num"><?= (int)($resumen['ganados'] ?? 0) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
