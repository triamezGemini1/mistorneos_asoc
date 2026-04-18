<?php
/**
 * PDF por tipo de reporte (Letter). tipo= posiciones | clubes_* | general | equipos_* | consolidado | todos
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/ResultadosReportesPrintHtml.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneoId = (int)($_GET['torneo_id'] ?? 0);
$tipo = preg_replace('/[^a-z_]/', '', (string)($_GET['tipo'] ?? 'consolidado'));
$allowed = ['por_club', 'clubes_resumido', 'clubes_detallado', 'general', 'posiciones', 'equipos_resumido', 'equipos_detallado', 'consolidado', 'todos'];
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
if ($tipo !== 'todos') {
    if ($tipo === 'general' && !$esEquipos) {
        $tipo = 'consolidado';
    }
    if (in_array($tipo, ['equipos_resumido', 'equipos_detallado'], true) && !$esEquipos) {
        $tipo = 'consolidado';
    }
}

$esc = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$nombreTorneo = $esc($torneo['nombre'] ?? 'Torneo');
$fechaGen = date('d/m/Y H:i');
$fechaTor = $esc($torneo['fechator'] ?? '');
$generoGet = isset($_GET['genero']) ? (string) $_GET['genero'] : null;

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
    .salto-reporte { page-break-before: always; }
' . ResultadosReportesPrintHtml::cssZebraPdf();

ob_start();

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';

if ($tipo === 'todos') {
    $partes = ResultadosReportesPrintHtml::tiposParaTodos($esEquipos);
    $primero = true;
    foreach ($partes as $subTipo) {
        if (!$primero) {
            echo '<div class="salto-reporte"></div>';
        }
        $primero = false;
        echo ResultadosReportesPrintHtml::renderBody($pdo, $torneoId, $torneo, $subTipo, $esc, $generoGet);
    }
} else {
    echo ResultadosReportesPrintHtml::renderBody($pdo, $torneoId, $torneo, $tipo, $esc, $generoGet);
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
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);
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
