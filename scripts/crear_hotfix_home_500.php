<?php
/**
 * Paquete mínimo para corregir HTTP 500 en page=home (beta/producción).
 * Uso: php scripts/crear_hotfix_home_500.php
 */
declare(strict_types=1);

$base = dirname(__DIR__);
$stamp = date('Ymd_His');
$output = $base . '/hotfix_home_500_' . $stamp . '.zip';

$files = [
    'lib/StoragePaths.php',
    'config/bootstrap.php',
    'public/index.php',
    'public/check_env.php',
    'public/diagnose_home.php',
    'storage/logs/.gitkeep',
    'storage/sessions/.gitkeep',
];

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive no disponible.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear $output\n");
    exit(1);
}

$readme = <<<TXT
HOTFIX — HTTP 500 en index.php?page=home
Generado: {$stamp}

INSTALACIÓN (FTP → /public_html/mistorneos_beta/)
1. Descomprimir manteniendo rutas (lib/, config/, public/, storage/).
2. Sobrescribir archivos existentes.
3. Abrir: public/check_env.php — verificar todo en verde.
4. En .env del servidor: MODERN_HOME=false (recomendado en beta).
5. Iniciar sesión y abrir: public/diagnose_home.php
6. Probar: public/index.php?page=home
7. Borrar diagnose_home.php y check_env.php cuando termine.

ARCHIVOS INCLUIDOS:
TXT;

foreach ($files as $rel) {
    $path = $base . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "Falta: $rel\n");
        continue;
    }
    $zip->addFile($path, str_replace('\\', '/', $rel));
    $readme .= "  - $rel\n";
}

$zip->addFromString('HOTFIX_README.txt', $readme);
$zip->close();

$size = filesize($output);
echo "=== Hotfix creado ===\n";
echo basename($output) . "\n";
echo 'Ruta: ' . $output . "\n";
echo 'Tamaño: ' . round($size / 1024, 1) . " KB\n";
