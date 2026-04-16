<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

$pdo = DB::pdo();

echo "=== Verificación de índices ===\n\n";

// Verificar índice en clubes.cod_org
$stmt = $pdo->query("SHOW INDEX FROM clubes WHERE Column_name = 'cod_org'");
echo "clubes.cod_org: " . ($stmt->rowCount() > 0 ? "✅ Tiene índice" : "❌ Sin índice") . "\n";

// Verificar índice en tournaments.club_responsable
$stmt = $pdo->query("SHOW INDEX FROM tournaments WHERE Column_name = 'club_responsable'");
echo "tournaments.club_responsable: " . ($stmt->rowCount() > 0 ? "✅ Tiene índice" : "❌ Sin índice") . "\n";

// Verificar índice en usuarios.club_id
$stmt = $pdo->query("SHOW INDEX FROM usuarios WHERE Column_name = 'club_id'");
echo "usuarios.club_id: " . ($stmt->rowCount() > 0 ? "✅ Tiene índice" : "❌ Sin índice") . "\n";

// Contar registros
echo "\n=== Conteo de registros ===\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM organizaciones");
echo "organizaciones: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM clubes");
echo "clubes: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM tournaments");
echo "tournaments: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
echo "usuarios: " . $stmt->fetchColumn() . "\n";

// Test de tiempo de las consultas que usa mi_organizacion.php
echo "\n=== Test de tiempo de consultas ===\n";

$start = microtime(true);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clubes WHERE cod_org = ?");
$stmt->execute([1]);
$result = $stmt->fetchColumn();
$time1 = (microtime(true) - $start) * 1000;
echo "clubes WHERE organizacion_id=1: {$result} registros, " . round($time1, 2) . "ms\n";

$start = microtime(true);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable = ?");
$stmt->execute([1]);
$result = $stmt->fetchColumn();
$time2 = (microtime(true) - $start) * 1000;
echo "tournaments WHERE club_responsable=1: {$result} registros, " . round($time2, 2) . "ms\n";

$start = microtime(true);
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM usuarios u 
    INNER JOIN clubes c ON u.club_id = c.id 
    WHERE c.cod_org = ? AND u.role = 'usuario' AND u.status = 'approved'
");
$stmt->execute([1]);
$result = $stmt->fetchColumn();
$time3 = (microtime(true) - $start) * 1000;
echo "afiliados de org 1: {$result} registros, " . round($time3, 2) . "ms\n";

echo "\nTotal tiempo consultas: " . round($time1 + $time2 + $time3, 2) . "ms\n";

echo "\n=== Verificación de columna datos_json ===\n";
$stmt = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'");
echo "notifications_queue.datos_json: " . ($stmt->rowCount() > 0 ? "✅ Existe" : "❌ No existe") . "\n";

// Test de tiempo de SHOW COLUMNS
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    $stmt = $pdo->query("SHOW COLUMNS FROM notifications_queue LIKE 'datos_json'");
    $stmt->fetchAll();
}
$time = (microtime(true) - $start) * 1000;
echo "Tiempo 10x SHOW COLUMNS: " . round($time, 2) . "ms (promedio: " . round($time/10, 2) . "ms)\n";
