<?php
/**
 * Vista: Hojas de Anotación para Imprimir
 * Tamaño: Carta
 * Estructura: Pareja AC (sec 1,2) vs Pareja BD (sec 3,4)
 * Secuencia a letra: 1=A, 2=C, 3=B, 4=D
 */
if (!defined('APP_BOOTSTRAPPED') && file_exists(__DIR__ . '/../../config/bootstrap.php')) {
    require_once __DIR__ . '/../../config/bootstrap.php';
}
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}
require_once __DIR__ . '/../../lib/QrMesaTokenHelper.php';
require_once __DIR__ . '/../../lib/GestionTorneosViewsData.php';
$href_torneo_context_switch = AppHelpers::url('assets/css/torneo-context-switch.css');
$letras_secuencia = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];
// URL base: interfaz móvil para carga de actas (formato requerido: ?t=&m=&r=&token=)
$url_dominio_public = rtrim(function_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (function_exists('app_base_url') ? rtrim(app_base_url(), '/') . '/public' : ''), '/');
$url_carga_publica_base = $url_dominio_public . '/public_mesa_input.php';
if (!isset($base_url) || !isset($use_standalone)) {
    $script_actual = basename($_SERVER['PHP_SELF'] ?? '');
    $use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
    $base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
}
$ctx_switch_base_url = function_exists('torneoGestionContextSwitchBaseUrl') ? torneoGestionContextSwitchBaseUrl() : $base_url;
$context_switcher = isset($context_switcher) && is_array($context_switcher)
    ? $context_switcher
    : ['active_tournament_id' => (int)($torneo['id'] ?? 0), 'items' => []];
$map_max_partida_switch = isset($map_max_partida_switch) && is_array($map_max_partida_switch)
    ? $map_max_partida_switch
    : [];
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hojas de Anotación - Ronda <?php echo $ronda; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($href_torneo_context_switch, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            overflow-y: auto;
            height: auto;
        }
        
        @page {
            size: letter;
            margin: 0.5in;
        }
        
        :root {
            --hojas-header-h: 48px;
            /* Margen para barra con píldoras en 1–2 líneas (nombres largos) */
            --hojas-toolbar-h: 68px;
            --hojas-fixed-stack: calc(var(--hojas-header-h) + var(--hojas-toolbar-h));
            /* 90% del ancho carta: encaja mejor en impresión/PDF */
            --hoja-ancho: calc(8.5in * 0.9);
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            overflow-y: auto;
            height: auto;
            padding: 20px;
            padding-top: calc(var(--hojas-fixed-stack) + 16px);
        }
        
        .contenedor-hojas {
            max-height: none;
            overflow: visible;
        }
        
        .no-print {
            text-align: center;
            margin-bottom: 20px;
            padding: 20px;
            background: white;
        }
        
        .btn-flotante {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            display: flex;
            gap: 10px;
        }
        
        .btn-flotante button,
        .btn-flotante a {
            padding: 14px 20px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-flotante button:hover,
        .btn-flotante a:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.4);
        }
        
        .btn-imprimir {
            background: #3b82f6;
            color: white;
        }
        
        .btn-imprimir:hover {
            background: #2563eb;
        }
        
        .btn-volver {
            background: #6b7280;
            color: white;
        }
        
        .btn-volver:hover {
            background: #4b5563;
        }
        
        .hojas-fixed-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1002;
            min-height: var(--hojas-header-h);
            padding: 0 16px;
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            overflow: hidden;
        }
        .hojas-fixed-header__title {
            flex-shrink: 0;
            color: #374151;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            font-size: 12px;
        }
        .hojas-fixed-header__torneo {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .hojas-fixed-header__meta {
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
            color: #4b5563;
            font-weight: 700;
            font-size: 14px;
        }

        .selector-mesas {
            position: fixed;
            top: var(--hojas-header-h);
            left: 0;
            right: 0;
            z-index: 1001;
            min-height: var(--hojas-toolbar-h);
            padding: 10px 16px;
            background: #fafafa;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: flex-start;
            gap: 14px;
            width: 100%;
            max-width: none;
            border-radius: 0;
            box-sizing: border-box;
        }

        /* Píldoras visibles (como cuadrícula / registrar) + selector de hoja */
        .selector-mesas__group {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 14px 16px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

        .selector-mesas__ir {
            display: inline-flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .selector-mesas label {
            font-weight: 600;
            color: #374151;
            white-space: nowrap;
            margin: 0;
        }

        .selector-mesas select {
            padding: 8px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            min-width: 160px;
            max-width: min(42vw, 280px);
            cursor: pointer;
            background: #fff;
        }

        .selector-mesas select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* Ocupa el espacio libre: ambas píldoras legibles, sin recorte horizontal */
        .selector-mesas__group > .tcs {
            flex: 1 1 280px;
            min-width: min(100%, 240px);
            max-width: none;
            width: auto;
            margin-left: 0;
            display: flex !important;
            flex-wrap: wrap;
            align-items: stretch;
            justify-content: flex-start;
            align-content: center;
            gap: 8px;
            overflow: visible;
        }
        .selector-mesas__group > .tcs .tcs__pill {
            flex: 1 1 200px;
            min-width: min(100%, 11rem);
            max-width: none !important;
            box-sizing: border-box;
        }
        .selector-mesas__group .tcs.tcs--hojas-inline .tcs__pill-name {
            font-size: 12px;
            line-height: 1.35;
            word-break: break-word;
            hyphens: auto;
        }

        .selector-mesas__group .tcs.tcs--hojas-inline {
            padding: 4px 6px;
            gap: 8px;
            border-radius: 12px;
        }

        @media (max-width: 1366px) {
            :root {
                --hojas-header-h: 44px;
                --hojas-toolbar-h: 80px;
            }
            .hojas-fixed-header { font-size: 14px; padding: 0 12px; }
            .hojas-fixed-header__title { font-size: 11px; }
            .selector-mesas { gap: 10px; padding: 6px 12px; }
            .selector-mesas label { font-size: 13px; }
            .selector-mesas select { min-width: 130px; max-width: min(50vw, 240px); font-size: 13px; padding: 6px 10px; }
            .tcs-info { font-size: 11px; }
        }
        
        .hoja-mesa {
            background: white;
            border: none;
            padding: 20px;
            margin-bottom: 30px;
            page-break-after: always;
            width: var(--hoja-ancho);
            min-height: 11in;
            max-width: var(--hoja-ancho);
            margin-left: auto;
            margin-right: auto;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        /* QR flotante/transparente en el centro, con info alrededor */
        .hoja-mesa .zona-qr {
            margin-bottom: 15px;
        }
        .hoja-mesa .qr-mesa {
            opacity: 0.9;
        }
        .hoja-mesa .qr-mesa a {
            text-decoration: none;
            display: inline-block;
            padding: 4px;
            background: rgba(255,255,255,0.7);
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.06);
        }
        .hoja-mesa .qr-mesa img {
            width: 100px;
            height: 100px;
            display: block;
        }
        
        .hoja-mesa:last-child {
            page-break-after: auto;
        }
        
        /* Header: Nombre del Torneo + Ronda y Mesa a la derecha */
        .header-torneo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 22px;
            font-weight: bold;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        .header-torneo .nombre-torneo {
            flex: 1;
        }
        .header-ronda-mesa {
            font-size: 18px;
            font-weight: bold;
            white-space: nowrap;
            margin-left: 15px;
        }
        /* Línea con jugadores y QR en el centro - info alrededor del QR */
        .linea-con-qr {
            display: flex;
            justify-content: space-between;
            align-items: stretch;
            gap: 15px;
            margin-top: 12px;
        }
        .linea-con-qr .col-izq, .linea-con-qr .col-der {
            flex: 1;
            min-width: 0;
        }
        .linea-con-qr .col-qr {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 120px;
        }
        .estadisticas-solo-izq, .estadisticas-solo-der {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 15px;
            margin-bottom: 6px;
            font-size: 11px;
        }
        .clubs-solo-izq, .clubs-solo-der {
            font-size: 12px;
            font-style: italic;
            padding-top: 4px;
            border-top: 1px solid #ccc;
        }

        /* Modalidad parejas: bloque unificado por lado (impresión) */
        .linea-con-qr--parejas .col-izq--parejas,
        .linea-con-qr--parejas .col-der--parejas {
            align-self: flex-start;
        }
        .hojas-bloque-pareja {
            font-size: 13px;
            line-height: 1.4;
            text-align: left;
        }
        .hojas-bloque-pareja--der {
            text-align: right;
        }
        .hojas-pareja-titulo {
            font-weight: 800;
            font-size: 14px;
            margin-bottom: 6px;
            letter-spacing: 0.02em;
        }
        .hojas-pareja-nombre {
            font-weight: 600;
            margin-bottom: 3px;
            word-break: break-word;
        }
        .hojas-pareja-stats {
            font-size: 11px;
            margin: 6px 0;
            color: #1f2937;
        }
        .hojas-pareja-equipo {
            font-size: 12px;
            font-style: italic;
            padding-top: 6px;
            margin-top: 4px;
            border-top: 1px solid #ccc;
            word-break: break-word;
        }
        
        /* Sección de jugadores */
        .seccion-jugadores {
            margin-bottom: 25px;
        }
        
        .linea-jugadores {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .jugador-izquierda {
            flex: 1;
            min-width: 0;
            padding-right: 20px;
        }
        
        .jugador-derecha {
            flex: 1;
            padding-left: 20px;
            min-width: 0;
            text-align: right;
        }
        
        .jugador-id-nombre {
            font-weight: bold;
            margin-bottom: 5px;
            display: flex;
            align-items: baseline;
            gap: 8px;
            min-width: 0;
            width: 100%;
        }
        /* Columna derecha (B/D): ID junto al borde interno; nombre trunca hacia el centro */
        .linea-con-qr .col-der .jugador-id-nombre,
        .jugador-derecha .jugador-id-nombre {
            flex-direction: row-reverse;
        }
        
        .jugador-id {
            flex-shrink: 0;
            font-weight: bold;
        }
        
        .jugador-nombre {
            min-width: 0;
            flex: 1 1 0%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Estadísticas */
        .estadisticas {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 11px;
        }
        
        .estadisticas-izquierda,
        .estadisticas-derecha {
            display: flex;
            gap: 15px;
        }
        
        .stat-item {
            display: inline-block;
        }
        
        .stat-label {
            font-weight: bold;
            margin-right: 3px;
        }
        
        /* Clubes / Equipos */
        .clubes {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 12px;
            font-style: italic;
            padding-top: 5px;
            border-top: 1px solid #ccc;
        }
        
        .equipo-info {
            display: flex;
            flex-direction: column;
        }
        
        .equipo-nombre {
            font-weight: bold;
            font-style: normal;
            margin-bottom: 3px;
        }
        
        .equipo-codigo-inline {
            font-weight: normal;
            font-size: 0.95em;
            color: #666;
            margin-right: 4px;
        }
        
        .equipo-estadisticas {
            font-size: 10px;
            color: #555;
            margin-top: 3px;
            font-style: normal;
        }
        
        .equipo-stat-item {
            margin-right: 8px;
        }
        
        .equipo-stat-label {
            font-weight: bold;
        }
        
        .tarjeta-club {
            font-size: 18px;
            font-weight: bold;
        }
        
        /* Espacio para anotar */
        .espacio-anotacion {
            flex: 1;
            border: none;
            margin: 16px 0;
            min-height: 200px;
            padding: 4px 0;
        }
        
        .espacio-anotacion-label {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        /* Firmas al pie */
        .firmas {
            display: flex;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 20px;
            border-top: none;
        }
        
        .firma-item {
            flex: 1;
            text-align: center;
            padding: 0 10px;
        }
        
        .firma-linea {
            border-top: none;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        
        /* Print styles */
        @media print {
            .no-print,
            .btn-flotante,
            .selector-mesas,
            .hojas-fixed-header {
                display: none !important;
            }
            body {
                background: white;
                padding-top: 20px;
            }
            .hoja-mesa {
                margin-bottom: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Botones flotantes -->
    <div class="btn-flotante no-print">
        <button onclick="window.print()" class="btn-imprimir">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo (int)($torneo['id'] ?? 0); ?>" class="btn-volver" id="btn-volver" title="Ir al panel de control del torneo">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>

    <header class="hojas-fixed-header no-print" role="banner">
        <span class="hojas-fixed-header__title">Hojas de anotación</span>
        <span class="hojas-fixed-header__torneo"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo', ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="hojas-fixed-header__meta">Ronda <?php echo (int) $ronda; ?></span>
    </header>

    <!-- Selector: ir a hoja + torneos asociados (una sola fila, panel fijo bajo el encabezado) -->
    <div class="selector-mesas no-print" role="navigation" aria-label="Ir a hoja y torneos asociados">
        <div class="selector-mesas__group">
            <?php if (!empty($context_switcher['items'])): ?>
                <?php
                $tcs = [
                    'items' => $context_switcher['items'],
                    'active_id' => (int) ($context_switcher['active_tournament_id'] ?? 0),
                    'base_url' => $ctx_switch_base_url,
                    'sep' => $use_standalone ? '?' : '&',
                    'ronda_base' => (int) $ronda,
                    'map_max' => $map_max_partida_switch,
                    'mode' => 'hojas_anotacion',
                    'theme' => 'on_light',
                    'select_id' => 'torneo-asociado-select-hojas',
                    'show_info' => false,
                    'show_select' => true,
                    'show_pill_meta' => false,
                    'select_class' => 'tcs-select-control',
                    'select_label_class' => 'mb-0 mr-1',
                    'pill_row_class' => 'tcs--hojas-inline',
                    'aria_label' => 'Torneos asociados (mismo evento)',
                ];
                require __DIR__ . '/../../resources/views/partials/torneo_context_switch.php';
                ?>
            <?php endif; ?>
            <div class="selector-mesas__ir">
                <label for="ir-a-mesa">Ir a hoja:</label>
                <select id="ir-a-mesa" onchange="irAMesa(this.value)">
                    <option value="">— Mesas asignadas —</option>
                    <?php foreach ($mesas as $idx => $m):
                        $num_mesa = (int)($m['numero'] ?? $idx + 1);
                    ?>
                        <option value="hoja-mesa-<?php echo $num_mesa; ?>">Mesa <?php echo $num_mesa; ?> (hoja <?php echo $idx + 1; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <?php 
    // Verificar si es torneo de equipos (modalidad 3)
    $es_torneo_equipos = isset($es_torneo_equipos) ? (bool)$es_torneo_equipos : false;
    if (!$es_torneo_equipos && isset($torneo)) {
        $es_torneo_equipos = (int)($torneo['modalidad'] ?? 0) === 3;
    }
    $es_torneo_parejas = isset($es_torneo_parejas) ? (bool)$es_torneo_parejas : false;
    if (!$es_torneo_parejas && isset($torneo)) {
        $es_torneo_parejas = (int)($torneo['modalidad'] ?? 0) === 2;
    }
    ?>

    <div class="contenedor-hojas">
    <?php foreach ($mesas as $mesa): ?>
        <?php
        // Organizar jugadores por secuencia
        $jugadores = [];
        foreach ($mesa['jugadores'] as $jugador) {
            $secuencia = (int)($jugador['secuencia'] ?? 0);
            $jugadores[$secuencia] = $jugador;
        }
        
        // Jugadores en orden: sec 1,2 = Pareja AC | sec 3,4 = Pareja BD
        $jugador1 = $jugadores[1] ?? null; // A (secuencia 1)
        $jugador2 = $jugadores[2] ?? null; // C (secuencia 2)
        $jugador3 = $jugadores[3] ?? null; // B (secuencia 3)
        $jugador4 = $jugadores[4] ?? null; // D (secuencia 4)
        $num_mesa_div = (int)($mesa['numero'] ?? 0);
        ?>
        
        <?php
        // Token dinámico: incluye ronda actual. Si la ronda cambia, el token es diferente.
        $tid = (int)$torneo['id'];
        $num_mesa = (int)($mesa['numero'] ?? 0);
        $r = (int)$ronda;
        $token = QrMesaTokenHelper::generar($tid, $num_mesa, $r);
        // URL: public_mesa_input.php?t={torneo_id}&m={mesa}&r={ronda_actual}&token={token}
        $url_carga_mesa = $url_carga_publica_base . '?t=' . $tid . '&m=' . $num_mesa . '&r=' . $r . '&token=' . urlencode($token);
        $qr_img_size = '140x140'; // Resolución mayor para impresión y escaneo móvil
        // ecc=H: nivel de corrección de errores alto (legible aunque la hoja esté arrugada)
        $qr_params = ['size' => $qr_img_size, 'data' => $url_carga_mesa, 'format' => 'png', 'margin' => 2, 'ecc' => 'H'];
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query($qr_params);
        ?>
        <div class="hoja-mesa" id="hoja-mesa-<?php echo $num_mesa_div; ?>">
            <!-- Zona: título + info alrededor del QR flotante -->
            <div class="zona-qr">
                <div class="header-torneo">
                    <div class="nombre-torneo"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?><?php if (!empty($torneo['fechator'])): ?> — <?php echo date('d/m/Y', strtotime($torneo['fechator'])); ?><?php endif; ?></div>
                    <div class="header-ronda-mesa">Ronda: <?php echo $ronda; ?> - Mesa: <?php echo $mesa['numero']; ?></div>
                </div>
                <?php if ($es_torneo_parejas): ?>
                <div class="linea-con-qr linea-con-qr--parejas">
                    <div class="col-izq col-izq--parejas">
                        <div class="hojas-bloque-pareja">
                            <div class="hojas-pareja-titulo">Pareja <?php echo htmlspecialchars(($letras_secuencia[1] ?? 'A') . '-' . ($letras_secuencia[2] ?? 'C'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars(GestionTorneosViewsData::prefijoNumeroClubHoja($jugador1) . ($jugador1['nombre_completo'] ?? $jugador1['nombre'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars(GestionTorneosViewsData::prefijoNumeroClubHoja($jugador2) . ($jugador2['nombre_completo'] ?? $jugador2['nombre'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-stats"><?php echo htmlspecialchars(GestionTorneosViewsData::lineaEstadisticasParejaHoja($jugador1, $jugador2), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-equipo"><?php echo GestionTorneosViewsData::htmlLineaNombreEquipoPareja($jugador1); ?></div>
                        </div>
                    </div>
                    <div class="col-qr">
                        <div class="qr-mesa" title="Escanear para cargar acta">
                            <a href="<?php echo htmlspecialchars($url_carga_mesa); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Cargar acta" width="100" height="100">
                            </a>
                        </div>
                    </div>
                    <div class="col-der col-der--parejas">
                        <div class="hojas-bloque-pareja hojas-bloque-pareja--der">
                            <div class="hojas-pareja-titulo">Pareja <?php echo htmlspecialchars(($letras_secuencia[3] ?? 'B') . '-' . ($letras_secuencia[4] ?? 'D'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars(GestionTorneosViewsData::prefijoNumeroClubHoja($jugador3) . ($jugador3['nombre_completo'] ?? $jugador3['nombre'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars(GestionTorneosViewsData::prefijoNumeroClubHoja($jugador4) . ($jugador4['nombre_completo'] ?? $jugador4['nombre'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-stats"><?php echo htmlspecialchars(GestionTorneosViewsData::lineaEstadisticasParejaHoja($jugador3, $jugador4), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-equipo"><?php echo GestionTorneosViewsData::htmlLineaNombreEquipoPareja($jugador3); ?></div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="linea-con-qr">
                    <div class="col-izq">
                        <div class="jugador-id-nombre">
                            <span class="jugador-id" title="Nº en club / id"><?php echo htmlspecialchars(GestionTorneosViewsData::numeroClubParaHoja($jugador1), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="jugador-nombre"><?php echo htmlspecialchars($jugador1['nombre_completo'] ?? $jugador1['nombre'] ?? 'N/A'); ?> (<?php echo $letras_secuencia[1] ?? 'A'; ?>)</span>
                        </div>
                        <div class="estadisticas estadisticas-solo-izq">
                            <?php if ($jugador1): ?>
                            <span class="stat-item"><span class="stat-label">Pos:</span><?php echo (int)($jugador1['inscrito']['posicion'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">G:</span><?php echo (int)($jugador1['inscrito']['ganados'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">P:</span><?php echo (int)($jugador1['inscrito']['perdidos'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">Efect:</span><?php echo (int)($jugador1['inscrito']['efectividad'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">Pts:</span><?php echo (int)($jugador1['inscrito']['puntos'] ?? 0); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="clubes clubs-solo-izq">
                            <?php 
                            if ($es_torneo_equipos && !empty($jugador1['nombre_equipo'])) { ?>
                            <div class="equipo-info">
                                <div class="equipo-nombre">
                                    <?php
                                    if (!empty($jugador1['codigo_equipo_display'])) {
                                        echo '<span class="equipo-codigo-inline">' . htmlspecialchars($jugador1['codigo_equipo_display']) . '</span> - ';
                                    }
                                    echo htmlspecialchars($jugador1['nombre_equipo']);
                                    $t1 = (int)($jugador1['tarjeta'] ?? 0);
                                    if ($t1 > 0) {
                                        $c = $t1 === 1 ? 'Amarilla' : ($t1 === 3 ? 'Roja' : ($t1 === 4 ? 'Negra' : ''));
                                        echo ' -<span class="tarjeta-club">* ' . $c . ' *</span>';
                                    }
                                    ?>
                                </div>
                                <?php if (!empty($jugador1['estadisticas_equipo'])):
                                    $statsEquipo1 = $jugador1['estadisticas_equipo'];
                                ?>
                                <div class="equipo-estadisticas">
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">Pos:</span><?php echo htmlspecialchars((string)($statsEquipo1['clasiequi'] ?? $statsEquipo1['posicion'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">G:</span><?php echo (int)($statsEquipo1['ganados'] ?? 0); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">P:</span><?php echo (int)($statsEquipo1['perdidos'] ?? 0); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">Efect:</span><?php echo (int)($statsEquipo1['efectividad'] ?? 0); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">Pts:</span><?php echo (int)($statsEquipo1['puntos'] ?? 0); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php } else { echo htmlspecialchars($jugador1['nombre_club'] ?? $jugador1['club_nombre'] ?? 'Sin Club'); $t1=(int)($jugador1['tarjeta']??0); if($t1>0){ $c=$t1==1?'Amarilla':($t1==3?'Roja':($t1==4?'Negra':'')); echo ' -<span class="tarjeta-club">* '.$c.' *</span>'; } } ?>
                        </div>
                    </div>
                    <div class="col-qr">
                        <div class="qr-mesa" title="Escanear para cargar acta">
                            <a href="<?php echo htmlspecialchars($url_carga_mesa); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Cargar acta" width="100" height="100">
                            </a>
                        </div>
                    </div>
                    <div class="col-der">
                        <div class="jugador-id-nombre">
                            <span class="jugador-id" title="Nº en club / id"><?php echo htmlspecialchars(GestionTorneosViewsData::numeroClubParaHoja($jugador3), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="jugador-nombre"><?php echo htmlspecialchars($jugador3['nombre_completo'] ?? $jugador3['nombre'] ?? 'N/A'); ?> (<?php echo $letras_secuencia[3] ?? 'B'; ?>)</span>
                        </div>
                        <div class="estadisticas estadisticas-solo-der">
                            <?php if ($jugador3): ?>
                            <span class="stat-item"><span class="stat-label">Pos:</span><?php echo (int)($jugador3['inscrito']['posicion'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">G:</span><?php echo (int)($jugador3['inscrito']['ganados'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">P:</span><?php echo (int)($jugador3['inscrito']['perdidos'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">Efect:</span><?php echo (int)($jugador3['inscrito']['efectividad'] ?? 0); ?></span>
                            <span class="stat-item"><span class="stat-label">Pts:</span><?php echo (int)($jugador3['inscrito']['puntos'] ?? 0); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="clubes clubs-solo-der" style="text-align:right">
                            <?php 
                            if ($es_torneo_equipos && !empty($jugador3['nombre_equipo'])) { ?>
                            <div class="equipo-info">
                                <div class="equipo-nombre">
                                    <?php
                                    if (!empty($jugador3['codigo_equipo_display'])) {
                                        echo '<span class="equipo-codigo-inline">' . htmlspecialchars($jugador3['codigo_equipo_display']) . '</span> - ';
                                    }
                                    echo htmlspecialchars($jugador3['nombre_equipo']);
                                    $t3 = (int)($jugador3['tarjeta'] ?? 0);
                                    if ($t3 > 0) {
                                        $c = $t3 === 1 ? 'Amarilla' : ($t3 === 3 ? 'Roja' : ($t3 === 4 ? 'Negra' : ''));
                                        echo ' -<span class="tarjeta-club">* ' . $c . ' *</span>';
                                    }
                                    ?>
                                </div>
                                <?php if (!empty($jugador3['estadisticas_equipo'])):
                                    $statsEquipo3 = $jugador3['estadisticas_equipo'];
                                ?>
                                <div class="equipo-estadisticas">
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">Pos:</span><?php echo htmlspecialchars((string)($statsEquipo3['clasiequi'] ?? $statsEquipo3['posicion'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">G:</span><?php echo (int)($statsEquipo3['ganados'] ?? 0); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">P:</span><?php echo (int)($statsEquipo3['perdidos'] ?? 0); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">Efect:</span><?php echo (int)($statsEquipo3['efectividad'] ?? 0); ?></span>
                                    <span class="equipo-stat-item"><span class="equipo-stat-label">Pts:</span><?php echo (int)($statsEquipo3['puntos'] ?? 0); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php } else { echo htmlspecialchars($jugador3['nombre_club'] ?? $jugador3['club_nombre'] ?? 'Sin Club'); $t3=(int)($jugador3['tarjeta']??0); if($t3>0){ $c=$t3==1?'Amarilla':($t3==3?'Roja':($t3==4?'Negra':'')); echo ' -<span class="tarjeta-club">* '.$c.' *</span>'; } } ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!$es_torneo_parejas): ?>
            <!-- Línea 2: C y D (Pareja AC vs Pareja BD) -->
            <div class="seccion-jugadores">
                <div class="linea-jugadores">
                    <div class="jugador-izquierda">
                        <div class="jugador-id-nombre">
                            <span class="jugador-id" title="Nº en club / id"><?php echo htmlspecialchars(GestionTorneosViewsData::numeroClubParaHoja($jugador2), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="jugador-nombre"><?php echo htmlspecialchars($jugador2['nombre_completo'] ?? $jugador2['nombre'] ?? 'N/A'); ?> (<?php echo $letras_secuencia[2] ?? 'C'; ?>)</span>
                        </div>
                    </div>
                    <div class="jugador-derecha">
                        <div class="jugador-id-nombre">
                            <span class="jugador-id" title="Nº en club / id"><?php echo htmlspecialchars(GestionTorneosViewsData::numeroClubParaHoja($jugador4), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="jugador-nombre"><?php echo htmlspecialchars($jugador4['nombre_completo'] ?? $jugador4['nombre'] ?? 'N/A'); ?> (<?php echo $letras_secuencia[4] ?? 'D'; ?>)</span>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas Jugadores 2 y 4 -->
                <div class="estadisticas">
                    <div class="estadisticas-izquierda">
                        <?php if ($jugador2): ?>
                            <span class="stat-item">
                                <span class="stat-label">Pos:</span><?php echo (int)($jugador2['inscrito']['posicion'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">G:</span><?php echo (int)($jugador2['inscrito']['ganados'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">P:</span><?php echo (int)($jugador2['inscrito']['perdidos'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">Efect:</span><?php echo (int)($jugador2['inscrito']['efectividad'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">Pts:</span><?php echo (int)($jugador2['inscrito']['puntos'] ?? 0); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="estadisticas-derecha">
                        <?php if ($jugador4): ?>
                            <span class="stat-item">
                                <span class="stat-label">Pos:</span><?php echo (int)($jugador4['inscrito']['posicion'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">G:</span><?php echo (int)($jugador4['inscrito']['ganados'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">P:</span><?php echo (int)($jugador4['inscrito']['perdidos'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">Efect:</span><?php echo (int)($jugador4['inscrito']['efectividad'] ?? 0); ?>
                            </span>
                            <span class="stat-item">
                                <span class="stat-label">Pts:</span><?php echo (int)($jugador4['inscrito']['puntos'] ?? 0); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Clubes / Equipos Jugadores 2 y 4 -->
                <div class="clubes">
                    <div>
                        <?php 
                        if ($es_torneo_equipos && !empty($jugador2['nombre_equipo'])) {
                            ?>
                            <div class="equipo-info">
                                <div class="equipo-nombre">
                                    <?php 
                                    if (!empty($jugador2['codigo_equipo_display'])) {
                                        echo '<span class="equipo-codigo-inline">' . htmlspecialchars($jugador2['codigo_equipo_display']) . '</span> - ';
                                    }
                                    echo htmlspecialchars($jugador2['nombre_equipo']); 
                                    $tarjeta2 = (int)($jugador2['tarjeta'] ?? 0);
                                    if ($tarjeta2 > 0) {
                                        $colorTarjeta = $tarjeta2 == 1 ? 'Amarilla' : ($tarjeta2 == 3 ? 'Roja' : ($tarjeta2 == 4 ? 'Negra' : ''));
                                        echo ' -<span class="tarjeta-club">* ' . $colorTarjeta . ' *</span>';
                                    }
                                    ?>
                                </div>
                                <?php if (!empty($jugador2['estadisticas_equipo'])):
                                    $statsEquipo2 = $jugador2['estadisticas_equipo'];
                                ?>
                                    <div class="equipo-estadisticas">
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">Pos:</span><?php echo htmlspecialchars((string)($statsEquipo2['clasiequi'] ?? $statsEquipo2['posicion'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">G:</span><?php echo (int)($statsEquipo2['ganados'] ?? 0); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">P:</span><?php echo (int)($statsEquipo2['perdidos'] ?? 0); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">Efect:</span><?php echo (int)($statsEquipo2['efectividad'] ?? 0); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">Pts:</span><?php echo (int)($statsEquipo2['puntos'] ?? 0); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                        } else {
                            echo htmlspecialchars($jugador2['nombre_club'] ?? $jugador2['club_nombre'] ?? 'Sin Club');
                            $tarjeta2 = (int)($jugador2['tarjeta'] ?? 0);
                            if ($tarjeta2 > 0) {
                                $colorTarjeta = $tarjeta2 == 1 ? 'Amarilla' : ($tarjeta2 == 3 ? 'Roja' : ($tarjeta2 == 4 ? 'Negra' : ''));
                                echo ' -<span class="tarjeta-club">* ' . $colorTarjeta . ' *</span>';
                            }
                        }
                        ?>
                    </div>
                    <div>
                        <?php 
                        if ($es_torneo_equipos && !empty($jugador4['nombre_equipo'])) {
                            ?>
                            <div class="equipo-info">
                                <div class="equipo-nombre">
                                    <?php 
                                    if (!empty($jugador4['codigo_equipo_display'])) {
                                        echo '<span class="equipo-codigo-inline">' . htmlspecialchars($jugador4['codigo_equipo_display']) . '</span> - ';
                                    }
                                    echo htmlspecialchars($jugador4['nombre_equipo']); 
                                    $tarjeta4 = (int)($jugador4['tarjeta'] ?? 0);
                                    if ($tarjeta4 > 0) {
                                        $colorTarjeta = $tarjeta4 == 1 ? 'Amarilla' : ($tarjeta4 == 3 ? 'Roja' : ($tarjeta4 == 4 ? 'Negra' : ''));
                                        echo ' -<span class="tarjeta-club">* ' . $colorTarjeta . ' *</span>';
                                    }
                                    ?>
                                </div>
                                <?php if (!empty($jugador4['estadisticas_equipo'])):
                                    $statsEquipo4 = $jugador4['estadisticas_equipo'];
                                ?>
                                    <div class="equipo-estadisticas">
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">Pos:</span><?php echo htmlspecialchars((string)($statsEquipo4['clasiequi'] ?? $statsEquipo4['posicion'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">G:</span><?php echo (int)($statsEquipo4['ganados'] ?? 0); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">P:</span><?php echo (int)($statsEquipo4['perdidos'] ?? 0); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">Efect:</span><?php echo (int)($statsEquipo4['efectividad'] ?? 0); ?></span>
                                        <span class="equipo-stat-item"><span class="equipo-stat-label">Pts:</span><?php echo (int)($statsEquipo4['puntos'] ?? 0); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                        } else {
                            echo htmlspecialchars($jugador4['nombre_club'] ?? $jugador4['club_nombre'] ?? 'Sin Club');
                            $tarjeta4 = (int)($jugador4['tarjeta'] ?? 0);
                            if ($tarjeta4 > 0) {
                                $colorTarjeta = $tarjeta4 == 1 ? 'Amarilla' : ($tarjeta4 == 3 ? 'Roja' : ($tarjeta4 == 4 ? 'Negra' : ''));
                                echo ' -<span class="tarjeta-club">* ' . $colorTarjeta . ' *</span>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Espacio para anotar -->
            <div class="espacio-anotacion">
                <div class="espacio-anotacion-label">ANOTACIONES:</div>
                <!-- Espacio en blanco para escribir -->
            </div>
            
            <!-- Firmas -->
            <div class="firmas">
                <div class="firma-item">
                    <div class="firma-linea">
                        Pareja <?php echo $es_torneo_parejas ? htmlspecialchars(($letras_secuencia[1] ?? 'A') . '-' . ($letras_secuencia[2] ?? 'C'), ENT_QUOTES, 'UTF-8') : 'AC'; ?>
                    </div>
                </div>
                <div class="firma-item">
                    <div class="firma-linea">
                        Pareja <?php echo $es_torneo_parejas ? htmlspecialchars(($letras_secuencia[3] ?? 'B') . '-' . ($letras_secuencia[4] ?? 'D'), ENT_QUOTES, 'UTF-8') : 'BD'; ?>
                    </div>
                </div>
                <div class="firma-item">
                    <div class="firma-linea">
                        Árbitro
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <script>
    function irAMesa(id) {
        if (!id) return;
        var el = document.getElementById(id);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    </script>
</body>
</html>
