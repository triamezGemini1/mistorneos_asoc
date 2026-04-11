<?php
/**
 * Vista de impresión: tarjetas de identificación en cuadrícula (tamaño tarjeta de crédito).
 * Papel CARTA. Tarjeta 8cm × 5cm. 2 columnas × 5 filas (10 por hoja).
 * QR: acceso a la vista móvil de consultas (mesa, resumen, clasificación) vía torneo_qr_jugador.php (token firmado).
 */

require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/TorneoJugadorQrToken.php';

$pdo = DB::pdo();
$torneo_nombre = isset($torneo['nombre']) ? $torneo['nombre'] : 'Torneo';

$stmt = $pdo->prepare("
    SELECT i.id_usuario, u.nombre, u.cedula, u.username AS user_login, u.photo_path
    FROM inscritos i
    INNER JOIN usuarios u ON u.id = i.id_usuario
    WHERE i.torneo_id = ?
    AND (i.estatus = 1 OR i.estatus = 2 OR i.estatus = '1' OR i.estatus = 'confirmado')
    ORDER BY CAST(TRIM(REPLACE(REPLACE(u.cedula, '.', ''), ' ', '')) AS UNSIGNED) ASC
");
$stmt->execute([$torneo_id]);
$jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
$jugadores = array_map(function ($row) { return array_change_key_case($row, CASE_LOWER); }, $jugadores);

function formatear_cedula_tarjeta($valor) {
    $digits = preg_replace('/\D/', '', (string)$valor);
    if ($digits === '') {
        return $valor !== '' && $valor !== null ? $valor : '—';
    }
    if (strlen($digits) < 8) {
        $digits = str_pad($digits, 8, '0', STR_PAD_LEFT);
    }
    return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3);
}

$script = $_SERVER['SCRIPT_NAME'] ?? 'index.php';
$base_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($script);
$base_public = rtrim($base_url, '/');
$url_panel = $base_public . '/' . basename($script) . '?page=torneo_gestion&action=panel&torneo_id=' . (int)$torneo_id;
$public_consultas_base = rtrim(AppHelpers::getPublicUrl(), '/');
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

.cuadricula-tarjetas-container {
    background: #fff;
    margin: 0 auto;
    width: 100%;
    max-width: 17cm;
}

.cuadricula-tarjetas-grid {
    display: grid;
    grid-template-columns: repeat(2, 8cm);
    grid-template-rows: repeat(5, 5cm);
    gap: 2px;
    width: calc(16cm + 2px);
    min-height: calc(25cm + 8px);
    margin: 0 auto;
    page-break-after: always;
}
.cuadricula-tarjetas-grid:last-child { page-break-after: auto; }

/* Diseño carnet: fila superior = Foto (izq) | Nombres (der); fila inferior izq = CI + ID bajo la foto; der = QR */
.tarjeta-id {
    width: 8cm;
    height: 5cm;
    box-sizing: border-box;
    border: 0.5mm solid #333;
    display: grid;
    grid-template-columns: 22mm 1fr;
    grid-template-rows: 1.4fr 0.6fr 0.6fr;
    gap: 1mm 2mm;
    font-family: Calibri, 'Lato', Arial, sans-serif;
    page-break-inside: avoid;
    background: #fff;
    padding: 2mm;
}
.tarjeta-id .tarjeta-foto {
    grid-column: 1;
    grid-row: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f0f0f0;
    border-radius: 2px;
    overflow: hidden;
}
.tarjeta-id .tarjeta-foto img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.tarjeta-id .tarjeta-foto-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
    font-size: 10pt;
}
.tarjeta-id .tarjeta-nombre {
    grid-column: 2;
    grid-row: 1;
    font-size: 14pt;
    font-weight: bold;
    color: #212121;
    line-height: 1.2;
    align-self: center;
    padding-left: 2mm;
}
.tarjeta-id .tarjeta-cedula {
    grid-column: 1;
    grid-row: 2;
    font-size: 11pt;
    color: #424242;
    align-self: end;
    padding-left: 0;
}
.tarjeta-id .tarjeta-id-jugador {
    grid-column: 1;
    grid-row: 3;
    font-size: 13pt;
    font-weight: bold;
    color: #0d47a1;
    align-self: start;
}
.tarjeta-id .tarjeta-qr-wrap {
    grid-column: 2;
    grid-row: 2 / 4;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.tarjeta-id .tarjeta-qr-wrap img {
    width: 18mm;
    height: 18mm;
    display: block;
}
.tarjeta-id .qr-label { font-size: 5pt; color: #666; text-align: center; margin-top: 0.5mm; }

@media print {
    @page { size: letter; margin: 1cm; }
    header, footer, nav, aside, .buttons, .no-print-lote,
    .col-md-3, .card > .card-body > p, .cuadricula-tarjetas-container > .no-print-lote { display: none !important; }
    .col-md-9 { max-width: 100% !important; flex: 0 0 100% !important; }
    .card, .card-body { border: none !important; box-shadow: none !important; background: transparent !important; padding: 0 !important; }
    body { background: #fff; margin: 0; padding: 0; }
    .cuadricula-tarjetas-container { padding: 0; }
    .cuadricula-tarjetas-grid { page-break-inside: avoid; }
}
</style>
<div class="buttons no-print-lote mb-3 d-flex align-items-center gap-2">
    <a href="<?= htmlspecialchars($url_panel) ?>" class="btn btn-primary">
        <i class="fas fa-arrow-left me-1"></i>Volver al panel
    </a>
    <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
        <i class="fas fa-print me-1"></i>Imprimir Tarjetas
    </button>
</div>
<div class="card">
    <div class="card-body">
        <?php if (empty($jugadores)): ?>
            <p class="text-muted">No hay jugadores confirmados para este torneo.</p>
        <?php else: ?>
            <div class="cuadricula-tarjetas-container">
                <div id="area-impresion-tarjetas">
                    <?php
                    $por_pagina = 10;
                    $paginas = array_chunk($jugadores, $por_pagina);
                    foreach ($paginas as $grupo):
                    ?>
                    <div class="cuadricula-tarjetas-grid">
                        <?php foreach ($grupo as $j):
                            $nombre = htmlspecialchars($j['nombre'] ?? '—');
                            $cedula = htmlspecialchars(formatear_cedula_tarjeta($j['cedula'] ?? ''));
                            $id_jugador = (int)($j['id_usuario'] ?? 0);
                            $photo_path = trim((string)($j['photo_path'] ?? ''));
                            $foto_src = '';
                            if ($photo_path !== '') {
                                if (strpos($photo_path, 'upload/') === 0) {
                                    $foto_src = $base_public . '/view_image.php?path=' . rawurlencode($photo_path);
                                } else {
                                    $foto_src = $base_public . '/view_image.php?path=' . rawurlencode('upload/' . ltrim($photo_path, '/'));
                                }
                            }
                            try {
                                $qr_token = TorneoJugadorQrToken::encode((int) $torneo_id, $id_jugador);
                                $url_consultas = $public_consultas_base . '/torneo_qr_jugador.php?t=' . rawurlencode($qr_token);
                            } catch (Throwable $e) {
                                $url_consultas = $public_consultas_base . '/info_torneo_mesas.php?torneo_id=' . (int) $torneo_id;
                            }
                            $qr_src = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&margin=1&data=' . rawurlencode($url_consultas);
                        ?>
                        <div class="tarjeta-id">
                            <div class="tarjeta-foto">
                                <?php if ($foto_src !== ''): ?>
                                    <img src="<?= htmlspecialchars($foto_src) ?>" alt="" />
                                <?php else: ?>
                                    <div class="tarjeta-foto-placeholder">Sin foto</div>
                                <?php endif; ?>
                            </div>
                            <div class="tarjeta-nombre"><?= $nombre ?></div>
                            <div class="tarjeta-cedula">C.I. <?= $cedula ?></div>
                            <div class="tarjeta-id-jugador">#<?= $id_jugador ?></div>
                            <div class="tarjeta-qr-wrap">
                                <img src="<?= htmlspecialchars($qr_src) ?>" alt="QR" />
                                <div class="qr-label">Consulta mesa y resultados</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
