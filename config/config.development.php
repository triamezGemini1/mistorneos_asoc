<?php
/**
 * Configuración de Desarrollo
 * Usa variables de entorno (.env). base_url vacía = auto (localhost → /mistorneos_fvd).
 *
 * NOTA: Para búsqueda de personas en desarrollo, necesitas tener
 * una copia local de la tabla dbo.persona en la base de datos fvdadmin
 * o crear una tabla de prueba dbo_persona_staging
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
  'persona_db' => [
    'host' => $env('DB_SECONDARY_HOST', 'localhost'),
    'port' => $env('DB_SECONDARY_PORT', '3306'),
    'name' => $env('DB_SECONDARY_DATABASE', 'personas'),
    'name_dev' => 'personas',
    'user' => $env('DB_SECONDARY_USERNAME', 'root'),
    'pass' => $env('DB_SECONDARY_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'table' => 'dbo_persona',
    'table_dev' => 'dbo_persona',
  ],
  'security' => [
    'session_name' => $env('SESSION_NAME', 'mistorneos_session_dev'),
    'csrf_key' => $env('CSRF_KEY', 'dev_csrf_key_replace_with_random_32_chars'),
    'password_algo' => PASSWORD_DEFAULT,
  ],
  'app' => [
    'base_url' => $env('APP_URL', ''),
    'debug' => true,
    'environment' => 'development',
  ],
  'whatsapp' => [
    'base_url' => $env('APP_URL', 'http://localhost/mistorneos_fvd'),
  ],
];




