<?php
/**
 * CLI: prueba de búsqueda pareja → inscritos.numero (misma lógica que ImportacionTorneoExternoService).
 *
 * Uso (desde la raíz del proyecto):
 *   php scripts/diagnostico_pareja_inscritos.php <torneo_id> <ruta/archivo_resultados.xlsx>
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/db.php';
require_once $root . '/lib/ImportacionTorneoExternoService.php';

if ($argc < 3) {
    fwrite(STDERR, "Uso: php " . basename($argv[0]) . " <torneo_id> <archivo_resultados.xlsx|csv>\n");
    exit(1);
}

$torneoId = (int) $argv[1];
$path = $argv[2];
if (! is_readable($path)) {
    fwrite(STDERR, "No se puede leer: {$path}\n");
    exit(1);
}

$pdo = DB::pdo();
$name = basename($path);
$rows = ImportacionTorneoExternoService::leerExcelOCsv($path, $name);
$res = ImportacionTorneoExternoService::diagnosticarParejaResultadosVsInscritos($pdo, $torneoId, $rows);

if (! $res['ok']) {
    foreach ($res['errores'] ?? [] as $e) {
        fwrite(STDERR, $e . "\n");
    }
    exit(2);
}

echo "Torneo ID: {$torneoId}\n";
echo "Filas de datos: {$res['filas_datos']}\n";
echo "Columna pareja: {$res['columna_pareja_titulo']} (índice {$res['columna_pareja_indice']})\n";
echo "Encontrados: {$res['encontrados']}\n";
echo "No encontrados: {$res['no_encontrados']}\n";
echo "Pareja vacía: {$res['pareja_vacia']}\n";
echo "inscritos.numero distintos (no retirado): {$res['inscritos_numero_distintos']}\n";
$m = $res['muestra_numeros_inscritos'] ?? [];
if ($m !== []) {
    echo 'Muestra números en BD: ' . implode(', ', array_map('strval', $m)) . "\n";
}
echo "--- Parejas sin coincidencia (clave; conteo; filas Excel ejemplo) ---\n";
foreach ($res['parejas_no_encontradas'] as $k => $info) {
    $filas = implode(',', array_map('strval', $info['muestra_filas_excel'] ?? []));
    echo "{$k}\t" . (int) ($info['conteo'] ?? 0) . "\t{$filas}\n";
}

exit(0);
