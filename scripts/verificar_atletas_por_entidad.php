<?php
/**
 * Verificación: cuántos atletas (usuarios con role = 'usuario') hay por entidad,
 * y cuántos contaría cada club (asociación) con la misma regla que ClubHelper.
 *
 * Uso (desde la raíz del proyecto):
 *   php scripts/verificar_atletas_por_entidad.php
 *   php scripts/verificar_atletas_por_entidad.php 6
 *
 * También puede copiar a phpMyAdmin el SQL que se imprime al inicio.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/ClubHelper.php';

$pdo = DB::pdo();
$filtroEntidad = isset($argv[1]) ? (int) $argv[1] : 0;

echo "=== Atletas por usuarios.entidad (role = usuario, sin filtrar status) ===\n\n";

$sqlResumen = <<<'SQL'
SELECT
    COALESCE(u.entidad, 0) AS entidad_id,
    COUNT(*) AS total_usuarios,
    SUM(CASE WHEN u.status = 0 THEN 1 ELSE 0 END) AS con_status_0
FROM usuarios u
WHERE u.role = 'usuario'
GROUP BY COALESCE(u.entidad, 0)
ORDER BY entidad_id ASC;
SQL;

echo "-- SQL resumen (phpMyAdmin):\n" . trim($sqlResumen) . "\n\n";

$rows = $pdo->query($sqlResumen)->fetchAll(PDO::FETCH_ASSOC);
$nombres = [];
try {
    $map = $pdo->query('SELECT id, nombre FROM entidad')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (is_array($map)) {
        $nombres = $map;
    }
} catch (Throwable $e) {
    // sin tabla entidad
}

printf("%-10s %-35s %10s %12s\n", 'entidad', 'nombre (catálogo)', 'atletas', 'status=0');
echo str_repeat('-', 75) . "\n";
foreach ($rows as $r) {
    $eid = (int) ($r['entidad_id'] ?? 0);
    if ($filtroEntidad > 0 && $eid !== $filtroEntidad) {
        continue;
    }
    $nom = $nombres[$eid] ?? ($eid === 0 ? '(sin entidad)' : '');
    printf(
        "%-10d %-35s %10s %12s\n",
        $eid,
        substr($nom, 0, 35),
        (string) ($r['total_usuarios'] ?? 0),
        (string) ($r['con_status_0'] ?? 0)
    );
}

echo "\n=== Clubes (asociación) con entidad > 0: afiliados según ClubHelper ===\n\n";

$sqlClubes = 'SELECT id, nombre, entidad FROM clubes WHERE estatus = 1 AND COALESCE(entidad, 0) > 0 ORDER BY entidad ASC, nombre ASC';
$st = $pdo->query($sqlClubes);
$clubes = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

printf("%-8s %-8s %-40s %12s\n", 'club_id', 'entidad', 'nombre', 'afiliados');
echo str_repeat('-', 75) . "\n";

foreach ($clubes as $c) {
    $eid = (int) ($c['entidad'] ?? 0);
    if ($filtroEntidad > 0 && $eid !== $filtroEntidad) {
        continue;
    }
    $cid = (int) ($c['id'] ?? 0);
    [$scopeSql, $scopeParams] = ClubHelper::afiliadosMatchSqlAndParams($pdo, $c, $cid);
    $q = "SELECT COUNT(DISTINCT u.id) FROM usuarios u WHERE u.role = 'usuario' AND ({$scopeSql})";
    $stc = $pdo->prepare($q);
    $stc->execute($scopeParams);
    $cnt = (int) $stc->fetchColumn();
    printf(
        "%-8d %-8d %-40s %12d\n",
        $cid,
        $eid,
        substr((string) ($c['nombre'] ?? ''), 0, 40),
        $cnt
    );
}

echo "\nNotas:\n";
echo "- Regla: u.entidad = clubes.id (igual que WHERE entidad = <id club>). Sin filtro de rol en el listado.\n";
echo "- Sin filtro de status en el alcance; en la pantalla de detalle el orden es u.status ASC.\n";
