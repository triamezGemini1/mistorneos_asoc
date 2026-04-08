<?php
/**
 * API: Buscar jugador por cédula para inscripción equipo/pareja.
 * Criterios alineados a search_usuario_inscripcion_sitio + coincidencia por solo dígitos
 * cuando la columna guarda puntos/guiones (V-12.345.678).
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

/** Extrae solo dígitos para búsqueda expedita. */
function cedulaSoloNumerosInscripcion($cedula) {
    return preg_replace('/\D/', '', trim($cedula));
}

try {
    $cedula = trim($_GET['cedula'] ?? '');
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);

    if ($cedula === '' || $torneo_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Cédula y torneo_id son requeridos']);
        exit;
    }

    $pdo = DB::pdo();
    $solo_numeros = cedulaSoloNumerosInscripcion($cedula);
    if ($solo_numeros === '') {
        echo json_encode(['success' => false, 'message' => 'Cédula inválida']);
        exit;
    }

    $cedNormExpr = "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(CAST(u.cedula AS CHAR)), '-', ''), '.', ''), ' ', ''), '/', '')";

    $sql = "
        SELECT u.id as id_usuario, u.nombre, u.cedula, u.sexo,
               u.club_id as club_id, c.nombre as club_nombre,
               ins.id as id_inscrito, ins.codigo_equipo
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ?
            AND (ins.estatus IS NULL OR CAST(ins.estatus AS CHAR) NOT IN ('4', 'retirado'))
        WHERE (
                u.cedula = ?
             OR u.cedula = ?
             OR u.cedula = ?
             OR u.cedula = ?
             OR u.cedula = ?
             OR {$cedNormExpr} = ?
        )
          AND (u.role IS NULL OR TRIM(CAST(u.role AS CHAR)) = '' OR u.role = 'usuario')
          AND (u.status IS NULL OR u.status IN ('approved', 'active', 'activo') OR u.status = 1)
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $torneo_id,
        $solo_numeros,
        'V' . $solo_numeros,
        'E' . $solo_numeros,
        'J' . $solo_numeros,
        'P' . $solo_numeros,
        $solo_numeros,
    ]);
    $jugador = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($jugador) {
        if (!empty(trim($jugador['codigo_equipo'] ?? ''))) {
            echo json_encode([
                'success' => false,
                'message' => 'Este jugador ya está asignado (código: ' . trim($jugador['codigo_equipo']) . ')',
                'jugador' => $jugador
            ]);
            exit;
        }
        $jugador['id'] = $jugador['id_inscrito'] ?? null;
        echo json_encode(['success' => true, 'jugador' => $jugador]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No encontrado. Verifique la cédula.']);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
