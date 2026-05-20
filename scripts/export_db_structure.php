<?php

declare(strict_types=1);

/**
 * Exporta solo la estructura (sin datos) de la BD principal definida en .env.
 *
 * 1) Intenta mysqldump (variable MYSQLDUMP_PATH o PATH).
 * 2) Si falla, genera el SQL con PDO (SHOW CREATE TABLE por tabla).
 *
 * Salida: storage/schema_exports/schema_YYYYMMDD_HHMMSS.sql
 *
 * Uso (desde la raíz del proyecto):
 *   php scripts/export_db_structure.php
 */

$root = dirname(__DIR__);
require_once $root . '/app/Helpers/EnvLoader.php';
mn_env_load($root . '/.env');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_DATABASE') ?: 'mistorneos_fvd';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD');
$pass = is_string($pass) ? $pass : '';

$outDir = $root . '/storage/schema_exports';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "No se pudo crear el directorio de salida: {$outDir}\n");
    exit(1);
}

$stamp = date('Ymd_His');
$outFile = $outDir . '/schema_' . $stamp . '.sql';

$mysqldump = getenv('MYSQLDUMP_PATH');
$mysqldump = is_string($mysqldump) && $mysqldump !== '' ? $mysqldump : 'mysqldump';

$args = [
    $mysqldump,
    '--no-data',
    '--single-transaction',
    '--routines',
    '--events',
    '-h' . $host,
    '-P' . $port,
    '-u' . $user,
    '--default-character-set=utf8mb4',
];

if ($pass !== '') {
    $args[] = '-p' . $pass;
}

$args[] = $name;

$cmd = '';
foreach ($args as $i => $a) {
    if ($i > 0) {
        $cmd .= ' ';
    }
    $cmd .= escapeshellarg($a);
}

$descriptorSpec = [
    1 => ['file', $outFile, 'wb'],
    2 => ['pipe', 'w'],
];

$proc = @proc_open($cmd, $descriptorSpec, $pipes, $root);
$mysqldumpOk = false;
if (is_resource($proc)) {
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $mysqldumpOk = $code === 0 && is_file($outFile) && filesize($outFile) > 0;
    if (!$mysqldumpOk) {
        @unlink($outFile);
        if ($stderr !== '') {
            fwrite(STDERR, $stderr . "\n");
        }
    }
}

if ($mysqldumpOk) {
    echo "Estructura exportada (mysqldump) a: {$outFile}\n";
    exit(0);
}

fwrite(STDERR, "mysqldump no disponible o falló; usando respaldo PDO…\n");

$charset = getenv('DB_CHARSET') ?: 'utf8mb4';
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $host,
    $port,
    $name,
    $charset
);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $e) {
    fwrite(STDERR, 'Conexión PDO fallida: ' . $e->getMessage() . "\n");
    exit(1);
}

$tables = $pdo->query(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
    . $pdo->quote($name) . ' ORDER BY TABLE_NAME'
)->fetchAll(PDO::FETCH_COLUMN);

if ($tables === false || $tables === []) {
    fwrite(STDERR, "No se encontraron tablas en la base `{$name}`.\n");
    exit(1);
}

$fh = fopen($outFile, 'wb');
if ($fh === false) {
    fwrite(STDERR, "No se pudo escribir: {$outFile}\n");
    exit(1);
}

fwrite($fh, "-- Estructura generada por scripts/export_db_structure.php (PDO)\n");
fwrite($fh, "-- Base: " . $name . " @ " . date('c') . "\n\n");
fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

foreach ($tables as $table) {
    $t = (string) $table;
    $stmt = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($row) || empty($row['Create Table'])) {
        fclose($fh);
        @unlink($outFile);
        fwrite(STDERR, "No se pudo obtener CREATE TABLE para `{$t}`.\n");
        exit(1);
    }
    fwrite($fh, "DROP TABLE IF EXISTS `" . str_replace('`', '``', $t) . "`;\n");
    fwrite($fh, $row['Create Table'] . ";\n\n");
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

echo "Estructura exportada (PDO) a: {$outFile}\n";
