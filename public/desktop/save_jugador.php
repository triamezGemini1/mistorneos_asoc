<?php
/**
 * Guarda un jugador desde el formulario inline (AJAX).
 * Acepta texto para Entidad/Organización/Club: si existe en SQLite usa su ID; si no, crea el registro con sync_status=0.
 * Huella: creado_por (ID del admin) y fecha_creacion; registra acción en auditoría.
 * Devuelve JSON con el jugador creado para actualizar la lista sin recargar.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';

function uuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$input = file_get_contents('php://input');
$isJson = $input && strpos(trim($input), '{') === 0;
$data = $isJson ? json_decode($input, true) : $_POST;
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

$nombre = trim((string)($data['nombre'] ?? ''));
$apellido = trim((string)($data['apellido'] ?? ''));
if ($apellido !== '') {
    $nombre = trim($nombre . ' ' . $apellido);
}
$cedula = preg_replace('/\D/', '', trim((string)($data['cedula'] ?? '')));
$nacionalidad = in_array($data['nacionalidad'] ?? '', ['V', 'E', 'J', 'P']) ? $data['nacionalidad'] : 'V';
$sexo = in_array($data['sexo'] ?? '', ['M', 'F', 'O']) ? $data['sexo'] : 'M';
$fechnac = trim((string)($data['fechnac'] ?? '')) ?: null;
$email = trim((string)($data['email'] ?? '')) ?: null;
$username = trim((string)($data['username'] ?? ''));
$categ = isset($data['categ']) ? (int)$data['categ'] : 0;
$entidad_text = trim((string)($data['entidad_text'] ?? $data['entidad'] ?? ''));
$organizacion_text = trim((string)($data['organizacion_text'] ?? $data['organizacion'] ?? ''));
$club_text = trim((string)($data['club_text'] ?? $data['club'] ?? ''));

if ($nombre === '' || $cedula === '' || $username === '') {
    echo json_encode(['ok' => false, 'error' => 'Nombre, cédula y usuario son obligatorios.']);
    exit;
}

try {
    $pdo = DB_Local::pdo();

    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? OR username = ?");
    $stmt->execute([$cedula, $username]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Ya existe un jugador con esa cédula o nombre de usuario.']);
        exit;
    }

    // Resolver o crear Entidad (codigo)
    $entidad_codigo = 0;
    if ($entidad_text !== '') {
        if (is_numeric($entidad_text)) {
            $entidad_codigo = (int)$entidad_text;
        } else {
            $stmt = $pdo->prepare("SELECT codigo FROM entidad WHERE nombre = ? COLLATE NOCASE LIMIT 1");
            $stmt->execute([$entidad_text]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $entidad_codigo = (int)$row['codigo'];
            } else {
                $stmt = $pdo->query("SELECT COALESCE(MAX(codigo), 0) + 1 AS next FROM entidad");
                $entidad_codigo = (int)$stmt->fetchColumn();
                try {
                    $pdo->prepare("INSERT INTO entidad (codigo, nombre, sync_status) VALUES (?, ?, 0)")->execute([$entidad_codigo, $entidad_text]);
                } catch (Throwable $e) {
                    $pdo->prepare("INSERT INTO entidad (codigo, nombre) VALUES (?, ?)")->execute([$entidad_codigo, $entidad_text]);
                }
            }
        }
    }

    // Resolver o crear Organización (id)
    $organizacion_id = null;
    if ($organizacion_text !== '') {
        $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE nombre = ? COLLATE NOCASE LIMIT 1");
        $stmt->execute([$organizacion_text]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $organizacion_id = (int)$row['id'];
        } else {
            $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS next FROM organizaciones");
            $organizacion_id = (int)$stmt->fetchColumn();
            try {
                $pdo->prepare("INSERT INTO organizaciones (id, nombre, entidad, estatus, sync_status) VALUES (?, ?, ?, 1, 0)")->execute([$organizacion_id, $organizacion_text, $entidad_codigo]);
            } catch (Throwable $e) {
                $pdo->prepare("INSERT INTO organizaciones (id, nombre, entidad, estatus) VALUES (?, ?, ?, 1)")->execute([$organizacion_id, $organizacion_text, $entidad_codigo]);
            }
        }
    }

    // Resolver o crear Club (id)
    $club_id = 0;
    if ($club_text !== '') {
        $stmt = $pdo->prepare("SELECT id FROM clubes WHERE nombre = ? COLLATE NOCASE LIMIT 1");
        $stmt->execute([$club_text]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $club_id = (int)$row['id'];
        } else {
            $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS next FROM clubes");
            $club_id = (int)$stmt->fetchColumn();
            try {
                $pdo->prepare("INSERT INTO clubes (id, nombre, organizacion_id, entidad, estatus, sync_status) VALUES (?, ?, ?, ?, 1, 0)")->execute([$club_id, $club_text, $organizacion_id, $entidad_codigo]);
            } catch (Throwable $e) {
                $pdo->prepare("INSERT INTO clubes (id, nombre, organizacion_id, entidad, estatus) VALUES (?, ?, ?, ?, 1)")->execute([$club_id, $club_text, $organizacion_id, $entidad_codigo]);
            }
        }
    }

    $uuid = uuidV4();
    $last_updated = date('Y-m-d H:i:s');
    $fecha_creacion = $last_updated;
    $creado_por = (int)($_SESSION['desktop_user']['id'] ?? 0);

    $organizacion_id = null;
    if ($club_id) {
        $st = $pdo->prepare("SELECT cod_org FROM clubes WHERE id = ?");
        $st->execute([$club_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && isset($r['organizacion_id'])) {
            $organizacion_id = (int)$r['organizacion_id'];
        }
    }

    $pdo->prepare("
        INSERT INTO usuarios (uuid, nombre, cedula, nacionalidad, sexo, fechnac, email, categ, username, club_id, entidad, status, role, last_updated, sync_status, creado_por, fecha_creacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'usuario', ?, 0, ?, ?)
    ")->execute([$uuid, $nombre, $cedula, $nacionalidad, $sexo, $fechnac, $email, $categ, $username, $club_id, $entidad_codigo, $last_updated, $creado_por ?: null, $fecha_creacion]);

    $id = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO auditoria (usuario_id, accion, detalle, entidad_tipo, entidad_id, organizacion_id, sync_status)
        VALUES (?, 'registro_jugador', ?, 'jugador', ?, ?, 0)
    ")->execute([$creado_por, $nombre, $id, $organizacion_id]);
    $jugador = [
        'id' => $id,
        'uuid' => $uuid,
        'nombre' => $nombre,
        'cedula' => $cedula,
        'nacionalidad' => $nacionalidad,
        'sexo' => $sexo,
        'fechnac' => $fechnac,
        'email' => $email,
        'username' => $username,
        'categ' => $categ,
        'club_id' => $club_id,
        'club_nombre' => $club_text,
        'entidad' => $entidad_codigo,
        'entidad_nombre' => $entidad_text,
        'last_updated' => $last_updated,
    ];
    echo json_encode(['ok' => true, 'jugador' => $jugador]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
