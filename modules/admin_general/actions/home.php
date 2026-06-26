<?php
/**
 * Action: Dashboard Home para Admin General
 * Solo tarjetas de estadísticas. Datos vía OrganizacionesData (Entidades, Orgs, Clubes, Usuarios por género).
 */

require_once __DIR__ . '/../../../config/admin_general_auth.php';
requireAdminGeneral();

require_once __DIR__ . '/../../../lib/OrganizacionesData.php';

$current_user = Auth::user();
$stats = OrganizacionesData::loadStatsGlobales();

extract([
    'stats' => $stats,
    'success_message' => $_GET['success'] ?? null,
    'error_message' => $_GET['error'] ?? null,
    'user_role' => 'admin_general',
    'current_user' => $current_user,
]);

include __DIR__ . '/../views/home.php';
