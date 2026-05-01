<?php
/**
 * Lee variables mínimas de sesión desde .env antes de bootstrap (usado por session_start_early).
 * @return array{gc:int, cookie:int, name:string, cookie_domain:string, regenerate_after:int}
 */
function session_read_lifetime_from_env(): array {
    $defaults = [
        'gc' => 28800,
        'cookie' => 28800,
        'name' => 'mistorneos_session',
        'cookie_domain' => '',
        'regenerate_after' => 0,
    ]; // regenerate_after 0 = sin session_regenerate_id periódico (estable en API/fetch)
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_readable($envFile)) {
        return $defaults;
    }
    $gc = 0;
    $cookie = 0;
    $sessionName = '';
    $cookieDomain = '';
    $regenerateAfter = 0;
    $regenerateFromEnv = false;
    $appEnv = '';
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        // BOM UTF-8 al inicio del archivo o línea rompe ^APP_ENV / ^SESSION_
        $line = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line);
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^APP_ENV\s*=\s*"?([\w\-]+)"?\s*$/i', $line, $m)) {
            $appEnv = strtolower(trim((string) $m[1]));
        }
        if (preg_match('/^SESSION_NAME\s*=\s*"?([A-Za-z0-9_\-]+)"?\s*$/', $line, $m)) {
            $sessionName = trim((string) $m[1]);
        }
        if (preg_match('/^SESSION_GC_MAXLIFETIME\s*=\s*(\d+)/', $line, $m)) {
            $gc = max(300, (int) $m[1]);
        }
        if (preg_match('/^SESSION_LIFETIME\s*=\s*(\d+)/', $line, $m)) {
            // Minutos (estilo común). Solo aplica si no hay SESSION_GC_MAXLIFETIME.
            $min = (int) $m[1];
            if ($min > 0 && $min <= 10080) {
                $gc = max($gc, $min * 60);
            }
        }
        if (preg_match('/^SESSION_COOKIE_LIFETIME\s*=\s*(\d+)/', $line, $m)) {
            $cookie = max(0, (int) $m[1]);
        }
        if (preg_match('/^SESSION_COOKIE_DOMAIN\s*=\s*(.*?)\s*$/', $line, $m)) {
            $cookieDomain = trim((string) $m[1], " \t\"'");
        }
        if (preg_match('/^SESSION_REGENERATE_AFTER_SECONDS\s*=\s*(\d+)/', $line, $m)) {
            $regenerateAfter = max(0, (int) $m[1]);
            $regenerateFromEnv = true;
        }
    }
    if ($gc <= 0) {
        $gc = $defaults['gc'];
    }
    if ($cookie <= 0) {
        $cookie = $gc;
    }
    if ($sessionName === '') {
        // Mantener compatibilidad con defaults por entorno de config/development|production.
        if ($appEnv === 'development') {
            $sessionName = 'mistorneos_session_dev';
        } elseif ($appEnv === 'production') {
            $sessionName = 'mistorneos_session_prod';
        } else {
            $sessionName = $defaults['name'];
        }
    }
    if (!$regenerateFromEnv) {
        $regenerateAfter = 0; // por defecto desactivado (evita pérdida de sesión en producción / otro navegador)
    }

    return [
        'gc' => $gc,
        'cookie' => $cookie,
        'name' => $sessionName,
        'cookie_domain' => $cookieDomain,
        'regenerate_after' => $regenerateAfter,
    ];
}
