<?php
/**
 * Ejemplo de configuración para sincronización Web ↔ Desktop.
 * Copiar a config_sync.php y ajustar valores.
 *
 * Producción: https://laestaciondeldominohoy.com/mistorneos_fvd/public/
 * Ejecuta desktop/test_connection.php para verificar que la ruta del API responde.
 */
define('SYNC_WEB_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/fetch_jugadores.php');
define('SYNC_PUSH_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/sync_api.php');
define('SYNC_API_KEY', 'cambiar-por-token-seguro');
define('SYNC_SSL_VERIFY', false);
