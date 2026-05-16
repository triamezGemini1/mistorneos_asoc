<?php
/**
 * Configuración global usando variables de entorno (.env)
 *
 * La aplicación detecta automáticamente si está en localhost o en producción
 * para ajustar base_url; puedes fijarla con APP_URL en .env si lo prefieres.
 */
if (!class_exists('Env')) {
    require_once __DIR__ . '/../lib/Env.php';
}
Env::load(__DIR__ . '/../.env');

$env = function (string $key, $default = '') {
    $v = Env::get($key, $default);
    return $v !== null && $v !== '' ? $v : $default;
};

return [
    'db' => [
        'host' => $env('DB_HOST', 'localhost'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $env('DB_DATABASE', 'mistorneos_fvd'),
        'user' => $env('DB_USERNAME', 'root'),
        'pass' => $env('DB_PASSWORD', ''),
        'charset' => $env('DB_CHARSET', 'utf8mb4'),
    ],
    'security' => [
        'session_name' => $env('SESSION_NAME', 'mistorneos_session'),
        'csrf_key' => $env('CSRF_KEY', 'replace_with_random_32_chars'),
        'password_algo' => PASSWORD_DEFAULT,
    ],
    'app' => [
        'base_url' => $env('APP_URL', ''),
        'debug' => Env::bool('APP_DEBUG', false),
        'environment' => $env('APP_ENV', 'development'),
    ],
];
