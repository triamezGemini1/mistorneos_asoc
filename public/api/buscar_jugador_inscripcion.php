<?php
/**
 * API: Buscar jugador para inscripción equipo/pareja.
 * Criterios unificados (lib/BusquedaJugadorInscripcionService): 1) usuarios 2) afiliados 3) no encontrado (manual).
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/BusquedaJugadorInscripcionService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $cedula = trim($_GET['cedula'] ?? $_GET['busqueda'] ?? '');
    $torneo_id = (int) ($_GET['torneo_id'] ?? 0);
    $nacionalidad = BusquedaJugadorInscripcionService::normalizarNacionalidad((string) ($_GET['nacionalidad'] ?? 'V'));

    if ($cedula === '' || $torneo_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Cédula y torneo_id son requeridos']);
        exit;
    }

    Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
    if (!Auth::canAccessTournament($torneo_id)) {
        echo json_encode(['success' => false, 'message' => 'Sin permiso para este torneo.']);
        exit;
    }

    $pdo = DB::pdo();
    $out = BusquedaJugadorInscripcionService::buscarParaInscripcionEquipo($pdo, $torneo_id, $nacionalidad, $cedula);

    if (empty($out['success']) && empty($out['jugador'])) {
        echo json_encode([
            'success' => false,
            'resultado' => $out['resultado'] ?? 'error',
            'message' => $out['message'] ?? 'Solicitud inválida.',
        ]);
        exit;
    }

    if (($out['resultado'] ?? '') === BusquedaJugadorInscripcionService::RESULTADO_YA_EN_EQUIPO) {
        echo json_encode([
            'success' => false,
            'resultado' => $out['resultado'],
            'message' => $out['message'] ?? '',
            'jugador' => $out['jugador'] ?? null,
        ]);
        exit;
    }

    if (!empty($out['success']) && !empty($out['jugador'])) {
        $j = $out['jugador'];
        $j['id'] = $j['id_inscrito'] ?? null;
        echo json_encode([
            'success' => true,
            'resultado' => $out['resultado'] ?? BusquedaJugadorInscripcionService::RESULTADO_USUARIO,
            'message' => $out['message'] ?? '',
            'jugador' => $j,
        ]);
        exit;
    }

    if (!empty($out['success']) && ($out['resultado'] ?? '') === BusquedaJugadorInscripcionService::RESULTADO_NO_ENCONTRADO) {
        echo json_encode([
            'success' => true,
            'resultado' => BusquedaJugadorInscripcionService::RESULTADO_NO_ENCONTRADO,
            'message' => $out['message'] ?? '',
            'jugador' => null,
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => $out['message'] ?? 'Error en la búsqueda.',
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
    ]);
}
