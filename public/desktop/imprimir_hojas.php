<?php
/**
 * Paso 5: Imprimir Hojas de Anotación (Desktop).
 * Una hoja por mesa con el diseño: encabezado (torneo | Ronda - Mesa),
 * Pareja AC (izq) / QR centro / Pareja BD (der), ANOTACIONES, firmas (Pareja AC, Pareja BD, Árbitro).
 */
declare(strict_types=1);
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/db_local.php';

$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$ronda = isset($_GET['ronda']) ? (int)$_GET['ronda'] : 0;

$pdo = DB_Local::pdo();
$torneos = [];
$rondas = [];
$torneo = null;
$mesas = [];
$letras_secuencia = [1 => 'A', 2 => 'C', 3 => 'B', 4 => 'D'];

try {
    $torneos = $pdo->query("SELECT id, nombre, rondas FROM tournaments ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($torneo_id > 0) {
        $stmt = $pdo->prepare("SELECT id, nombre, rondas FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT DISTINCT partida FROM partiresul WHERE id_torneo = ? ORDER BY partida ASC");
        $stmt->execute([$torneo_id]);
        $rondas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ronda > 0) {
            $stmt = $pdo->prepare("
                SELECT pr.id_usuario, pr.mesa, pr.secuencia, pr.partida,
                       u.nombre AS nombre_completo, u.username,
                       i.posicion, i.ganados, i.perdidos, i.efectividad, i.puntos, i.id_club,
                       c.nombre AS nombre_club
                FROM partiresul pr
                INNER JOIN usuarios u ON pr.id_usuario = u.id
                LEFT JOIN inscritos i ON i.id_usuario = u.id AND i.torneo_id = pr.id_torneo
                LEFT JOIN clubes c ON i.id_club = c.id
                WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
                ORDER BY pr.mesa ASC, pr.secuencia ASC
            ");
            $stmt->execute([$torneo_id, $ronda]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $numMesa = (int)$row['mesa'];
                if (!isset($mesas[$numMesa])) {
                    $mesas[$numMesa] = ['numero' => $numMesa, 'jugadores' => []];
                }
                $row['inscrito'] = [
                    'posicion' => (int)($row['posicion'] ?? 0),
                    'ganados' => (int)($row['ganados'] ?? 0),
                    'perdidos' => (int)($row['perdidos'] ?? 0),
                    'efectividad' => (int)($row['efectividad'] ?? 0),
                    'puntos' => (int)($row['puntos'] ?? 0),
                ];
                $mesas[$numMesa]['jugadores'][] = $row;
            }
            $mesas = array_values($mesas);
        }
    }
} catch (Throwable $e) {
}

$pageTitle = 'Imprimir Hojas de Anotación';
$desktopActive = 'panel';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3 no-print">
    <h2 class="h4 mb-3"><i class="fas fa-print text-primary me-2"></i>Imprimir Hojas de Anotación (Paso 5)</h2>
    <p class="text-muted">Una hoja por mesa: jugadores, estadísticas, QR, espacio de anotaciones y firmas. Use Imprimir o Guardar como PDF.</p>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="get" action="imprimir_hojas.php" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Torneo</label>
                    <select name="torneo_id" class="form-select">
                        <option value="0">-- Seleccione torneo --</option>
                        <?php foreach ($torneos as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $torneo_id === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['nombre'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Ronda</label>
                    <select name="ronda" class="form-select">
                        <option value="0">-- Seleccione ronda --</option>
                        <?php foreach ($rondas as $r): ?>
                        <option value="<?= (int)$r ?>" <?= $ronda === (int)$r ? 'selected' : '' ?>><?= (int)$r ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-file-pdf me-1"></i>Ver hojas para imprimir</button>
                    <?php if ($torneo_id > 0): ?><a href="panel_torneo.php?torneo_id=<?= $torneo_id ?>" class="btn btn-outline-secondary ms-2">Volver al Panel</a><?php else: ?><a href="torneos.php" class="btn btn-outline-secondary ms-2">Torneos</a><?php endif; ?>
                </div>
            </form>
            <?php if ($torneo_id > 0 && count($rondas) === 0): ?>
            <p class="text-muted mt-3 mb-0 small">No hay rondas generadas. Use "Generar Ronda" en el Panel.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($torneo && $ronda > 0 && !empty($mesas)): ?>
<style>
:root { --hoja-ancho: calc(8.5in * 0.9); }
@media print { .no-print { display: none !important; } body { background: #fff; } }
.hoja-mesa {
    background: #fff;
    border: 2px solid #000;
    padding: 20px;
    margin: 0 auto 30px;
    page-break-after: always;
    width: var(--hoja-ancho);
    min-height: 11in;
    max-width: var(--hoja-ancho);
    display: flex;
    flex-direction: column;
}
.hoja-mesa:last-child { page-break-after: auto; }
.header-torneo {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 22px;
    font-weight: bold;
    padding-bottom: 10px;
    border-bottom: 2px solid #000;
}
.header-ronda-mesa { font-size: 18px; font-weight: bold; white-space: nowrap; margin-left: 15px; }
.linea-con-qr {
    display: flex;
    justify-content: space-between;
    align-items: stretch;
    gap: 15px;
    margin-top: 15px;
}
.linea-con-qr .col-izq, .linea-con-qr .col-der { flex: 1; min-width: 0; }
.linea-con-qr .col-qr { flex-shrink: 0; display: flex; align-items: center; justify-content: center; min-width: 120px; }
.jugador-block { margin-bottom: 12px; min-width: 0; }
.jugador-linea-id-nombre {
    display: flex;
    align-items: baseline;
    gap: 8px;
    min-width: 0;
    width: 100%;
    font-weight: bold;
}
.jugador-linea-id-nombre--izq .jugador-nombre {
    min-width: 0;
    flex: 1 1 0%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.jugador-linea-id-nombre--der {
    flex-direction: row-reverse;
}
.jugador-linea-id-nombre--der .jugador-nombre {
    min-width: 0;
    flex: 1 1 0%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.jugador-id { flex-shrink: 0; font-weight: bold; }
.jugador-nombre { font-weight: bold; }
.jugador-stats { font-size: 12px; margin: 4px 0 2px 0; }
.jugador-club { font-size: 12px; font-style: italic; color: #333; }
.col-der .jugador-block { text-align: right; }
.qr-mesa img { width: 100px; height: 100px; display: block; }
.seccion-c-d { margin-top: 10px; display: flex; justify-content: space-between; gap: 15px; }
.seccion-c-d .col-izq, .seccion-c-d .col-der { flex: 1; }
.seccion-c-d .col-der { text-align: right; }
.espacio-anotacion {
    border: 2px solid #000;
    min-height: 200px;
    margin: 20px 0;
    padding: 10px;
    flex: 1;
}
.espacio-anotacion-label { font-weight: bold; font-size: 14px; margin-bottom: 8px; }
.firmas {
    display: flex;
    justify-content: space-between;
    margin-top: auto;
    padding-top: 20px;
    border-top: 2px solid #000;
}
.firma-item { flex: 1; text-align: center; padding: 0 15px; }
.firma-linea { border-top: 1px solid #000; margin-top: 45px; padding-top: 6px; font-size: 12px; font-weight: bold; }
</style>

<div class="container-fluid py-3" id="hojas-imprimir">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h5 class="mb-0"><?= htmlspecialchars($torneo['nombre'] ?? '') ?> — Ronda <?= $ronda ?></h5>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimir / Guardar como PDF</button>
    </div>

    <?php foreach ($mesas as $mesa):
        $jugadores = [];
        foreach ($mesa['jugadores'] as $j) {
            $sec = (int)($j['secuencia'] ?? 0);
            $jugadores[$sec] = $j;
        }
        $jugador1 = $jugadores[1] ?? null;  // A
        $jugador2 = $jugadores[2] ?? null;  // C
        $jugador3 = $jugadores[3] ?? null;  // B
        $jugador4 = $jugadores[4] ?? null;  // D
        $num_mesa = (int)$mesa['numero'];
        $qr_text = 'Torneo: ' . rawurlencode($torneo['nombre'] ?? '') . ' Ronda: ' . $ronda . ' Mesa: ' . $num_mesa;
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query(['size' => '100x100', 'data' => $qr_text, 'format' => 'png', 'margin' => 2, 'ecc' => 'H']);
    ?>
    <div class="hoja-mesa" id="hoja-mesa-<?= $num_mesa ?>">
        <div class="header-torneo">
            <div class="nombre-torneo"><?= htmlspecialchars($torneo['nombre'] ?? 'Torneo') ?></div>
            <div class="header-ronda-mesa">Ronda: <?= $ronda ?> - Mesa: <?= $num_mesa ?></div>
        </div>

        <div class="linea-con-qr">
            <div class="col-izq">
                <div class="jugador-block">
                    <div class="jugador-linea-id-nombre jugador-linea-id-nombre--izq">
                    <span class="jugador-id"><?= $jugador1 ? (int)($jugador1['id_usuario'] ?? 0) : '—' ?></span>
                    <span class="jugador-nombre"><?= $jugador1 ? htmlspecialchars($jugador1['nombre_completo'] ?? $jugador1['nombre'] ?? 'N/A') . ' (' . ($letras_secuencia[1] ?? 'A') . ')' : '—' ?></span>
                    </div>
                    <?php if ($jugador1): ?><div class="jugador-stats">Pos: <?= (int)($jugador1['inscrito']['posicion'] ?? 0) ?> G: <?= (int)($jugador1['inscrito']['ganados'] ?? 0) ?> P: <?= (int)($jugador1['inscrito']['perdidos'] ?? 0) ?> Efect: <?= (int)($jugador1['inscrito']['efectividad'] ?? 0) ?> Pts: <?= (int)($jugador1['inscrito']['puntos'] ?? 0) ?></div><div class="jugador-club"><?= htmlspecialchars($jugador1['nombre_club'] ?? 'Sin Club') ?></div><?php endif; ?>
                </div>
                <div class="jugador-block">
                    <div class="jugador-linea-id-nombre jugador-linea-id-nombre--izq">
                    <span class="jugador-id"><?= $jugador2 ? (int)($jugador2['id_usuario'] ?? 0) : '—' ?></span>
                    <span class="jugador-nombre"><?= $jugador2 ? htmlspecialchars($jugador2['nombre_completo'] ?? $jugador2['nombre'] ?? 'N/A') . ' (' . ($letras_secuencia[2] ?? 'C') . ')' : '—' ?></span>
                    </div>
                    <?php if ($jugador2): ?><div class="jugador-stats">Pos: <?= (int)($jugador2['inscrito']['posicion'] ?? 0) ?> G: <?= (int)($jugador2['inscrito']['ganados'] ?? 0) ?> P: <?= (int)($jugador2['inscrito']['perdidos'] ?? 0) ?> Efect: <?= (int)($jugador2['inscrito']['efectividad'] ?? 0) ?> Pts: <?= (int)($jugador2['inscrito']['puntos'] ?? 0) ?></div><div class="jugador-club"><?= htmlspecialchars($jugador2['nombre_club'] ?? 'Sin Club') ?></div><?php endif; ?>
                </div>
            </div>
            <div class="col-qr">
                <div class="qr-mesa">
                    <img src="<?= htmlspecialchars($qr_url) ?>" alt="QR Mesa <?= $num_mesa ?>" width="100" height="100">
                </div>
            </div>
            <div class="col-der">
                <div class="jugador-block">
                    <div class="jugador-linea-id-nombre jugador-linea-id-nombre--der">
                    <span class="jugador-nombre"><?= $jugador3 ? htmlspecialchars($jugador3['nombre_completo'] ?? $jugador3['nombre'] ?? 'N/A') . ' (' . ($letras_secuencia[3] ?? 'B') . ')' : '—' ?></span>
                    <span class="jugador-id"><?= $jugador3 ? (int)($jugador3['id_usuario'] ?? 0) : '—' ?></span>
                    </div>
                    <?php if ($jugador3): ?><div class="jugador-stats">Pos: <?= (int)($jugador3['inscrito']['posicion'] ?? 0) ?> G: <?= (int)($jugador3['inscrito']['ganados'] ?? 0) ?> P: <?= (int)($jugador3['inscrito']['perdidos'] ?? 0) ?> Efect: <?= (int)($jugador3['inscrito']['efectividad'] ?? 0) ?> Pts: <?= (int)($jugador3['inscrito']['puntos'] ?? 0) ?></div><div class="jugador-club"><?= htmlspecialchars($jugador3['nombre_club'] ?? 'Sin Club') ?></div><?php endif; ?>
                </div>
                <div class="jugador-block">
                    <div class="jugador-linea-id-nombre jugador-linea-id-nombre--der">
                    <span class="jugador-nombre"><?= $jugador4 ? htmlspecialchars($jugador4['nombre_completo'] ?? $jugador4['nombre'] ?? 'N/A') . ' (' . ($letras_secuencia[4] ?? 'D') . ')' : '—' ?></span>
                    <span class="jugador-id"><?= $jugador4 ? (int)($jugador4['id_usuario'] ?? 0) : '—' ?></span>
                    </div>
                    <?php if ($jugador4): ?><div class="jugador-stats">Pos: <?= (int)($jugador4['inscrito']['posicion'] ?? 0) ?> G: <?= (int)($jugador4['inscrito']['ganados'] ?? 0) ?> P: <?= (int)($jugador4['inscrito']['perdidos'] ?? 0) ?> Efect: <?= (int)($jugador4['inscrito']['efectividad'] ?? 0) ?> Pts: <?= (int)($jugador4['inscrito']['puntos'] ?? 0) ?></div><div class="jugador-club"><?= htmlspecialchars($jugador4['nombre_club'] ?? 'Sin Club') ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="espacio-anotacion">
            <div class="espacio-anotacion-label">ANOTACIONES:</div>
        </div>

        <div class="firmas">
            <div class="firma-item"><div class="firma-linea">Pareja AC</div></div>
            <div class="firma-item"><div class="firma-linea">Pareja BD</div></div>
            <div class="firma-item"><div class="firma-linea">Árbitro</div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif ($torneo_id > 0 && $ronda > 0 && empty($mesas)): ?>
<div class="alert alert-warning">No hay mesas para esta ronda. Genere la ronda desde el Panel.</div>
<?php endif; ?>
</main></body></html>
