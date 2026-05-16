<?php
declare(strict_types=1);

/**
 * Reorganiza la PK de `clubes` para que coincida con el código territorial
 * (`clubes.entidad` = id del catálogo `entidad`), evitando confusiones entre
 * id interno y código de estado/asociación.
 *
 * Estrategia:
 *  1) Comprueba que no haya dos clubes con la misma entidad > 0.
 *  2) Comprueba que ningún club con entidad=0 ocupe un id que otra fila
 *     vaya a reclamar como destino (entidad del otro).
 *  3) Mueve temporalmente todos los clubes con entidad>0 a id+OFFSET.
 *  4) Actualiza todas las FKs descubiertas en information_schema (+ algunas habituales).
 *  5) Asigna id = entidad en esas filas.
 *  6) Ajusta AUTO_INCREMENT.
 *
 * Uso:
 *   php scripts/reorganizar_clubes_id_igual_entidad.php --dry-run
 *   php scripts/reorganizar_clubes_id_igual_entidad.php --execute
 *
 * Hacer copia de seguridad de la base antes de --execute.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$execute = in_array('--execute', $argv ?? [], true);

if (!$dryRun && !$execute) {
    fwrite(STDERR, "Indique --dry-run o --execute\n");
    exit(1);
}
if ($dryRun && $execute) {
    fwrite(STDERR, "Use solo uno: --dry-run o --execute\n");
    exit(1);
}

$pdo = DB::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($schema) || $schema === '') {
    fwrite(STDERR, "No se pudo resolver el nombre de la base de datos.\n");
    exit(1);
}

/**
 * @param non-empty-string $ident
 */
function esIdentificadorSqlSeguro(string $ident): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]+$/', $ident);
}

/**
 * @return list<array{TABLE_NAME: string, COLUMN_NAME: string}>
 */
function columnasQueReferencianClubesId(PDO $pdo, string $schema): array
{
    $sql = <<<'SQL'
SELECT DISTINCT TABLE_NAME, COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = ?
  AND REFERENCED_TABLE_SCHEMA = ?
  AND REFERENCED_TABLE_NAME = 'clubes'
  AND REFERENCED_COLUMN_NAME = 'id'
  AND TABLE_NAME <> 'clubes'
ORDER BY TABLE_NAME, COLUMN_NAME
SQL;
    $st = $pdo->prepare($sql);
    $st->execute([$schema, $schema]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $t = (string) ($r['TABLE_NAME'] ?? '');
        $c = (string) ($r['COLUMN_NAME'] ?? '');
        if ($t !== '' && $c !== '' && esIdentificadorSqlSeguro($t) && esIdentificadorSqlSeguro($c)) {
            $out[] = ['TABLE_NAME' => $t, 'COLUMN_NAME' => $c];
        }
    }

    return $out;
}

/** Comprueba que la tabla y columna existan (por si el esquema varía). */
function tablaColumnaExiste(PDO $pdo, string $schema, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute([$schema, $table, $column]);

    return (bool) $st->fetchColumn();
}

echo $dryRun ? "=== DRY-RUN reorganizar clubes.id = entidad ===\n\n" : "=== EJECUTANDO reorganizar clubes.id = entidad ===\n\n";

// 1) Clubes con entidad territorial definida
$clubesTerr = $pdo->query(
    "SELECT id, nombre, entidad FROM clubes WHERE COALESCE(entidad, 0) > 0 ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($clubesTerr)) {
    echo "No hay clubes con entidad > 0. Nada que hacer.\n";
    exit(0);
}

// 2) Misma entidad en más de un club
$dup = $pdo->query(
    "SELECT entidad, COUNT(*) AS n FROM clubes WHERE COALESCE(entidad, 0) > 0 GROUP BY entidad HAVING n > 1"
)->fetchAll(PDO::FETCH_ASSOC);
if (!empty($dup)) {
    fwrite(STDERR, "ABORTAR: hay más de un club con la misma entidad > 0:\n");
    foreach ($dup as $d) {
        fwrite(STDERR, "  entidad=" . (int) ($d['entidad'] ?? 0) . "  clubes=" . (int) ($d['n'] ?? 0) . "\n");
    }
    exit(1);
}

// 3) Club con entidad=0 que bloquea un id destino
$bloqueo = $pdo->query(
    "SELECT c0.id, c0.nombre
     FROM clubes c0
     WHERE COALESCE(c0.entidad, 0) = 0
       AND c0.id IN (SELECT DISTINCT c1.entidad FROM clubes c1 WHERE COALESCE(c1.entidad, 0) > 0)"
)->fetchAll(PDO::FETCH_ASSOC);
if (!empty($bloqueo)) {
    fwrite(STDERR, "ABORTAR: un club sin entidad (>0) usa un id que otra asociación necesita como PK:\n");
    foreach ($bloqueo as $b) {
        fwrite(STDERR, '  id=' . (int) ($b['id'] ?? 0) . '  ' . ($b['nombre'] ?? '') . "\n");
    }
    exit(1);
}

$maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM clubes')->fetchColumn();
$offset = max($maxId, 100000) + 5000000;

echo "OFFSET temporal: {$offset} (max id clubes era {$maxId})\n";
echo 'Clubes a alinear (entidad > 0): ' . count($clubesTerr) . "\n\n";

$mapOldToTemp = [];
$mapTempToFinal = [];
foreach ($clubesTerr as $row) {
    $oid = (int) ($row['id'] ?? 0);
    $e = (int) ($row['entidad'] ?? 0);
    if ($oid <= 0 || $e <= 0) {
        continue;
    }
    $mapOldToTemp[$oid] = $oid + $offset;
    $mapTempToFinal[$oid + $offset] = $e;
}

echo "Mapeo (muestra):\n";
$n = 0;
foreach ($mapOldToTemp as $o => $t) {
    if ($n++ >= 12) {
        echo "  ...\n";
        break;
    }
    $f = $mapTempToFinal[$t] ?? 0;
    echo "  club id {$o} -> temp {$t} -> final {$f}\n";
}

$fks = columnasQueReferencianClubesId($pdo, $schema);
// Tablas usadas en el proyecto que a veces no declaran FK en BD
$candidatosExtra = [
    ['usuarios', 'club_id'],
    ['inscritos', 'id_club'],
    ['deuda_clubes', 'club_id'],
    ['club_debts', 'club_id'],
    ['equipos', 'id_club'],
];
    foreach ($candidatosExtra as [$tbl, $col]) {
        if (!esIdentificadorSqlSeguro($tbl) || !esIdentificadorSqlSeguro($col)) {
            continue;
        }
    if (!tablaColumnaExiste($pdo, $schema, $tbl, $col)) {
        continue;
    }
    $pair = ['TABLE_NAME' => $tbl, 'COLUMN_NAME' => $col];
    $exists = false;
    foreach ($fks as $x) {
        if ($x['TABLE_NAME'] === $tbl && $x['COLUMN_NAME'] === $col) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $fks[] = $pair;
    }
}

echo "\nFK / columnas a actualizar (clubes.id):\n";
foreach ($fks as $fk) {
    echo '  ' . $fk['TABLE_NAME'] . '.' . $fk['COLUMN_NAME'] . "\n";
}

if ($dryRun) {
    echo "\n[DRY-RUN] No se modificó la base. Ejecute con --execute tras respaldo.\n";
    exit(0);
}

$run = static function (PDO $pdo, string $sql, array $params = []): void {
    $st = $pdo->prepare($sql);
    $st->execute($params);
};

try {
    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    // Fase A: clubes.id -> temp
    $sqlA = 'UPDATE clubes SET id = id + ? WHERE COALESCE(entidad, 0) > 0';
    $run($pdo, $sqlA, [$offset]);

    // Fase B: referencias old -> temp
    foreach ($fks as $fk) {
        $tbl = $fk['TABLE_NAME'];
        $col = $fk['COLUMN_NAME'];
        foreach ($mapOldToTemp as $oldId => $tempId) {
            $run(
                $pdo,
                "UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` = ?",
                [$tempId, $oldId]
            );
        }
    }

    // Fase C: temp -> final (id = entidad)
    $sqlC = 'UPDATE clubes SET id = entidad WHERE COALESCE(entidad, 0) > 0 AND id >= ?';
    $run($pdo, $sqlC, [$offset]);

    // Fase D: referencias temp -> final
    foreach ($fks as $fk) {
        $tbl = $fk['TABLE_NAME'];
        $col = $fk['COLUMN_NAME'];
        foreach ($mapTempToFinal as $tempId => $finalId) {
            $run(
                $pdo,
                "UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` = ?",
                [$finalId, $tempId]
            );
        }
    }

    $nextAi = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM clubes')->fetchColumn();
    $pdo->exec('ALTER TABLE clubes AUTO_INCREMENT = ' . max(1, $nextAi));

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();

    echo "\n✅ Migración completada. AUTO_INCREMENT clubes = " . max(1, $nextAi) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, '❌ Error: ' . $e->getMessage() . "\n");
    exit(1);
}
