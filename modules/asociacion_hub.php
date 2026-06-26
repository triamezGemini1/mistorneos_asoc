<?php
declare(strict_types=1);

/**
 * Punto de entrada del Hub de Asociación (incluido por layout.php).
 * La validación de org_id ocurre en public/index.php antes del layout.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/asociacion_gestion_controller.php';
    exit;
}

require_once __DIR__ . '/asociacion_hub_controller.php';