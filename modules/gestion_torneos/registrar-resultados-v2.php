<?php
/**
 * Vista: Registrar Resultados V2 - Formulario mejorado con todas las funcionalidades
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$ctx_switch_base_url = function_exists('torneoGestionContextSwitchBaseUrl') ? torneoGestionContextSwitchBaseUrl() : $base_url;
$action_param = $use_standalone ? '?' : '&';
$esTorneoParejas = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
$context_switcher = isset($context_switcher) && is_array($context_switcher)
    ? $context_switcher
    : ['active_tournament_id' => (int)($torneo['id'] ?? 0), 'items' => []];
$map_max_partida_switch = isset($map_max_partida_switch) && is_array($map_max_partida_switch)
    ? $map_max_partida_switch
    : [];
$contextGenero = '';
$contextBadgeIndex = 0;
$contextBadgeName = (string)($torneo['nombre'] ?? 'Torneo activo');
if (!empty($context_switcher['items']) && is_array($context_switcher['items'])) {
    $activeContextId = (int)($context_switcher['active_tournament_id'] ?? ($torneo['id'] ?? 0));
    foreach ($context_switcher['items'] as $idx => $ctxItem) {
        if ((int)($ctxItem['id'] ?? 0) === $activeContextId) {
            $contextGenero = strtoupper((string)($ctxItem['genero'] ?? ''));
            $contextBadgeIndex = (int)$idx % 3;
            $contextBadgeName = (string)($ctxItem['nombre'] ?? $contextBadgeName);
            break;
        }
    }
}
if ($contextGenero === '') {
    $nombreCtx = mb_strtolower((string)($torneo['nombre'] ?? ''), 'UTF-8');
    if (preg_match('/\b(femenino|fem|damas)\b/ui', $nombreCtx)) {
        $contextGenero = 'F';
    } elseif (preg_match('/\b(masculino|masc|caballeros)\b/ui', $nombreCtx)) {
        $contextGenero = 'M';
    }
}
$contextClass = $contextGenero === 'F' ? 'context-femenino' : 'context-masculino';
$contextLabel = $contextGenero === 'F' ? 'Femenino' : 'Masculino';

/** Límite superior de ronda para el diálogo “Corrección directa” (rondas existentes o plan del torneo). */
$rr_max_ronda_correccion = max(1, (int) ($ronda ?? 1));
foreach ($todasLasRondas ?? [] as $_rr) {
    $pv = (int) ($_rr['partida'] ?? 0);
    if ($pv > $rr_max_ronda_correccion) {
        $rr_max_ronda_correccion = $pv;
    }
}
$rr_rondas_plan = (int) ($torneo['rondas'] ?? 0);
if ($rr_rondas_plan > $rr_max_ronda_correccion) {
    $rr_max_ronda_correccion = $rr_rondas_plan;
}
?>

<style>
    html {
        font-size: 16px; /* Base para rem */
    }
    
    /* Contenedor: 90% del ancho de pantalla para reducir márgenes laterales */
    .registrar-resultados-wrap {
        width: 90%;
        max-width: 100%;
        margin-left: auto;
        margin-right: auto;
    }
    .registrar-resultados-wrap.context-masculino .formulario-resultados-sticky {
        border-top: 4px solid #3498db;
    }
    .registrar-resultados-wrap.context-femenino .formulario-resultados-sticky {
        border-top: 4px solid #e91e63;
    }
    .registrar-resultados-wrap.context-masculino .formulario-resultados-sticky .card-body .form-control:not(.is-invalid):not(.is-valid),
    .registrar-resultados-wrap.context-masculino .formulario-resultados-sticky .card-body textarea:not(.is-invalid):not(.is-valid),
    .registrar-resultados-wrap.context-masculino .formulario-resultados-sticky .card-body select:not(.is-invalid):not(.is-valid) {
        background-color: rgba(52, 152, 219, 0.06);
    }
    .registrar-resultados-wrap.context-femenino .formulario-resultados-sticky .card-body .form-control:not(.is-invalid):not(.is-valid),
    .registrar-resultados-wrap.context-femenino .formulario-resultados-sticky .card-body textarea:not(.is-invalid):not(.is-valid),
    .registrar-resultados-wrap.context-femenino .formulario-resultados-sticky .card-body select:not(.is-invalid):not(.is-valid) {
        background-color: rgba(233, 30, 99, 0.06);
    }
    .contexto-genero-badge {
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        border-radius: 999px;
        padding: 0.35rem 0.65rem;
    }
    .contexto-genero-badge.theme-0 {
        background-color: rgba(52, 152, 219, 0.14);
        color: #2a6b90;
        border: 1px solid rgba(52, 152, 219, 0.31);
    }
    .contexto-genero-badge.theme-1 {
        background-color: rgba(233, 30, 99, 0.12);
        color: #9f2c53;
        border: 1px solid rgba(233, 30, 99, 0.3);
    }
    .contexto-genero-badge.theme-2 {
        background-color: rgba(16, 185, 129, 0.12);
        color: #1b7a58;
        border: 1px solid rgba(16, 185, 129, 0.28);
    }
    
    /* Navegación partidas: 13.2% (= 12% + 10%), formulario 86.8% (≥992px) */
    @media (min-width: 992px) {
        .registrar-resultados-wrap #sidebar-mesas {
            flex: 0 0 13.2%;
            max-width: 13.2%;
        }
        .registrar-resultados-wrap #sidebar-mesas .card {
            max-width: 100%;
        }
        .registrar-resultados-wrap .col-form-registro {
            flex: 0 0 86.8%;
            max-width: 86.8%;
        }
    }
    /* Móvil/tablet: sin navegador de mesas */
    @media (max-width: 991.98px) {
        .registrar-resultados-wrap #sidebar-mesas { display: none !important; }
        .registrar-resultados-wrap .col-form-registro { flex: 0 0 100% !important; max-width: 100% !important; }
    }
    
    .mesa-item {
        transition: all 0.3s;
        padding: 0.35rem 0.425rem !important;
        cursor: pointer;
        font-size: clamp(0.68rem, 1.15vw, 0.8rem);
    }
    /* Contenido número + icono: −15% ancho respecto al enlace (menos scroll horizontal) */
    .registrar-resultados-wrap .mesa-item .mesa-item-inner {
        width: 85%;
        max-width: 85%;
        margin-left: auto;
        margin-right: auto;
        box-sizing: border-box;
    }
    .mesa-item:hover {
        transform: translateX(0.3125rem);
    }
    .mesa-activa {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-weight: bold;
    }
    .mesa-completada {
        background: #10b981;
        color: white;
    }
    .mesa-pendiente {
        background: #f59e0b;
        color: white;
    }
    
    /* Validación de input de mesa */
    #input_ir_mesa.is-invalid {
        border-color: #dc3545;
        background-color: #fff5f5;
    }
    
    #input_ir_mesa.is-invalid:focus {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }
    
    #input_ir_mesa.is-valid {
        border-color: #28a745;
        background-color: #f0fff4;
    }
    
    #input_ir_mesa.is-valid:focus {
        border-color: #28a745;
        box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
    }
    
    /* Selector de búsqueda de mesa: ancho +40%, tamaño un punto menos */
    #input_ir_mesa {
        font-size: clamp(2.3rem, 4.1vw, 2.7rem) !important;
        font-weight: bold !important;
        width: clamp(9.8rem, 19.6vw, 12.75rem) !important;
        min-width: 9.8rem !important;
        text-align: center;
    }
    
    /* Validación de input de puntos */
    #puntos_pareja_A.is-invalid,
    #puntos_pareja_B.is-invalid {
        border-color: #dc3545;
        background-color: #fff5f5;
    }
    
    #puntos_pareja_A.is-invalid:focus,
    #puntos_pareja_B.is-invalid:focus {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }
    
    #puntos_pareja_A.is-valid,
    #puntos_pareja_B.is-valid {
        border-color: #28a745;
        background-color: #f0fff4;
    }
    
    #puntos_pareja_A.is-valid:focus,
    #puntos_pareja_B.is-valid:focus {
        border-color: #28a745;
        box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
    }
    
    /* Jugador con tarjeta previa: resaltar en naranja para advertir al administrador */
    .jugador-tarjeta-previa {
        color: #e65100 !important;
        background: linear-gradient(90deg, rgba(230, 81, 0, 0.15), transparent);
        padding: 2px 6px;
        border-radius: 4px;
    }
    
    /* Tarjetas: sin borde */
    .tarjeta-btn {
        width: clamp(2rem, 5vw, 2.5rem);
        height: clamp(2rem, 5vw, 2.5rem);
        min-width: 2rem;
        min-height: 2rem;
        border-radius: 0.5rem;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: clamp(1rem, 2.5vw, 1.2rem);
        background-color: transparent !important;
        position: relative;
        touch-action: manipulation;
    }
    .tarjeta-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.2);
    }
    .tarjeta-btn:active {
        transform: scale(0.95);
    }
    .tarjeta-btn.activo {
        box-shadow: 0 0 0.75rem rgba(0,0,0,0.6);
        background-color: transparent !important;
    }
    .tarjeta-btn.activo::after {
        content: '✓';
        position: absolute;
        top: -0.3125rem;
        right: -0.3125rem;
        background-color: #10b981;
        color: white;
        border-radius: 50%;
        width: clamp(1rem, 2.5vw, 1.25rem);
        height: clamp(1rem, 2.5vw, 1.25rem);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: clamp(0.625rem, 1.5vw, 0.75rem);
        font-weight: bold;
        border: 0.125rem solid white;
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.3);
        z-index: 10;
    }
    
    /* Columnas en % del ancho de pantalla (tabla con table-layout: fixed) */
    #formResultados .table {
        table-layout: fixed;
        width: 100%;
    }
    .columna-id { width: 3%; }
    .columna-nombre { width: 27%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 300; }
    .columna-puntos { width: 10%; }
    .columna-sancion { width: 5%; }
    .columna-forfait { width: 3.2%; }
    .columna-tarjeta { width: 15%; overflow: hidden; }
    /* Una columna: título con iniciales POS · GAN · PER · EFE (sustituye "Estadísticas") */
    .columna-estadisticas { width: 9%; max-width: 9%; }
    .stats-th-iniciales {
        display: flex;
        flex-wrap: nowrap;
        justify-content: space-between;
        gap: 0.15em;
        font-size: 0.72em;
        letter-spacing: -0.02em;
        line-height: 1.2;
        padding: 0 0.05rem;
    }
    .columna-estadisticas .estadisticas-valores { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .columna-tarjeta .tarjeta-btn { width: 33.33%; min-width: 1.5rem; max-width: 33.33%; box-sizing: border-box; flex-shrink: 0; }
    .estadisticas-valores { font-size: clamp(0.75rem, 1.5vw, 0.875rem); font-weight: 300; color: #111827; white-space: nowrap; line-height: 1.275; }
    /* Títulos de columna (ID, nombre, puntos, etc.) en negrita; −15% padding vs. anterior */
    #formResultados thead th { font-weight: bold !important; padding: 0.13rem 0.24rem !important; line-height: 1.19 !important; }
    /* Contenedor de la información: reducir tamaño de letra y negrita */
    .registrar-resultados-wrap #formResultados tbody td,
    .registrar-resultados-wrap .estadisticas-valores,
    .registrar-resultados-wrap .nombre-jugador-linea { font-size: 0.88em !important; font-weight: bold !important; }
    .registrar-resultados-wrap #formResultados tbody td { line-height: 1.19 !important; }
    .registrar-resultados-wrap #formResultados .nombre-jugador-linea { line-height: 1.19 !important; }
    /* Suprimir incrementador en inputs numéricos */
    .registrar-resultados-wrap input[type="number"]::-webkit-outer-spin-button,
    .registrar-resultados-wrap input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .registrar-resultados-wrap input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
    
    /* Filas de jugadores: altura −15% (padding) y bordes sólidos */
    #formResultados tbody tr {
        border: 2px solid #333 !important;
    }
    #formResultados tbody tr td {
        padding: calc(0.13rem * 1.25) 0.24rem !important;
        vertical-align: middle;
        border: 1px solid #666;
    }
    .registrar-resultados-wrap #formResultados tbody td .form-control-sm {
        padding: 0.2125rem 0.425rem !important;
        min-height: 1.45rem !important;
        line-height: 1.15 !important;
    }
    .registrar-resultados-wrap #formResultados tbody td.columna-puntos .form-control {
        padding: 0.23rem 0.34rem !important;
        min-height: 2.565rem !important;
        line-height: 1.25 !important;
    }
    /* Pantallas ~13" (1200–1440px): otro escalón compacto para evitar scroll vertical */
    @media (min-width: 1200px) and (max-width: 1440px) {
        .registrar-resultados-wrap #formResultados thead th {
            padding: 0.11rem 0.2rem !important;
            line-height: 1.14 !important;
        }
        .registrar-resultados-wrap #formResultados tbody tr td {
            padding: calc(0.11rem * 1.25) 0.2rem !important;
            line-height: 1.14 !important;
        }
        .registrar-resultados-wrap #formResultados tbody td .form-control-sm {
            padding: 0.18rem 0.36rem !important;
            min-height: 1.35rem !important;
        }
        .registrar-resultados-wrap #formResultados tbody td.columna-puntos .form-control {
            padding: 0.19rem 0.28rem !important;
            min-height: 2.3625rem !important;
        }
    }
    #formResultados tbody tr.table-info,
    #formResultados tbody tr.table-success {
        border-left: 4px solid;
    }
    #formResultados tbody tr.table-info {
        border-left-color: #17a2b8;
    }
    #formResultados tbody tr.table-success {
        border-left-color: #28a745;
    }
    
    /* Sidebar sticky */
    .sidebar-sticky {
        position: sticky;
        top: 1.25rem;
        max-height: calc(100vh - 2.5rem);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Scroll si hay más de 10 mesas; altura ≈10 filas visibles (13" y similares) */
    .lista-mesas-scroll {
        max-height: min(60vh, calc(10 * 2.35rem));
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }
    .lista-mesas-scroll::-webkit-scrollbar {
        width: 6px;
    }
    .lista-mesas-scroll::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }
    .lista-mesas-scroll::-webkit-scrollbar-thumb {
        background: #667eea;
        border-radius: 3px;
    }
    
    /* Sin sticky en la tarjeta del formulario: evita saltos de scroll al enfocar campos */
    .formulario-resultados-sticky {
        position: static;
        align-self: stretch;
    }
    
    #formResultados {
        scroll-margin-top: 0;
        overflow-anchor: none;
    }
    .registrar-resultados-wrap .formulario-resultados-sticky .card-body {
        overflow-anchor: none;
    }
    
    /* Evitar salto de layout: reservar espacio estable para mensajes */
    .card.formulario-resultados-sticky .card-body > .alert {
        min-height: 2.2rem;
    }
    
    /* Formulario más compacto: menos padding en card-body del formulario de resultados (filas ~10% más compactas) */
    .registrar-resultados-wrap .formulario-resultados-sticky .card-body {
        padding: 0.55rem 0.7rem !important;
    }
    .registrar-resultados-wrap .formulario-resultados-sticky .card-header {
        padding: 0.36rem 0.7rem !important;
    }
    .registrar-resultados-wrap .formulario-resultados-sticky .card-body .mb-3 {
        margin-bottom: 0.6rem !important;
    }
    .registrar-resultados-wrap .formulario-resultados-sticky .card-body .mb-4 {
        margin-bottom: 0.75rem !important;
    }
    /* Filas del formulario ~10% más compactas: navegación, botones */
    .registrar-resultados-wrap .formulario-resultados-sticky .form-botones-row .gap-2 { gap: 0.45rem !important; }
    .registrar-resultados-wrap .formulario-resultados-sticky .form-botones-row { gap: 0.65rem !important; }
    /* Título ronda/mesa en la misma fila que Volver y buscar: tamaño contenido */
    .registrar-resultados-wrap .formulario-resultados-sticky .rr-nav-mesa-compact .rr-mesa-titulo {
        font-size: clamp(0.95rem, 2.2vw, 1.3rem) !important;
        line-height: 1.2 !important;
    }
    .rr-nav-mesa-compact .rr-input-ir-mesa {
        flex: 0 1 auto;
        max-width: min(100%, 6rem);
    }
    /* ~30% menos alto que form-control estándar (padding + línea) */
    .registrar-resultados-wrap .rr-nav-mesa-compact #input_ir_mesa.form-control {
        padding-top: 0.2625rem;
        padding-bottom: 0.2625rem;
        font-size: 0.9375rem;
        line-height: 1.25;
        min-height: 0;
    }
    /* Grid: izq. ronda+mesa | centro nº mesa | der. volver */
    .rr-nav-mesa-compact .rr-mesa-una-fila {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
        align-items: center;
        gap: 0.45rem;
        width: 100%;
    }
    .rr-nav-mesa-compact .rr-mesa-una-fila__left {
        justify-self: start;
        text-align: left;
        min-width: 0;
    }
    .rr-nav-mesa-compact .rr-mesa-una-fila__center {
        justify-self: center;
    }
    .rr-nav-mesa-compact .rr-mesa-una-fila__right {
        justify-self: end;
        display: flex;
        align-items: center;
        min-width: 0;
    }
    
    /* Mensaje de validación */
    #mensaje-validacion {
        display: none;
    }
    #mensaje-validacion.show {
        display: block;
    }
    
    /* Fila Observaciones + Zap/Chan compacta; textarea −20% de alto respecto al bloque anterior */
    .row-observaciones-zapchan { margin-bottom: 0.44rem; }
    .row-observaciones-zapchan .d-flex.mb-2 { margin-bottom: 0.32rem !important; }
    .row-observaciones-zapchan textarea.observaciones-compact {
        width: 60%;
        max-width: 60%;
        box-sizing: border-box;
        min-height: 1.92rem !important;
        padding: 0.28rem 0.4rem !important;
        font-size: 0.9em;
        line-height: 1.2;
        resize: vertical;
    }
    @media screen and (max-width: 768px) {
        .row-observaciones-zapchan textarea.observaciones-compact {
            width: 100%;
            max-width: 100%;
        }
    }
    .row-observaciones-zapchan .zapchan-linea { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 0.4rem; flex-wrap: nowrap; }
    .row-observaciones-zapchan .zapchan-jugador { display: inline-flex; align-items: center; gap: 0.2rem; }
    
    /* Responsive para móviles */
    @media screen and (max-width: 768px) {
        .registrar-resultados-wrap {
            width: 95%;
        }
        .container-fluid {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        /* Sidebar se convierte en dropdown o se oculta */
        .col-md-2.col-lg-1 {
            position: fixed;
            top: 0;
            left: -100%;
            width: 70%;
            max-width: 18.75rem;
            height: 100vh;
            z-index: 1050;
            background: white;
            transition: left 0.3s ease;
            box-shadow: 0.125rem 0 0.5rem rgba(0,0,0,0.2);
            overflow-y: auto;
        }
        
        .col-md-2.col-lg-1.show {
            left: 0;
        }
        
        .col-md-10.col-lg-11 {
            width: 100%;
            max-width: 100%;
        }
        
        /* Botón para mostrar sidebar en móvil */
        .sidebar-toggle {
            display: block;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1051;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-size: 1.25rem;
            cursor: pointer;
            box-shadow: 0 0.125rem 0.5rem rgba(0,0,0,0.3);
        }
        
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1049;
        }
        
        .overlay.show {
            display: block;
        }
        
        /* Tabla responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            font-size: clamp(0.7rem, 2vw, 0.875rem);
        }
        
        .table th,
        .table td {
            padding: 0.28rem 0.2rem;
            font-size: clamp(0.62rem, 1.7vw, 0.78rem);
        }
        .registrar-resultados-wrap #formResultados .table tbody tr td {
            padding-top: calc(0.28rem * 1.25) !important;
            padding-bottom: calc(0.28rem * 1.25) !important;
        }
        
        .columna-puntos {
            min-width: 5.4rem;
            max-width: 7.2rem;
        }
        .columna-forfait {
            min-width: 1.44rem;
            max-width: 1.6rem;
        }
        
        .columna-sancion {
            min-width: 3.5rem;
            max-width: 4.5rem;
        }
        
        .columna-tarjeta {
            min-width: 6.5rem;
            max-width: 8rem;
        }
        
        .columna-estadisticas {
            min-width: 4.7rem;
            font-size: clamp(0.6rem, 1.5vw, 0.75rem);
        }
        
        /* Inputs para touch (~15% más compactos para caber en pantalla) */
        .form-control {
            min-height: 2.1rem;
            font-size: clamp(0.8rem, 2.2vw, 0.95rem);
        }
        
        .form-control-sm {
            min-height: 1.7rem;
            font-size: clamp(0.7rem, 1.8vw, 0.8rem);
        }
        
        /* Botones algo más compactos */
        .btn {
            min-height: 2.3rem;
            padding: 0.4rem 0.85rem;
            font-size: clamp(0.8rem, 1.8vw, 0.95rem);
            touch-action: manipulation;
        }
        
        .btn-sm {
            min-height: 1.9rem;
            padding: 0.3rem 0.6rem;
            font-size: clamp(0.7rem, 1.6vw, 0.8rem);
        }
        
        /* Ajustes de espaciado (~15% más compacto para caber en pantalla) */
        .card-body {
            padding: 0.5rem 0.6rem !important;
        }
        
        .mb-3, .mb-4 {
            margin-bottom: 0.65rem !important;
        }
        
        /* Input de puntos: +35% alto respecto al compacto anterior */
        #puntos_pareja_A,
        #puntos_pareja_B {
            font-size: clamp(0.95rem, 2.8vw, 1.15rem) !important;
            min-height: 3.375rem;
        }
    }
    
    /* Responsive para móviles pequeños */
    @media screen and (max-width: 480px) {
        .table {
            font-size: clamp(0.65rem, 1.8vw, 0.75rem);
        }
        
        .table th,
        .table td {
            padding: 0.25rem 0.15rem;
        }
        .registrar-resultados-wrap #formResultados .table tbody tr td {
            padding-top: calc(0.25rem * 1.25) !important;
            padding-bottom: calc(0.25rem * 1.25) !important;
        }
        
        .columna-puntos {
            min-width: 4.8rem;
            max-width: 6rem;
        }
        .columna-forfait {
            min-width: 1.28rem;
            max-width: 1.6rem;
        }
        
        .columna-sancion {
            min-width: 3rem;
            max-width: 4rem;
        }
        
        .columna-tarjeta {
            min-width: 5.5rem;
            max-width: 7rem;
        }
        
        .columna-estadisticas {
            min-width: 3.4rem;
        }
        
        .tarjeta-btn {
            width: 1.75rem;
            height: 1.75rem;
            min-width: 1.75rem;
            min-height: 1.75rem;
            font-size: 0.9rem;
            border: none;
        }
        
        .d-flex.gap-2 {
            gap: 0.5rem !important;
        }
        
        .d-flex.gap-3 {
            gap: 0.75rem !important;
        }
    }
    
    /* Orientación horizontal en móviles */
    @media screen and (max-width: 768px) and (orientation: landscape) {
        .sidebar-sticky {
            max-height: calc(100vh - 1.5rem);
        }
        
        .table {
            font-size: clamp(0.7rem, 1.5vw, 0.85rem);
        }
    }
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/modern-registro-resultados.css">
<link rel="stylesheet" href="assets/css/torneo-context-switch.css">

<div class="container-fluid registrar-resultados-wrap <?php echo htmlspecialchars($contextClass, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (!empty($es_operador_ambito) && !empty($mesas_ambito)): ?>
    <div class="alert alert-info py-2 mb-2 d-flex align-items-center">
        <i class="fas fa-user-cog me-2"></i>
        <span><strong>Su ámbito:</strong> solo puede ver y registrar resultados en las mesas <?php echo min($mesas_ambito); ?> a <?php echo max($mesas_ambito); ?> (<?php echo count($mesas_ambito); ?> mesas asignadas).</span>
    </div>
    <?php endif; ?>
    <!-- Botón para mostrar sidebar en móvil -->
    <button class="sidebar-toggle d-md-none" onclick="toggleSidebar()" aria-label="Mostrar menú">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Overlay para cerrar sidebar en móvil -->
    <div class="overlay" onclick="toggleSidebar()"></div>
    
    <div class="row align-items-start">
        <!-- Panel Lateral - Lista de Mesas (ancho reducido 50%) -->
        <div class="col-md-2 col-lg-1" id="sidebar-mesas">
            <div class="card sidebar-sticky border-top border-primary border-2">
                <!-- Selector de Ronda/Partida -->
                <div class="card-body py-2 px-2 border-bottom bg-light">
                    <select id="selector-ronda" 
                            onchange="cambiarRonda(<?php echo $torneo['id']; ?>, this.value)"
                            class="form-control form-control-sm">
                        <?php foreach ($todasLasRondas as $r): ?>
                            <option value="<?php echo $r['partida']; ?>" <?php echo $r['partida'] == $ronda ? 'selected' : ''; ?>>
                                Ronda <?php echo $r['partida']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="card-body py-2 px-2 border-bottom bg-light text-center">
                    <span class="badge bg-warning text-dark px-2 py-1 small">
                        Faltan: <strong><?php echo $mesasPendientes; ?></strong>
                    </span>
                </div>

                <!-- Lista de Mesas (solo las pendientes) -->
                <?php 
                $mesasPendientesLista = array_filter($todasLasMesas ?? [], function($m) { return empty($m['tiene_resultados']); });
                $mesasPendientesLista = array_values($mesasPendientesLista);
                ?>
                <div class="card-body p-2 pt-1">
                    <div class="<?php echo count($mesasPendientesLista) > 10 ? 'lista-mesas-scroll' : ''; ?>">
                    <div class="list-group list-group-flush">
                        <?php if (empty($mesasPendientesLista)): ?>
                            <div class="list-group-item text-center text-success py-3 small">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <div>Todas las mesas completadas</div>
                            </div>
                        <?php else: ?>
                        <?php foreach ($mesasPendientesLista as $m): ?>
                            <?php $esActiva = $m['numero'] == $mesaActual; ?>
                            <a href="<?php echo $base_url . $action_param; ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $m['numero']; ?>"
                               class="mesa-item list-group-item list-group-item-action <?php echo $esActiva ? 'mesa-activa' : 'mesa-pendiente'; ?> rounded mb-1">
                                <div class="d-flex justify-content-between align-items-center mesa-item-inner">
                                    <strong><?php echo $m['numero']; ?></strong>
                                    <i class="far fa-circle"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Área Principal - Formulario (ampliada 20%) -->
        <div class="col-md-10 col-lg-11 col-form-registro">
            <?php
            $url_volver_panel = $base_url . $action_param . 'action=panel&torneo_id=' . (int)($torneo['id'] ?? 0);
            $rr_card_header_visible = (!empty($mostrar_countdown_correcciones) && !empty($countdown_fin_timestamp))
                || !empty($puede_cerrar_torneo)
                || (!empty($torneo['locked']) && (int)$torneo['locked'] === 1);
            ?>
            <div class="card formulario-resultados-sticky">
                <?php if ($rr_card_header_visible): ?>
                <div class="card-header d-flex flex-row align-items-center justify-content-between flex-wrap gap-2 py-2" style="background-color: #e3f2fd; color: #1565c0;">
                    <?php if (!empty($mostrar_countdown_correcciones) && !empty($countdown_fin_timestamp)): ?>
                    <p class="mb-0 font-weight-bold" style="font-size: 1.1rem;">
                        Correcciones se cierran en: <span id="countdown-correcciones" class="tabular-nums" data-fin="<?php echo (int)$countdown_fin_timestamp; ?>">--:--</span>
                    </p>
                    <?php endif; ?>
                    <div class="d-flex align-items-center flex-wrap justify-content-end ms-auto" style="gap:.5rem;">
                        <?php if (!empty($puede_cerrar_torneo)): ?>
                        <form method="POST" action="<?php echo $use_standalone ? $base_url : 'index.php?page=torneo_gestion'; ?>" class="mb-0" onsubmit="return confirm('¿Finalizar el torneo? A partir de ese momento no se podrán modificar datos.');">
                            <input type="hidden" name="action" value="cerrar_torneo">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                            <input type="hidden" name="torneo_id" value="<?php echo (int)$torneo['id']; ?>">
                            <button type="submit" class="btn btn-dark btn-sm font-weight-bold">
                                <i class="fas fa-lock mr-1"></i>Finalizar torneo
                            </button>
                        </form>
                        <?php elseif (!empty($torneo['locked']) && (int)$torneo['locked'] === 1): ?>
                        <span class="badge bg-secondary">Torneo finalizado</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="card-body">
                    <!-- Mensajes -->
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle mr-2"></i>
                            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['warning'])): ?>
                        <div class="alert alert-warning alert-dismissible fade show">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?>
                            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['info'])): ?>
                        <div class="alert alert-info alert-dismissible fade show">
                            <i class="fas fa-info-circle mr-2"></i>
                            <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?>
                            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($context_switcher['items'])): ?>
                    <div class="mb-2 d-flex align-items-center flex-wrap gap-2 rr-torneos-asociados-pills">
                        <?php
                        $tcs = [
                            'items' => $context_switcher['items'],
                            'active_id' => (int) ($context_switcher['active_tournament_id'] ?? 0),
                            'base_url' => $ctx_switch_base_url,
                            'sep' => $use_standalone ? '?' : '&',
                            'ronda_base' => (int) $ronda,
                            'map_max' => $map_max_partida_switch,
                            'mode' => 'registrar_resultados',
                            'theme' => 'on_light',
                            'show_select' => true,
                            'show_info' => false,
                            'show_pill_meta' => false,
                            'aria_label' => 'Torneos asociados (mismo evento)',
                            'extra' => ['mesa' => 0],
                        ];
                        require __DIR__ . '/../../resources/views/partials/torneo_context_switch.php';
                        ?>
                    </div>
                    <?php endif; ?>

                    <!-- Una fila: ronda+mesa (izq.) | nº mesa (centro) | Volver → panel -->
                    <?php
                    $rr_label_ronda_mesa = 'Ronda ' . (int)($ronda ?? 0) . ' — Mesa ' . (int)($mesaActual ?? 0);
                    ?>
                    <div class="mb-2 rr-nav-mesa-compact">
                        <div class="rr-mesa-una-fila">
                            <div class="rr-mesa-una-fila__left">
                                <div class="text-muted rr-mesa-titulo font-weight-bold text-nowrap" title="<?php echo htmlspecialchars($rr_label_ronda_mesa, ENT_QUOTES, 'UTF-8'); ?>">
                                    Ronda <?php echo (int)($ronda ?? 0); ?> · Mesa <?php echo (int)($mesaActual ?? 0); ?>
                                </div>
                            </div>
                            <div class="rr-mesa-una-fila__center">
                                <div class="input-group rr-input-ir-mesa flex-shrink-0">
                                    <input type="number" 
                                           id="input_ir_mesa" 
                                           name="ir_mesa"
                                           value="<?php echo (int)($mesaActual ?? 0); ?>"
                                           min="1"
                                           max="<?php echo !empty($todasLasMesas) ? max(array_column($todasLasMesas, 'numero')) : 1; ?>"
                                           class="form-control"
                                           style="text-align: center; min-width: 3rem; max-width: 4.25rem;"
                                           aria-label="Ir a mesa"
                                           onkeydown="manejarEnterIrAMesa(event);"
                                           oninput="validarNumeroMesa(this); actualizarEstadoPorMesa();"
                                           onblur="validarNumeroMesa(this); actualizarEstadoPorMesa();"
                                           onfocus="this.select();"
                                           placeholder=""
                                           title="Ingrese un número entre 1 y <?php echo !empty($todasLasMesas) ? max(array_column($todasLasMesas, 'numero')) : 1; ?>">
                                </div>
                            </div>
                            <div class="rr-mesa-una-fila__right gap-1">
                                <?php if (!empty($jugadores) && count($jugadores) == 4): ?>
                                <a href="<?php echo $base_url . $action_param; ?>action=reasignar_mesa&torneo_id=<?php echo (int)$torneo['id']; ?>&ronda=<?php echo (int)$ronda; ?>&mesa=<?php echo (int)$mesaActual; ?>"
                                   class="btn btn-sm flex-shrink-0" style="background-color: #20c997; color: white;" title="Intercambiar posiciones de jugadores en la mesa">
                                    <i class="fas fa-exchange-alt"></i> Reasignar
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars($url_volver_panel, ENT_QUOTES, 'UTF-8'); ?>"
                                   class="btn btn-outline-secondary btn-sm flex-shrink-0"
                                   title="Volver al panel"
                                   aria-label="Volver">
                                    <i class="fas fa-arrow-left me-1"></i> Volver
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mensaje de Validación -->
                    <div id="mensaje-validacion" class="mb-2"></div>

                    <!-- Formulario -->
                    <?php 
                    $mesaValida = ((int)($mesaActual ?? 0) > 0 && !empty($jugadores) && count($jugadores) == 4);
                    $mesasNumeros = array_column($todasLasMesas ?? [], 'numero');
                    if ($mesaValida && !empty($mesasNumeros)) {
                        $mesaValida = in_array((int)$mesaActual, array_map('intval', $mesasNumeros));
                    }
                    ?>
                    <?php if (empty($jugadores) || count($jugadores) != 4): ?>
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <p class="mb-2">No se encontraron los 4 jugadores de esta mesa</p>
                            <a href="<?php echo htmlspecialchars($url_volver_panel, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                        </div>
                    <?php elseif (!$mesaValida): ?>
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <p class="mb-2">No hay una mesa válida seleccionada. Seleccione una mesa de la lista para registrar resultados.</p>
                            <a href="<?php echo htmlspecialchars($url_volver_panel, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                        </div>
                    <?php else: ?>
                        <form method="POST" 
                              action="<?php echo $base_url; ?>" 
                              id="formResultados"
                              data-mesa-valida="1"
                              data-mesa="<?php echo (int)$mesaActual; ?>">
                            
                            <input type="hidden" name="action" value="guardar_resultados">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRF::token(); ?>">
                            <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                            <input type="hidden" name="ronda" value="<?php echo $ronda; ?>">
                            <input type="hidden" name="mesa" value="<?php echo (int)$mesaActual; ?>">

                            <!-- Tabla de Jugadores -->
                            <div class="table-responsive mb-2">
                                <table class="table table-bordered table-sm">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th rowspan="2" class="text-center align-middle columna-id">ID</th>
                                            <th rowspan="2" class="text-center align-middle columna-nombre">NOMBRE</th>
                                            <th rowspan="2" class="text-center align-middle columna-puntos">Puntos</th>
                                            <th rowspan="2" class="text-center align-middle columna-sancion">Sanción</th>
                                            <th rowspan="2" class="text-center align-middle columna-forfait">Forfait</th>
                                            <th rowspan="2" class="text-center align-middle columna-tarjeta">Tarjeta</th>
                                            <th rowspan="2" class="text-center align-middle columna-estadisticas" title="Posición, Ganados, Perdidos, Efectividad">
                                                <span class="stats-th-iniciales"><span>POS</span><span>GAN</span><span>PER</span><span>EFE</span></span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                            $parejaA = [];
                                            $parejaB = [];
                                            foreach ($jugadores as $jugador) {
                                                if ($jugador['secuencia'] <= 2) {
                                                    $parejaA[] = $jugador;
                                                } else {
                                                    $parejaB[] = $jugador;
                                                }
                                            }
                                            
                                            // Procesar Pareja A
                                            foreach ($parejaA as $index => $jugador): 
                                                $indiceArray = $index;
                                                $puntosParejaA = $jugador['resultado1'] ?? 0;
                                                if ($esTorneoParejas && $index === 1):
                                                    $tpP = (int)($jugador['inscrito']['tarjeta_previa'] ?? $jugador['inscrito']['tarjeta'] ?? 0);
                                                    $tieneP = $tpP >= 1;
                                                    $titP = $tieneP ? '⚠️ Tiene tarjeta previa. Sanción 80 pts = siguiente tarjeta (Roja/Negra).' : '';
                                                ?>
                                            <tr class="table-info">
                                                <td class="text-center font-weight-bold bg-info columna-id"><?php echo (int)$jugador['id_usuario']; ?></td>
                                                <td class="columna-nombre">
                                                    <span class="nombre-jugador-linea <?php echo $tieneP ? 'jugador-tarjeta-previa' : ''; ?>"<?php echo $titP !== '' ? ' title="' . htmlspecialchars($titP, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id]" value="<?php echo (int)$jugador['id']; ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id_usuario]" value="<?php echo (int)$jugador['id_usuario']; ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][secuencia]" value="<?php echo (int)$jugador['secuencia']; ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado1]" id="resultado1_<?php echo $indiceArray; ?>" value="<?php echo htmlspecialchars((string)($jugador['resultado1'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado2]" id="resultado2_<?php echo $indiceArray; ?>" value="<?php echo htmlspecialchars((string)($jugador['resultado2'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][tarjeta]" id="tarjeta_<?php echo $indiceArray; ?>" value="<?php echo (int)($jugador['tarjeta'] ?? 0); ?>">
                                                </td>
                                            </tr>
                                                <?php
                                                continue;
                                                endif;
                                                ?>
                                            <tr class="table-info">
                                                <!-- ID Usuario -->
                                                <td class="text-center font-weight-bold bg-info columna-id">
                                                    <?php echo $jugador['id_usuario']; ?>
                                                </td>
                                                
                                                <!-- Nombre (naranja si tiene tarjeta previa en partidas anteriores: advierte al administrador) -->
                                                <?php 
                                                $tarjetaPrevia = (int)($jugador['inscrito']['tarjeta_previa'] ?? $jugador['inscrito']['tarjeta'] ?? 0);
                                                $tieneTarjetaPrevia = $tarjetaPrevia >= 1; 
                                                $tituloTarjeta = $tieneTarjetaPrevia ? '⚠️ Tiene tarjeta previa. Sanción 80 pts = siguiente tarjeta (Roja/Negra).' : '';
                                                ?>
                                                <td class="columna-nombre">
                                                    <span class="nombre-jugador-linea <?php echo $tieneTarjetaPrevia ? 'jugador-tarjeta-previa' : ''; ?>" <?php echo $tituloTarjeta ? 'title="' . htmlspecialchars($tituloTarjeta) . '"' : ''; ?>><?php echo htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A'); ?></span>
                                                </td>
                                                
                                                <!-- Puntos -->
                                                <?php if ($index == 0): ?>
                                                <td rowspan="2" class="text-center align-middle columna-puntos">
                                <input type="number" 
                                       id="puntos_pareja_A"
                                       class="form-control text-center font-weight-bold"
                                       style="font-size: clamp(0.92rem, 2.75vw, 1.15rem);"
                                       value="<?php echo $puntosParejaA; ?>"
                                                           min="0"
                                                           max="999"
                                                           maxlength="3"
                                                           onfocus="this.select();"
                                                           onkeydown="manejarEnterPuntos(event, 'A', 'B');"
                                                           onchange="distribuirPuntos('A'); validarPuntosEnTiempoReal();"
                                                           onblur="validarPuntosInmediato(event);"
                                                           oninput="limitardigitos(this, 3); distribuirPuntos('A'); validarPuntosEnTiempoReal();"
                                                           required>
                                                </td>
                                                <?php endif; ?>
                                                
                                                <?php if (!$esTorneoParejas): ?>
                                                <!-- Sanción: 40=resta pts sin tarjeta; 80=resta pts y tarjeta (amarilla o siguiente si ya tenía) -->
                                                <td class="text-center columna-sancion" data-tarjeta-inscritos="<?php echo (int)($jugador['inscrito']['tarjeta_previa'] ?? $jugador['inscrito']['tarjeta'] ?? 0); ?>">
                                                    <input type="number" 
                                                           name="jugadores[<?php echo $indiceArray; ?>][sancion]"
                                                           class="form-control form-control-sm text-center"
                                                           value="<?php echo min((int)($jugador['sancion'] ?? 0), 80); ?>"
                                                           min="0" 
                                                           max="80"
                                                           placeholder="0"
                                                           oninput="validarSancionYTarjeta(<?php echo $indiceArray; ?>);"
                                                           onchange="validarSancionYTarjeta(<?php echo $indiceArray; ?>); validarPuntosEnTiempoReal();">
                                                    <small id="indicador_tarjeta_80_<?php echo $indiceArray; ?>" class="d-block text-muted mt-1" style="display:none !important;"></small>
                                                </td>
                                                
                                                <!-- Forfait (FF): marca checkbox y aplica procedimiento establecido -->
                                                <td class="text-center columna-forfait">
                                                    <input type="checkbox" 
                                                           name="jugadores[<?php echo $indiceArray; ?>][ff]"
                                                           id="ff_<?php echo $indiceArray; ?>"
                                                           class="form-check-input"
                                                           value="1"
                                                           <?php echo (isset($jugador['ff']) && $jugador['ff']) ? 'checked' : ''; ?>
                                                           onchange="validarPuntosEnTiempoReal();">
                                                </td>
                                                
                                                <!-- Tarjeta directa: marca checkbox correspondiente y procedimiento establecido -->
                                                <td class="text-center columna-tarjeta">
                                                    <div class="d-flex justify-content-center gap-1">
                                                        <button type="button" class="tarjeta-btn" 
                                                                data-tarjeta="1"
                                                                data-index="<?php echo $indiceArray; ?>"
                                                                onclick="seleccionarTarjeta(<?php echo $indiceArray; ?>, 1)"
                                                                title="Tarjeta Amarilla">🟨</button>
                                                        <button type="button" class="tarjeta-btn" 
                                                                data-tarjeta="3"
                                                                data-index="<?php echo $indiceArray; ?>"
                                                                onclick="seleccionarTarjeta(<?php echo $indiceArray; ?>, 3)"
                                                                title="Tarjeta Roja">🟥</button>
                                                        <button type="button" class="tarjeta-btn" 
                                                                data-tarjeta="4"
                                                                data-index="<?php echo $indiceArray; ?>"
                                                                onclick="seleccionarTarjeta(<?php echo $indiceArray; ?>, 4)"
                                                                title="Tarjeta Negra">⬛</button>
                                                        <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][tarjeta]" 
                                                               id="tarjeta_<?php echo $indiceArray; ?>" 
                                                               value="<?php echo $jugador['tarjeta'] ?? 0; ?>">
                                                    </div>
                                                </td>
                                                
                                                <?php 
                                                $pos = (int)($jugador['inscrito']['posicion'] ?? 0);
                                                $gan = (int)($jugador['inscrito']['ganados'] ?? 0);
                                                $per = (int)($jugador['inscrito']['perdidos'] ?? 0);
                                                $efec = (int)($jugador['inscrito']['efectividad'] ?? 0);
                                                $estadisticas_linea = $pos . ' - ' . $gan . ' - ' . $per . ' - ' . $efec;
                                                ?>
                                                <td class="text-center bg-light columna-estadisticas"><span class="estadisticas-valores"><?php echo htmlspecialchars($estadisticas_linea); ?></span></td>
                                                <?php else:
                                                    $jugP0 = $parejaA[0] ?? $jugador;
                                                    $jugP1 = $parejaA[1] ?? $jugP0;
                                                    $tpMaxA = max(
                                                        (int)($jugP0['inscrito']['tarjeta_previa'] ?? $jugP0['inscrito']['tarjeta'] ?? 0),
                                                        (int)($jugP1['inscrito']['tarjeta_previa'] ?? $jugP1['inscrito']['tarjeta'] ?? 0)
                                                    );
                                                    $sancUnifA = min(max((int)($jugP0['sancion'] ?? 0), (int)($jugP1['sancion'] ?? 0)), 80);
                                                    $tarjUnifA = max((int)($jugP0['tarjeta'] ?? 0), (int)($jugP1['tarjeta'] ?? 0));
                                                    $ffUnifA = (!empty($jugP0['ff']) || !empty($jugP1['ff']));
                                                    $posP = (int)($jugP0['inscrito']['posicion'] ?? 0);
                                                    $ganP = (int)($jugP0['inscrito']['ganados'] ?? 0);
                                                    $perP = (int)($jugP0['inscrito']['perdidos'] ?? 0);
                                                    $efecP = (int)($jugP0['inscrito']['efectividad'] ?? 0);
                                                    $estadisticasParejaA = $posP . ' - ' . $ganP . ' - ' . $perP . ' - ' . $efecP;
                                                ?>
                                                <td rowspan="2" class="text-center align-middle columna-sancion" data-tarjeta-inscritos="<?php echo $tpMaxA; ?>">
                                                    <input type="number"
                                                           name="jugadores[0][sancion]"
                                                           class="form-control form-control-sm text-center"
                                                           value="<?php echo $sancUnifA; ?>"
                                                           min="0"
                                                           max="80"
                                                           placeholder="0"
                                                           oninput="validarSancionYTarjeta(0); sincronizarTarjetaOcultaPareja(0, 1);"
                                                           onchange="validarSancionYTarjeta(0); sincronizarTarjetaOcultaPareja(0, 1); validarPuntosEnTiempoReal();">
                                                    <small id="indicador_tarjeta_80_0" class="d-block text-muted mt-1" style="display:none !important;"></small>
                                                </td>
                                                <td rowspan="2" class="text-center align-middle columna-forfait">
                                                    <input type="checkbox"
                                                           name="jugadores[0][ff]"
                                                           id="ff_0"
                                                           class="form-check-input"
                                                           value="1"
                                                           <?php echo $ffUnifA ? 'checked' : ''; ?>
                                                           onchange="validarPuntosEnTiempoReal();">
                                                </td>
                                                <td rowspan="2" class="text-center align-middle columna-tarjeta">
                                                    <div class="d-flex justify-content-center gap-1">
                                                        <button type="button" class="tarjeta-btn"
                                                                data-tarjeta="1"
                                                                data-index="0"
                                                                onclick="seleccionarTarjeta(0, 1)"
                                                                title="Tarjeta Amarilla">🟨</button>
                                                        <button type="button" class="tarjeta-btn"
                                                                data-tarjeta="3"
                                                                data-index="0"
                                                                onclick="seleccionarTarjeta(0, 3)"
                                                                title="Tarjeta Roja">🟥</button>
                                                        <button type="button" class="tarjeta-btn"
                                                                data-tarjeta="4"
                                                                data-index="0"
                                                                onclick="seleccionarTarjeta(0, 4)"
                                                                title="Tarjeta Negra">⬛</button>
                                                        <input type="hidden" name="jugadores[0][tarjeta]"
                                                               id="tarjeta_0"
                                                               value="<?php echo $tarjUnifA; ?>">
                                                    </div>
                                                </td>
                                                <td rowspan="2" class="text-center bg-light columna-estadisticas align-middle"><span class="estadisticas-valores"><?php echo htmlspecialchars($estadisticasParejaA); ?></span></td>
                                                <?php endif; ?>
                                                
                                                <!-- Campos Hidden -->
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id]" 
                                                       value="<?php echo $jugador['id']; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id_usuario]" 
                                                       value="<?php echo $jugador['id_usuario']; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][secuencia]" 
                                                       value="<?php echo $jugador['secuencia']; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado1]" 
                                                       id="resultado1_<?php echo $indiceArray; ?>" 
                                                       value="<?php echo $jugador['resultado1'] ?? 0; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado2]" 
                                                       id="resultado2_<?php echo $indiceArray; ?>" 
                                                       value="<?php echo $jugador['resultado2'] ?? 0; ?>">
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php 
                                            // Procesar Pareja B
                                            foreach ($parejaB as $index => $jugador): 
                                                $indiceArray = 2 + $index;
                                                $puntosParejaB = $jugador['resultado1'] ?? 0;
                                                if ($esTorneoParejas && $index === 1):
                                                    $tpP = (int)($jugador['inscrito']['tarjeta_previa'] ?? $jugador['inscrito']['tarjeta'] ?? 0);
                                                    $tieneP = $tpP >= 1;
                                                    $titP = $tieneP ? '⚠️ Tiene tarjeta previa. Sanción 80 pts = siguiente tarjeta (Roja/Negra).' : '';
                                                ?>
                                            <tr class="table-success">
                                                <td class="text-center font-weight-bold bg-success columna-id"><?php echo (int)$jugador['id_usuario']; ?></td>
                                                <td class="columna-nombre">
                                                    <span class="nombre-jugador-linea <?php echo $tieneP ? 'jugador-tarjeta-previa' : ''; ?>"<?php echo $titP !== '' ? ' title="' . htmlspecialchars($titP, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id]" value="<?php echo (int)$jugador['id']; ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id_usuario]" value="<?php echo (int)$jugador['id_usuario']; ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][secuencia]" value="<?php echo (int)$jugador['secuencia']; ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado1]" id="resultado1_<?php echo $indiceArray; ?>" value="<?php echo htmlspecialchars((string)($jugador['resultado1'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado2]" id="resultado2_<?php echo $indiceArray; ?>" value="<?php echo htmlspecialchars((string)($jugador['resultado2'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][tarjeta]" id="tarjeta_<?php echo $indiceArray; ?>" value="<?php echo (int)($jugador['tarjeta'] ?? 0); ?>">
                                                </td>
                                            </tr>
                                                <?php
                                                continue;
                                                endif;
                                                ?>
                                            <tr class="table-success">
                                                <!-- ID Usuario -->
                                                <td class="text-center font-weight-bold bg-success columna-id">
                                                    <?php echo $jugador['id_usuario']; ?>
                                                </td>
                                                
                                                <!-- Nombre (naranja si tiene tarjeta previa en partidas anteriores: advierte al administrador) -->
                                                <?php 
                                                $tarjetaPrevia = (int)($jugador['inscrito']['tarjeta_previa'] ?? $jugador['inscrito']['tarjeta'] ?? 0);
                                                $tieneTarjetaPrevia = $tarjetaPrevia >= 1; 
                                                $tituloTarjeta = $tieneTarjetaPrevia ? '⚠️ Tiene tarjeta previa. Sanción 80 pts = siguiente tarjeta (Roja/Negra).' : '';
                                                ?>
                                                <td class="columna-nombre">
                                                    <span class="nombre-jugador-linea <?php echo $tieneTarjetaPrevia ? 'jugador-tarjeta-previa' : ''; ?>" <?php echo $tituloTarjeta ? 'title="' . htmlspecialchars($tituloTarjeta) . '"' : ''; ?>><?php echo htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A'); ?></span>
                                                </td>
                                                
                                                <!-- Puntos -->
                                                <?php if ($index == 0): ?>
                                                <td rowspan="2" class="text-center align-middle columna-puntos">
                                                    <input type="number" 
                                                           id="puntos_pareja_B"
                                                           class="form-control text-center font-weight-bold"
                                                           style="font-size: clamp(0.92rem, 2.75vw, 1.15rem);"
                                                           value="<?php echo $puntosParejaB; ?>"
                                                           min="0" 
                                                           max="999"
                                                           maxlength="3"
                                                           onfocus="this.select();"
                                                           onkeydown="manejarEnterPuntos(event, 'B', 'guardar');"
                                                           onchange="distribuirPuntos('B'); validarPuntosEnTiempoReal();"
                                                           onblur="validarPuntosInmediato(event);"
                                                           oninput="limitardigitos(this, 3); distribuirPuntos('B'); validarPuntosEnTiempoReal();"
                                                           required>
                                                </td>
                                                <?php endif; ?>
                                                
                                                <?php if (!$esTorneoParejas): ?>
                                                <!-- Sanción: 40=resta pts sin tarjeta; 80=resta pts y tarjeta (amarilla o siguiente si ya tenía) -->
                                                <td class="text-center columna-sancion" data-tarjeta-inscritos="<?php echo (int)($jugador['inscrito']['tarjeta_previa'] ?? $jugador['inscrito']['tarjeta'] ?? 0); ?>">
                                                    <input type="number" 
                                                           name="jugadores[<?php echo $indiceArray; ?>][sancion]"
                                                           class="form-control form-control-sm text-center"
                                                           value="<?php echo min((int)($jugador['sancion'] ?? 0), 80); ?>"
                                                           min="0" 
                                                           max="80"
                                                           placeholder="0"
                                                           oninput="validarSancionYTarjeta(<?php echo $indiceArray; ?>);"
                                                           onchange="validarSancionYTarjeta(<?php echo $indiceArray; ?>); validarPuntosEnTiempoReal();">
                                                    <small id="indicador_tarjeta_80_<?php echo $indiceArray; ?>" class="d-block text-muted mt-1" style="display:none !important;"></small>
                                                </td>
                                                
                                                <!-- Forfait (FF) -->
                                                <td class="text-center columna-forfait">
                                                    <input type="checkbox" 
                                                           name="jugadores[<?php echo $indiceArray; ?>][ff]"
                                                           id="ff_<?php echo $indiceArray; ?>"
                                                           class="form-check-input"
                                                           value="1"
                                                           <?php echo (isset($jugador['ff']) && $jugador['ff']) ? 'checked' : ''; ?>
                                                           onchange="validarPuntosEnTiempoReal();">
                                                </td>
                                                
                                                <!-- Tarjeta -->
                                                <td class="text-center columna-tarjeta">
                                                    <div class="d-flex justify-content-center gap-1">
                                                        <button type="button" class="tarjeta-btn" 
                                                                data-tarjeta="1"
                                                                data-index="<?php echo $indiceArray; ?>"
                                                                onclick="seleccionarTarjeta(<?php echo $indiceArray; ?>, 1)"
                                                                title="Tarjeta Amarilla">🟨</button>
                                                        <button type="button" class="tarjeta-btn" 
                                                                data-tarjeta="3"
                                                                data-index="<?php echo $indiceArray; ?>"
                                                                onclick="seleccionarTarjeta(<?php echo $indiceArray; ?>, 3)"
                                                                title="Tarjeta Roja">🟥</button>
                                                        <button type="button" class="tarjeta-btn" 
                                                                data-tarjeta="4"
                                                                data-index="<?php echo $indiceArray; ?>"
                                                                onclick="seleccionarTarjeta(<?php echo $indiceArray; ?>, 4)"
                                                                title="Tarjeta Negra">⬛</button>
                                                        <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][tarjeta]" 
                                                               id="tarjeta_<?php echo $indiceArray; ?>" 
                                                               value="<?php echo $jugador['tarjeta'] ?? 0; ?>">
                                                    </div>
                                                </td>
                                                
                                                <?php 
                                                $pos = (int)($jugador['inscrito']['posicion'] ?? 0);
                                                $gan = (int)($jugador['inscrito']['ganados'] ?? 0);
                                                $per = (int)($jugador['inscrito']['perdidos'] ?? 0);
                                                $efec = (int)($jugador['inscrito']['efectividad'] ?? 0);
                                                $estadisticas_linea = $pos . ' - ' . $gan . ' - ' . $per . ' - ' . $efec;
                                                ?>
                                                <td class="text-center bg-light columna-estadisticas"><span class="estadisticas-valores"><?php echo htmlspecialchars($estadisticas_linea); ?></span></td>
                                                <?php else:
                                                    $jugP0 = $parejaB[0] ?? $jugador;
                                                    $jugP1 = $parejaB[1] ?? $jugP0;
                                                    $tpMaxB = max(
                                                        (int)($jugP0['inscrito']['tarjeta_previa'] ?? $jugP0['inscrito']['tarjeta'] ?? 0),
                                                        (int)($jugP1['inscrito']['tarjeta_previa'] ?? $jugP1['inscrito']['tarjeta'] ?? 0)
                                                    );
                                                    $sancUnifB = min(max((int)($jugP0['sancion'] ?? 0), (int)($jugP1['sancion'] ?? 0)), 80);
                                                    $tarjUnifB = max((int)($jugP0['tarjeta'] ?? 0), (int)($jugP1['tarjeta'] ?? 0));
                                                    $ffUnifB = (!empty($jugP0['ff']) || !empty($jugP1['ff']));
                                                    $posP = (int)($jugP0['inscrito']['posicion'] ?? 0);
                                                    $ganP = (int)($jugP0['inscrito']['ganados'] ?? 0);
                                                    $perP = (int)($jugP0['inscrito']['perdidos'] ?? 0);
                                                    $efecP = (int)($jugP0['inscrito']['efectividad'] ?? 0);
                                                    $estadisticasParejaB = $posP . ' - ' . $ganP . ' - ' . $perP . ' - ' . $efecP;
                                                ?>
                                                <td rowspan="2" class="text-center align-middle columna-sancion" data-tarjeta-inscritos="<?php echo $tpMaxB; ?>">
                                                    <input type="number"
                                                           name="jugadores[2][sancion]"
                                                           class="form-control form-control-sm text-center"
                                                           value="<?php echo $sancUnifB; ?>"
                                                           min="0"
                                                           max="80"
                                                           placeholder="0"
                                                           oninput="validarSancionYTarjeta(2); sincronizarTarjetaOcultaPareja(2, 3);"
                                                           onchange="validarSancionYTarjeta(2); sincronizarTarjetaOcultaPareja(2, 3); validarPuntosEnTiempoReal();">
                                                    <small id="indicador_tarjeta_80_2" class="d-block text-muted mt-1" style="display:none !important;"></small>
                                                </td>
                                                <td rowspan="2" class="text-center align-middle columna-forfait">
                                                    <input type="checkbox"
                                                           name="jugadores[2][ff]"
                                                           id="ff_2"
                                                           class="form-check-input"
                                                           value="1"
                                                           <?php echo $ffUnifB ? 'checked' : ''; ?>
                                                           onchange="validarPuntosEnTiempoReal();">
                                                </td>
                                                <td rowspan="2" class="text-center align-middle columna-tarjeta">
                                                    <div class="d-flex justify-content-center gap-1">
                                                        <button type="button" class="tarjeta-btn"
                                                                data-tarjeta="1"
                                                                data-index="2"
                                                                onclick="seleccionarTarjeta(2, 1)"
                                                                title="Tarjeta Amarilla">🟨</button>
                                                        <button type="button" class="tarjeta-btn"
                                                                data-tarjeta="3"
                                                                data-index="2"
                                                                onclick="seleccionarTarjeta(2, 3)"
                                                                title="Tarjeta Roja">🟥</button>
                                                        <button type="button" class="tarjeta-btn"
                                                                data-tarjeta="4"
                                                                data-index="2"
                                                                onclick="seleccionarTarjeta(2, 4)"
                                                                title="Tarjeta Negra">⬛</button>
                                                        <input type="hidden" name="jugadores[2][tarjeta]"
                                                               id="tarjeta_2"
                                                               value="<?php echo $tarjUnifB; ?>">
                                                    </div>
                                                </td>
                                                <td rowspan="2" class="text-center bg-light columna-estadisticas align-middle"><span class="estadisticas-valores"><?php echo htmlspecialchars($estadisticasParejaB); ?></span></td>
                                                <?php endif; ?>
                                                
                                                <!-- Campos Hidden -->
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id]" 
                                                       value="<?php echo $jugador['id']; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][id_usuario]" 
                                                       value="<?php echo $jugador['id_usuario']; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][secuencia]" 
                                                       value="<?php echo $jugador['secuencia']; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado1]" 
                                                       id="resultado1_<?php echo $indiceArray; ?>" 
                                                       value="<?php echo $jugador['resultado1'] ?? 0; ?>">
                                                <input type="hidden" name="jugadores[<?php echo $indiceArray; ?>][resultado2]" 
                                                       id="resultado2_<?php echo $indiceArray; ?>" 
                                                       value="<?php echo $jugador['resultado2'] ?? 0; ?>">
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Observaciones + Zap/Chan (un solo indicador por mesa, aplica a todos los jugadores) -->
                            <div class="row-observaciones-zapchan mb-2">
                                <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                    <label class="font-weight-bold mb-0">
                                        <i class="fas fa-comment-alt mr-1"></i>Observaciones
                                    </label>
                                    <span class="text-muted small">Observaciones sobre la partida (opcional)</span>
                                    <div class="zapchan-linea d-flex align-items-center gap-2 flex-nowrap">
                                        <span class="text-muted small">Mesa:</span>
                                        <?php 
                                        $mesa_tiene_chancleta = !empty(array_filter($jugadores, function($j) { return !empty($j['chancleta']) && (int)$j['chancleta'] > 0; }));
                                        $mesa_tiene_zapato = !empty(array_filter($jugadores, function($j) { return !empty($j['zapato']) && (int)$j['zapato'] > 0; }));
                                        ?>
                                        <label class="mb-0 cursor-pointer d-inline">
                                            <input type="radio" name="pena_mesa" id="pena_mesa_chancleta" value="chancleta" class="form-check-input" <?php echo $mesa_tiene_chancleta ? 'checked' : ''; ?>>
                                            <span class="ml-1">🥿</span>
                                        </label>
                                        <label class="mb-0 cursor-pointer d-inline">
                                            <input type="radio" name="pena_mesa" id="pena_mesa_zapato" value="zapato" class="form-check-input" <?php echo $mesa_tiene_zapato ? 'checked' : ''; ?>>
                                            <span class="ml-1">👞</span>
                                        </label>
                                        <?php foreach ($jugadores as $indiceZap => $jugadorZap): ?>
                                        <input type="hidden" name="jugadores[<?php echo $indiceZap; ?>][chancleta]" id="chancleta_<?php echo $indiceZap; ?>" value="<?php echo $jugadorZap['chancleta'] ?? 0; ?>">
                                        <input type="hidden" name="jugadores[<?php echo $indiceZap; ?>][zapato]" id="zapato_<?php echo $indiceZap; ?>" value="<?php echo $jugadorZap['zapato'] ?? 0; ?>">
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <textarea name="observaciones" 
                                          rows="1"
                                          class="form-control observaciones-compact"
                                          placeholder="Observaciones sobre la partida (opcional)"><?php echo htmlspecialchars($observacionesMesa ?? ''); ?></textarea>
                            </div>

                            <!-- Botones de Acción -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 form-botones-row">
                                <!-- Resumen (si aplica), navegación mesa (Volver arriba a la derecha con Reasignar) -->
                                <div class="d-flex gap-1 flex-wrap align-items-center">
                                    <?php if (isset($vieneDeResumen) && $vieneDeResumen && isset($inscritoId) && $inscritoId): ?>
                                        <?php
                                        $from_pf = isset($_GET['from_original']) ? $_GET['from_original'] : (isset($_GET['from']) && $_GET['from'] !== 'resumen' ? $_GET['from'] : '');
                                        $from_up = $from_pf !== '' ? '&from=' . urlencode($from_pf) : '';
                                        $url_volver_resumen = $base_url . $action_param . 'action=resumen_individual&torneo_id=' . (int)$torneo['id'] . '&inscrito_id=' . (int)$inscritoId . $from_up;
                                        ?>
                                        <a href="<?php echo htmlspecialchars($url_volver_resumen, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="btn btn-info btn-sm">
                                            <i class="fas fa-arrow-left"></i> Resumen
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($mesaAnterior ?? null): ?>
                                        <a href="<?php echo $base_url . $action_param; ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $mesaAnterior; ?>"
                                           class="btn btn-secondary btn-sm">
                                            <i class="fas fa-arrow-left"></i> Ant.
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($mesaSiguiente ?? null): ?>
                                        <a href="<?php echo $base_url . $action_param; ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $mesaSiguiente; ?>"
                                           class="btn btn-secondary btn-sm">
                                            Sig.<i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <!-- Acciones -->
                                <div class="d-flex gap-1 align-items-center flex-wrap">
                                    <button type="button" 
                                            id="btn-limpiar"
                                            onclick="limpiarFormulario()"
                                            class="btn btn-warning btn-sm">
                                        <i class="fas fa-eraser"></i> Limpiar
                                    </button>
                                    <button type="button"
                                            id="btn-mano-desierta"
                                            onclick="guardarManoDesierta()"
                                            class="btn btn-secondary btn-sm">
                                        <i class="fas fa-flag"></i> Mano desierta
                                    </button>
                                    <button type="button"
                                            id="btn-correccion-directa"
                                            onclick="abrirCorreccionDirecta()"
                                            class="btn btn-outline-primary btn-sm"
                                            title="Ir a otra ronda y mesa para corregir ingresos">
                                        <i class="fas fa-edit"></i> Corrección directa
                                    </button>
                                    
                                    <button type="submit" 
                                            id="btn-guardar"
                                            class="btn btn-success font-weight-bold btn-sm"
                                            disabled>
                                        <i class="fas fa-save"></i> GUARDAR
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const ES_TORNEO_PAREJAS = <?php echo $esTorneoParejas ? 'true' : 'false'; ?>;
const RR_TORNEO_ID_COR = <?php echo (int) ($torneo['id'] ?? 0); ?>;
const RR_MAX_RONDA_COR = <?php echo (int) $rr_max_ronda_correccion; ?>;
const RR_RONDA_ACTUAL_COR = <?php echo (int) ($ronda ?? 0); ?>;
const RR_MESA_ACTUAL_COR = <?php echo (int) ($mesaActual ?? 0); ?>;
const RR_URL_BASE_COR = <?php echo json_encode($base_url . $action_param, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

/**
 * Corrección directa: solicita ronda y mesa y navega al mismo formulario de ingresos.
 */
async function abrirCorreccionDirecta() {
    const defR = RR_RONDA_ACTUAL_COR > 0 ? String(RR_RONDA_ACTUAL_COR) : '1';
    const defM = RR_MESA_ACTUAL_COR > 0 ? String(RR_MESA_ACTUAL_COR) : '1';

    if (typeof Swal === 'undefined') {
        const r = parseInt(window.prompt('Ronda a corregir:', defR) || '0', 10);
        const m = parseInt(window.prompt('Mesa a corregir:', defM) || '0', 10);
        if (!r || r < 1 || !m || m < 1) {
            return;
        }
        window.location.href = RR_URL_BASE_COR + 'action=registrar_resultados&torneo_id=' + RR_TORNEO_ID_COR + '&ronda=' + r + '&mesa=' + m;
        return;
    }

    const { value, isConfirmed } = await Swal.fire({
        title: 'Corrección directa',
        html:
            '<p class="text-muted small text-left mb-2">Indique la <strong>ronda</strong> y la <strong>mesa</strong> que desea corregir. Se abrirá el mismo formulario de ingresos.</p>' +
            '<div class="form-group text-left mb-2">' +
            '<label for="swal-cor-ronda" class="d-block small font-weight-bold">Ronda</label>' +
            '<input id="swal-cor-ronda" type="number" class="form-control" min="1" max="' + RR_MAX_RONDA_COR + '" value="' + defR + '">' +
            '</div>' +
            '<div class="form-group text-left mb-0">' +
            '<label for="swal-cor-mesa" class="d-block small font-weight-bold">Mesa</label>' +
            '<input id="swal-cor-mesa" type="number" class="form-control" min="1" value="' + defM + '">' +
            '</div>',
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Ir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#667eea',
        cancelButtonColor: '#6c757d',
        didOpen: () => {
            const el = document.getElementById('swal-cor-ronda');
            if (el) {
                el.focus();
                el.select();
            }
        },
        preConfirm: () => {
            const elR = document.getElementById('swal-cor-ronda');
            const elM = document.getElementById('swal-cor-mesa');
            const r = parseInt(elR && elR.value ? String(elR.value).trim() : '0', 10);
            const m = parseInt(elM && elM.value ? String(elM.value).trim() : '0', 10);
            if (!r || r < 1) {
                Swal.showValidationMessage('Indique una ronda válida (mayor o igual a 1).');
                return false;
            }
            if (RR_MAX_RONDA_COR > 0 && r > RR_MAX_RONDA_COR) {
                Swal.showValidationMessage('La ronda no puede ser mayor a ' + RR_MAX_RONDA_COR + '.');
                return false;
            }
            if (!m || m < 1) {
                Swal.showValidationMessage('Indique una mesa válida (mayor o igual a 1).');
                return false;
            }
            return { r: r, m: m };
        }
    });

    if (isConfirmed && value && value.r && value.m) {
        window.location.href = RR_URL_BASE_COR + 'action=registrar_resultados&torneo_id=' + RR_TORNEO_ID_COR + '&ronda=' + value.r + '&mesa=' + value.m;
    }
}

function sincronizarTarjetaOcultaPareja(leaderIdx, followerIdx) {
    if (!ES_TORNEO_PAREJAS) return;
    const lead = document.getElementById('tarjeta_' + leaderIdx);
    const fol = document.getElementById('tarjeta_' + followerIdx);
    if (lead && fol) fol.value = lead.value;
}

// Función para mostrar/ocultar sidebar en móvil
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar-mesas');
    const overlay = document.querySelector('.overlay');
    
    if (sidebar && overlay) {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    }
}

// Cerrar sidebar al hacer clic fuera en móvil
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar-mesas');
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const overlay = document.querySelector('.overlay');
    
    if (window.innerWidth <= 768 && sidebar && overlay) {
        if (!sidebar.contains(event.target) && 
            !sidebarToggle.contains(event.target) && 
            sidebar.classList.contains('show')) {
            toggleSidebar();
        }
    }
});

// Función para cambiar de ronda
function cambiarRonda(torneoId, ronda) {
    window.location.href = '<?php echo $base_url . $action_param; ?>action=mesas&torneo_id=' + torneoId + '&ronda=' + ronda;
}

// Función para limitar dígitos
function limitardigitos(input, max) {
    if (input.value.length > max) {
        input.value = input.value.slice(0, max);
    }
}

// Función para manejar Enter en campos de puntos
function manejarEnterPuntos(event, parejaActual, siguienteAccion) {
    if (event.key === 'Enter') {
        event.preventDefault();
        
        // Solo navegar entre campos, NO guardar automáticamente
        if (siguienteAccion === 'guardar') {
            // Si es el último campo, solo enfocar el botón de guardar (NO guardar)
            const btnGuardar = document.getElementById('btn-guardar');
            if (btnGuardar) {
                btnGuardar.focus();
            }
        } else {
            // Ir al siguiente campo de puntos
            const siguienteCampo = document.getElementById('puntos_pareja_' + siguienteAccion);
            if (siguienteCampo) {
                siguienteCampo.focus();
                siguienteCampo.select();
            }
        }
    }
}


// Función para manejar Enter en el input de ir a mesa (parte superior)
function manejarEnterIrAMesa(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        irAMesaDesdeInput();
    }
}

// Función para validar el número de mesa en tiempo real
function validarNumeroMesa(input) {
    if (!input) {
        return false;
    }
    
    const valor = input.value.trim();
    const maxMesa = <?php echo !empty($todasLasMesas) ? max(array_column($todasLasMesas, 'numero')) : 0; ?>;
    
    // Si el campo está vacío, no validar aún (pero 0 sí debe validarse como inválido)
    if (valor === '') {
        input.classList.remove('is-invalid', 'is-valid');
        input.setCustomValidity('');
        return true;
    }
    
    // Si el valor es 0, marcarlo como inválido
    if (valor === '0') {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        input.setCustomValidity('Número de mesa inválido');
        return false;
    }
    
    const numeroMesa = parseInt(valor);
    
    // Validar que sea un número válido
    if (isNaN(numeroMesa)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        input.setCustomValidity('Número de mesa inválido');
        return false;
    }
    
    // Validar que sea mayor a 0
    if (numeroMesa <= 0) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        input.setCustomValidity('Número de mesa inválido');
        return false;
    }
    
    // Validar que no exceda el máximo de mesas asignadas
    if (maxMesa > 0 && numeroMesa > maxMesa) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        input.setCustomValidity(`El número máximo de mesa asignada es ${maxMesa}`);
        return false;
    }
    
    // Verificar que la mesa existe en la lista de mesas disponibles
    const mesasDisponibles = [<?php echo !empty($todasLasMesas) ? implode(',', array_column($todasLasMesas, 'numero')) : ''; ?>];
    if (mesasDisponibles.length > 0 && !mesasDisponibles.includes(numeroMesa)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        input.setCustomValidity(`La mesa #${numeroMesa} no está asignada en esta ronda`);
        return false;
    }
    
    // Si pasa todas las validaciones
    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
    input.setCustomValidity('');
    return true;
}

// Función para ir a mesa usando solo el número de mesa (ronda actual)
function irAMesaDesdeInput() {
    const inputMesa = document.getElementById('input_ir_mesa');
    
    if (!inputMesa) {
        return;
    }
    
    const valor = inputMesa.value.trim();
    
    // Validar que no esté vacío
    if (valor === '') {
        Swal.fire({
            icon: 'error',
            title: 'Mesa inválida',
            text: 'Número de mesa inválido',
            confirmButtonColor: '#667eea'
        });
        inputMesa.focus();
        inputMesa.select();
        return;
    }
    
    const numeroMesa = parseInt(valor);
    
    // Validar que sea un número válido y mayor a 0 (incluye el caso de 0)
    if (isNaN(numeroMesa) || numeroMesa <= 0) {
        Swal.fire({
            icon: 'error',
            title: 'Mesa inválida',
            text: 'Número de mesa inválido',
            confirmButtonColor: '#667eea'
        });
        inputMesa.focus();
        inputMesa.select();
        return;
    }
    
    // Validar el valor antes de proceder (validación completa)
    if (!validarNumeroMesa(inputMesa)) {
        // Si la validación falla, mostrar mensaje de error
        const mensajeError = inputMesa.validationMessage || inputMesa.getAttribute('data-error') || 'Número de mesa inválido';
        
        Swal.fire({
            icon: 'error',
            title: 'Mesa inválida',
            text: mensajeError,
            confirmButtonColor: '#667eea'
        });
        
        inputMesa.focus();
        inputMesa.select();
        return;
    }
    const torneoId = <?php echo $torneo['id']; ?>;
    const rondaActual = <?php echo $ronda; ?>;
    
    // Ir directamente a la mesa
    const url = '<?php echo $base_url . $action_param; ?>action=registrar_resultados&torneo_id=' + torneoId + '&ronda=' + rondaActual + '&mesa=' + numeroMesa;
    window.location.href = url;
}

// Función para distribuir puntos de las parejas a los jugadores individuales
function distribuirPuntos(pareja) {
    // Si se llama con 'todas', distribuir ambas parejas
    if (pareja === 'todas') {
        distribuirPuntos('A');
        distribuirPuntos('B');
        return;
    }
    
    // Obtener puntos actuales de ambas parejas
    const puntosParejaA = parseInt(document.getElementById('puntos_pareja_A').value) || 0;
    const puntosParejaB = parseInt(document.getElementById('puntos_pareja_B').value) || 0;
    
    // Determinar qué pareja estamos procesando
    let puntosPareja, puntosContraria, indices;
    if (pareja === 'A') {
        puntosPareja = puntosParejaA;
        puntosContraria = puntosParejaB;
        indices = [0, 1]; // Secuencias 1-2
    } else {
        puntosPareja = puntosParejaB;
        puntosContraria = puntosParejaA;
        indices = [2, 3]; // Secuencias 3-4
    }
    
    // Distribuir puntos a cada jugador de la pareja
    // IMPORTANTE: Siempre actualizar ambos campos (resultado1 y resultado2) para mantener sincronización
    indices.forEach(index => {
        const campoR1 = document.getElementById('resultado1_' + index);
        const campoR2 = document.getElementById('resultado2_' + index);
        
        if (campoR1) {
            campoR1.value = puntosPareja;
            console.log('Distribuido resultado1[' + index + '] = ' + puntosPareja + ' (Pareja ' + pareja + ')');
        } else {
            console.error('No se encontró resultado1_' + index);
        }
        if (campoR2) {
            campoR2.value = puntosContraria;
            console.log('Distribuido resultado2[' + index + '] = ' + puntosContraria + ' (Contraria)');
        } else {
            console.error('No se encontró resultado2_' + index);
        }
    });
    
    // IMPORTANTE: Cuando se actualiza una pareja, también actualizar la pareja contraria
    // para mantener sincronización de resultado2
    if (pareja === 'A') {
        // Si actualizamos A, actualizar resultado2 de B (que apunta a A)
        [2, 3].forEach(index => {
            const campoR2 = document.getElementById('resultado2_' + index);
            if (campoR2) {
                campoR2.value = puntosParejaA;
                console.log('Actualizado resultado2[' + index + '] = ' + puntosParejaA + ' (desde Pareja A)');
            }
        });
    } else {
        // Si actualizamos B, actualizar resultado2 de A (que apunta a B)
        [0, 1].forEach(index => {
            const campoR2 = document.getElementById('resultado2_' + index);
            if (campoR2) {
                campoR2.value = puntosParejaB;
                console.log('Actualizado resultado2[' + index + '] = ' + puntosParejaB + ' (desde Pareja B)');
            }
        });
    }
}

// Devuelve true si el textbox "Ir a Mesa" tiene un valor válido (> 0 y en lista de mesas)
function esMesaValidaEnInput() {
    const input = document.getElementById('input_ir_mesa');
    if (!input) return false;
    const num = parseInt(input.value) || 0;
    if (num <= 0) return false;
    const mesasDisponibles = [<?php echo !empty($todasLasMesas) ? implode(',', array_column($todasLasMesas, 'numero')) : ''; ?>];
    if (mesasDisponibles.length > 0 && !mesasDisponibles.includes(num)) return false;
    return true;
}

// Habilita o deshabilita todos los controles del formulario según el valor del textbox de mesa
function actualizarEstadoPorMesa() {
    const habilitado = esMesaValidaEnInput();
    const form = document.getElementById('formResultados');
    if (!form) return;
    
    const mesaInput = form.querySelector('input[name="mesa"]');
    const inputIrMesa = document.getElementById('input_ir_mesa');
    if (mesaInput && inputIrMesa && habilitado) {
        const num = parseInt(inputIrMesa.value) || 0;
        if (num > 0) mesaInput.value = num;
    }
    
    const controles = [
        document.getElementById('puntos_pareja_A'),
        document.getElementById('puntos_pareja_B'),
        document.getElementById('btn-guardar'),
        document.getElementById('btn-limpiar'),
        document.getElementById('btn-mano-desierta'),
        document.querySelector('textarea[name="observaciones"]')
    ];
    controles.forEach(el => { if (el) el.disabled = !habilitado; });
    
    for (let i = 0; i < 4; i++) {
        const sancion = document.querySelector('input[name="jugadores[' + i + '][sancion]"]');
        const ff = document.getElementById('ff_' + i);
        if (sancion) sancion.disabled = !habilitado;
        if (ff) ff.disabled = !habilitado;
        const tarjetas = document.querySelectorAll('[data-index="' + i + '"].tarjeta-btn');
        tarjetas.forEach(btn => { btn.disabled = !habilitado; });
    }
    const penaMesaC = document.getElementById('pena_mesa_chancleta');
    const penaMesaZ = document.getElementById('pena_mesa_zapato');
    if (penaMesaC) penaMesaC.disabled = !habilitado;
    if (penaMesaZ) penaMesaZ.disabled = !habilitado;
    
    actualizarEstadoBotonGuardar();
}

// Función global para habilitar/deshabilitar botón guardar según valores del formulario
function actualizarEstadoBotonGuardar() {
    const btnGuardar = document.getElementById('btn-guardar');
    if (!btnGuardar) return;
    
    if (!esMesaValidaEnInput()) {
        btnGuardar.disabled = true;
        return;
    }
    
    const puntosA = parseInt(document.getElementById('puntos_pareja_A').value) || 0;
    const puntosB = parseInt(document.getElementById('puntos_pareja_B').value) || 0;
    
    // Verificar si hay forfait marcado (cualquiera de los 4 jugadores)
    let hayForfait = false;
    for (let i = 0; i < 4; i++) {
        const ff = document.getElementById('ff_' + i);
        if (ff && ff.checked) {
            hayForfait = true;
            break;
        }
    }
    
    // Verificar si hay tarjeta grave - roja (3) o negra (4) (cualquiera de los 4 jugadores)
    let hayTarjetaGrave = false;
    for (let i = 0; i < 4; i++) {
        const campoTarjeta = document.getElementById('tarjeta_' + i);
        if (campoTarjeta) {
            const tarjeta = parseInt(campoTarjeta.value) || 0;
            if (tarjeta == 3 || tarjeta == 4) {
                hayTarjetaGrave = true;
                break;
            }
        }
    }
    
    // Habilitar si hay puntos, forfait o tarjeta grave
    if (puntosA > 0 || puntosB > 0 || hayForfait || hayTarjetaGrave) {
        btnGuardar.disabled = false;
    } else {
        btnGuardar.disabled = true;
    }
}

// Sanción 40: Amarilla (adv. adm., no resta pts). Sanción 80: 0 prev→Amarilla, ya amarilla→Roja
const SANCION_AMARILLA = 40;
const SANCION_MAXIMA = 80;

function validarSancionYTarjeta(index) {
    const input = document.querySelector('input[name="jugadores[' + index + '][sancion]"]');
    if (!input) return;
    let val = parseInt(input.value, 10);
    if (isNaN(val) || val < 0) val = 0;
    if (val > SANCION_MAXIMA) {
        val = SANCION_MAXIMA;
        input.value = val;
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Límite de sanción',
                text: 'Las sanciones no pueden superar los ' + SANCION_MAXIMA + ' puntos.',
                confirmButtonColor: '#667eea',
                timer: 2500,
                timerProgressBar: true
            });
        }
    }
    const campoHidden = document.getElementById('tarjeta_' + index);
    const tarjetaForm = campoHidden ? parseInt(campoHidden.value, 10) || 0 : 0;
    const tdSancion = input.closest('td.columna-sancion');
    const indicador = document.getElementById('indicador_tarjeta_80_' + index);
    const tarjetaInscritos = tdSancion ? parseInt(tdSancion.getAttribute('data-tarjeta-inscritos'), 10) || 0 : 0;
    const mostrarIndicador = (val === SANCION_AMARILLA) || (val === SANCION_MAXIMA) || (tarjetaForm === 1);
    if (indicador) {
        if (mostrarIndicador) {
            if (val === SANCION_AMARILLA) {
                indicador.textContent = 'Resta 40 pts. Sin tarjeta.';
            } else if (val === SANCION_MAXIMA || tarjetaForm === 1) {
                indicador.textContent = tarjetaInscritos >= 1 ? 'Será: Roja (acum.)' : 'Será: Amarilla';
            } else {
                indicador.textContent = '';
            }
            indicador.style.display = 'block';
        } else {
            indicador.textContent = '';
            indicador.style.display = 'none';
        }
    }
    // 40 pts: no se asigna tarjeta. 80 pts: asignar amarilla o siguiente según tarjeta previa.
    if (val === SANCION_AMARILLA && campoHidden) {
        if (0 !== tarjetaForm) seleccionarTarjeta(index, 0);
    } else if (val === SANCION_MAXIMA && campoHidden) {
        const nuevaTarjeta = tarjetaInscritos >= 1 ? 3 : 1;
        if (nuevaTarjeta !== tarjetaForm) seleccionarTarjeta(index, nuevaTarjeta);
    }
}

// Función para seleccionar tarjeta
function seleccionarTarjeta(index, tarjeta) {
    const campoHidden = document.getElementById('tarjeta_' + index);
    if (!campoHidden) return;
    
    const tarjetaActual = parseInt(campoHidden.value) || 0;
    
    // Si se hace clic en el mismo botón, deseleccionar
    if (tarjetaActual === tarjeta) {
        tarjeta = 0;
    }
    
    // Remover clase activo de todos los botones de este jugador
    const botones = document.querySelectorAll('[data-index="' + index + '"].tarjeta-btn');
    botones.forEach(btn => btn.classList.remove('activo'));
    
    // Si hay una tarjeta seleccionada, agregar clase activo al botón correspondiente
    if (tarjeta > 0) {
        const botonSeleccionado = document.querySelector('[data-index="' + index + '"][data-tarjeta="' + tarjeta + '"]');
        if (botonSeleccionado) {
            botonSeleccionado.classList.add('activo');
        }
    }
    
    // Actualizar campo hidden
    campoHidden.value = tarjeta;
    
    // Actualizar indicador de amarilla/roja por acumulación (cuando se selecciona amarilla directa)
    validarSancionYTarjeta(index);
    if (ES_TORNEO_PAREJAS && (index === 0 || index === 2)) {
        sincronizarTarjetaOcultaPareja(index, index + 1);
    }
    
    // Validar puntos
    validarPuntosEnTiempoReal();
    
    // Actualizar estado del botón guardar (importante para tarjetas rojas y negras)
    actualizarEstadoBotonGuardar();
}

// Un solo indicador por mesa: sincroniza pena_mesa con los 4 hidden chancleta/zapato
function procesarPena() {
    const radioChancleta = document.getElementById('pena_mesa_chancleta');
    const radioZapato = document.getElementById('pena_mesa_zapato');
    const esChancleta = radioChancleta && radioChancleta.checked;
    const esZapato = radioZapato && radioZapato.checked;
    for (let i = 0; i < 4; i++) {
        const ch = document.getElementById('chancleta_' + i);
        const zp = document.getElementById('zapato_' + i);
        if (ch) ch.value = esChancleta ? '1' : '0';
        if (zp) zp.value = esZapato ? '1' : '0';
    }
}

// Función para limpiar formulario (con confirmación)
async function limpiarFormulario() {
    const result = await Swal.fire({
        title: '¿Limpiar formulario?',
        text: '¿Estás seguro de limpiar todos los campos?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, limpiar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#667eea',
        cancelButtonColor: '#6c757d'
    });
    
    if (result.isConfirmed) {
        limpiarFormularioSilencioso();
    }
}

// Función para limpiar formulario sin confirmación (usado después de guardar)
function limpiarFormularioSilencioso() {
    // Limpiar el textbox "Ir a Mesa" estableciéndolo en 0
    const inputMesa = document.getElementById('input_ir_mesa');
    if (inputMesa) {
        inputMesa.value = '0';
        inputMesa.classList.remove('is-invalid', 'is-valid');
        inputMesa.setCustomValidity('');
    }
    
    const puntosA = document.getElementById('puntos_pareja_A');
    const puntosB = document.getElementById('puntos_pareja_B');
    if (puntosA) puntosA.value = '0';
    if (puntosB) puntosB.value = '0';
    distribuirPuntos('todas');
    
    // Limpiar tarjetas
    for (let i = 0; i < 4; i++) {
        const botones = document.querySelectorAll('[data-index="' + i + '"].tarjeta-btn');
        botones.forEach(btn => btn.classList.remove('activo'));
        document.getElementById('tarjeta_' + i).value = 0;
        const sancion = document.querySelector('input[name="jugadores[' + i + '][sancion]"]');
        if (sancion) sancion.value = '0';
        const ff = document.getElementById('ff_' + i);
        if (ff) ff.checked = false;
    }
    const penaMesaC = document.getElementById('pena_mesa_chancleta');
    const penaMesaZ = document.getElementById('pena_mesa_zapato');
    if (penaMesaC) penaMesaC.checked = false;
    if (penaMesaZ) penaMesaZ.checked = false;
    
    procesarPena();
    const observaciones = document.querySelector('textarea[name="observaciones"]');
    if (observaciones) observaciones.value = '';
    
    // Enfocar el primer campo después de limpiar
    if (puntosA) {
        setTimeout(() => {
            puntosA.focus();
            puntosA.select();
        }, 100);
    }
    
    validarPuntosEnTiempoReal();
    actualizarEstadoPorMesa();
}

async function guardarManoDesierta() {
    if (!esMesaValidaEnInput()) {
        Swal.fire({
            icon: 'error',
            title: 'Mesa no válida',
            text: 'Seleccione una mesa válida antes de registrar Mano Desierta.',
            confirmButtonColor: '#667eea'
        });
        return;
    }

    const confirmacion = await Swal.fire({
        title: '¿Registrar Mano Desierta?',
        text: 'Se guardará la mesa automáticamente con resultado 0-0 para ambas parejas.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, registrar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#667eea',
        cancelButtonColor: '#6c757d'
    });

    if (!confirmacion.isConfirmed) {
        return;
    }

    const form = document.getElementById('formResultados');
    if (!form) {
        return;
    }

    // Mano desierta limpia sanciones/incidencias y fija marcador 0-0.
    const puntosA = document.getElementById('puntos_pareja_A');
    const puntosB = document.getElementById('puntos_pareja_B');
    if (puntosA) puntosA.value = '0';
    if (puntosB) puntosB.value = '0';

    for (let i = 0; i < 4; i++) {
        const ff = document.getElementById('ff_' + i);
        if (ff) ff.checked = false;
        const tarjeta = document.getElementById('tarjeta_' + i);
        if (tarjeta) tarjeta.value = '0';
        const sancion = document.querySelector('input[name="jugadores[' + i + '][sancion]"]');
        if (sancion) sancion.value = '0';
        const botones = document.querySelectorAll('[data-index="' + i + '"].tarjeta-btn');
        botones.forEach(btn => btn.classList.remove('activo'));
    }

    const penaMesaC = document.getElementById('pena_mesa_chancleta');
    const penaMesaZ = document.getElementById('pena_mesa_zapato');
    if (penaMesaC) penaMesaC.checked = false;
    if (penaMesaZ) penaMesaZ.checked = false;

    distribuirPuntos('todas');
    procesarPena();
    validarPuntosEnTiempoReal();

    form.requestSubmit();
}

// Función para validar puntos en tiempo real
// Función para validar puntos inmediatamente al salir del campo (onblur)
function validarPuntosInmediato(event) {
    const campo = event.target;
    const puntos = parseInt(campo.value) || 0;
    const puntosTorneo = <?php echo (int)($torneo['puntos'] ?? 100); ?>;
    // Máximo permitido: puntos del torneo + 60% = puntosTorneo * 1.6
    const maximoPermitido = Math.round(puntosTorneo * 1.6);
    
    // Remover clases de error previas
    campo.classList.remove('is-invalid', 'is-valid', 'border-danger', 'bg-danger');
    
    // Si el campo está vacío, no validar
    if (campo.value.trim() === '') {
        return;
    }
    
    // Validar máximo
    if (puntos > maximoPermitido) {
        campo.classList.add('is-invalid', 'border-danger', 'bg-danger');
        campo.setCustomValidity('El monto es exagerado');
        
        // Mostrar mensaje de error inmediatamente
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'El monto es exagerado',
            confirmButtonColor: '#667eea',
            timer: 3000,
            timerProgressBar: true
        });
        
        // Enfocar y seleccionar el campo
        setTimeout(() => {
            campo.focus();
            campo.select();
        }, 100);
    } else {
        campo.classList.remove('is-invalid');
        campo.classList.add('is-valid');
        campo.setCustomValidity('');
    }
}

function validarPuntosEnTiempoReal() {
    const puntosA = parseInt(document.getElementById('puntos_pareja_A').value) || 0;
    const puntosB = parseInt(document.getElementById('puntos_pareja_B').value) || 0;
    const puntosTorneo = <?php echo (int)($torneo['puntos'] ?? 100); ?>;
    // Máximo permitido: puntos del torneo + 60% = puntosTorneo * 1.6
    const maximoPermitido = Math.round(puntosTorneo * 1.6);
    
    const campoA = document.getElementById('puntos_pareja_A');
    const campoB = document.getElementById('puntos_pareja_B');
    const mensajeDiv = document.getElementById('mensaje-validacion');
    
    // Remover clases de error previas
    campoA.classList.remove('border-danger', 'bg-danger', 'border-warning', 'bg-warning');
    campoB.classList.remove('border-danger', 'bg-danger', 'border-warning', 'bg-warning');
    mensajeDiv.classList.remove('show', 'alert', 'alert-danger', 'alert-warning');
    mensajeDiv.innerHTML = '';
    
    // Obtener estado de forfait y tarjetas
    let hayForfait = false;
    let hayTarjetaGrave = false;
    
    for (let i = 0; i < 4; i++) {
        const ff = document.getElementById('ff_' + i);
        if (ff && ff.checked) hayForfait = true;
        const tarjeta = parseInt(document.getElementById('tarjeta_' + i).value) || 0;
        if (tarjeta == 3 || tarjeta == 4) hayTarjetaGrave = true;
    }
    
    let hayError = false;
    let mensaje = '';
    
    // Validar máximo (puntos del torneo + 60%)
    if (puntosA > maximoPermitido) {
        campoA.classList.add('border-danger', 'bg-danger');
        hayError = true;
        mensaje += '⚠️ Pareja A: El monto es exagerado. ';
    }
    
    if (puntosB > maximoPermitido) {
        campoB.classList.add('border-danger', 'bg-danger');
        hayError = true;
        mensaje += '⚠️ Pareja B: El monto es exagerado. ';
    }
    
    // Empate en tranque: mano nula (0-0 para ambas parejas) al guardar
    if (puntosA == puntosB && puntosA > 0 && !hayForfait && !hayTarjetaGrave) {
        campoA.classList.add('border-warning', 'bg-warning');
        campoB.classList.add('border-warning', 'bg-warning');
        mensaje += 'ℹ️ Empate detectado: se registrará Mano Nula (0-0 para ambas parejas). ';
    }
    
    // Validar que solo uno alcance los puntos del torneo
    const parejaAAlcanzo = puntosA >= puntosTorneo;
    const parejaBAlcanzo = puntosB >= puntosTorneo;
    
    if (parejaAAlcanzo && parejaBAlcanzo && !hayForfait && !hayTarjetaGrave) {
        hayError = true;
        mensaje += '⚠️ Solo una pareja puede alcanzar los puntos del torneo (' + puntosTorneo + '). ';
    }
    
    // Mostrar mensaje si hay error
    if (hayError && mensaje) {
        mensajeDiv.className = 'mb-3 alert alert-warning show';
        mensajeDiv.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>' + mensaje;
    }
}

// Función para validar resultados antes de enviar
function validarResultados() {
    const form = document.getElementById('formResultados');
    const mesaInput = form ? form.querySelector('input[name="mesa"]') : null;
    const mesa = mesaInput ? parseInt(mesaInput.value) || 0 : 0;
    
    if (mesa <= 0) {
        Swal.fire({
            icon: 'error',
            title: 'Mesa no válida',
            text: 'No hay una mesa válida seleccionada. Seleccione una mesa de la lista antes de guardar.',
            confirmButtonColor: '#667eea'
        });
        const inputIrMesa = document.getElementById('input_ir_mesa');
        if (inputIrMesa) inputIrMesa.focus();
        return false;
    }
    
    const puntosA = parseInt(document.getElementById('puntos_pareja_A').value) || 0;
    const puntosB = parseInt(document.getElementById('puntos_pareja_B').value) || 0;
    const puntosTorneo = <?php echo (int)($torneo['puntos'] ?? 100); ?>;
    // Máximo permitido: puntos del torneo + 60% = puntosTorneo * 1.6
    const maximoPermitido = Math.round(puntosTorneo * 1.6);
    
    // Obtener estado de forfait y tarjetas
    let hayForfait = false;
    let hayTarjetaGrave = false;
    
    for (let i = 0; i < 4; i++) {
        const ff = document.getElementById('ff_' + i);
        if (ff && ff.checked) {
            hayForfait = true;
        }
        const tarjeta = parseInt(document.getElementById('tarjeta_' + i).value) || 0;
        if (tarjeta == 3 || tarjeta == 4) {
            hayTarjetaGrave = true;
        }
    }
    
    // Validación 1: No exceder máximo (puntos del torneo + 60%)
    if (puntosA > maximoPermitido) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'El monto es exagerado',
            confirmButtonColor: '#667eea'
        });
        const campoA = document.getElementById('puntos_pareja_A');
        if (campoA) {
            campoA.focus();
            campoA.select();
        }
        return false;
    }
    if (puntosB > maximoPermitido) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'El monto es exagerado',
            confirmButtonColor: '#667eea'
        });
        const campoB = document.getElementById('puntos_pareja_B');
        if (campoB) {
            campoB.focus();
            campoB.select();
        }
        return false;
    }
    
    // Validación 2: empate permitido como Mano Nula (0-0)
    if (puntosA == puntosB && puntosA > 0 && !hayForfait && !hayTarjetaGrave) {
        // No bloquear: backend aplica Mano Nula automáticamente.
    }
    
    // Validación 3: Solo uno debe alcanzar o superar los puntos del torneo
    const parejaAAlcanzo = puntosA >= puntosTorneo;
    const parejaBAlcanzo = puntosB >= puntosTorneo;
    
    if (parejaAAlcanzo && parejaBAlcanzo && !hayForfait && !hayTarjetaGrave) {
        Swal.fire({
            icon: 'error',
            title: 'Error de validación',
            html: 'Solo una pareja puede alcanzar o superar los puntos del torneo (' + puntosTorneo + ').<br>Ambas alcanzaron: Pareja A: ' + puntosA + ', Pareja B: ' + puntosB,
            confirmButtonColor: '#667eea'
        });
        const campoActivo = document.activeElement;
        if (campoActivo && (campoActivo.id === 'puntos_pareja_A' || campoActivo.id === 'puntos_pareja_B')) {
            campoActivo.focus();
            campoActivo.select();
        }
        return false;
    }
    
    return true;
}

// Event listener para submit del formulario
document.addEventListener('DOMContentLoaded', function() {
    // Cuenta regresiva "Correcciones se cierran en" (se resetea a 20 min al guardar una corrección)
    const countdownCorrecciones = document.getElementById('countdown-correcciones');
    if (countdownCorrecciones) {
        const finTimestamp = parseInt(countdownCorrecciones.getAttribute('data-fin'), 10);
        function actualizar() {
            const ahora = Math.floor(Date.now() / 1000);
            let restante = finTimestamp - ahora;
            if (restante <= 0) {
                countdownCorrecciones.textContent = '00:00';
                countdownCorrecciones.closest('p').innerHTML = 'Correcciones cerradas. <span class="tabular-nums">00:00</span>';
                if (window.countdownCorreccionesInterval) clearInterval(window.countdownCorreccionesInterval);
                return;
            }
            const m = Math.floor(restante / 60), s = restante % 60;
            countdownCorrecciones.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }
        actualizar();
        window.countdownCorreccionesInterval = setInterval(actualizar, 1000);
    }

    const form = document.getElementById('formResultados');
    if (form) {
        form.addEventListener('submit', function(e) {
            // No prevenir el submit normal, solo validar y procesar
            if (!validarResultados()) {
                e.preventDefault();
                return false;
            }
            
            // Procesar radio buttons de pena
            procesarPena();
            
            if (ES_TORNEO_PAREJAS) {
                sincronizarTarjetaOcultaPareja(0, 1);
                sincronizarTarjetaOcultaPareja(2, 3);
            }
            
            // Distribuir puntos antes de enviar - asegurar que ambas parejas estén sincronizadas
            distribuirPuntos('todas');
            
            // Verificar que todos los campos estén correctamente actualizados
            for (let i = 0; i < 4; i++) {
                const r1 = document.getElementById('resultado1_' + i);
                const r2 = document.getElementById('resultado2_' + i);
                if (r1 && r2) {
                    console.log('Antes de enviar - Jugador ' + (i + 1) + ': r1=' + r1.value + ', r2=' + r2.value);
                }
            }
            
            console.log('Formulario enviado con datos actualizados');
        });
    }
    
    // Mostrar indicador de tarjeta por acumulación (80 pts) si ya viene con 80 en el formulario
    for (let i = 0; i < 4; i++) {
        validarSancionYTarjeta(i);
    }
    if (ES_TORNEO_PAREJAS) {
        sincronizarTarjetaOcultaPareja(0, 1);
        sincronizarTarjetaOcultaPareja(2, 3);
    }

    // Inicializar tarjetas visualmente (mostrar el estado actual)
    for (let i = 0; i < 4; i++) {
        const tarjetaInput = document.getElementById('tarjeta_' + i);
        if (tarjetaInput) {
            const tarjetaValue = parseInt(tarjetaInput.value) || 0;
            // Solo marcar si tiene una tarjeta seleccionada (1, 3 o 4), no si es 0
            if (tarjetaValue > 0) {
                // Remover clase activo de todos los botones de este jugador
                const botones = document.querySelectorAll('[data-index="' + i + '"].tarjeta-btn');
                botones.forEach(btn => btn.classList.remove('activo'));
                
                // Agregar clase activo al botón seleccionado
                const botonSeleccionado = document.querySelector('[data-index="' + i + '"][data-tarjeta="' + tarjetaValue + '"]');
                if (botonSeleccionado) {
                    botonSeleccionado.classList.add('activo');
                }
            } else {
                // Si es 0, remover cualquier selección visual
                const botones = document.querySelectorAll('[data-index="' + i + '"].tarjeta-btn');
                botones.forEach(btn => btn.classList.remove('activo'));
            }
        }
    }
    
    // Actualizar estado según textbox de mesa y botón guardar
    actualizarEstadoPorMesa();
    
    // Limpiar formulario si se acaba de guardar
    <?php if (isset($_SESSION['limpiar_formulario'])): ?>
        <?php unset($_SESSION['limpiar_formulario']); ?>
        limpiarFormularioSilencioso();
    <?php endif; ?>
    
    // Si se acaba de guardar, enfocar el textbox "ir a mesa" y limpiarlo
    <?php if (isset($_SESSION['resultados_guardados'])): ?>
        <?php unset($_SESSION['resultados_guardados']); ?>
        setTimeout(() => {
            const inputMesa = document.getElementById('input_ir_mesa');
            if (inputMesa) {
                inputMesa.value = '0';
                inputMesa.classList.remove('is-invalid', 'is-valid');
                inputMesa.setCustomValidity('');
                inputMesa.focus();
                actualizarEstadoPorMesa();
            }
        }, 100);
    <?php else: ?>
        // Enfocar el primer campo de puntos al cargar la página si no se acaba de guardar
        const puntosA = document.getElementById('puntos_pareja_A');
        if (puntosA) {
            setTimeout(() => {
                puntosA.focus();
                puntosA.select();
            }, 100);
        }
    <?php endif; ?>
    
    // Distribuir puntos inicialmente si ya hay valores cargados
    distribuirPuntos('todas');
    
    // Validar puntos al cargar la página
    validarPuntosEnTiempoReal();
    
    // Actualizar estado del botón cuando cambian los puntos
    const puntosAInput = document.getElementById('puntos_pareja_A');
    const puntosBInput = document.getElementById('puntos_pareja_B');
    if (puntosAInput) {
        puntosAInput.addEventListener('input', actualizarEstadoBotonGuardar);
        puntosAInput.addEventListener('change', actualizarEstadoBotonGuardar);
    }
    if (puntosBInput) {
        puntosBInput.addEventListener('input', actualizarEstadoBotonGuardar);
        puntosBInput.addEventListener('change', actualizarEstadoBotonGuardar);
    }
    
    // Listener en textbox de mesa para habilitar/deshabilitar controles
    const inputIrMesa = document.getElementById('input_ir_mesa');
    if (inputIrMesa) {
        inputIrMesa.addEventListener('input', actualizarEstadoPorMesa);
        inputIrMesa.addEventListener('change', actualizarEstadoPorMesa);
    }
    
    // Actualizar estado cuando cambian forfait o tarjetas
    for (let i = 0; i < 4; i++) {
        const ff = document.getElementById('ff_' + i);
        if (ff) {
            ff.addEventListener('change', function() {
                validarPuntosEnTiempoReal();
                actualizarEstadoBotonGuardar();
            });
        }
    }
    
    // Actualizar estado inicial
    actualizarEstadoBotonGuardar();
    
    
    // Un solo indicador Zap/Chan por mesa: al cambiar, actualiza todos los jugadores
    const penaMesaChancleta = document.getElementById('pena_mesa_chancleta');
    const penaMesaZapato = document.getElementById('pena_mesa_zapato');
    if (penaMesaChancleta && penaMesaZapato) {
        function actualizarPenaMesaTodos() {
            const esChancleta = penaMesaChancleta.checked;
            const esZapato = penaMesaZapato.checked;
            for (let i = 0; i < 4; i++) {
                const ch = document.getElementById('chancleta_' + i);
                const zp = document.getElementById('zapato_' + i);
                if (ch) ch.value = esChancleta ? '1' : '0';
                if (zp) zp.value = esZapato ? '1' : '0';
            }
        }
        penaMesaChancleta.addEventListener('change', actualizarPenaMesaTodos);
        penaMesaZapato.addEventListener('change', actualizarPenaMesaTodos);
        actualizarPenaMesaTodos();
    }
});
</script>
