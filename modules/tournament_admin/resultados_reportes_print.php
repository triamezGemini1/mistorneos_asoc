<?php
/**
 * Impresión por tipo (mismo contenido que PDF). Letter. tipo en GET.
 */
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/ResultadosReporteData.php';
require_once __DIR__ . '/../../lib/ResultadosPorClubHelper.php';

$tipo = preg_replace('/[^a-z_]/', '', (string)($_GET['tipo'] ?? 'consolidado'));
$allowed = ['por_club', 'clubes_resumido', 'clubes_detallado', 'general', 'posiciones', 'equipos_resumido', 'equipos_detallado', 'consolidado'];
if (!in_array($tipo, $allowed, true)) {
    $tipo = 'consolidado';
}
if ($tipo === 'por_club') {
    $tipo = 'clubes_detallado';
}
$esEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
if ($tipo === 'general' && !$esEquipos) {
    $tipo = 'consolidado';
}
if (in_array($tipo, ['equipos_resumido', 'equipos_detallado'], true) && !$esEquipos) {
    $tipo = 'consolidado';
}

$pdo = DB::pdo();
$esc = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$titles = [
    'clubes_resumido' => 'Clubes — resumido',
    'clubes_detallado' => 'Clubes — detallado',
    'general' => 'Clasificación con equipos',
    'posiciones' => 'Clasificación general',
    'equipos_resumido' => 'Equipos — resumido',
    'equipos_detallado' => 'Equipos — detallado',
    'consolidado' => 'Reporte consolidado',
];
$title = $titles[$tipo] ?? 'Reporte';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $esc($title) ?> — <?= $esc($torneo['nombre'] ?? '') ?></title>
    <style>
        @page { size: letter portrait; margin: 12mm; }
        @media print { .no-print { display: none !important; } }
        body { font-family: system-ui, sans-serif; font-size: 10pt; margin: 12px; }
        h1 { font-size: 14pt; margin: 0 0 6px 0; }
        h2 { font-size: 11pt; margin: 12px 0 4px 0; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
        th, td { border: 1px solid #666; padding: 4px 6px; }
        th { background: #eee; }
        td.num { text-align: center; }
        .meta { font-size: 9pt; color: #444; margin-bottom: 8px; }
        .club-block { page-break-inside: avoid; margin-bottom: 12px; }
        .no-print { margin-bottom: 12px; }
        .no-print a, .no-print button {
            display: inline-block; margin-right: 8px; margin-bottom: 8px;
            padding: 10px 16px; font-weight: 700; color: #000 !important;
            background: #fde68a; border: 2px solid #000; border-radius: 8px; text-decoration: none;
        }
    </style>
</head>
<body>
<div class="no-print">
    <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    <a href="<?= $esc(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resultados_reportes', 'torneo_id' => (int)$torneo_id])) ?>">Volver a reportes</a>
    <?php
    $origen = [
        'clubes_resumido' => 'resultados_por_club',
        'clubes_detallado' => 'resultados_por_club',
        'general' => 'resultados_general',
        'posiciones' => 'posiciones',
        'equipos_resumido' => 'resultados_equipos_resumido',
        'equipos_detallado' => 'resultados_equipos_detallado',
        'consolidado' => 'resultados_reportes',
    ];
    $act = $origen[$tipo] ?? 'resultados_reportes';
    ?>
    <a href="<?= $esc(AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => $act, 'torneo_id' => (int)$torneo_id])) ?>">Volver al origen</a>
</div>

<?php
$nombreTorneo = $esc($torneo['nombre'] ?? '');
$fechaTor = $esc($torneo['fechator'] ?? '');
$fechaGen = $esc(date('d/m/Y H:i'));

if ($tipo === 'clubes_resumido') {
    $topN = max(1, (int)($torneo['pareclub'] ?? 8));
    $dataClub = obtenerTopJugadoresPorClub($pdo, $torneo_id, $topN);
    echo '<h1>Clubes — resumido — ' . $nombreTorneo . '</h1><div class="meta">Letter · Top ' . $topN . ' · ' . $fechaGen . '</div>';
    echo '<table><tr><th>Club</th><th>Jug.</th><th>∑G</th><th>∑P</th><th>Prom.ef.</th><th>∑Pts</th><th>∑GFF</th><th>Mej.pos</th></tr>';
    foreach ($dataClub['estadisticas'] as $st) {
        echo '<tr><td>' . $esc($st['club_nombre']) . '</td><td class="num">' . (int)$st['cantidad_jugadores'] . '</td><td class="num">' . (int)$st['total_ganados'] . '</td><td class="num">' . (int)$st['total_perdidos'] . '</td><td class="num">' . (int)$st['promedio_efectividad'] . '</td><td class="num">' . (int)$st['total_puntos_grupo'] . '</td><td class="num">' . (int)$st['total_gff'] . '</td><td class="num">' . (int)$st['mejor_posicion'] . '</td></tr>';
    }
    echo '</table>';
} elseif ($tipo === 'clubes_detallado') {
    $topN = max(1, (int)($torneo['pareclub'] ?? 8));
    $dataClub = obtenerTopJugadoresPorClub($pdo, $torneo_id, $topN);
    echo '<h1>Clubes — detallado — ' . $nombreTorneo . '</h1><div class="meta">Letter · Top ' . $topN . ' · ' . $fechaGen . '</div>';
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
    $data = ResultadosReporteData::cargar($pdo, $torneo_id, $torneo);
    $esParejasRep = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
    $colJugadorRep = $esParejasRep ? 'Pareja' : 'Jugador';
    $h1p = $tipo === 'posiciones' ? 'Tabla de posiciones — ' : 'Resultados general — ';
    echo '<h1>' . $h1p . $nombreTorneo . '</h1><div class="meta">' . $fechaTor . ' · ' . $fechaGen . '</div>';
    echo '<table><tr><th>Pos</th><th>' . $esc($colJugadorRep) . '</th><th>Club</th><th>Equipo</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Rnk</th><th>GFF</th><th>Sanc.</th><th>Tarj.</th></tr>';
    $n = 0;
    foreach ($data['participantes'] as $p) {
        $n++;
        $pos = (int)($p['posicion'] ?? 0) ?: $n;
        $eq = trim(($p['codigo_equipo'] ?? '') . ' ' . ($p['nombre_equipo'] ?? ''));
        echo '<tr><td class="num">' . $pos . '</td><td>' . $esc($p['nombre_completo'] ?? '') . '</td><td>' . $esc($p['club_nombre'] ?? '') . '</td><td>' . $esc($eq) . '</td><td class="num">' . (int)$p['ganados'] . '</td><td class="num">' . (int)$p['perdidos'] . '</td><td class="num">' . (int)$p['efectividad'] . '</td><td class="num">' . (int)$p['puntos'] . '</td><td class="num">' . (int)$p['ptosrnk'] . '</td><td class="num">' . (int)$p['gff'] . '</td><td class="num">' . (int)$p['sancion'] . '</td><td class="num">' . $esc(ResultadosReporteData::tarjetaTexto($p['tarjeta'] ?? 0)) . '</td></tr>';
    }
    echo '</table>';
} elseif ($tipo === 'equipos_resumido') {
    $sql = "SELECT e.codigo_equipo, e.nombre_equipo, c.nombre AS club_nombre, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion FROM equipos e LEFT JOIN clubes c ON e.id_club = c.id WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo != '' ORDER BY e.ganados DESC, e.efectividad DESC, e.puntos DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$torneo_id]);
    $eqs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '<h1>Equipos — resumido — ' . $nombreTorneo . '</h1><div class="meta">Letter · ' . $fechaGen . '</div>';
    echo '<table><tr><th>#</th><th>Cód.</th><th>Equipo</th><th>Club</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>Sanc.</th></tr>';
    $pos = 1;
    foreach ($eqs as $e) {
        echo '<tr><td class="num">' . $pos++ . '</td><td>' . $esc($e['codigo_equipo']) . '</td><td>' . $esc($e['nombre_equipo'] ?? '') . '</td><td>' . $esc($e['club_nombre'] ?? '') . '</td><td class="num">' . (int)$e['ganados'] . '</td><td class="num">' . (int)$e['perdidos'] . '</td><td class="num">' . (int)$e['efectividad'] . '</td><td class="num">' . (int)$e['puntos'] . '</td><td class="num">' . (int)($e['sancion'] ?? 0) . '</td></tr>';
    }
    echo '</table>';
} elseif ($tipo === 'equipos_detallado') {
    $gffSql = ResultadosReporteData::sqlGffSubquery();
    $sqlEq = "SELECT e.codigo_equipo, e.nombre_equipo, c.nombre AS club_nombre, e.ganados, e.perdidos, e.efectividad, e.puntos, e.sancion FROM equipos e LEFT JOIN clubes c ON e.id_club = c.id WHERE e.id_torneo = ? AND e.estatus = 0 AND e.codigo_equipo != '' ORDER BY e.ganados DESC, e.efectividad DESC, e.puntos DESC";
    $eqs = $pdo->prepare($sqlEq);
    $eqs->execute([$torneo_id]);
    echo '<h1>Equipos — detallado — ' . $nombreTorneo . '</h1><div class="meta">Letter · ' . $fechaGen . '</div>';
    foreach ($eqs->fetchAll(PDO::FETCH_ASSOC) as $e) {
        echo '<div class="club-block"><h2>[' . $esc($e['codigo_equipo']) . '] ' . $esc($e['nombre_equipo'] ?? '') . '</h2>';
        $sj = $pdo->prepare("SELECT u.nombre AS nombre_completo, i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.ptosrnk, {$gffSql} AS gff FROM inscritos i INNER JOIN usuarios u ON i.id_usuario = u.id WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 'retirado' ORDER BY i.ganados DESC");
        $sj->execute([$torneo_id, $e['codigo_equipo']]);
        echo '<table><tr><th>Jugador</th><th>Pos</th><th>G</th><th>P</th><th>Ef.</th><th>Pts</th><th>GFF</th></tr>';
        foreach ($sj->fetchAll(PDO::FETCH_ASSOC) as $j) {
            echo '<tr><td>' . $esc($j['nombre_completo']) . '</td><td class="num">' . (int)$j['posicion'] . '</td><td class="num">' . (int)$j['ganados'] . '</td><td class="num">' . (int)$j['perdidos'] . '</td><td class="num">' . (int)$j['efectividad'] . '</td><td class="num">' . (int)$j['puntos'] . '</td><td class="num">' . (int)$j['gff'] . '</td></tr>';
        }
        echo '</table></div>';
    }
} else {
    $data = ResultadosReporteData::cargar($pdo, $torneo_id, $torneo);
    echo '<h1>Consolidado — ' . $nombreTorneo . '</h1><div class="meta">' . $fechaGen . '</div>';
    if (!empty($data['rondas'])) {
        echo '<h2>Rondas</h2><table><tr><th>Ronda</th><th>Mesas</th></tr>';
        foreach ($data['rondas'] as $r) {
            echo '<tr><td class="num">' . $esc($r['num_ronda']) . '</td><td class="num">' . $esc($r['mesas']) . '</td></tr>';
        }
        echo '</table>';
    }
    $esParejasCons = in_array((int)($torneo['modalidad'] ?? 0), [2, 4], true);
    $colJugCons = $esParejasCons ? 'Pareja' : 'Jugador';
    $titClas = $esParejasCons ? 'Clasificación por pareja' : 'Clasificación';
    echo '<h2>' . $esc($titClas) . '</h2><table><tr><th>Pos</th><th>' . $esc($colJugCons) . '</th><th>Club</th><th>G</th><th>P</th><th>Pts</th></tr>';
    $n = 0;
    foreach ($data['participantes'] as $p) {
        $n++;
        $pos = (int)($p['posicion'] ?? 0) ?: $n;
        echo '<tr><td class="num">' . $pos . '</td><td>' . $esc($p['nombre_completo'] ?? '') . '</td><td>' . $esc($p['club_nombre'] ?? '') . '</td><td class="num">' . (int)$p['ganados'] . '</td><td class="num">' . (int)$p['perdidos'] . '</td><td class="num">' . (int)$p['puntos'] . '</td></tr>';
    }
    echo '</table>';
}
?>
</body>
</html>
