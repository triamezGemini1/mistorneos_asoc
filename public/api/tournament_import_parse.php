<?php
declare(strict_types=1);

/**
 * API: Parsea archivo de importación (.xlsx, .xls, .xlsm, .csv).
 * Usa el mismo lector que la carga masiva de equipos (CargaMasivaEquiposSitioService).
 */

require_once __DIR__ . '/../../config/session_start_early.php';
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/CargaMasivaEquiposSitioService.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireRoleJson(['admin_general', 'admin_torneo', 'admin_club']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';
if (!$csrf_token || !$session_token || !hash_equals($session_token, $csrf_token)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido o sesión desincronizada. Recargue la página (F5) e intente de nuevo.']);
    exit;
}

if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    $err = (int) ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE);
    $msg = 'No se recibió el archivo o hubo error en la subida';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'El archivo supera el tamaño máximo permitido por el servidor (php.ini: upload_max_filesize / post_max_size).';
    }
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$file = $_FILES['archivo'];
$tmpPath = $file['tmp_name'];
$name = $file['name'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

if (!in_array($ext, ['xls', 'xlsx', 'xlsm', 'csv'], true)) {
    echo json_encode(['success' => false, 'error' => 'Formato no soportado. Use Excel (.xls, .xlsx, .xlsm) o CSV.']);
    exit;
}

$asegurarUtf8 = static function ($v): string {
    if ($v === null || $v === '') {
        return '';
    }
    if (is_object($v) && method_exists($v, '__toString')) {
        $v = (string) $v;
    }
    $s = trim((string) $v);
    $s = str_replace("\xEF\xBB\xBF", '', $s);
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $enc = mb_detect_encoding($s, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') {
        $s = mb_convert_encoding($s, 'UTF-8', $enc);
    }
    return $s;
};

/**
 * @param array<int, array<int, mixed>> $allRows
 * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
 */
function extract_headers_and_data_rows(array $allRows, callable $asegurarUtf8): array {
    $allRows = array_values(array_filter($allRows, static function ($row) {
        if (!is_array($row)) {
            return false;
        }
        foreach ($row as $c) {
            if ($c !== null && $c !== '') {
                return true;
            }
        }
        return false;
    }));
    if (empty($allRows)) {
        throw new RuntimeException('El archivo no contiene filas');
    }
    $headerRowIndex = null;
    $headerKeywords = ['nacionalidad', 'cedula', 'cédula', 'nombre', 'club', 'organizacion', 'organización', 'sexo', 'telefono', 'email'];
    for ($r = 0, $max = min(4, count($allRows)); $r < $max; $r++) {
        $row = $allRows[$r];
        $cells = array_map(static function ($c) {
            return trim(mb_strtolower((string) $c));
        }, $row);
        $match = 0;
        foreach ($cells as $cell) {
            foreach ($headerKeywords as $kw) {
                if ($cell === $kw || ($cell !== '' && strpos($cell, $kw) !== false)) {
                    $match++;
                    break;
                }
            }
        }
        if ($match >= 2) {
            $headerRowIndex = $r;
            break;
        }
    }
    if ($headerRowIndex === null) {
        $headerRowIndex = 3;
    }
    if (count($allRows) < $headerRowIndex + 1) {
        throw new RuntimeException('El archivo debe tener cabecera (nacionalidad, CEDULA, nombre, etc.) y al menos una fila de datos');
    }
    $headers = array_map($asegurarUtf8, $allRows[$headerRowIndex]);
    $numCols = count($headers);
    $rows = [];
    for ($i = $headerRowIndex + 1, $n = count($allRows); $i < $n; $i++) {
        $rowCells = $allRows[$i];
        $rowCells = array_map(static function ($v) use ($asegurarUtf8) {
            if ($v !== null && is_float($v) && (int) $v == $v) {
                $v = (int) $v;
            }
            return $asegurarUtf8($v);
        }, $rowCells);
        while (count($rowCells) < $numCols) {
            $rowCells[] = '';
        }
        $rows[] = array_slice($rowCells, 0, $numCols);
    }
    return [$headers, $rows];
}

try {
    @ini_set('memory_limit', '256M');
    @set_time_limit(180);

    $errLectura = null;
    $allRows = CargaMasivaEquiposSitioService::leerFilasImportacionMasivaIndividual($tmpPath, $name, $errLectura);
    if ($allRows === []) {
        $msg = 'No se pudo leer el archivo o no tiene filas.';
        if ($errLectura !== null && $errLectura !== '') {
            $msg .= ' ' . $errLectura;
        } elseif (in_array($ext, ['xlsx', 'xlsm', 'xls'], true)) {
            $msg .= ' Si es Excel: use .xlsx, primera hoja con datos, o guarde como CSV UTF-8. En el servidor hace falta PhpSpreadsheet (composer install) para .xls/.xlsx/.xlsm.';
        }
        throw new RuntimeException($msg);
    }

    [$headers, $rows] = extract_headers_and_data_rows($allRows, $asegurarUtf8);

    echo json_encode([
        'success' => true,
        'headers' => $headers,
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('tournament_import_parse: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error al leer el archivo: ' . $e->getMessage(),
    ]);
}
