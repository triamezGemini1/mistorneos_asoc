<?php
/**
 * Script de prueba para verificar que los logs funcionan correctamente
 * Acceder a: http://localhost/mistorneos_fvd/public/api/test_logs.php
 */

error_log("=== TEST LOGS - INICIO ===");
error_log("Fecha y hora: " . date('Y-m-d H:i:s'));

header('Content-Type: application/json; charset=utf-8');

try {
    error_log("TEST: Intentando escribir en error_log");
    
    $logPath = ini_get('error_log');
    error_log("TEST: Ruta de error_log: " . ($logPath ?: 'No configurado'));
    
    // Verificar si el archivo es escribible
    if ($logPath && file_exists($logPath)) {
        $writable = is_writable($logPath);
        error_log("TEST: Archivo de log existe y es escribible: " . ($writable ? 'Sí' : 'No'));
    }
    
    // Test de diferentes tipos de logs
    error_log("TEST: Log simple");
    error_log("TEST: Log con datos: " . json_encode(['test' => true, 'numero' => 123]));
    
    echo json_encode([
        'success' => true,
        'message' => 'Test de logs ejecutado',
        'error_log_path' => $logPath ?: 'No configurado',
        'log_file_exists' => $logPath && file_exists($logPath),
        'log_file_writable' => $logPath && file_exists($logPath) && is_writable($logPath),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    error_log("=== TEST LOGS - FIN ===");
    
} catch (Exception $e) {
    error_log("TEST ERROR: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error en test: ' . $e->getMessage()
    ]);
}








