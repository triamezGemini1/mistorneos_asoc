<?php
/**
 * Asociaciones (tabla entidad): el mantenimiento operativo es vía módulo Clubes.
 * Este archivo conserva permisos y redirige a `page=clubs` para que la información
 * territorial se gestione junto al club (nombre, delegado, estado, afiliados, etc.).
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();

$urlClubsBase = static function (array $qs = []): string {
    $base = ['page' => 'clubs', 'action' => 'list'];
    return 'index.php?' . http_build_query(array_merge($base, $qs), '', '&', PHP_QUERY_RFC3986);
};

if ((($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    header('Location: ' . $urlClubsBase([
        'info' => 'La gestión de asociaciones se realiza desde Clubes: cree o edite un club y asigne la asociación (tabla territorial) en el formulario.',
    ]));
    exit;
}

$action = $_GET['action'] ?? 'index';
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'detail' && $detailId > 0) {
    try {
        $st = $pdo->prepare('SELECT id FROM clubes WHERE entidad = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$detailId]);
        $clubId = (int)$st->fetchColumn();
        if ($clubId > 0) {
            header('Location: index.php?page=clubs&action=detail&id=' . $clubId);
            exit;
        }
    } catch (Throwable $e) {
        error_log('entidades redirect→clubs: ' . $e->getMessage());
    }
    header('Location: ' . $urlClubsBase(['entidad_id' => $detailId]) . '#asociacion-' . $detailId);
    exit;
}

$carry = [];
foreach (['success', 'error'] as $k) {
    if (isset($_GET[$k]) && (string)$_GET[$k] !== '') {
        $carry[$k] = (string)$_GET[$k];
    }
}

header('Location: ' . $urlClubsBase($carry));
