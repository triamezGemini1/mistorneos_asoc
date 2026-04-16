<?php
/**
 * Cambia el estatus de un torneo (Activo/Inactivo) y registra la acción en auditoría.
 * POST: torneo_id, estatus (0|1)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';

$input = file_get_contents('php://input');
$data = $input && strpos(trim($input), '{') === 0 ? json_decode($input, true) : $_POST;
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

$torneo_id = isset($data['torneo_id']) ? (int)$data['torneo_id'] : 0;
$estatus = isset($data['estatus']) ? (int)$data['estatus'] : -1;
if ($torneo_id <= 0 || !in_array($estatus, [0, 1], true)) {
    echo json_encode(['ok' => false, 'error' => 'torneo_id y estatus (0 o 1) requeridos']);
    exit;
}

try {
    $pdo = DB_Local::pdo();
    $stmt = $pdo->prepare("SELECT id, nombre, organizacion_id FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        echo json_encode(['ok' => false, 'error' => 'Torneo no encontrado']);
        exit;
    }

    $pdo->prepare("UPDATE tournaments SET estatus = ?, last_updated = ? WHERE id = ?")
        ->execute([$estatus, date('Y-m-d H:i:s'), $torneo_id]);

    $admin = $_SESSION['desktop_user'] ?? [];
    $usuario_id = (int)($admin['id'] ?? 0);
    $organizacion_id = isset($torneo['cod_org']) ? (int)$torneo['cod_org'] : null;

    $pdo->prepare("
        INSERT INTO auditoria (usuario_id, accion, detalle, entidad_tipo, entidad_id, organizacion_id, sync_status)
        VALUES (?, 'modifico_estado_torneo', ?, 'torneo', ?, ?, 0)
    ")->execute([$usuario_id, $torneo['nombre'] ?? '', $torneo_id, $organizacion_id]);

    echo json_encode([
        'ok' => true,
        'estatus' => $estatus,
        'nombre' => $torneo['nombre'] ?? '',
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
