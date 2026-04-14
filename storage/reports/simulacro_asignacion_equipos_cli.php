<?php
/**
 * Simulacro paso a paso: clasificación (equipos) → asignación ronda 1 y ronda 2+
 * usando la lógica real de MesaAsignacionEquiposService (métodos privados vía Reflection).
 * No escribe en partiresul ni mesas_asignacion.
 *
 * Uso: php storage/reports/simulacro_asignacion_equipos_cli.php [torneo_id]
 */
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/MesaAsignacionEquiposService.php';

function out(string $s = ''): void
{
    echo $s . "\n";
}

function fmtJug(array $j, array $clasiequiPorInscrito = []): string
{
    $nom = mb_substr((string) ($j['nombre'] ?? ''), 0, 28);
    $iid = (int) ($j['id_inscrito'] ?? 0);
    $cl = $clasiequiPorInscrito[$iid] ?? ($j['clasiequi'] ?? '—');

    return sprintf(
        '%s | eq:%s | hom:%s | #%s | clasiequi:%s | pts:%s',
        $nom,
        $j['codigo_equipo'] ?? '?',
        isset($j['posicion_equipo']) ? (string) $j['posicion_equipo'] : '—',
        $j['numero'] ?? '-',
        $cl,
        $j['puntos'] ?? '0'
    );
}

$tid = isset($argv[1]) ? (int) $argv[1] : 10;
$pdo = DB::pdo();
$svc = new MesaAsignacionEquiposService($pdo);
$ref = new ReflectionClass($svc);
$clasiequiPorInscrito = [];
$stCl = $pdo->prepare('SELECT id, clasiequi FROM inscritos WHERE torneo_id = ?');
$stCl->execute([$tid]);
foreach ($stCl->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $clasiequiPorInscrito[(int) $row['id']] = $row['clasiequi'];
}
$st = $pdo->prepare('SELECT id, nombre, modalidad FROM tournaments WHERE id = ?');
$st->execute([$tid]);
$t = $st->fetch(PDO::FETCH_ASSOC);
if (!$t || (int) ($t['modalidad'] ?? 0) !== 3) {
    fwrite(STDERR, "Torneo no encontrado o modalidad != 3 (equipos).\n");
    exit(1);
}

out('================================================================================');
out(' SIMULACRO ASIGNACIÓN MESAS — EQUIPOS (modalidad 3)');
out(' Torneo: ' . ($t['nombre'] ?? '') . ' (id=' . $tid . ')');
out('================================================================================');
out();

out('--- PASO 0 · Origen de la “clasificación” en base de datos ---');
out('Antes de cada nueva ronda, el panel suele actualizar estadísticas y posiciones:');
out('  • equipos: puntos, ganados, perdidos, efectividad → equipos.posicion');
out('  • inscritos: inscritos.clasiequi = posición del equipo de esa jugadora');
out('  • Dentro del equipo, el orden por rendimiento usa puntos/efectividad (rondas 2+).');
out();

$sqlEq = 'SELECT codigo_equipo, nombre_equipo, COALESCE(puntos,0) p, COALESCE(ganados,0) g, COALESCE(perdidos,0) per,
          (COALESCE(ganados,0)-COALESCE(perdidos,0)) dif, COALESCE(posicion,0) pos_tabla
          FROM equipos WHERE id_torneo = ? AND estatus = 0
          ORDER BY p DESC, (COALESCE(ganados,0)-COALESCE(perdidos,0)) DESC, COALESCE(ganados,0) DESC, codigo_equipo ASC';
$qeq = $pdo->prepare($sqlEq);
$qeq->execute([$tid]);
$eqRows = $qeq->fetchAll(PDO::FETCH_ASSOC);
out('Orden de equipos tal como lo usa ronda 2+ (misma consulta que obtenerEquiposConJugadoresYClasificacion):');
$r = 1;
foreach ($eqRows as $row) {
    out(sprintf(
        '  %2d. %s | %s | pts=%s dif=%s gan=%s perd=%s | pos_tabla_equipos=%s',
        $r++,
        $row['codigo_equipo'],
        mb_substr($row['nombre_equipo'] ?? '', 0, 24),
        $row['p'],
        $row['dif'],
        $row['g'],
        $row['per'],
        $row['pos_tabla']
    ));
}
out();

// --- Ronda 1 ---
$mEq = $ref->getMethod('obtenerEquiposConJugadores');
$mEq->setAccessible(true);
$equiposR1 = $mEq->invoke($svc, $tid);

$mLista = $ref->getMethod('obtenerTodosJugadoresOrdenClubNombre');
$mLista->setAccessible(true);
$listaGlobal = $mLista->invoke($svc, $tid);

$mDist = $ref->getMethod('distribuirCuatroSegmentos');
$mDist->setAccessible(true);

$N = count($equiposR1);
out('--- PASO 1 · RONDA 1 — No se usa la tabla de posiciones por puntos ---');
out('Se ordenan equipos por id_club y codigo_equipo; jugadoras por número de tablero (o id).');
out('Lista global = todas las jugadoras en orden club ↑, nombre ↑ (56 = 4 × N).');
out('Total equipos N=' . $N . ', jugadores en lista=' . count($listaGlobal) . '.');
out();

if (count($listaGlobal) !== $N * 4) {
    out('ADVERTENCIA: la lista no coincide con 4×N; el servicio devolvería error en producción.');
    out();
}

out('Primeras 8 filas de la lista global (muestra). En ronda 1 no hay “homólogo” hasta armar mesas:');
for ($i = 0; $i < min(8, count($listaGlobal)); $i++) {
    out('  [' . $i . '] ' . fmtJug($listaGlobal[$i], $clasiequiPorInscrito));
}
out('  ...');
out();

out('Matriz de 4 segmentos (cada segmento tiene N=' . $N . ' jugadoras):');
out('  Mesa j (1…N) = [ lista[j-1], lista[N+j-1], lista[2N+j-1], lista[3N+j-1] ]');
out();

$mesasR1 = $mDist->invoke($svc, $listaGlobal, $N);
if ($mesasR1 === null) {
    out('ERROR: distribuirCuatroSegmentos devolvió null.');
    exit(1);
}

foreach ($mesasR1 as $idx => $mesa) {
    $nj = $idx + 1;
    out("Mesa {$nj}:");
    foreach ($mesa as $pj) {
        out('    · ' . fmtJug($pj, $clasiequiPorInscrito));
    }
    out();
}

// --- Ronda 2+ ---
$mEq2 = $ref->getMethod('obtenerEquiposConJugadoresYClasificacion');
$mEq2->setAccessible(true);
$equiposR2 = $mEq2->invoke($svc, $tid, 1);

$mConst = $ref->getMethod('construirMesasRonda2DesdeEquiposOrdenados');
$mConst->setAccessible(true);

out('--- PASO 2 · RONDA 2+ — Clasificación de equipos + homólogos 1…4 ---');
out('Equipos ya ordenados por: puntos DESC, diferencia DESC, ganados DESC, codigo_equipo ASC.');
out('Dentro de cada equipo, jugadoras por puntos DESC, efectividad DESC (hasta 4).');
out('Se forman bloques de 4 equipos consecutivos: en cada bloque, 4 mesas (homólogos 1, luego 2, 3 y 4).');
out('Con 14 equipos: bloque completo 4+4+4 = 12 equipos → 12 mesas; resto 2 equipos → reparto cíclico.');
out();

$rank = 1;
foreach ($equiposR2 as $eq) {
    out(sprintf(
        'Equipo rank %d · %s · clasif_equipo=%s | pts_eq=%s',
        $rank++,
        $eq['codigo_equipo'] ?? '',
        $eq['clasificacion_equipo'] ?? '?',
        $eq['puntos_equipo'] ?? 0
    ));
    foreach ($eq['jugadores'] ?? [] as $pj) {
        out('    homólogo ' . ($pj['posicion_equipo'] ?? '?') . ': ' . fmtJug($pj, $clasiequiPorInscrito));
    }
    out();
}

$mesasR2 = $mConst->invoke($svc, $equiposR2);
out('--- Mesas generadas (ronda 2+, misma lógica que producción) ---');
foreach ($mesasR2 as $idx => $mesa) {
    $nj = $idx + 1;
    out("Mesa {$nj}:");
    foreach ($mesa as $pj) {
        out('    · ' . fmtJug($pj, $clasiequiPorInscrito));
    }
    out();
}

out('================================================================================');
out('Fin del simulacro (solo lectura; no se guardó partiresul).');
out('================================================================================');
