<?php
/** Sesión lo antes posible; sin includes previos para no enviar salida antes de session_start(). */
$session_debug = getenv('SESSION_DEBUG');
if (session_status() === PHP_SESSION_ACTIVE) {
    if ($session_debug) error_log('[SESSION_DEBUG] session_start_early.php | sesión ya activa, saliendo');
    return;
}
if (headers_sent()) {
    if ($session_debug) error_log('[SESSION_DEBUG] session_start_early.php | headers already sent, skip');
    return;
}

// Duración en servidor y cookie (evita "sesión expirada" con el panel abierto o importaciones largas)
require_once __DIR__ . '/session_env_read.php';
$sessionTimes = session_read_lifetime_from_env();
ini_set('session.gc_maxlifetime', (string) $sessionTimes['gc']);

// Usar path='/' para que la cookie se envíe en toda la ruta (evita pérdida de sesión en subcarpetas tipo /mistorneos_beta/public/)
$path = '/';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$cookieDomain = isset($sessionTimes['cookie_domain']) ? (string) $sessionTimes['cookie_domain'] : '';
session_set_cookie_params([
    'lifetime' => $sessionTimes['cookie'],
    'path' => $path,
    'domain' => $cookieDomain,
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
$sname = !empty($sessionTimes['name']) ? (string)$sessionTimes['name'] : (getenv('SESSION_NAME') ?: 'mistorneos_session');
session_name($sname);
session_start();
if ($session_debug) {
    error_log('[SESSION_DEBUG] session_start_early.php | session_start OK | path=' . $path . ' | domain=' . ($cookieDomain !== '' ? $cookieDomain : '(host)') . ' | name=' . $sname . ' | id=' . session_id() . ' | cookie_enviada=' . (isset($_COOKIE[$sname]) ? 'si' : 'no'));
}
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else {
    // Regeneración muy frecuente (p. ej. 30 min) + fetch/XHR puede dejar al usuario "sin sesión" hasta recargar (cf. Auth::login).
    // Intervalo por defecto = max(1 h, SESSION_GC) vía session_env_read.php; 0 en SESSION_REGENERATE_AFTER_SECONDS desactiva.
    $regenAfter = isset($sessionTimes['regenerate_after']) ? (int) $sessionTimes['regenerate_after'] : 0;
    if ($regenAfter > 0 && (time() - (int) $_SESSION['created'] > $regenAfter)) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

// Invalidación puntual de referencias club_id por remapeo de IDs (marzo 2026).
// Se ejecuta una sola vez por sesión para evitar conflictos con IDs legados.
$clubRemapVersion = 'club_id_remap_20260326_v1';
if (!isset($_SESSION['_club_id_remap_version']) || $_SESSION['_club_id_remap_version'] !== $clubRemapVersion) {
    unset(
        $_SESSION['club_id'],
        $_SESSION['club_nombre'],
        $_SESSION['authenticated_club_id'],
        $_SESSION['club_authenticated']
    );

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['club_id'] = 0;
    }
    if (isset($_SESSION['desktop_user']) && is_array($_SESSION['desktop_user']) && isset($_SESSION['desktop_user']['club_id'])) {
        $_SESSION['desktop_user']['club_id'] = 0;
    }

    $_SESSION['_club_id_remap_version'] = $clubRemapVersion;
}
