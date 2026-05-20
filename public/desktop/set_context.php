<?php
/**
 * Guarda el contexto de la sesión del usuario que inició sesión con internet
 * (organización/club/entidad) para pre-seleccionar en registro offline.
 * POST o GET: organizacion_id, organizacion_nombre, club_id, club_nombre, entidad_id, entidad_nombre
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$file = __DIR__ . '/session_context.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    if ($input && strpos($input, '{') === 0) {
        $data = json_decode($input, true);
    } else {
        $data = [
            'organizacion_id' => (int)($_POST['organizacion_id'] ?? 0),
            'organizacion_nombre' => trim($_POST['organizacion_nombre'] ?? ''),
            'club_id' => (int)($_POST['club_id'] ?? 0),
            'club_nombre' => trim($_POST['club_nombre'] ?? ''),
            'entidad_id' => (int)($_POST['entidad_id'] ?? 0),
            'entidad_nombre' => trim($_POST['entidad_nombre'] ?? ''),
        ];
    }
} else {
    $data = [
        'organizacion_id' => (int)($_GET['organizacion_id'] ?? 0),
        'organizacion_nombre' => trim($_GET['organizacion_nombre'] ?? ''),
        'club_id' => (int)($_GET['club_id'] ?? 0),
        'club_nombre' => trim($_GET['club_nombre'] ?? ''),
        'entidad_id' => (int)($_GET['entidad_id'] ?? 0),
        'entidad_nombre' => trim($_GET['entidad_nombre'] ?? ''),
    ];
}

$data['organizacion_id'] = FvdConfig::ORGANIZACION_ID;
$data['organizacion_nombre'] = FvdConfig::getOrganizacionNombre();
$data['updated_at'] = date('c');

// Invalidar referencias club_id legadas tras remapeo (IDs anteriores al corte).
if (!empty($data['club_id']) && (int)$data['club_id'] > 0 && (int)$data['club_id'] < 40) {
    $data['club_id'] = 0;
    $data['club_nombre'] = '';
}

$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (file_put_contents($file, $json) !== false) {
    echo json_encode(['ok' => true, 'message' => 'Contexto guardado.']);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No se pudo escribir session_context.json']);
}
