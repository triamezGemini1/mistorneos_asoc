<?php
/**
 * Configuración para sincronización Web ↔ Desktop.
 *
 * SYNC_WEB_URL = URL exacta del endpoint de jugadores en tu servidor.
 * Base URL producción: https://laestaciondeldominohoy.com/mistorneos_fvd/public/
 * SYNC_API_KEY = EXACTAMENTE el mismo valor que SYNC_API_KEY (o API_KEY) en el .env del servidor.
 * SYNC_SSL_VERIFY = false en desarrollo local si el certificado SSL da problemas.
 */
define('SYNC_WEB_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/fetch_jugadores.php');
define('SYNC_PUSH_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/sync_api.php');
define('SYNC_API_KEY', 'TorneoMaster2024*');
if (!defined('API_KEY')) {
    define('API_KEY', SYNC_API_KEY);
}
define('SYNC_SSL_VERIFY', false);
