<?php
/**
 * Configuración centralizada de autenticación para Admin General.
 * Centraliza la verificación del rol admin_general, eliminando comprobaciones dispersas.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/auth.php';

/**
 * Exige rol admin_general. Redirige si no cumple.
 */
function requireAdminGeneral(): void {
    Auth::requireRole(['admin_general']);
}

/**
 * Verifica si el usuario actual es admin_general.
 * @return bool
 */
function isAdminGeneral(): bool {
    return Auth::isAdminGeneral();
}
