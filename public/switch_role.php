<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session_start_early.php';
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth_service.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/app_helpers.php';

/**
 * Redirección interna segura (respeta subcarpeta /mistorneos/public/).
 */
function switch_role_redirect_target(string $returnTo, string $fallback): string
{
    $returnTo = trim($returnTo);
    if ($returnTo === '') {
        return $fallback;
    }

    if (preg_match('#^(javascript|data):#i', $returnTo)) {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $returnTo)) {
        $currentHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $parsed = parse_url($returnTo);
        $returnHost = strtolower((string) ($parsed['host'] ?? ''));
        if ($returnHost !== '' && $returnHost !== $currentHost) {
            return $fallback;
        }
        $returnTo = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    }

    $publicBase = rtrim(AppHelpers::getPublicUrl(), '/');
    $publicPath = parse_url($publicBase, PHP_URL_PATH) ?: '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    if (str_starts_with($returnTo, '/')) {
        if ($publicPath !== '' && str_starts_with($returnTo, $publicPath)) {
            return $scheme . '://' . $host . $returnTo;
        }

        return $fallback;
    }

    if (preg_match('#^[a-zA-Z0-9_\-/\.\?=&]+$#', $returnTo)) {
        return $publicBase . '/' . ltrim($returnTo, '/');
    }

    return $fallback;
}

AuthService::requireAuth();
$user = Auth::user();

$homeUrl = AppHelpers::dashboard('home');

$role_original = (string) ($user['role_original'] ?? $user['role'] ?? '');
if ($role_original !== 'admin_general') {
    header('Location: ' . $homeUrl);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $homeUrl);
    exit;
}

CSRF::validate();

$mode = isset($_POST['role_mode']) ? (int) $_POST['role_mode'] : 0;
$allowed = [0, 1, 2, 3, 4];
if (!in_array($mode, $allowed, true)) {
    $mode = 0;
}

$_SESSION['role_switch_mode'] = $mode;

if (class_exists(\Core\Http\Context::class)) {
    \Core\Http\Context::reset();
}

$return_to = trim((string) ($_POST['return_to'] ?? ''));
$target = switch_role_redirect_target($return_to, $homeUrl);

header('Location: ' . $target);
exit;
