<?php
/**
 * Crea un ZIP con el proyecto completo (incluye vendor, node_modules, etc.)
 * Excluye solo: .git, .env*, y otros archivos .zip para no duplicar paquetes.
 */

$base_dir = realpath(__DIR__ . '/..');
$output_file = $base_dir . '/mistorneos_completo_' . date('Ymd_His') . '.zip';

$excluir_prefijos = [
    '.git',
    '.env',
    '.env.local',
    '.env.development',
];

function debeExcluir(string $rel, array $excluir_prefijos): bool
{
    $norm = str_replace('\\', '/', $rel);
    if (substr($norm, -4) === '.zip') {
        return true;
    }
    foreach ($excluir_prefijos as $prefijo) {
        if ($norm === $prefijo || strpos($norm, $prefijo . '/') === 0) {
            return true;
        }
    }

    return false;
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive no disponible.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear: {$output_file}\n");
    exit(1);
}

echo "=== PAQUETE COMPLETO DEL PROYECTO ===\n";
echo 'Destino: ' . basename($output_file) . "\n\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)
);

$count = 0;
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $rel = substr($path, strlen($base_dir) + 1);
    if (debeExcluir($rel, $excluir_prefijos)) {
        continue;
    }

    $zip->addFile($path, str_replace('\\', '/', $rel));
    $count++;
    if ($count % 500 === 0) {
        echo "  Procesados: {$count} archivos...\r";
    }
}

$zip->close();

$bytes = filesize($output_file);
$mb = round($bytes / 1024 / 1024, 2);

echo "\n" . str_repeat('=', 60) . "\n";
echo "PAQUETE CREADO\n";
echo str_repeat('=', 60) . "\n";
echo "Archivo: {$output_file}\n";
echo "Archivos: {$count}\n";
echo "Tamano: " . number_format($bytes) . " bytes ({$mb} MB)\n";
