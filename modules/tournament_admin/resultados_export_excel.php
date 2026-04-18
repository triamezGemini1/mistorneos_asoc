<?php
/**
 * Excel resultados (.xlsx o .xls HTML si no hay PhpSpreadsheet).
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

$generoGet = isset($_GET['genero']) ? (string) $_GET['genero'] : null;
$data = ResultadosReporteData::cargar($pdo, $torneoId, $torneo, $generoGet);
$esEquipos = (int)($torneo['modalidad'] ?? 0) === 3;
$topN = max(1, (int)($torneo['pareclub'] ?? 8));
$dataClub = obtenerTopJugadoresPorClub($pdo, $torneoId, $topN);

$autoload = __DIR__ . '/../../vendor/autoload.php';
$useSpreadsheet = file_exists($autoload);
if ($useSpreadsheet) {
    try {
        require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $useSpreadsheet = false;
        }
    } catch (Throwable $e) {
        $useSpreadsheet = false;
    }
}

if (!$useSpreadsheet) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="resultados_' . $torneoId . '_' . date('Y-m-d') . '.xls"');
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1"><tr><th colspan="6">Clasificación — ' . htmlspecialchars($torneo['nombre'] ?? '') . '</th></tr>';
    echo '<tr><th>Pos</th><th>Jugador</th><th>Club</th><th>G</th><th>P</th><th>Pts</th></tr>';
    $n = 0;
    foreach ($data['participantes'] as $p) {
        $n++;
        $pos = (int)($p['posicion'] ?? 0) ?: $n;
        echo '<tr><td>' . $pos . '</td><td>' . htmlspecialchars($p['nombre_completo'] ?? '') . '</td><td>' . htmlspecialchars($p['club_nombre'] ?? '') . '</td><td>' . ($p['ganados'] ?? '') . '</td><td>' . ($p['perdidos'] ?? '') . '</td><td>' . ($p['puntos'] ?? '') . '</td></tr>';
    }
    echo '</table><br><table border="1"><tr><th colspan="8">Clubes resumido (top ' . $topN . ')</th></tr>';
    echo '<tr><th>Club</th><th>Jug</th><th>Sum G</th><th>Sum P</th><th>Prom ef</th><th>Sum Pts</th><th>Sum GFF</th><th>Mej pos</th></tr>';
    foreach ($dataClub['estadisticas'] as $st) {
        echo '<tr><td>' . htmlspecialchars($st['club_nombre']) . '</td><td>' . (int)$st['cantidad_jugadores'] . '</td><td>' . (int)$st['total_ganados'] . '</td><td>' . (int)$st['total_perdidos'] . '</td><td>' . (int)$st['promedio_efectividad'] . '</td><td>' . (int)$st['total_puntos_grupo'] . '</td><td>' . (int)$st['total_gff'] . '</td><td>' . (int)$st['mejor_posicion'] . '</td></tr>';
    }
    echo '</table><br><table border="1"><tr><th colspan="9">Clubes detallado</th></tr>';
    echo '<tr><th>Club</th><th>#</th><th>Jugador</th><th>Pos</th><th>G</th><th>P</th><th>Ef</th><th>Pts</th><th>GFF</th></tr>';
    foreach ($dataClub['detalle'] as $r) {
        echo '<tr><td>' . htmlspecialchars($r['club_nombre']) . '</td><td>' . (int)$r['ranking'] . '</td><td>' . htmlspecialchars($r['nombre']) . '</td><td>' . (int)$r['posicion'] . '</td><td>' . (int)$r['ganados'] . '</td><td>' . (int)$r['perdidos'] . '</td><td>' . (int)$r['efectividad'] . '</td><td>' . (int)$r['puntos'] . '</td><td>' . (int)$r['gff'] . '</td></tr>';
    }
    echo '</table></body></html>';
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator('Mistorneos')->setTitle('Resultados ' . ($torneo['nombre'] ?? ''));

// Hoja 1: Clasificación
$si = $spreadsheet->getActiveSheet();
$si->setTitle('Clasificación');
$headers = ['Pos', 'Jugador', 'Cédula/ID', 'Club'];
if ($esEquipos) {
    $headers[] = 'Cód. eq';
    $headers[] = 'Equipo';
}
$headers = array_merge($headers, ['G', 'P', 'Efec.', 'Pts', 'Rnk', 'GFF', 'Sanc.', 'Tarj.', 'Bye']);
$colCount = count($headers);
for ($c = 0; $c < $colCount; $c++) {
    $si->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . '1', $headers[$c]);
}
$si->getStyle('A1:' . Coordinate::stringFromColumnIndex($colCount) . '1')->getFont()->setBold(true);
$row = 2;
$n = 0;
foreach ($data['participantes'] as $p) {
    $n++;
    $pos = (int)($p['posicion'] ?? 0) ?: $n;
    $c = 1;
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $pos);
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['nombre_completo'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['cedula'] ?? $p['username'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['club_nombre'] ?? '');
    if ($esEquipos) {
        $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['codigo_equipo'] ?? '');
        $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['nombre_equipo'] ?? '');
    }
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['ganados'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['perdidos'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['efectividad'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['puntos'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['ptosrnk'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['gff'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['sancion'] ?? '');
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, ResultadosReporteData::tarjetaTexto($p['tarjeta'] ?? 0));
    $si->setCellValue(Coordinate::stringFromColumnIndex($c++) . $row, $p['partidas_bye'] ?? 0);
    $row++;
}

// Clubes resumido
$sr = $spreadsheet->createSheet();
$sr->setTitle('Clubes_resumido');
$sr->fromArray([['Club', 'Jugadores', 'Sum G', 'Sum P', 'Prom.ef.', 'Sum Pts', 'Sum GFF', 'Mej.pos', 'Top N club']], null, 'A1');
$h = 2;
foreach ($dataClub['estadisticas'] as $st) {
    $sr->setCellValue('A' . $h, $st['club_nombre']);
    $sr->setCellValue('B' . $h, $st['cantidad_jugadores']);
    $sr->setCellValue('C' . $h, $st['total_ganados']);
    $sr->setCellValue('D' . $h, $st['total_perdidos']);
    $sr->setCellValue('E' . $h, $st['promedio_efectividad']);
    $sr->setCellValue('F' . $h, $st['total_puntos_grupo']);
    $sr->setCellValue('G' . $h, $st['total_gff']);
    $sr->setCellValue('H' . $h, $st['mejor_posicion']);
    $sr->setCellValue('I' . $h, $topN);
    $h++;
}

// Clubes detallado
$sd = $spreadsheet->createSheet();
$sd->setTitle('Clubes_detallado');
$sd->fromArray([['Club', '# club', 'Jugador', 'Pos torneo', 'G', 'P', 'Ef.', 'Pts', 'Rnk', 'GFF']], null, 'A1');
$h = 2;
foreach ($dataClub['detalle'] as $r) {
    $sd->setCellValue('A' . $h, $r['club_nombre']);
    $sd->setCellValue('B' . $h, $r['ranking']);
    $sd->setCellValue('C' . $h, $r['nombre']);
    $sd->setCellValue('D' . $h, $r['posicion']);
    $sd->setCellValue('E' . $h, $r['ganados']);
    $sd->setCellValue('F' . $h, $r['perdidos']);
    $sd->setCellValue('G' . $h, $r['efectividad']);
    $sd->setCellValue('H' . $h, $r['puntos']);
    $sd->setCellValue('I' . $h, $r['ptosrnk']);
    $sd->setCellValue('J' . $h, $r['gff']);
    $h++;
}

// Rondas
$sr2 = $spreadsheet->createSheet();
$sr2->setTitle('Rondas');
$sr2->fromArray([['Ronda', 'Mesas', 'Registros']], null, 'A1');
$h = 2;
foreach ($data['rondas'] as $r) {
    $sr2->setCellValue('A' . $h, $r['num_ronda']);
    $sr2->setCellValue('B' . $h, $r['mesas']);
    $sr2->setCellValue('C' . $h, $r['registros']);
    $h++;
}

if ($esEquipos && !empty($data['equipos'])) {
    $se = $spreadsheet->createSheet();
    $se->setTitle('Equipos');
    $se->fromArray([['Pos', 'Código', 'Nombre', 'G', 'P', 'Efec.', 'Pts']], null, 'A1');
    $h = 2;
    foreach ($data['equipos'] as $eq) {
        $se->setCellValue('A' . $h, $eq['pos_equipo'] ?? '');
        $se->setCellValue('B' . $h, $eq['codigo_equipo']);
        $se->setCellValue('C' . $h, $eq['nombre_equipo'] ?? '');
        $se->setCellValue('D' . $h, $eq['g_eq'] ?? '');
        $se->setCellValue('E' . $h, $eq['p_eq'] ?? '');
        $se->setCellValue('F' . $h, $eq['ef_eq'] ?? '');
        $se->setCellValue('G' . $h, $eq['pts_eq'] ?? '');
        $h++;
    }
}

$spreadsheet->setActiveSheetIndex(0);
$fname = 'resultados_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $torneo['nombre'] ?? 't') . '_' . date('Y-m-d_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
