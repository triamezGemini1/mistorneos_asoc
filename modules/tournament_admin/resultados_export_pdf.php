<?php
/**
 * PDF por tipo de reporte (Letter). tipo= por_club | general | equipos_resumido | equipos_detallado | consolidado
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../../lib/ResultadosPorClubHelper.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneoId = (int)($_GET['torneo_id'] ?? 0);
$tipo = preg_replace('/[^a-z_]/', '', (string)($_GET['tipo'] ?? 'consolidado'));
$allowed = ['por_club', 'clubes_resumido', 'clubes_detallado', 'general', 'posiciones', 'equipos_resumido', 'equipos_detallado', 'consolidado'];
if (!in_array($tipo, $allowed, true)) {
    $tipo = 'consolidado';
}
if ($tipo === 'por_club') {
    $tipo = 'clubes_detallado';
}

if ($torneoId <= 0 || !Auth::canAccessTournament($torneoId)) {
    http_response_code(403);
    exit('Acceso denegado');
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
$stmt->execute([$torneoId]);
$torneo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$torneo) {
    http_response_code(404);
    exit('Torneo no encontrado');
}

$esEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
if ($tipo === 'general' && !$esEquipos) {
    $tipo = 'consolidado';
}
if (in_array($tipo, ['equipos_resumido', 'equipos_detallado'], true) && !$esEquipos) {
    $tipo = 'consolidado';
}


$esc = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$nombreTorneo = $esc($torneo['nombre'] ?? 'Torneo');
$fechaGen = date('d/m/Y H:i');
$fechaTor = $esc($torneo['fechator'] ?? '');

$css = '
    @page { size: letter portrait; margin: 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
    h1 { font-size: 12pt; margin: 0 0 4px 0; }
    h2 { font-size: 9pt; margin: 10px 0 4px 0; border-bottom: 1px solid #333; }
    .meta { font-size: 7pt; color: #444; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #555; padding: 2px 4px; text-align: left; }
    th { background: #e0e0e0; font-weight: bold; font-size: 7pt; }
    td.num { text-align: center; }
    .club-block { page-break-inside: avoid; margin-bottom: 10px; }
';

ob_start();

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';

if ($tipo === 'clubes_resumido') {
    $topN = max(1, (int)($torneo['pareclub'] ?? 8));
    $dataClub = obtenerTopJugadoresPorClub($pdo, $torneoId, $topN);
    echo '<h1>Clasificación por clubes — Resumido — ' . $nombreTorneo . '</h1>';
    echo '<div class="meta">Letter · Top ' . $topN . ' por club · ' . $esc($fechaGen) . '</div>';
    echo '<table><tr><th>Club</th><th>Jug.</th><th>∑G</th><th>∑P</th><th>Prom.ef.</th><th>∑Pts</th><th>∑GFF</th><th>Mej.pos</th></tr>';
    foreach ($dataClub['estadisticas'] as $st) {
        echo '<tr><td>' . $esc($st['club_nombre']) . '</td><td class="num">' . (int)$st['cantidad_jugadores'] . '</td><td class="num">' . (int)$st['total_ganados'] . '</td><td class="num">' . (int)$st['total_perdidos'] . '</td><td class="num">' . (int)$st['promedio_efectividad'] . '</td><td class="num">' . (int)$st['total_puntos_grupo'] . '</td><td class="num">' . (int)$st['total_gff'] . '</td><td class="num">' . (int)$st['mejor_posicion'] . '</td></tr>';
    }
    echo '</table>';
} elseif ($tipo === 'clubes_detallado') {
    $topN = max(1, (int)($torneo['pareclub'] ?? 8));
    $dataClub = obtenerTopJugadoresPorClub($pdo, $torneoId, $topN);
    echo '<h1>Clasificación por clubes — Detallado — ' . $nombreTorneo . '</h1>';
    echo '<div class="meta">Letter · Top ' . $topN . ' por club · ' . $esc($fechaGen) . '</div>';
    $byClub = [];
    foreach ($dataClub['detalle'] as $row) {
        $byClub[$row['club_nombre']][] = $row;
    }
    foreach ($byClub as $clubNombre => $rows) {
        echo '<div class="club-block"><h2>' . $esc($clubNombre) . '</h2><table><tr><th>#</th><th>Jugador</th><th>Pos</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td class="num">' . (int)$r['ranking'] . '</td><td>' . $esc($r['nombre']) . '</td><td class="num">' . (int)$r['posicion'] . '</td><td class="num">' . (int)$r['ganados'] . '</td><td class="num">' . (int)$r['perdidos'] . '</td><td class="num">' . (int)$r['efectividad'] . '</td><td class="num">' . (int)$r['puntos'] . '</td><td class="num">' . (int)$r['ptosrnk'] . '</td><td class="num">' . (int)$r['gff'] . '</td></tr>';
        }
        echo '</table></div>';
    }
} elseif ($tipo === 'general' || $tipo === 'posiciones') {
    $data = ResultadosReporteData::cargar($pdo, $torneoId, $torneo);
    $participantes = $data['participantes'];
    $esParejasPdf = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
    $colJugPdf = $esParejasPdf ? 'Pareja' : 'Jugador';
    $h1 = $tipo === 'posiciones' ? 'Clasificación general (posiciones) — ' : 'Clasificación individual — ';
    echo '<h1>' . $h1 . $nombreTorneo . '</h1>';
    echo '<div class="meta">Fecha torneo: ' . $fechaTor . ' · Generado: ' . $esc($fechaGen) . '</div>';
    echo '<table><tr><th>Pos</th><th>' . $esc($colJugPdf) . '</th><th>Club</th><th>Equipo</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th><th>Sanc.</th><th>Tarj.</th></tr>';
    $n = 0;
    foreach ($participantes as $p) {
        $n++;
        $pos = (int)($p['posicion'] ?? 0) ?: $n;
        $eq = trim(($p['codigo_equipo'] ?? '') . ' ' . ($p['nombre_equipo'] ?? ''));
        echo '<tr><td class="num">' . $pos . '</td><td>' . $esc($p['nombre_completo'] ?? '') . '</td><td>' . $esc($p['club_nombre'] ?? '') . '</td><td>' . $esc($eq) . '</td><td class="num">' . $esc($p['ganados'] ?? '') . '</td><td class="num">' . $esc($p['perdidos'] ?? '') . '</td><td class="num">' . $esc($p['efectividad'] ?? '') . '</td><td class="num">' . $esc($p['puntos'] ?? '') . '</td><td class="num">' . $esc($p['ptosrnk'] ?? '') . '</td><td class="num">' . $esc($p['gff'] ?? '') . '</td><td class="num">' . $esc($p['sancion'] ?? '') . '</td><td class="num">' . $esc(ResultadosReporteData::tarjetaTexto($p['tarjeta'] ?? 0)) . '</td></tr>';
    }
    echo '</table>';
} elseif ($tipo === 'equipos_resumido') {
    $sql = "SELECT e.codigo_equipo, e.nombre_equipo, c.nombre AS club_nombre, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion
        FROM equipos e LEFT JOIN clubes c ON e.id_club = c.id
        WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
        ORDER BY e.ganados DESC, e.efectividad DESC, e.puntos DESC, e.codigo_equipo";
    $st = $pdo->prepare($sql);
    $st->execute([$torneoId]);
    $eqs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '<h1>Equipos — Resumido — ' . $nombreTorneo . '</h1>';
    echo '<div class="meta">Letter · ' . $esc($fechaGen) . '</div>';
    echo '<table><tr><th>#</th><th>Cód.</th><th>Equipo</th><th>Club</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Sanc.</th></tr>';
    $pos = 1;
    foreach ($eqs as $e) {
        echo '<tr><td class="num">' . $pos++ . '</td><td>' . $esc($e['codigo_equipo']) . '</td><td>' . $esc($e['nombre_equipo'] ?? '') . '</td><td>' . $esc($e['club_nombre'] ?? '') . '</td><td class="num">' . (int)$e['ganados'] . '</td><td class="num">' . (int)$e['perdidos'] . '</td><td class="num">' . (int)$e['efectividad'] . '</td><td class="num">' . (int)$e['puntos'] . '</td><td class="num">' . (int)($e['sancion'] ?? 0) . '</td></tr>';
    }
    echo '</table>';
} elseif ($tipo === 'equipos_detallado') {
    $sqlEq = "SELECT e.codigo_equipo, e.nombre_equipo, c.nombre AS club_nombre, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion
        FROM equipos e LEFT JOIN clubes c ON e.id_club = c.id
        WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
        ORDER BY e.ganados DESC, e.efectividad DESC, e.puntos DESC";
    $eqs = $pdo->prepare($sqlEq);
    $eqs->execute([$torneoId]);
    $lista = $eqs->fetchAll(PDO::FETCH_ASSOC);
    echo '<h1>Equipos — Detallado — ' . $nombreTorneo . '</h1>';
    echo '<div class="meta">Letter · ' . $esc($fechaGen) . '</div>';
    $gffSql = ResultadosReporteData::sqlGffSubquery();
    foreach ($lista as $e) {
        echo '<div class="club-block"><h2>[' . $esc($e['codigo_equipo']) . '] ' . $esc($e['nombre_equipo'] ?? '') . ' — ' . $esc($e['club_nombre'] ?? '') . '</h2>';
        echo '<div class="meta">G ' . (int)($e['ganados'] ?? 0) . ' P ' . (int)($e['perdidos'] ?? 0) . ' Ef ' . (int)($e['efectividad'] ?? 0) . ' Pts ' . (int)($e['puntos'] ?? 0) . '</div>';
        $sj = $pdo->prepare("SELECT u.nombre AS nombre_completo, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.ptosrnk, {$gffSql} AS gff, i.sancion, i.tarjeta
            FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 'retirado'
            ORDER BY i.ganados DESC, i.efectividad DESC, i.puntos DESC");
        $sj->execute([$torneoId, $e['codigo_equipo']]);
        $jug = $sj->fetchAll(PDO::FETCH_ASSOC);
        echo '<table><tr><th>Jugador</th><th>Pos</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th></tr>';
        foreach ($jug as $j) {
            echo '<tr><td>' . $esc($j['nombre_completo']) . '</td><td class="num">' . (int)$j['posicion'] . '</td><td class="num">' . (int)$j['ganados'] . '</td><td class="num">' . (int)$j['perdidos'] . '</td><td class="num">' . (int)$j['efectividad'] . '</td><td class="num">' . (int)$j['puntos'] . '</td><td class="num">' . (int)$j['ptosrnk'] . '</td><td class="num">' . (int)$j['gff'] . '</td></tr>';
        }
        echo '</table></div>';
    }
} else {
    $data = ResultadosReporteData::cargar($pdo, $torneoId, $torneo);
    $participantes = $data['participantes'];
    $clubes = $data['resumen_clubes'];
    $equipos = $data['equipos'];
    $rondas = $data['rondas'];
    echo '<h1>Reporte consolidado — ' . $nombreTorneo . '</h1><div class="meta">Fecha torneo: ' . $fechaTor . ' · Generado: ' . $esc($fechaGen) . '</div>';
    if (!empty($rondas)) {
        echo '<h2>Rondas</h2><table><tr><th>Ronda</th><th>Mesas</th><th>Reg.</th></tr>';
        foreach ($rondas as $r) {
            echo '<tr><td class="num">' . $esc($r['num_ronda']) . '</td><td class="num">' . $esc($r['mesas']) . '</td><td class="num">' . $esc($r['registros']) . '</td></tr>';
        }
        echo '</table>';
    }
    if ($esEquipos && !empty($equipos)) {
        echo '<h2>Equipos</h2><table><tr><th>Pos</th><th>Cód</th><th>Nombre</th><th>G</th><th>P</th><th>Ef</th><th>Pts</th></tr>';
        foreach ($equipos as $eq) {
            echo '<tr><td class="num">' . $esc($eq['pos_equipo'] ?? '') . '</td><td>' . $esc($eq['codigo_equipo']) . '</td><td>' . $esc($eq['nombre_equipo'] ?? '') . '</td><td class="num">' . $esc($eq['g_eq'] ?? '') . '</td><td class="num">' . $esc($eq['p_eq'] ?? '') . '</td><td class="num">' . $esc($eq['ef_eq'] ?? '') . '</td><td class="num">' . $esc($eq['pts_eq'] ?? '') . '</td></tr>';
        }
        echo '</table>';
    }
    echo '<h2>Por club</h2><table><tr><th>Club</th><th>Jug</th><th>∑G</th><th>∑P</th></tr>';
    foreach ($clubes as $c) {
        echo '<tr><td>' . $esc($c['club_nombre']) . '</td><td class="num">' . $esc($c['jugadores']) . '</td><td class="num">' . $esc($c['sum_ganados']) . '</td><td class="num">' . $esc($c['sum_perdidos']) . '</td></tr>';
    }
    $esParejasPdfCons = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
    $colJugPdfCons = $esParejasPdfCons ? 'Pareja' : 'Jugador';
    $titClasPdf = $esParejasPdfCons ? 'Clasificación por pareja' : 'Clasificación individual';
    echo '</table><h2>' . $esc($titClasPdf) . '</h2><table><tr><th>Pos</th><th>' . $esc($colJugPdfCons) . '</th><th>Club</th><th>G</th><th>P</th><th>Pts</th></tr>';
    $n = 0;
    foreach ($participantes as $p) {
        $n++;
        $pos = (int)($p['posicion'] ?? 0) ?: $n;
        echo '<tr><td class="num">' . $pos . '</td><td>' . $esc($p['nombre_completo'] ?? '') . '</td><td>' . $esc($p['club_nombre'] ?? '') . '</td><td class="num">' . $esc($p['ganados'] ?? '') . '</td><td class="num">' . $esc($p['perdidos'] ?? '') . '</td><td class="num">' . $esc($p['puntos'] ?? '') . '</td></tr>';
    }
    echo '</table>';
}

echo '</body></html>';
$html = ob_get_clean();

$baseName = 'resultados_' . $tipo . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $torneo['nombre'] ?? 't') . '_' . date('Y-m-d');
$autoload = __DIR__ . '/../../vendor/autoload.php';
$dompdfOk = file_exists($autoload) && is_readable($autoload);

if ($dompdfOk) {
    try {
        if (!class_exists(\Dompdf\Dompdf::class, false)) {
            require_once $autoload;
        }
        if (!class_exists(\Dompdf\Dompdf::class, false)) {
            $dompdfOk = false;
        }
    } catch (Throwable $e) {
        $dompdfOk = false;
        if (function_exists('error_log')) {
            error_log('[resultados_export_pdf] autoload: ' . $e->getMessage());
        }
    }
}

if ($dompdfOk) {
    try {
        @ini_set('memory_limit', '256M');
        @set_time_limit(120);
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        while (ob_get_level()) {
            ob_end_clean();
        }
        $dompdf->stream($baseName . '.pdf', ['Attachment' => true]);
        exit;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[resultados_export_pdf] Dompdf: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        $dompdfOk = false;
    }
}

// Fallback: HTML listo para imprimir (evita HTTP 500 si falta vendor o Dompdf falla en el servidor)
while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $baseName . '_imprimir.html"');
echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . htmlspecialchars($baseName, ENT_QUOTES, 'UTF-8') . '</title>';
echo '<style>@page{size:letter portrait;margin:12mm}body{font-family:system-ui,sans-serif;padding:12px}</style></head><body>';
echo '<p style="background:#fff3cd;padding:10px;border:1px solid #856404"><strong>PDF no generado en el servidor</strong> (falta <code>vendor/</code> o error al renderizar). ';
echo 'Abra este archivo y use <strong>Imprimir → Guardar como PDF</strong> en el navegador, o ejecute en el servidor: <code>composer install</code>.</p>';
echo $html;
echo '</body></html>';
exit;
