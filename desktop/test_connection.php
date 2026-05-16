<?php
/**
 * Verificación de conexión al API de jugadores en la nube.
 * Hace una petición a fetch_jugadores.php y reporta:
 * - "No autorizado" → correcto (el endpoint existe y exige API key).
 * - 404 / Not Found → la ruta no existe en el servidor.
 * - JSON con jugadores → conexión y clave correctas.
 *
 * Uso: php desktop/test_connection.php
 */
declare(strict_types=1);

if (file_exists(__DIR__ . '/config_sync.php')) {
    require __DIR__ . '/config_sync.php';
}
$url = defined('SYNC_WEB_URL') ? SYNC_WEB_URL : (getenv('SYNC_WEB_URL') ?: '');
$apiKey = defined('SYNC_API_KEY') ? SYNC_API_KEY : (getenv('SYNC_API_KEY') ?: '');

if ($url === '') {
    echo "ERROR: Configura SYNC_WEB_URL en desktop/config_sync.php\n";
    exit(1);
}

$testUrl = rtrim($url, '/') . (strpos($url, '?') !== false ? '&' : '?') . 'api_key=' . urlencode($apiKey);
$headers = ['X-API-Key: ' . trim($apiKey), 'Accept: application/json'];

$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$isCli = php_sapi_name() === 'cli';
$br = $isCli ? "\n" : "<br>\n";

if ($err !== '') {
    echo "ERROR de conexión: " . $err . $br;
    exit(1);
}

$data = is_string($body) && $body !== '' ? json_decode($body, true) : null;
$preview = is_string($body) ? substr(strip_tags($body), 0, 200) : '';

if ($httpCode === 404 || (is_array($data) && isset($data['error']) && $data['error'] === 'Not Found')) {
    echo "RESULTADO: 404 Not Found." . $br;
    echo "La ruta del API no existe en el servidor. Comprueba SYNC_WEB_URL (base: https://laestaciondeldominohoy.com/mistorneos_fvd/public/)." . $br;
    echo "Respuesta: " . $preview . $br;
    exit(1);
}

if (is_array($data) && isset($data['message']) && stripos($data['message'], 'No autorizado') !== false) {
    echo "RESULTADO: OK (esperado)." . $br;
    echo "El servidor respondió 'No autorizado'. El endpoint existe y está exigiendo API key." . $br;
    echo "Si la clave en config_sync.php coincide con la del .env del servidor, la importación debería funcionar." . $br;
    exit(0);
}

if (is_array($data) && !empty($data['jugadores'])) {
    echo "RESULTADO: OK. Conexión y API key correctas." . $br;
    echo "Se recibieron " . count($data['jugadores']) . " jugador(es). Puedes ejecutar: php desktop/import_from_web.php" . $br;
    exit(0);
}

echo "RESULTADO: Respuesta inesperada (HTTP " . $httpCode . ")." . $br;
echo "Vista previa: " . $preview . $br;
exit(1);
