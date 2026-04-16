<?php
/**
 * PDF del ranking en vista matriz (Pos / P Gan / Pts por torneo).
 * Mismos filtros que ranking_atletas.php (genero, organizacion_id).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/RankingAtletasPublicoService.php';
require_once __DIR__ . '/../lib/report_generator.php';

$pdo = DB::pdo();
require_once __DIR__ . '/includes/ranking_atletas_context.php';

if ($role === 'admin_general' && $ranking_sin_org_admin_general) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Seleccione una organización en el ranking y vuelva a exportar.';
    exit;
}
if ($role === 'admin_club' && $organizacion_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No hay organización asociada a su cuenta.';
    exit;
}
if ($atletas === [] || $torneos_matriz === []) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No hay datos para exportar con los filtros actuales.';
    exit;
}

$titulo_genero = $genero === 'F' ? 'Femenino' : 'Masculino';
$subtitle = 'Vista matriz — ' . $titulo_genero;
if ($org_nombre_encabezado !== '') {
    $subtitle .= ' — ' . $org_nombre_encabezado;
}

$tableStyle = '
<style>
table.matriz-pdf {
  width: 100%;
  border-collapse: collapse;
  margin-top: 12px;
  font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
  font-size: 5.5pt;
}
table.matriz-pdf th, table.matriz-pdf td {
  border: 1px solid #333;
  padding: 1px 2px;
  vertical-align: middle;
}
table.matriz-pdf thead th { font-weight: bold; }
table.matriz-pdf tbody tr:nth-child(even) { background: #f4f6f8; }
.c { text-align: center; }
.r { text-align: right; }
/* +20 % sobre 72px ≈ 86px */
.nombre-col {
  max-width: 86px;
  width: 86px;
  overflow: hidden;
  font-size: 6.5pt;
  font-weight: bold;
  line-height: 1.25;
  color: #111;
}
.torneo-h { font-size: 5.5pt; line-height: 1.1; background: #475569; color: #fff; }
/* Pos / P Gan / Pts y totales: ~4 dígitos con holgura (misma anchura todas) */
.stat-pdf, .stat-total-pdf {
  width: 22pt;
  min-width: 22pt;
  max-width: 22pt;
  font-size: 6pt;
  font-weight: bold;
  font-variant-numeric: tabular-nums;
  padding: 2px 3px !important;
  box-sizing: border-box;
}
.stat-pdf { text-align: center; }
.stat-total-pdf { text-align: right; }
</style>';

$html = $tableStyle;
$html .= '<table class="matriz-pdf">';
$html .= '<thead>';
$html .= '<tr>';
$html .= '<th rowspan="2">#</th>';
$html .= '<th rowspan="2">Atleta</th>';
foreach ($torneos_matriz as $col) {
    $nom = (string) ($col['nombre'] ?? '');
    if (function_exists('mb_strlen') && mb_strlen($nom) > 22) {
        $nom = mb_substr($nom, 0, 20) . '…';
    } elseif (strlen($nom) > 22) {
        $nom = substr($nom, 0, 20) . '…';
    }
    $html .= '<th colspan="3" class="torneo-h c">' . htmlspecialchars($nom) . '</th>';
}
$html .= '<th rowspan="2" class="r stat-total-pdf">Pts Σ</th>';
$html .= '<th rowspan="2" class="r stat-total-pdf">Efect. Σ</th>';
$html .= '<th rowspan="2" class="r stat-total-pdf">G Σ</th>';
$html .= '</tr><tr>';
foreach ($torneos_matriz as $_) {
    $html .= '<th class="c stat-pdf">Pos</th><th class="c stat-pdf">P Gan</th><th class="c stat-pdf">Pts</th>';
}
$html .= '</tr></thead><tbody>';

foreach ($atletas as $a) {
    $rk = (int) $a['rank'];
    $porTorneo = [];
    foreach ($a['detalle_torneos'] as $t) {
        $porTorneo[(int) ($t['torneo_id'] ?? 0)] = $t;
    }
    $html .= '<tr>';
    $html .= '<td class="c">' . $rk . '</td>';
    $html .= '<td class="nombre-col">' . htmlspecialchars($a['nombre']) . '</td>';
    foreach ($torneos_matriz as $col) {
        $tid = (int) $col['torneo_id'];
        $celda = $porTorneo[$tid] ?? null;
        if ($celda === null) {
            $html .= '<td class="c stat-pdf">—</td><td class="c stat-pdf">—</td><td class="c stat-pdf">—</td>';
        } else {
            $posN = (int) ($celda['posicion'] ?? 0);
            $html .= '<td class="c stat-pdf">' . ($posN > 0 ? (string) $posN : '—') . '</td>';
            $html .= '<td class="c stat-pdf">' . (int) ($celda['ganados'] ?? 0) . '</td>';
            $html .= '<td class="c stat-pdf">' . (int) ($celda['ptosrnk'] ?? 0) . '</td>';
        }
    }
    $html .= '<td class="r stat-total-pdf">' . (int) $a['total_ptosrnk'] . '</td>';
    $html .= '<td class="r stat-total-pdf">' . (int) $a['total_efectividad'] . '</td>';
    $html .= '<td class="r stat-total-pdf">' . (int) $a['total_ganados'] . '</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table>';
$html .= '<p style="font-size:7pt;color:#666;margin-top:10px;">Pos = posición en el torneo; P Gan = partidas ganadas; Pts = puntos de ranking en el evento.</p>';

try {
    $report = new ReportGenerator('Ranking de atletas — Matriz', 'landscape');
    $report->setContent($report->addReportHeader($subtitle) . $html);
    $fn = 'ranking_atletas_matriz_' . date('Y-m-d_His') . '_' . $genero;
    if ($organizacion_id > 0) {
        $fn .= '_org' . $organizacion_id;
    }
    $fn .= '.pdf';
    $report->generate($fn, true);
} catch (Throwable $e) {
    error_log('ranking_atletas_pdf: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar el PDF. Compruebe que Dompdf esté instalado (composer).';
    exit;
}
