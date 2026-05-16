<?php
/**
 * Verifica inscripción previa y devuelve datos de usuario para prellenar formulario público.
 * Orden de búsqueda en cliente: este endpoint (usuarios) → search_persona.php (personas).
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @return array{nombre:string,apellido:string}
 */
function vi_split_nombre_apellido(string $nombreCompleto): array
{
    $nombreCompleto = trim(preg_replace('/\s+/', ' ', $nombreCompleto));
    if ($nombreCompleto === '') {
        return ['nombre' => '', 'apellido' => ''];
    }
    $pos = strpos($nombreCompleto, ' ');
    if ($pos === false) {
        return ['nombre' => $nombreCompleto, 'apellido' => ''];
    }

    return [
        'nombre' => substr($nombreCompleto, 0, $pos),
        'apellido' => trim(substr($nombreCompleto, $pos + 1)),
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function vi_persona_payload(array $row): array
{
    $parts = vi_split_nombre_apellido((string)($row['nombre'] ?? ''));
    $fechnac = (string)($row['fechnac'] ?? '');
    if ($fechnac !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $fechnac) === 0 && strtotime($fechnac) !== false) {
        $fechnac = date('Y-m-d', strtotime($fechnac));
    }
    $sexo = strtoupper(trim((string)($row['sexo'] ?? '')));
    if (!in_array($sexo, ['M', 'F', 'O'], true)) {
        $sexo = '';
    }

    return [
        'nombre' => $parts['nombre'],
        'apellido' => $parts['apellido'],
        'nombre_completo' => trim($parts['nombre'] . ' ' . $parts['apellido']),
        'email' => (string)($row['email'] ?? ''),
        'celular' => (string)($row['celular'] ?? ''),
        'sexo' => $sexo,
        'fechnac' => $fechnac,
        'nacionalidad' => strtoupper(trim((string)($row['nacionalidad'] ?? 'V'))) ?: 'V',
        'entidad' => (int)($row['entidad'] ?? 0),
    ];
}

$cedula = trim($_GET['cedula'] ?? '');
$nacionalidad = strtoupper(trim($_GET['nacionalidad'] ?? 'V'));
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
    $nacionalidad = 'V';
}

if (empty($cedula) || $torneo_id <= 0) {
    http_response_code(400);
    echo json_encode(['inscrito' => false, 'error' => 'Parámetros requeridos: cedula y torneo_id']);
    exit;
}

$cedula_num = preg_replace('/\D/', '', $cedula);
$cedula_variantes = array_values(array_unique(array_filter([
    $cedula_num,
    preg_replace('/^[VEJP]/i', '', $cedula),
    $cedula,
    $nacionalidad . $cedula_num,
])));

try {
    $pdo = DB::pdo();

    $usuario = null;
    foreach ($cedula_variantes as $c) {
        if ($c === '') {
            continue;
        }
        $stmt = $pdo->prepare(
            'SELECT id, nombre, email, celular, sexo, fechnac, nacionalidad, entidad
             FROM usuarios WHERE cedula = ? LIMIT 1'
        );
        $stmt->execute([$c]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($usuario) {
            break;
        }
    }

    if (!$usuario && $cedula_num !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, nombre, email, celular, sexo, fechnac, nacionalidad, entidad
             FROM usuarios
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), '-', ''), '.', ''), ' ', ''), '/', '') = ?
             LIMIT 1"
        );
        $stmt->execute([$cedula_num]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$usuario) {
        echo json_encode([
            'inscrito' => false,
            'usuario_existe' => false,
            'requiere_registro' => true,
            'mensaje' => 'No registrado en usuarios. Busque en padrón o complete el formulario.',
        ]);
        exit;
    }

    $id_usuario = (int)$usuario['id'];
    $stmt = $pdo->prepare('SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
    $stmt->execute([$torneo_id, $id_usuario]);
    $inscripcion = $stmt->fetch(PDO::FETCH_ASSOC);

    $payload = vi_persona_payload($usuario);

    if ($inscripcion) {
        echo json_encode([
            'inscrito' => true,
            'usuario_existe' => true,
            'mensaje' => 'Ya estás inscrito en este evento',
            'usuario' => $payload,
        ]);
        exit;
    }

    echo json_encode([
        'inscrito' => false,
        'usuario_existe' => true,
        'requiere_registro' => false,
        'mensaje' => 'Usuario encontrado. Revise sus datos y proceda con la inscripción.',
        'usuario' => $payload,
        'datos' => $payload,
    ]);
} catch (Exception $e) {
    error_log('verificar_inscripcion.php - Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'inscrito' => false,
        'error' => 'Error interno del servidor',
    ]);
}
