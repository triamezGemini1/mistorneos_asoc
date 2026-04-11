<?php
/**
 * Vista: Cuadrícula de Asignaciones
 * Rejilla: 8 segmentos (IDEN|MESA) × 12 filas datos = 96 jugadores/página; grid 13 filas (cabecera + datos).
 * Llenado vertical por segmento: índice en bloque = segmento * filas_datos + fila.
 * Celdas: resources/views/tournament/partials/grid_display.php (foreach $cuad_paginas + bucles internos).
 * Estilos 10": public/assets/css/custom-13inch.css (.matrix-header 5vh, .matrix-row 6.8vh).
 */
if (!isset($base_url) || !isset($use_standalone)) {
    $script_actual = basename($_SERVER['PHP_SELF'] ?? '');
    $use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
    $base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
}

$letras = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];

if (!isset($asignaciones) || !is_array($asignaciones)) {
    $asignaciones = [];
}
if (!isset($torneo) || !is_array($torneo)) {
    $torneo = ['id' => 0, 'nombre' => 'Torneo'];
}
$context_switcher = isset($context_switcher) && is_array($context_switcher)
    ? $context_switcher
    : ['active_tournament_id' => (int)($torneo['id'] ?? 0), 'items' => []];
$activeContextName = (string)($torneo['nombre'] ?? 'Torneo');
$activeContextViewId = (int)($torneo['id'] ?? 0);
if (!empty($context_switcher['items']) && is_array($context_switcher['items'])) {
    $activeContextId = (int)($context_switcher['active_tournament_id'] ?? ($torneo['id'] ?? 0));
    foreach ($context_switcher['items'] as $ctxItem) {
        if ((int)($ctxItem['id'] ?? 0) === $activeContextId) {
            $activeContextName = (string)($ctxItem['nombre'] ?? $activeContextName);
            $activeContextViewId = (int)($ctxItem['id'] ?? $activeContextViewId);
            break;
        }
    }
}

$totalInscritos = isset($totalInscritos)
    ? (int) $totalInscritos
    : (isset($totalAsignaciones) ? (int) $totalAsignaciones : 0);

$map_max_partida_switch = isset($map_max_partida_switch) && is_array($map_max_partida_switch)
    ? $map_max_partida_switch
    : [];

/** 8 pares × 12 filas datos = 96 celdas jugador/página (16 columnas + cabecera = 13 filas en grid) */
$cuad_filas_datos = 12; // debe coincidir con grid_display.php y 12 filas de datos + 1 cabecera en CSS
$cuad_pares = 8;
$claseGrilla = 'grilla-pantalla';
$es_modalidad_equipos_v3 = (int)($torneo['modalidad'] ?? 0) === 3;
$usarNumfvd = !$es_modalidad_equipos_v3 && (int)($torneo['club_responsable'] ?? 0) === 7;

$listaPlana = [];
if (!empty($asignaciones) && is_array($asignaciones)) {
    foreach ($asignaciones as $asignacion) {
        $mesaRaw = $asignacion['mesa'] ?? 0;
        $mesa = (int) $mesaRaw;
        $secuencia = (int) ($asignacion['secuencia'] ?? 0);
        $letra = $letras[$secuencia] ?? '';
        $esBye = ($mesa === 0 || $mesaRaw === '0' || $mesaRaw === 0);
        $idMostrar = $usarNumfvd
            ? (int)($asignacion['numfvd'] ?? 0)
            : (int)($asignacion['id_usuario'] ?? 0);
        if ($idMostrar <= 0) {
            $idMostrar = (int)($asignacion['id_usuario'] ?? 0);
        }
        $listaPlana[] = [
            'id' => (string) $idMostrar,
            'bye' => $esBye,
            'mesa_num' => $esBye ? null : $mesa,
            'mesa_letra' => $esBye ? '' : $letra,
        ];
    }
}

$cuad_cap = $cuad_filas_datos * $cuad_pares;
if (empty($listaPlana)) {
    $cuad_paginas = [[]];
} else {
    $cuad_paginas = array_chunk($listaPlana, $cuad_cap);
}

require_once __DIR__ . '/../../lib/app_helpers.php';
$cuad_url_panel = $base_url . ($use_standalone ? '?' : '&') . 'action=panel&torneo_id=' . (int) ($torneo['id'] ?? 0);
$href_custom_13 = AppHelpers::url('assets/css/custom-13inch.css');
$href_torneo_context_switch = AppHelpers::url('assets/css/torneo-context-switch.css');
$pageTitle = isset($titulo) ? (string) $titulo : ('Cuadrícula - Ronda ' . (int) ($numRonda ?? 0));
?>
<!DOCTYPE html>
<html lang="es" class="cuadricula-scroll-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href_custom_13, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href_torneo_context_switch, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        @media print {
            .no-print { display: none !important; }
            html.cuadricula-scroll-root, html.cuadricula-scroll-root body {
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
            }
            .cuadricula-shell { height: auto !important; max-height: none !important; overflow: visible !important; }
        }
        /* Equipos V3: cabecera compacta, sin desbordar 1366×768 */
        body.cuadricula-equipos-v3 .cuadricula-header-torneo { font-size: 0.8rem; line-height: 1.2; max-width: min(52vw, 520px); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        body.cuadricula-equipos-v3 .cuadricula-header { flex-wrap: nowrap; gap: 4px; }
        body.cuadricula-equipos-v3 .cuadricula-header-toolbar .tcs { flex-shrink: 0; min-width: 0; }
        @media (max-width: 1366px) and (max-height: 800px) {
            body.cuadricula-equipos-v3 .cuadricula-header .btn-sm { font-size: 0.7rem; padding: 0.2rem 0.45rem; }
        }
        /* Una sola fila: título torneo+ronda | píldoras | Imprimir, alineados al centro vertical */
        .cuadricula-header-toolbar {
            gap: 0.5rem 0.75rem;
        }
        .cuadricula-header-toolbar .cuadricula-tcs-pills {
            margin-right: 0 !important;
        }
        .cuadricula-header-toolbar .cuadricula-header-actions {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            flex-shrink: 0;
        }
    </style>
</head>
<body class="page-cuadricula-10<?php echo $es_modalidad_equipos_v3 ? ' cuadricula-equipos-v3' : ''; ?>">
    <div class="cuadricula-shell">
        <div class="cuadricula-header no-print d-flex align-items-center flex-wrap w-100 cuadricula-header-toolbar">
            <span class="cuadricula-header-torneo align-middle" style="min-width:0;">
                <?php echo htmlspecialchars(strtoupper($torneo['nombre'] ?? 'Torneo'), ENT_QUOTES, 'UTF-8'); ?>
                — RONDA <?php echo (int) ($numRonda ?? 0); ?>
            </span>
            <?php if (!empty($context_switcher['items'])): ?>
                <?php
                $tcs = [
                    'items' => $context_switcher['items'],
                    'active_id' => (int) ($context_switcher['active_tournament_id'] ?? 0),
                    'base_url' => $base_url,
                    'sep' => $use_standalone ? '?' : '&',
                    'ronda_base' => (int) ($numRonda ?? 0),
                    'map_max' => $map_max_partida_switch,
                    'mode' => 'cuadricula',
                    'theme' => 'on_dark',
                    'show_select' => false,
                    'show_info' => false,
                    'show_pill_meta' => false,
                    'pill_row_class' => 'cuadricula-tcs-pills',
                ];
                require __DIR__ . '/../../resources/views/partials/torneo_context_switch.php';
                ?>
            <?php endif; ?>
            <div class="cuadricula-header-actions no-print">
                <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="fas fa-print mr-2"></i> Imprimir
                </button>
                <a href="<?php echo htmlspecialchars($cuad_url_panel, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary btn-sm" title="Ir al panel de control">
                    <i class="fas fa-arrow-left mr-1"></i> Volver
                </a>
            </div>
        </div>
        <div class="cuadricula-meta no-print" id="cuadriculaMeta" aria-live="polite"></div>
        <?php
        // Rejilla IDEN|MESA: parcial (foreach $cuad_paginas, segmentos, celdas matrix-cell)
        include __DIR__ . '/../../resources/views/tournament/partials/grid_display.php';
        ?>
    </div>
    <script>
(function () {
    var ROTACION_MS = 10 * 1000;
    var series = document.querySelectorAll('.cuadricula-serie');
    var meta = document.getElementById('cuadriculaMeta');
    var idx = 0;

    function formatearRestanteCuad(ms) {
        var s = Math.max(0, Math.ceil(ms / 1000));
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    function mostrarSerie(i) {
        for (var j = 0; j < series.length; j++) {
            series[j].classList.toggle('is-hidden-screen', j !== i);
        }
    }

    if (series.length > 1) {
        var deadline = Date.now() + ROTACION_MS;
        function tick() {
            var left = deadline - Date.now();
            if (left <= 0) {
                idx = (idx + 1) % series.length;
                mostrarSerie(idx);
                deadline = Date.now() + ROTACION_MS;
                left = ROTACION_MS;
            }
            if (meta) {
                meta.textContent = 'Página ' + (idx + 1) + ' de ' + series.length
                    + ' · siguiente en ' + formatearRestanteCuad(left) + ' (mm:ss)';
            }
        }
        tick();
        setInterval(tick, 1000);
        mostrarSerie(0);
    } else if (meta) {
        meta.textContent = '';
    }

    var grid = document.querySelector('.cuadricula-matrix-grid');
    if (!grid) return;

    function clearHover() {
        var active = grid.querySelectorAll('.matrix-cell.is-row-hover');
        for (var i = 0; i < active.length; i++) active[i].classList.remove('is-row-hover');
    }

    grid.addEventListener('mouseover', function (ev) {
        var cell = ev.target.closest('.matrix-cell[data-row]');
        if (!cell || !grid.contains(cell)) return;
        clearHover();
        var row = cell.getAttribute('data-row');
        var rowCells = grid.querySelectorAll('.matrix-cell[data-row="' + row + '"]');
        for (var i = 0; i < rowCells.length; i++) rowCells[i].classList.add('is-row-hover');
    });
    grid.addEventListener('mouseleave', clearHover);

})();
    </script>
</body>
</html>
