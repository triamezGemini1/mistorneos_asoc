<?php
/**
 * Script para crear un paquete ZIP con todos los archivos necesarios para producción
 * Excluye archivos de desarrollo y mantiene la estructura
 */

$base_dir = __DIR__ . '/..';
$output_file = $base_dir . '/mistorneos_produccion_' . date('Ymd_His') . '.zip';

echo "=== CREANDO PAQUETE PARA PRODUCCIÓN ===\n\n";

// Leer .deployignore si existe
$excluir = [];
$deployignore = $base_dir . '/.deployignore';
if (file_exists($deployignore)) {
    $excluir = array_filter(array_map('trim', file($deployignore)));
}

// Exclusiones obligatorias (además de .deployignore)
$excluir = array_merge($excluir, [
    '.git',
    'node_modules',
    'vendor',
    '.vscode',
    'storage/logs/*.log',
    'storage/cache/*',
    'storage/sessions/*',
    '*.zip',
    '*.sql.backup',
    'confiprrod.php',
    'config/config.development.php',
    'config/env.production.php',
    'tests',
    '.DS_Store',
    'Thumbs.db',
]);

$sql_obligatorios = [
    'sql/migracion_produccion_2026.sql',
    'sql/fix_cod_org_organizaciones_particulares.sql',
];

function debeExcluirRuta(string $ruta_relativa, string $nombre_archivo, array $excluir): bool
{
    $norm = str_replace('\\', '/', $ruta_relativa);
    if ($norm === '.env' || str_ends_with($norm, '/.env')) {
        return true;
    }
    foreach ($excluir as $patron) {
        if ($patron === '' || str_starts_with($patron, '#')) {
            continue;
        }
        if ($norm === $patron || $nombre_archivo === $patron) {
            return true;
        }
        if (str_contains($norm, $patron)) {
            return true;
        }
        if (function_exists('fnmatch') && (fnmatch($patron, $norm) || fnmatch($patron, $nombre_archivo))) {
            return true;
        }
    }

    return false;
}

// Crear ZIP
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    
    if ($zip->open($output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("✗ No se pudo crear el archivo ZIP\n");
    }
    
    echo "Creando archivo ZIP: " . basename($output_file) . "\n\n";
    
    // Función recursiva para agregar archivos
    function agregarArchivos($dir, $zip, $base_dir, $excluir, $prefix = '') {
        $files = scandir($dir);
        $agregados = 0;
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $ruta_completa = $dir . '/' . $file;
            $ruta_relativa = ltrim(str_replace($base_dir . '/', '', $ruta_completa), '/');
            
            if (debeExcluirRuta($ruta_relativa, $file, $excluir)) {
                continue;
            }
            
            if (is_dir($ruta_completa)) {
                // Agregar directorio vacío
                $zip->addEmptyDir($ruta_relativa);
                // Recursión
                $agregados += agregarArchivos($ruta_completa, $zip, $base_dir, $excluir, $prefix);
            } else {
                // Agregar archivo
                $zip->addFile($ruta_completa, $ruta_relativa);
                $agregados++;
                if ($agregados % 50 == 0) {
                    echo "  Procesados: $agregados archivos...\r";
                }
            }
        }
        
        return $agregados;
    }
    
    // Agregar todos los archivos
    $total = agregarArchivos($base_dir, $zip, $base_dir, $excluir);
    
    foreach ($sql_obligatorios as $sql_rel) {
        $sql_file = $base_dir . '/' . $sql_rel;
        if (is_file($sql_file)) {
            $zip->addFile($sql_file, $sql_rel);
            echo "  ✓ SQL incluido: {$sql_rel}\n";
        } else {
            echo "  ⚠ SQL no encontrado: {$sql_rel}\n";
        }
    }
    
    // Agregar documentación de despliegue
    $deploy_doc = $base_dir . '/DEPLOY_PRODUCCION_2026.md';
    if (file_exists($deploy_doc)) {
        $zip->addFile($deploy_doc, 'DEPLOY_PRODUCCION_2026.md');
        echo "  ✓ Documentación de despliegue incluida\n";
    }
    
    $zip->close();
    
    $tamaño = filesize($output_file);
    $tamaño_mb = round($tamaño / 1024 / 1024, 2);
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✅ PAQUETE CREADO EXITOSAMENTE\n";
    echo str_repeat("=", 60) . "\n";
    echo "Archivo: " . basename($output_file) . "\n";
    echo "Tamaño: " . number_format($tamaño) . " bytes ($tamaño_mb MB)\n";
    echo "Archivos incluidos: $total\n";
    echo "\nEl paquete está listo para subir a producción.\n";
    echo "Ubicación: $output_file\n";
    
} else {
    die("✗ La extensión ZipArchive no está disponible en PHP.\n");
}

