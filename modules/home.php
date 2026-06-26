<?php
/**
 * Punto de entrada al Dashboard (page=home)
 * Admin General: delega en admin_general (solo tarjetas).
 * Otros roles: delega en admin_dashboard.php.
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';

$user = Auth::user();
// Admin general efectivo (incl. modo 0 tras switch): vista con tarjetas globales legacy.
if ($user && Auth::isAdminGeneral() && ($user['role'] ?? '') === 'admin_general') {
    require __DIR__ . '/admin_general/actions/home.php';
    return;
}
// Admin asociación (ASOC): inicio con tarjetas y menú header.
if ($user && ($user['role'] ?? '') === 'admin_club') {
    require_once __DIR__ . '/../lib/AsociacionAdminNav.php';
    if (AsociacionAdminNav::useHeaderNav(
        (string) ($user['role_original'] ?? $user['role'] ?? ''),
        (string) ($user['role'] ?? '')
    )) {
        require __DIR__ . '/admin_club/actions/home.php';
        return;
    }
}
// admin_torneo, operador y simulaciones de rol: dashboard legacy por rol.
require __DIR__ . '/admin_dashboard.php';
