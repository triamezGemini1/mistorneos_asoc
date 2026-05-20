<?php
/**
 * Endpoint: devuelve tablas maestras (entidad, organizaciones, clubes) para sincronización desktop.
 * Misma validación API_KEY que api_fetch_jugadores.php.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/db.php';

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$expectedKey = class_exists('Env') ? (Env::get('SYNC_API_KEY') ?: Env::get('API_KEY')) : '';

if ($expectedKey === '' || $apiKey === '' || !hash_equals((string)$expectedKey, (string)$apiKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado.', 'entidades' => [], 'organizaciones' => [], 'clubes' => []]);
    exit;
}

try {
    $pdo = DB::pdo();

    // Entidad: tabla puede tener codigo/nombre o id/nombre
    $entidades = [];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_COLUMN);
        $codeCol = in_array('codigo', $cols) ? 'codigo' : (in_array('id', $cols) ? 'id' : $cols[0] ?? 'codigo');
        $nameCol = in_array('nombre', $cols) ? 'nombre' : (in_array('descripcion', $cols) ? 'descripcion' : ($cols[1] ?? 'nombre'));
        $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol}");
        $entidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // tabla entidad puede no existir
    }

    // Organizaciones
    $organizaciones = [];
    try {
        $stmt = $pdo->query("SELECT id, nombre, entidad, estatus FROM organizaciones WHERE estatus = 1 ORDER BY nombre");
        $organizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }

    // Clubes
    $clubes = [];
    try {
        require_once __DIR__ . '/lib/ClubHelper.php';
        $orgFk = ClubHelper::clubOrganizacionFkColumn();
        $orgSelect = $orgFk !== null
            ? "{$orgFk} AS organizacion_id"
            : 'NULL AS organizacion_id';
        $stmt = $pdo->query("SELECT id, nombre, {$orgSelect}, entidad, estatus FROM clubes WHERE estatus = 1 ORDER BY nombre");
        $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }

    echo json_encode([
        'ok' => true,
        'entidades' => $entidades,
        'organizaciones' => $organizaciones,
        'clubes' => $clubes,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
        'entidades' => [],
        'organizaciones' => [],
        'clubes' => [],
    ]);
}
