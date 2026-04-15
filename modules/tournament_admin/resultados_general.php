<?php
/**
 * Resultados General del Torneo por Equipos
 * Muestra todos los participantes ordenados por clasificación individual
 * Mostrando el equipo en vez del club
 */

require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/PartiresulEstatusSql.php';
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../../lib/Tournament/Services/PaginationService.php';

$pdo = DB::pdo();
$es_parejas = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);

// Configuración de paginación
$items_por_pagina = 30; // Jugadores por página
$pagina_raw_general = isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1;

// Función helper para generar HTML del paginador
function generarPaginador($pagina_actual, $total_paginas, $base_url, $parametros_get = []) {
    if ($total_paginas <= 1) {
        return '';
    }
    $buildUrl = static function ($base_url, array $params): string {
        $sep = (strpos($base_url, '?') !== false) ? '&' : '?';
        return $base_url . $sep . http_build_query($params);
    };
    
    $html = '<div class="flex items-center justify-center gap-2 mt-6 mb-4">';
    $html .= '<div class="flex items-center gap-1">';
    
    // Botón Primera página
    if ($pagina_actual > 1) {
        $parametros_get['pagina'] = 1;
        $url = $buildUrl($base_url, $parametros_get);
        $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 transition"><i class="fas fa-angle-double-left"></i></a>';
    } else {
        $html .= '<span class="px-3 py-2 bg-gray-300 text-gray-500 rounded cursor-not-allowed"><i class="fas fa-angle-double-left"></i></span>';
    }
    
    // Botón Página anterior
    if ($pagina_actual > 1) {
        $parametros_get['pagina'] = $pagina_actual - 1;
        $url = $buildUrl($base_url, $parametros_get);
        $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 transition"><i class="fas fa-angle-left"></i></a>';
    } else {
        $html .= '<span class="px-3 py-2 bg-gray-300 text-gray-500 rounded cursor-not-allowed"><i class="fas fa-angle-left"></i></span>';
    }
    
    // Números de página
    $inicio = max(1, $pagina_actual - 2);
    $fin = min($total_paginas, $pagina_actual + 2);
    
    if ($inicio > 1) {
        $parametros_get['pagina'] = 1;
        $url = $buildUrl($base_url, $parametros_get);
        $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-white text-purple-600 rounded hover:bg-purple-50 transition">1</a>';
        if ($inicio > 2) {
            $html .= '<span class="px-2 text-gray-500">...</span>';
        }
    }
    
    for ($i = $inicio; $i <= $fin; $i++) {
        if ($i == $pagina_actual) {
            $html .= '<span class="px-3 py-2 bg-purple-600 text-white rounded font-bold">' . $i . '</span>';
        } else {
            $parametros_get['pagina'] = $i;
            $url = $buildUrl($base_url, $parametros_get);
            $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-white text-purple-600 rounded hover:bg-purple-50 transition">' . $i . '</a>';
        }
    }
    
    if ($fin < $total_paginas) {
        if ($fin < $total_paginas - 1) {
            $html .= '<span class="px-2 text-gray-500">...</span>';
        }
        $parametros_get['pagina'] = $total_paginas;
        $url = $buildUrl($base_url, $parametros_get);
        $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-white text-purple-600 rounded hover:bg-purple-50 transition">' . $total_paginas . '</a>';
    }
    
    // Botón Página siguiente
    if ($pagina_actual < $total_paginas) {
        $parametros_get['pagina'] = $pagina_actual + 1;
        $url = $buildUrl($base_url, $parametros_get);
        $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 transition"><i class="fas fa-angle-right"></i></a>';
    } else {
        $html .= '<span class="px-3 py-2 bg-gray-300 text-gray-500 rounded cursor-not-allowed"><i class="fas fa-angle-right"></i></span>';
    }
    
    // Botón Última página
    if ($pagina_actual < $total_paginas) {
        $parametros_get['pagina'] = $total_paginas;
        $url = $buildUrl($base_url, $parametros_get);
        $html .= '<a href="' . htmlspecialchars($url) . '" class="px-3 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 transition"><i class="fas fa-angle-double-right"></i></a>';
    } else {
        $html .= '<span class="px-3 py-2 bg-gray-300 text-gray-500 rounded cursor-not-allowed"><i class="fas fa-angle-double-right"></i></span>';
    }
    
    $html .= '</div>';
    $html .= '<div class="ml-4 text-sm text-gray-600">';
    $html .= 'Página ' . $pagina_actual . ' de ' . $total_paginas;
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

// Obtener TODOS los participantes (jugadores) ordenados por clasificación individual
$participantes = [];

try {
    // Asegurar que las posiciones estén actualizadas
    if (function_exists('recalcularPosiciones')) {
        recalcularPosiciones($torneo_id);
    }
    
    // Contar total de participantes para paginación
    $sql_count = "
        SELECT COUNT(*) as total
        FROM inscritos i
        WHERE i.torneo_id = ? 
            AND i.estatus != 'retirado'
    ";
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute([$torneo_id]);
    $total_participantes = (int)$stmt_count->fetchColumn();
    $p_gen = \Tournament\Services\PaginationService::getParams($total_participantes, $pagina_raw_general, $items_por_pagina);
    $pagina_actual = $p_gen['page'];
    $total_paginas = $p_gen['total_pages'];
    $items_por_pagina_int = (int) $p_gen['per_page'];
    $offset_int = (int) $p_gen['offset'];
    $sql = "
        SELECT 
            i.id,
            i.id_usuario,
            i.torneo_id,
            i.codigo_equipo,
            i.posicion,
            i.ganados,
            i.perdidos,
            i.efectividad,
            i.puntos,
            i.ptosrnk,
            " . \ResultadosReporteData::sqlGffSubquery() . " AS gff,
            i.sancion,
            i.tarjeta,
            (SELECT COUNT(*) FROM partiresul pr_bye WHERE pr_bye.id_usuario = i.id_usuario AND pr_bye.id_torneo = i.torneo_id AND " . \PartiresulEstatusSql::whereRegistradoUno('pr_bye') . " AND pr_bye.mesa = 0 AND pr_bye.resultado1 > pr_bye.resultado2) as partidas_bye,
            u.nombre as nombre_completo,
            u.username,
            u.sexo,
            u.cedula,
            c.nombre as club_nombre,
            c.id as club_id,
            e.nombre_equipo
        FROM inscritos i
        INNER JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        LEFT JOIN equipos e ON i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo AND e.estatus = 0
        WHERE i.torneo_id = ? 
            AND i.estatus != 'retirado'
        ORDER BY 
            CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
            i.ganados DESC,
            i.efectividad DESC,
            i.puntos DESC
        LIMIT " . $items_por_pagina_int . " OFFSET " . $offset_int . "
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($es_parejas) {
        $participantes = \ResultadosReporteData::colapsarFilasPorPareja($participantes, $pdo, $torneo_id);
        foreach ($participantes as &$participante) {
            $codigo = trim((string)($participante['codigo_equipo'] ?? ''));
            $disp = trim((string)($participante['nombre_completo'] ?? ''));
            if ($disp !== '') {
                $participante['pareja_display'] = $disp;
                $participante['nombre_equipo'] = $disp;
            } elseif ($codigo !== '') {
                $participante['nombre_completo'] = 'Pareja ' . $codigo;
                $participante['pareja_display'] = $participante['nombre_completo'];
                $participante['nombre_equipo'] = $participante['nombre_completo'];
            }
        }
        unset($participante);
    }
    
    // Asegurar que todos los jugadores tengan el nombre del equipo si tienen codigo_equipo
    foreach ($participantes as &$participante) {
        if (empty($participante['nombre_equipo']) && !empty($participante['codigo_equipo'])) {
            // Si no tiene nombre_equipo pero tiene codigo_equipo, construir uno
            $participante['nombre_equipo'] = 'Equipo ' . $participante['codigo_equipo'];
        }
    }
    unset($participante);
    
} catch (Exception $e) {
    error_log("Error obteniendo resultados generales: " . $e->getMessage());
    $participantes = [];
    $total_paginas = 1;
    $pagina_actual = max(1, $pagina_raw_general);
}

// Obtener información del club responsable con logo
$club_responsable = null;
$club_logo_url = null;

if (!empty($torneo['club_responsable'])) {
    $stmt = $pdo->prepare("
        SELECT id, nombre, logo, delegado
        FROM clubes
        WHERE id = ?
    ");
    $stmt->execute([$torneo['club_responsable']]);
    $club_responsable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($club_responsable && !empty($club_responsable['logo'])) {
        $base_url = AppHelpers::getBaseUrl();
        $club_logo_url = AppHelpers::imageUrl($club_responsable['logo']);
    }
}

// Función helper para obtener texto de tarjeta
function getTarjetaTexto($tarjeta) {
    switch ((int)$tarjeta) {
        case 1: return '🟨 Amarilla';
        case 3: return '🟥 Roja';
        case 4: return '⬛ Negra';
        default: return 'Sin tarjeta';
    }
}
?>

<!-- Tailwind CSS (compilado localmente para mejor rendimiento) -->
<link rel="stylesheet" href="assets/dist/output.css">

<?php
// Obtener base URL para el botón de retorno
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url_return = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
?>

<div class="min-h-screen bg-gradient-to-br from-purple-600 via-purple-700 to-indigo-800 p-6">
    <!-- Botón de retorno al panel -->
    <div class="mb-4">
        <a href="<?php echo $base_url_return . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo_id; ?>" 
           class="inline-flex items-center px-6 py-3 bg-gray-800 hover:bg-gray-900 text-white rounded-lg shadow-lg transition-all transform hover:scale-105 font-bold">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver al Panel de Control
        </a>
    </div>
    
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-2xl p-6 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <?php if ($club_logo_url): ?>
                    <img src="<?php echo htmlspecialchars($club_logo_url); ?>" 
                         alt="<?php echo htmlspecialchars($club_responsable['nombre'] ?? ''); ?>" 
                         class="w-20 h-20 object-contain rounded-lg">
                <?php endif; ?>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">
                        <i class="fas fa-list-ol text-purple-600 mr-2"></i>
                        <?php echo $es_parejas ? 'Resultados Parejas - Clasificación por Pareja' : 'Resultados General - Clasificación Individual'; ?>
                    </h1>
                    <h2 class="text-xl text-gray-600"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></h2>
                    <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                        <span><i class="fas fa-calendar-alt mr-1"></i> <?php echo date('d/m/Y', strtotime($torneo['fechator'] ?? 'now')); ?></span>
                        <span><i class="fas fa-building mr-1"></i> <?php echo htmlspecialchars($club_responsable['nombre'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
            <div class="text-right flex flex-wrap gap-2 justify-end">
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes', 'torneo_id' => $torneo_id])); ?>"
                   class="px-6 py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-lg shadow-lg font-bold inline-flex items-center">
                    <i class="fas fa-file-alt mr-2"></i> Reportes PDF/Excel
                </a>
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'export_resultados_pdf', 'torneo_id' => $torneo_id, 'tipo' => 'general'])); ?>"
                   class="px-4 py-3 bg-red-200 text-black font-bold rounded-lg border-2 border-black inline-flex items-center text-sm">PDF este reporte</a>
                <a href="<?php echo htmlspecialchars(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes_print', 'torneo_id' => $torneo_id, 'tipo' => 'general'])); ?>" target="_blank" rel="noopener"
                   class="px-4 py-3 bg-blue-200 text-black font-bold rounded-lg border-2 border-black inline-flex items-center text-sm">Imprimir (modelo)</a>
                <button onclick="window.print()" 
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-lg transition-all transform hover:scale-105 font-bold">
                    <i class="fas fa-print mr-2"></i> Imprimir
                </button>
            </div>
        </div>
    </div>
    
    <!-- Tabla de Resultados -->
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-4">
            <h3 class="text-xl font-bold text-white">
                        <i class="fas fa-trophy mr-2"></i> <?php echo $es_parejas ? 'Clasificación General de Parejas' : 'Clasificación General de Participantes'; ?>
            </h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Pos.</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700"><?php echo $es_parejas ? 'Código Pareja' : 'ID Usuario'; ?></th>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700"><?php echo $es_parejas ? 'Participantes' : 'Jugador'; ?></th>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700">Club</th>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700">Equipo</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">G</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">P</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Efec.</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Puntos</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Pts. Rnk.</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">GFF</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Sanc.</th>
                        <th class="border border-gray-300 px-4 py-3 text-center font-bold text-gray-700">Tarj.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $posicion_display = 1;
                    foreach ($participantes as $participante): 
                        $posicion_actual = (int)($participante['posicion'] ?? 0);
                        if ($posicion_actual == 0) {
                            $posicion_actual = $posicion_display;
                        }
                        
                        $medalla_class = '';
                        if ($posicion_actual == 1) $medalla_class = 'bg-yellow-50';
                        elseif ($posicion_actual == 2) $medalla_class = 'bg-gray-50';
                        elseif ($posicion_actual == 3) $medalla_class = 'bg-orange-50';
                        
                        $nombre_equipo = $participante['nombre_equipo'] ?? 'Sin Equipo';
                        $codigo_equipo = $participante['codigo_equipo'] ?? '';
                        $equipo_display = !empty($codigo_equipo) ? "[{$codigo_equipo}] {$nombre_equipo}" : $nombre_equipo;
                        
                        // URL para resumen individual
                        $base_url_link = $base_url_return;
                        $action_param = $use_standalone ? '?' : '&';
                        $url_resumen = $base_url_link . $action_param . 'action=resumen_individual&torneo_id=' . $torneo_id . '&inscrito_id=' . $participante['id_usuario'] . '&from=resultados_general';
                    ?>
                        <tr class="hover:bg-gray-50 <?php echo $medalla_class; ?> border-b border-gray-200">
                            <td class="border border-gray-300 px-4 py-3 text-center font-bold">
                                <?php if ($posicion_actual == 1): ?>
                                    <i class="fas fa-trophy text-yellow-500"></i>
                                <?php elseif ($posicion_actual == 2): ?>
                                    <i class="fas fa-medal text-gray-400"></i>
                                <?php elseif ($posicion_actual == 3): ?>
                                    <i class="fas fa-medal text-orange-400"></i>
                                <?php else: ?>
                                    <?php echo $posicion_actual; ?>
                                <?php endif; ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center">
                                <code><?php echo htmlspecialchars($participante['id_usuario'] ?? 'N/A'); ?></code>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-gray-800">
                                <?php if ($es_parejas): ?>
                                    <span class="font-semibold">
                                        <i class="fas fa-user-friends mr-1"></i>
                                        <?php echo htmlspecialchars($participante['nombre_completo'] ?? 'N/A'); ?>
                                    </span>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($url_resumen); ?>" 
                                       class="text-purple-600 hover:text-purple-800 hover:underline font-semibold">
                                        <i class="fas fa-user mr-1"></i>
                                        <?php echo htmlspecialchars($participante['nombre_completo'] ?? $participante['username'] ?? 'N/A'); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($participante['sexo'])): ?>
                                    <small class="text-gray-500 ml-1">
                                        <?php echo $participante['sexo'] == 'M' || $participante['sexo'] == 1 ? '♂' : '♀'; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-gray-700">
                                <i class="fas fa-building mr-1 text-gray-500"></i>
                                <?php echo htmlspecialchars($participante['club_nombre'] ?? '—'); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-gray-700">
                                <i class="fas fa-users mr-1 text-purple-600"></i>
                                <?php echo htmlspecialchars($equipo_display); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-semibold text-green-600">
                                <?php echo (int)($participante['ganados'] ?? 0); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-semibold text-red-600">
                                <?php echo (int)($participante['perdidos'] ?? 0); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-semibold text-blue-600">
                                <?php echo (int)($participante['efectividad'] ?? 0); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-bold text-purple-600">
                                <?php echo (int)($participante['puntos'] ?? 0); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center font-bold text-indigo-600">
                                <?php echo (int)($participante['ptosrnk'] ?? 0); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center text-red-600">
                                <?php echo (int)($participante['gff'] ?? 0); ?>
                                <?php $partidas_bye = (int)($participante['partidas_bye'] ?? 0); if ($partidas_bye > 0): ?>
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-bold" style="background-color: #0d9488; color: #fff;" title="Partidas con descanso (BYE)"><?php echo $partidas_bye; ?> BYE</span>
                                <?php endif; ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center text-gray-600">
                                <?php echo (int)($participante['sancion'] ?? 0); ?>
                            </td>
                            <td class="border border-gray-300 px-4 py-3 text-center text-xs">
                                <?php echo getTarjetaTexto($participante['tarjeta'] ?? 0); ?>
                            </td>
                        </tr>
                        <?php $posicion_display++; ?>
                    <?php endforeach; ?>
                    
                    <?php if (empty($participantes)): ?>
                        <tr>
                            <td colspan="13" class="border border-gray-300 px-4 py-8 text-center text-gray-500">
                                <i class="fas fa-info-circle mr-2"></i>
                                No hay participantes registrados en este torneo
                            </td>
                        </tr>
                    <?php else: ?>
                        <!-- Paginador -->
                        <?php 
                        if (isset($total_paginas) && $total_paginas > 1) {
                            // Construir URL base para el paginador
                            $use_standalone_pag = $use_standalone;
                            $base_url_pag = $base_url_return;
                            $parametros_get = ['action' => 'resultados_general', 'torneo_id' => $torneo_id];
                            // Preservar otros parámetros GET si existen
                            foreach ($_GET as $key => $value) {
                                if ($key !== 'pagina' && $key !== 'action' && $key !== 'torneo_id') {
                                    $parametros_get[$key] = $value;
                                }
                            }
                            echo generarPaginador($pagina_actual, $total_paginas, $base_url_pag, $parametros_get);
                        }
                        ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@media print {
    body { margin: 0; padding: 0; }
    .bg-gradient-to-br { background: white !important; }
    .mb-4, .mb-6 { margin-bottom: 1rem !important; }
    .p-6 { padding: 1rem !important; }
    button, a[onclick] { display: none !important; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
}
</style>


