<?php
/**
 * Script para agregar el campo finalizado a la tabla tournaments
 */

// Cargar configuración directamente
$config_file = __DIR__ . '/../config/config.development.php';
if (!file_exists($config_file)) {
    $config_file = __DIR__ . '/../config/config.php';
}

require_once $config_file;

try {
    // Crear conexión directa
    $host = $config['db']['host'] ?? 'localhost';
    $dbname = $config['db']['name'] ?? 'mistorneos_fvd';
    $username = $config['db']['user'] ?? 'root';
    $password = $config['db']['pass'] ?? '';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Verificar si el campo ya existe
    $stmt = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'finalizado'");
    if ($stmt->rowCount() > 0) {
        echo "El campo 'finalizado' ya existe en la tabla tournaments.\n";
        exit(0);
    }
    
    // Leer y ejecutar el SQL
    $sql = file_get_contents(__DIR__ . '/add_tournament_finalizado_field.sql');
    
    // Dividir por punto y coma para ejecutar cada sentencia
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Ejecutado: " . substr($statement, 0, 50) . "...\n";
        }
    }
    
    echo "\n✓ Campo 'finalizado' agregado exitosamente a la tabla tournaments.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
