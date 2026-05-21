<?php
/**
 * ZIP mínimo: estructura asociaciones / particulares / torneos por contexto.
 * Uso: php scripts/crear_paquete_estructura.php
 */
declare(strict_types=1);

$base = dirname(__DIR__);
$stamp = date('Ymd_His');
$output = $base . '/estructura_organizaciones_' . $stamp . '.zip';

$paths = [
    // SQL
    'sql/migracion_estructura_organizaciones_2026.sql',
    'sql/fix_cod_org_organizaciones_particulares.sql',
    'DEPLOY_ESTRUCTURA_ORGANIZACIONES.md',
    // Core lógica
    'lib/OrganizacionDashboardStats.php',
    'lib/TorneosEstructuraService.php',
    'lib/ClubHelper.php',
    'lib/StoragePaths.php',
    // Módulos
    'modules/organizaciones_particulares.php',
    'modules/organizaciones/listado_particulares.php',
    'modules/torneos_estructura.php',
    'modules/torneos_estructura/lista.php',
    'modules/torneos_estructura/reporte.php',
    'modules/organizaciones.php',
    'modules/entidades.php',
    'modules/mi_organizacion.php',
    'modules/organizaciones/listado_entidades.php',
    'modules/organizaciones/org_detail.php',
    'modules/affiliate_requests/list.php',
    // Router y menú
    'public/index.php',
    'public/includes/layout.php',
    'public/check_env.php',
    'config/auth.php',
    'config/bootstrap.php',
    // Hotfix home (recomendado en el mismo deploy)
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
    fwrite(STDERR, "No se pudo crear ZIP.\n");
    exit(1);
}

$added = 0;
$missing = [];
foreach ($paths as $rel) {
    $path = $base . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($path, str_replace('\\', '/', $rel));
    $added++;
}

$zip->addFromString(
    'INSTALAR.txt',
    "ESTRUCTURA ORGANIZACIONES — {$stamp}\n\n"
    . "1. SQL en phpMyAdmin (orden):\n"
    . "   sql/migracion_estructura_organizaciones_2026.sql\n"
    . "   sql/fix_cod_org_organizaciones_particulares.sql\n\n"
    . "2. Subir archivos manteniendo rutas (lib/, modules/, public/, config/, storage/).\n\n"
    . "3. Ver DEPLOY_ESTRUCTURA_ORGANIZACIONES.md\n\n"
    . "4. Abrir public/check_env.php y probar menú Estructura.\n"
);
$zip->close();

echo "=== Paquete estructura ===\n";
echo basename($output) . "\n";
echo 'Ruta: ' . $output . "\n";
echo "Archivos: {$added}\n";
echo 'Tamaño: ' . round(filesize($output) / 1024, 1) . " KB\n";
if ($missing !== []) {
    echo "Faltantes:\n  - " . implode("\n  - ", $missing) . "\n";
}
