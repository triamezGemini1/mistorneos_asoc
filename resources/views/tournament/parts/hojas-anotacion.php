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
require_once __DIR__ . '/../../lib/QrMesaTokenHelper.php';
require_once __DIR__ . '/../../../../lib/GestionTorneosViewsData.php';
$letras_secuencia = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];
// URL base: interfaz móvil para carga de actas (formato requerido: ?t=&m=&r=&token=)
$url_dominio_public = rtrim(function_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (function_exists('app_base_url') ? rtrim(app_base_url(), '/') . '/public' : ''), '/');
$url_carga_publica_base = $url_dominio_public . '/public_mesa_input.php';
if (!isset($base_url) || !isset($use_standalone)) {
    $script_actual = basename($_SERVER['PHP_SELF'] ?? '');
    $use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
    $base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hojas de Anotación - Ronda <?php echo $ronda; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* 90% del ancho carta: encaja mejor en impresión/PDF */
            --hoja-ancho: calc(8.5in * 0.9);
        }
        
        html {
            overflow-y: auto;
            height: auto;
        }
        
        @page {
            size: letter;
            margin: 0.5in;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            overflow-y: auto;
            height: auto;
            padding: 20px;
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
        
        .selector-mesas {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: white;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .selector-mesas label {
            font-weight: 600;
            color: #374151;
            white-space: nowrap;
        }
        
        .selector-mesas select {
            padding: 8px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            min-width: 140px;
            cursor: pointer;
        }
        
        .selector-mesas select:focus {
            outline: none;
            border-color: #3b82f6;
        }
        
        .hoja-mesa {
            background: white;
            border: 2px solid #000;
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
            min-width: 0;
            padding-left: 20px;
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
            border: 2px solid #000;
            margin: 20px 0;
            min-height: 200px;
            padding: 10px;
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
            border-top: 2px solid #000;
        }
        
        .firma-item {
            flex: 1;
            text-align: center;
            padding: 0 10px;
        }
        
        .firma-linea {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        
        /* Print styles */
        @media print {
            .no-print,
            .btn-flotante,
            .selector-mesas {
                display: none !important;
            }
            body {
                background: white;
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

    <!-- Selector: ver mesas asignadas e ir a una hoja en particular -->
    <div class="selector-mesas no-print">
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
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars($jugador1['nombre_completo'] ?? $jugador1['nombre'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars($jugador2['nombre_completo'] ?? $jugador2['nombre'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
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
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars($jugador3['nombre_completo'] ?? $jugador3['nombre'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-nombre"><?php echo htmlspecialchars($jugador4['nombre_completo'] ?? $jugador4['nombre'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-stats"><?php echo htmlspecialchars(GestionTorneosViewsData::lineaEstadisticasParejaHoja($jugador3, $jugador4), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="hojas-pareja-equipo"><?php echo GestionTorneosViewsData::htmlLineaNombreEquipoPareja($jugador3); ?></div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="linea-con-qr">
                    <div class="col-izq">
                        <div class="jugador-id-nombre">
                            <span class="jugador-id"><?php echo $jugador1['id_usuario'] ?? 'N/A'; ?></span>
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
                            <span class="jugador-id"><?php echo $jugador3['id_usuario'] ?? 'N/A'; ?></span>
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
                            <span class="jugador-id"><?php echo $jugador2['id_usuario'] ?? 'N/A'; ?></span>
                            <span class="jugador-nombre"><?php echo htmlspecialchars($jugador2['nombre_completo'] ?? $jugador2['nombre'] ?? 'N/A'); ?> (<?php echo $letras_secuencia[2] ?? 'C'; ?>)</span>
                        </div>
                    </div>
                    <div class="jugador-derecha">
                        <div class="jugador-id-nombre">
                            <span class="jugador-id"><?php echo $jugador4['id_usuario'] ?? 'N/A'; ?></span>
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
