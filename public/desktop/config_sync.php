<?php
/**
 * Configuración para sincronización Web ↔ Desktop (ruta desktop, sin public).
 * SYNC_API_KEY debe coincidir con el .env del proyecto.
 * SYNC_PUSH_URL = endpoint que recibe POST con jugadores (export_to_web.php).
 */
define('SYNC_WEB_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/fetch_jugadores.php');
define('SYNC_PUSH_URL', 'https://laestaciondeldominohoy.com/mistorneos_fvd/public/api/sync_api.php');
define('SYNC_API_KEY', 'TorneoMaster2024*');
/** Master Admin: solo este usuario puede activar/desactivar a otros en Gestión de Administradores. Definir email y/o id. */
define('MASTER_ADMIN_EMAIL', ''); // ej: 'admin@laestaciondeldominohoy.com'
define('MASTER_ADMIN_ID', 1);     // id del usuario en la tabla usuarios (0 = no usar)
define('SYNC_SSL_VERIFY', false); // false en desarrollo local si el certificado SSL falla
