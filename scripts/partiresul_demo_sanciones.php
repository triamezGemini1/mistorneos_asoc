<?php
/**
 * Carga demo en partiresul usando la misma lógica que el formulario de ingreso de resultados
 * ({@see \Tournament\Handlers\TournamentActionHandler::aplicarResultadosMesaCore}).
 *
 * Asigna al azar sobre 6 filas distintas (elegidas entre partiresul con mesa > 0):
 * - 3× sanción de 80 puntos
 * - 1× tarjeta roja (código 3)
 * - 2× forfait
 *
 * Uso:
 *   php scripts/partiresul_demo_sanciones.php <id_torneo> <partida> [registrado_por]
 *   php scripts/partiresul_demo_sanciones.php --dry-run <id_torneo> <partida>
 *   php scripts/partiresul_demo_sanciones.php --seed=12345 <id_torneo> <partida>
 *
 * Notas:
 * - Requiere al menos 6 filas en esa ronda con mesa > 0.
 * - En modalidad parejas (2, 4) el sistema unifica sanción/ff/tarjeta por pareja: pueden quedar más
 *   de 3 filas con sanción 80 u otras interacciones; es el mismo comportamiento que el formulario.
 * - No ejecuta actualizarEstadisticasInscritos ni notificaciones; puede hacerlo desde el panel si lo necesita.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/TorneoCampoNumerico.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/TournamentActionHandler.php';

use Tournament\Handlers\TournamentActionHandler;

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function demo_row_to_jugador(array $row, int $puntosTorneo): array
{
    $sec = (int) ($row['secuencia'] ?? 0);
    $r1 = \TorneoCampoNumerico::intEstadistica($row['resultado1'] ?? 0);
    $r2 = \TorneoCampoNumerico::intEstadistica($row['resultado2'] ?? 0);
    if ($r1 === 0 && $r2 === 0) {
        $maxR = (int) round($puntosTorneo * 1.6);
        if ($sec === 1 || $sec === 2) {
            $r1 = min(110, $maxR);
            $r2 = min(95, $maxR);
        } else {
            $r1 = min(95, $maxR);
            $r2 = min(110, $maxR);
        }
    }

    $ff = (int) ($row['ff'] ?? 0) === 1;
    $out = [
        'id' => (int) ($row['id'] ?? 0),
        'id_usuario' => (int) ($row['id_usuario'] ?? 0),
        'secuencia' => $sec,
        'resultado1' => (string) $r1,
        'resultado2' => (string) $r2,
        'tarjeta' => (string) (int) ($row['tarjeta'] ?? 0),
        'sancion' => (string) min(80, max(0, (int) ($row['sancion'] ?? 0))),
        'chancleta' => (string) (int) ($row['chancleta'] ?? 0),
        'zapato' => (string) (int) ($row['zapato'] ?? 0),
    ];
    if ($ff) {
        $out['ff'] = '1';
    }

    return $out;
}

/**
 * @param array<int, array<string, mixed>> $jugadores
 * @param array<int, string> $overlay id partiresul => s80|t3|ff
 */
function demo_aplicar_overlay(array &$jugadores, array $overlay): void
{
    foreach ($jugadores as &$j) {
        $id = (int) ($j['id'] ?? 0);
        if ($id <= 0 || ! isset($overlay[$id])) {
            continue;
        }
        switch ($overlay[$id]) {
            case 's80':
                $j['sancion'] = '80';
                $j['tarjeta'] = '0';
                unset($j['ff']);
                break;
            case 't3':
                $j['tarjeta'] = '3';
                $j['sancion'] = '0';
                unset($j['ff']);
                break;
            case 'ff':
                $j['ff'] = '1';
                $j['sancion'] = '0';
                $j['tarjeta'] = '0';
                break;
        }
    }
    unset($j);
}

$dryRun = false;
$seed = null;
$posArgs = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--seed=(\d+)$/', $arg, $m)) {
        $seed = (int) $m[1];
        continue;
    }
    $posArgs[] = $arg;
}

$torneoId = (int) ($posArgs[0] ?? 0);
$partida = (int) ($posArgs[1] ?? 0);
$userId = (int) ($posArgs[2] ?? 1);

if ($torneoId <= 0 || $partida <= 0) {
    fwrite(STDERR, "Uso: php scripts/partiresul_demo_sanciones.php [--dry-run] [--seed=N] <id_torneo> <partida> [registrado_por]\n");
    exit(1);
}

if ($seed !== null) {
    mt_srand($seed);
}

$pdo = \DB::pdo();

$stT = $pdo->prepare('SELECT puntos FROM tournaments WHERE id = ?');
$stT->execute([$torneoId]);
$puntosTorneo = (int) ($stT->fetchColumn() ?: 100);

$sqlAll = 'SELECT id, id_torneo, partida, mesa, id_usuario, secuencia, resultado1, resultado2, ff, tarjeta, sancion, chancleta, zapato
    FROM partiresul
    WHERE id_torneo = ? AND partida = ? AND mesa > 0';
$stAll = $pdo->prepare($sqlAll);
$stAll->execute([$torneoId, $partida]);
$todas = $stAll->fetchAll(\PDO::FETCH_ASSOC);

if (count($todas) < 6) {
    fwrite(STDERR, "Se requieren al menos 6 filas con mesa > 0 para esta partida (hay " . count($todas) . ").\n");
    exit(1);
}

$filas = $todas;
shuffle($filas);
$elegidas = array_slice($filas, 0, 6);
$tipos = ['s80', 's80', 's80', 't3', 'ff', 'ff'];
shuffle($tipos);

$overlay = [];
foreach ($elegidas as $i => $fila) {
    $overlay[(int) $fila['id']] = $tipos[$i];
}

$mesas = [];
foreach ($elegidas as $fila) {
    $m = (int) $fila['mesa'];
    $mesas[$m] = true;
}
$mesasOrden = array_keys($mesas);
sort($mesasOrden, SORT_NUMERIC);

$stMesa = $pdo->prepare($sqlAll . ' AND mesa = ? ORDER BY secuencia ASC');
echo "Torneo {$torneoId}, partida {$partida}, registrado_por={$userId}" . ($dryRun ? ' (dry-run)' : '') . "\n";
echo 'Tipos asignados (id => tipo): ';
$parts = [];
foreach ($overlay as $pid => $t) {
    $parts[] = "{$pid}=>{$t}";
}
echo implode(', ', $parts) . "\n\n";

if ($dryRun) {
    echo "Dry-run: no se escribió en la base de datos.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($mesasOrden as $mesa) {
        $stMesa->execute([$torneoId, $partida, $mesa]);
        $rows = $stMesa->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== 4) {
            throw new \RuntimeException("La mesa {$mesa} no tiene 4 jugadores en partiresul.");
        }
        $jugadores = [];
        foreach ($rows as $row) {
            $jugadores[] = demo_row_to_jugador($row, $puntosTorneo);
        }
        demo_aplicar_overlay($jugadores, $overlay);
        TournamentActionHandler::aplicarResultadosMesaCore($pdo, $torneoId, $partida, $mesa, $jugadores, $userId, '');
    }
    $pdo->commit();
    echo "OK: actualizadas " . count($mesasOrden) . " mesa(s).\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
