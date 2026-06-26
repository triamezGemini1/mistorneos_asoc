<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

$pdo = DB::pdo();

echo "=== ENTIDADES (1-39) ===\n";
$rows = $pdo->query('SELECT id, nombre FROM entidad WHERE id BETWEEN 1 AND 39 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['id'] . ' | ' . $r['nombre'] . "\n";
}

echo "\n=== ORGANIZACIONES ===\n";
$hasTipo = false;
try {
    $pdo->query('SELECT tipo_org FROM organizaciones LIMIT 0');
    $hasTipo = true;
} catch (Throwable $ignored) {
}

$sqlOrg = $hasTipo
    ? 'SELECT id, nombre, entidad, cod_org, tipo_org FROM organizaciones ORDER BY id'
    : 'SELECT id, nombre, entidad, cod_org FROM organizaciones ORDER BY id';
$orgs = $pdo->query($sqlOrg)->fetchAll(PDO::FETCH_ASSOC);
foreach ($orgs as $o) {
    $tipo = $o['tipo_org'] ?? '?';
    echo $o['id'] . ' | ent=' . ($o['entidad'] ?? 0) . ' | cod_org=' . ($o['cod_org'] ?? 'NULL')
        . ' | tipo=' . $tipo . ' | ' . mb_substr((string) $o['nombre'], 0, 60) . "\n";
}

echo "\n=== COLUMNAS clubes ===\n";
$clubCols = array_column($pdo->query('SHOW COLUMNS FROM clubes')->fetchAll(PDO::FETCH_ASSOC), 'Field');
echo implode(', ', $clubCols) . "\n";

$clubSelect = 'id, nombre, entidad, cod_org';
if (in_array('organizacion_id', $clubCols, true)) {
    $clubSelect .= ', organizacion_id';
}
echo "\n=== CLUBES ASOCIACION (entidad 1-39) ===\n";
$clubs = $pdo->query(
    "SELECT {$clubSelect} FROM clubes
     WHERE COALESCE(entidad, 0) BETWEEN 1 AND 39 ORDER BY entidad, id LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($clubs as $c) {
    echo $c['id'] . ' | ent=' . ($c['entidad'] ?? 0) . ' | cod_org=' . ($c['cod_org'] ?? 'NULL')
        . ' | org_id=' . ($c['organizacion_id'] ?? 'NULL') . ' | ' . mb_substr((string) $c['nombre'], 0, 50) . "\n";
}

echo "\n=== DESALINEACIONES org nombre vs entidad ===\n";
$whereTipo = $hasTipo ? 'COALESCE(o.tipo_org, 0) = 0 AND' : '';
$mis = $pdo->query("
    SELECT o.id, o.nombre AS org_nombre, o.entidad, o.cod_org, e.nombre AS ent_nombre
    FROM organizaciones o
    LEFT JOIN entidad e ON e.id = o.entidad
    WHERE {$whereTipo} o.entidad BETWEEN 1 AND 39
      AND (e.id IS NULL OR LOWER(TRIM(o.nombre)) <> LOWER(TRIM(e.nombre)))
    ORDER BY o.entidad
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($mis as $m) {
    echo 'org#' . $m['id'] . ' ent=' . $m['entidad'] . ' cod=' . ($m['cod_org'] ?? '')
        . ' org="' . $m['org_nombre'] . '" ent="' . ($m['ent_nombre'] ?? 'MISSING') . '"' . "\n";
}
echo 'Total desalineados nombre: ' . count($mis) . "\n";

echo "\n=== cod_org <> entidad en organizaciones ===\n";
$codMis = $pdo->query("
    SELECT id, nombre, entidad, cod_org FROM organizaciones
    WHERE COALESCE(entidad, 0) > 0
      AND COALESCE(cod_org, 0) <> COALESCE(entidad, 0)
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($codMis as $m) {
    echo 'org#' . $m['id'] . ' ent=' . $m['entidad'] . ' cod_org=' . ($m['cod_org'] ?? 'NULL') . "\n";
}
echo 'Total cod_org distinto: ' . count($codMis) . "\n";

echo "\n=== org.id <> org.entidad (asociaciones) ===\n";
$whereTipo2 = $hasTipo ? 'COALESCE(tipo_org, 0) = 0 AND' : '';
$idMis = $pdo->query("
    SELECT id, nombre, entidad, cod_org FROM organizaciones
    WHERE {$whereTipo2} entidad BETWEEN 1 AND 39 AND id <> entidad
    ORDER BY entidad
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($idMis as $m) {
    echo 'org#' . $m['id'] . ' deberia ser id=' . $m['entidad'] . ' | ' . mb_substr((string) $m['nombre'], 0, 40) . "\n";
}
echo 'Total id <> entidad: ' . count($idMis) . "\n";

echo "\n=== Duplicados por entidad (asociaciones) ===\n";
$dup = $pdo->query("
    SELECT entidad, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM organizaciones
    WHERE {$whereTipo2} entidad BETWEEN 1 AND 39
    GROUP BY entidad HAVING n > 1
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($dup as $d) {
    echo 'entidad ' . $d['entidad'] . ': ' . $d['n'] . ' orgs ids=' . $d['ids'] . "\n";
}
