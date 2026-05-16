<?php
/**
 * Script para verificar conexión a MySQL
 * ELIMINAR DESPUÉS DE USAR
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Verificación de MySQL</h1>";
echo "<pre>";

echo "PHP Version: " . phpversion() . "\n\n";

echo "=== Verificando puerto 3306 ===\n";
$connection = @fsockopen('localhost', 3306, $errno, $errstr, 2);
if ($connection) {
    echo "✅ Puerto 3306 está abierto\n";
    fclose($connection);
} else {
    echo "❌ Puerto 3306 NO está accesible\n";
    echo "   Error: $errstr ($errno)\n";
    echo "\n   SOLUCIÓN:\n";
    echo "   1. Abre WAMP Server\n";
    echo "   2. Verifica que el icono esté VERDE\n";
    echo "   3. Si está naranja o rojo, haz clic y selecciona 'Start/Resume Service' > 'MySQL'\n";
}

echo "\n=== Intentando conectar a MySQL ===\n";
try {
    $pdo = new PDO(
        'mysql:host=localhost;port=3306;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]
    );
    echo "✅ Conexión a MySQL exitosa\n";
    
    // Verificar base de datos
    $dbName = class_exists('Env') ? (Env::get('DB_DATABASE') ?: 'mistorneos_fvd') : 'mistorneos_fvd';
    $stmt = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($dbName));
    if ($stmt->rowCount() > 0) {
        echo "✅ Base de datos '{$dbName}' existe\n";
    } else {
        echo "⚠️  Base de datos '{$dbName}' NO existe\n";
        echo "   Necesitas crearla o importar el schema\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    echo "\n   SOLUCIÓN:\n";
    echo "   1. Abre WAMP Server\n";
    echo "   2. Verifica que MySQL esté corriendo (icono verde)\n";
    echo "   3. Si no está corriendo:\n";
    echo "      - Clic derecho en icono de WAMP\n";
    echo "      - Tools > Services > MySQL > Start/Resume Service\n";
}

echo "</pre>";
echo "</pre>";

echo "<div style='text-align: center; margin-top: 2rem;'>";
echo "<a href='start_mysql_guide.php' style='display: inline-block; padding: 12px 32px; background: #1a365d; color: white; text-decoration: none; border-radius: 10px; font-weight: 600; min-height: 44px;'>";
echo "📋 Ver Guía para Iniciar MySQL";
echo "</a>";
echo "</div>";

echo "<p style='text-align: center; margin-top: 2rem; color: #6b7280; font-size: 0.9rem;'>";
echo "<strong>⚠️ RECUERDA ELIMINAR ESTOS ARCHIVOS (check_mysql.php, start_mysql_guide.php) DESPUÉS DE USAR</strong>";
echo "</p>";

