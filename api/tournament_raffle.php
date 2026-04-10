<?php
/**
 * API: sorteos de premios entre inscritos del torneo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/RaffleHandler.php';

use Tournament\Handlers\RaffleHandler;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
    exit;
}

if (Auth::id() <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autenticado']);
    exit;
}
if (!Auth::canAccessTournament((int) ($_POST['torneo_id'] ?? 0))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sin permiso para este torneo']);
    exit;
}

$t = (string) ($_POST['csrf_token'] ?? '');
if ($t === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'CSRF inválido']);
    exit;
}

$torneoId = (int) ($_POST['torneo_id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'list');

if ($torneoId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'torneo_id requerido']);
    exit;
}

$pdo = DB::pdo();

try {
    if ($action === 'list') {
        $lista = RaffleHandler::listarInscritosElegibles($pdo, $torneoId);
        echo json_encode(['ok' => true, 'inscritos' => $lista, 'total' => count($lista)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'historial') {
        $filas = RaffleHandler::historialPorTorneo($pdo, $torneoId);
        $lotes = RaffleHandler::agruparHistorialPorLote($filas);
        echo json_encode(['ok' => true, 'lotes' => $lotes, 'filas' => $filas], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'run') {
        $n = max(1, min(50, (int) ($_POST['cantidad'] ?? 1)));
        $premio = (string) ($_POST['premio_label'] ?? 'Premio');
        $excluirPrev = !empty($_POST['excluir_ganadores_previos']);
        $excluirRaw = (string) ($_POST['excluir_ids'] ?? '');
        $excluirIds = [];
        if ($excluirRaw !== '') {
            foreach (preg_split('/[\s,;]+/', $excluirRaw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
                $excluirIds[] = (int) $p;
            }
        }
        $res = RaffleHandler::ejecutarSorteo($pdo, $torneoId, $n, $premio, $excluirIds, $excluirPrev);
        if (!empty($res['error'])) {
            echo json_encode(['ok' => false, 'message' => $res['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'batch_id' => $res['batch_id'], 'ganadores' => $res['ganadores']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Acción no reconocida']);
} catch (Throwable $e) {
    error_log('tournament_raffle: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error interno']);
}
