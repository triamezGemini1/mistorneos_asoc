<?php
require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

AuthService::requireAuth();
$user = Auth::user();

$dashHome = class_exists('AppHelpers') ? AppHelpers::dashboard('home') : 'index.php?page=home';

$role_original = (string)($user['role_original'] ?? $user['role'] ?? '');
if ($role_original !== 'admin_general') {
    header('Location: ' . $dashHome);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $dashHome);
    exit;
}

CSRF::validate();

$mode = isset($_POST['role_mode']) ? (int)$_POST['role_mode'] : 0;
$allowed = [0, 1, 2, 3, 4];
if (!in_array($mode, $allowed, true)) {
    $mode = 0;
}

$_SESSION['role_switch_mode'] = $mode;

/**
 * Evita Location relativo mal resuelto (p. ej. SCRIPT_NAME en raíz → localhost/index.php fuera del proyecto)
 * y acepta URLs absolutas mismo-host que antes se descartaban.
 */
function switch_role_resolve_redirect(string $returnTo): string {
    $default = class_exists('AppHelpers') ? AppHelpers::dashboard('home') : 'index.php?page=home';

    $returnTo = trim($returnTo);
    if ($returnTo === '' || preg_match('#^(javascript|data):#i', $returnTo)) {
        return $default;
    }

    $schemeOut = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hostOut = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $hostsMatch = static function (string $urlHost): bool {
        $a = strtolower(preg_replace('#:\d+$#', '', $urlHost));
        $b = strtolower(preg_replace('#:\d+$#', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($a === $b) {
            return true;
        }
        if ($a === '' || $b === '') {
            return false;
        }
        $local = ['localhost', '127.0.0.1'];
        return in_array($a, $local, true) && in_array($b, $local, true);
    };

    $looksLikeDashboard = static function (string $path): bool {
        return str_contains($path, 'index.php');
    };

    if (preg_match('#^https?://#i', $returnTo)) {
        $p = parse_url($returnTo);
        if (!$p || empty($p['host']) || !$hostsMatch($p['host'])) {
            return $default;
        }
        $path = $p['path'] ?? '';
        $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
        $pathJoined = ($path ?: '/') . $query;

        if ($path === '/' || $path === '' || !$looksLikeDashboard($path)) {
            return $default;
        }

        return $schemeOut . '://' . $hostOut . $pathJoined;
    }

    // Protocol-relative
    if (str_starts_with($returnTo, '//')) {
        return $default;
    }

    // Ruta absoluta en el servidor
    if (str_starts_with($returnTo, '/')) {
        $pathOnly = strtok($returnTo, '?') ?: '';
        if ($pathOnly === '/' || !$looksLikeDashboard($pathOnly)) {
            return $default;
        }

        return $schemeOut . '://' . $hostOut . $returnTo;
    }

    // Relativo al directorio del script actual (preferir entrada public/)
    if (!preg_match('#^[a-zA-Z0-9_\-./?=&]+$#', $returnTo)) {
        return $default;
    }

    if (class_exists('AppHelpers')) {
        return rtrim(AppHelpers::getRequestEntryUrl(), '/') . '/' . ltrim($returnTo, '/');
    }

    return $default;
}

$return_to = trim((string)($_POST['return_to'] ?? ''));
$target = switch_role_resolve_redirect($return_to);

header('Location: ' . $target);
exit;
