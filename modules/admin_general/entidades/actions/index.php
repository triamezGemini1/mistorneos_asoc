<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/auth.php';
require_once __DIR__ . '/../../../../config/db.php';
Auth::requireRole(['admin_general']);

require_once __DIR__ . '/../../../../lib/OrganizacionesData.php';

$resumen_entidades = OrganizacionesData::loadResumenEntidades();
$entidades_crud = [];
try {
    $stmt = DB::pdo()->query('SELECT id, nombre, estado FROM entidad ORDER BY id ASC');
    $entidades_crud = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('entidades/actions/index: ' . $e->getMessage());
}

include_once __DIR__ . '/../views/index.php';
