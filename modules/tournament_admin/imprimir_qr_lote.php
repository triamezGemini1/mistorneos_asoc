<?php
/**
 * Vista de impresión: tarjetas de identificación en cuadrícula (tamaño tarjeta de crédito).
 * Papel CARTA. Tarjeta 8cm × 5cm. 2 columnas × 5 filas (10 por hoja).
 * QR: acceso a la vista móvil de consultas (mesa, resumen, clasificación) vía torneo_qr_jugador.php (token firmado).
 *
 * Agrupación: parejas/equipos (mismo codigo_equipo en inscritos) van juntos, sin partir un equipo entre hojas
 * cuando cabe en una hoja; se rellenan hasta 10 huecos por hoja priorizando equipos completos.
 */

require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/TorneoJugadorQrToken.php';

$pdo = DB::pdo();
$torneo_nombre = isset($torneo['nombre']) ? $torneo['nombre'] : 'Torneo';

/** @var list<array<string, mixed>> $jugadores */
$jugadores = [];
try {
    $chk = $pdo->query("SHOW COLUMNS FROM inscritos LIKE 'codigo_equipo'");
    $hasCodigoEquipo = $chk && $chk->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $hasCodigoEquipo = false;
}
try {
    $chkEq = $pdo->query("SHOW TABLES LIKE 'equipos'");
    $hasTablaEquipos = $chkEq && $chkEq->fetch(PDO::FETCH_NUM);
} catch (Throwable $e) {
    $hasTablaEquipos = false;
}

if ($hasCodigoEquipo && $hasTablaEquipos) {
    $stmt = $pdo->prepare("
        SELECT i.id_usuario, u.nombre, u.cedula, u.username AS user_login, u.photo_path,
               COALESCE(i.codigo_equipo, '') AS codigo_equipo,
               e.nombre_equipo
        FROM inscritos i
        INNER JOIN usuarios u ON u.id = i.id_usuario
        LEFT JOIN equipos e ON e.id_torneo = i.torneo_id AND e.codigo_equipo = i.codigo_equipo
        WHERE i.torneo_id = ?
        AND (i.estatus = 1 OR i.estatus = 2 OR i.estatus = '1' OR i.estatus = 'confirmado')
    ");
} elseif ($hasCodigoEquipo) {
    $stmt = $pdo->prepare("
        SELECT i.id_usuario, u.nombre, u.cedula, u.username AS user_login, u.photo_path,
               COALESCE(i.codigo_equipo, '') AS codigo_equipo,
               NULL AS nombre_equipo
        FROM inscritos i
        INNER JOIN usuarios u ON u.id = i.id_usuario
        WHERE i.torneo_id = ?
        AND (i.estatus = 1 OR i.estatus = 2 OR i.estatus = '1' OR i.estatus = 'confirmado')
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT i.id_usuario, u.nombre, u.cedula, u.username AS user_login, u.photo_path,
               '' AS codigo_equipo,
               NULL AS nombre_equipo
        FROM inscritos i
        INNER JOIN usuarios u ON u.id = i.id_usuario
        WHERE i.torneo_id = ?
        AND (i.estatus = 1 OR i.estatus = 2 OR i.estatus = '1' OR i.estatus = 'confirmado')
    ");
}
$stmt->execute([$torneo_id]);
$jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
$jugadores = array_map(function ($row) { return array_change_key_case($row, CASE_LOWER); }, $jugadores);

/**
 * Clave estable de agrupación: sin código de equipo compartido → cada jugador es su propio grupo.
 */
function imprimir_qr_lote_grupo_key(array $row): string {
    $ce = trim((string) ($row['codigo_equipo'] ?? ''));
    if ($ce === '' || $ce === '0' || strcasecmp($ce, '000-000') === 0) {
        return 'solo:' . (int) ($row['id_usuario'] ?? 0);
    }
    return 'eq:' . $ce;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<list<array<string, mixed>>>
 */
function imprimir_qr_lote_agrupar_en_equipos(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        $k = imprimir_qr_lote_grupo_key($row);
        if (!isset($map[$k])) {
            $map[$k] = [];
        }
        $map[$k][] = $row;
    }
    foreach ($map as &$g) {
        usort($g, static function ($a, $b) {
            return (int) ($a['id_usuario'] ?? 0) <=> (int) ($b['id_usuario'] ?? 0);
        });
    }
    unset($g);
    $teams = array_values($map);
    usort($teams, static function ($a, $b) {
        $ma = min(array_column($a, 'id_usuario'));
        $mb = min(array_column($b, 'id_usuario'));
        return $ma <=> $mb;
    });
    return $teams;
}

/**
 * Empaca equipos en páginas de $cap huecos sin partir un equipo entre hojas (si el equipo cabe en una hoja).
 *
 * @param list<list<array<string, mixed>>> $teams
 * @return list<list<array<string, mixed>>>
 */
function imprimir_qr_lote_empacar_paginas(array $teams, int $cap = 10): array {
    $paginas = [];
    $actual = [];
    $usados = 0;

    foreach ($teams as $team) {
        $n = count($team);
        if ($n <= 0) {
            continue;
        }
        if ($n > $cap) {
            if ($actual !== []) {
                $paginas[] = $actual;
                $actual = [];
                $usados = 0;
            }
            $parte = 1;
            $totalPartes = (int) ceil($n / $cap);
            foreach (array_chunk($team, $cap) as $chunk) {
                $bloque = [];
                foreach ($chunk as $jug) {
                    $jug['_grupo_parte'] = $parte;
                    $jug['_grupo_partes'] = $totalPartes;
                    $bloque[] = $jug;
                }
                $paginas[] = $bloque;
                $parte++;
            }
            continue;
        }
        if ($usados + $n > $cap) {
            if ($actual !== []) {
                $paginas[] = $actual;
            }
            $actual = [];
            $usados = 0;
        }
        foreach ($team as $jug) {
            $jug['_grupo_parte'] = 1;
            $jug['_grupo_partes'] = 1;
            $actual[] = $jug;
        }
        $usados += $n;
    }
    if ($actual !== []) {
        $paginas[] = $actual;
    }
    return $paginas;
}

function imprimir_qr_lote_etiqueta_grupo(array $row): string {
    $ne = trim((string) ($row['nombre_equipo'] ?? ''));
    if ($ne !== '') {
        return $ne;
    }
    $ce = trim((string) ($row['codigo_equipo'] ?? ''));
    if ($ce !== '' && $ce !== '0' && strcasecmp($ce, '000-000') !== 0) {
        return 'Cód. ' . $ce;
    }
    return '';
}

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
$pageCtx = (string) ($_GET['page'] ?? '');
if ($pageCtx === 'tournament_admin') {
    $url_panel = $base_public . '/' . basename($script) . '?page=tournament_admin&torneo_id=' . (int) $torneo_id . '&action=dashboard';
} else {
    $url_panel = $base_public . '/' . basename($script) . '?page=torneo_gestion&action=panel&torneo_id=' . (int) $torneo_id;
}
$public_consultas_base = rtrim(AppHelpers::getPublicUrl(), '/');

$por_pagina = 10;
$equipos = imprimir_qr_lote_agrupar_en_equipos($jugadores);
$paginas = imprimir_qr_lote_empacar_paginas($equipos, $por_pagina);
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
    align-content: start;
}
.cuadricula-tarjetas-grid:last-child { page-break-after: auto; }

.tarjeta-slot-vacio {
    width: 8cm;
    height: 5cm;
    visibility: hidden;
    pointer-events: none;
    border: none !important;
}

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
    break-inside: avoid;
    background: #fff;
    padding: 2mm;
}
.tarjeta-id.tarjeta-id--inicio-grupo {
    box-shadow: inset 0 3px 0 #0d47a1;
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
    line-height: 1.15;
    align-self: center;
    padding-left: 2mm;
}
.tarjeta-id .tarjeta-equipo-line {
    display: block;
    font-size: 6.5pt;
    font-weight: 700;
    color: #0d47a1;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 1mm;
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
<div class="buttons no-print-lote mb-3 d-flex align-items-center gap-2 flex-wrap">
    <a href="<?= htmlspecialchars($url_panel) ?>" class="btn btn-primary">
        <i class="fas fa-arrow-left me-1"></i>Volver al panel
    </a>
    <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
        <i class="fas fa-print me-1"></i>Imprimir Tarjetas
    </button>
    <?php if ($hasCodigoEquipo): ?>
    <span class="text-muted small ms-2">Parejas/equipos: mismas tarjetas consecutivas; no se parte un equipo entre hojas si cabe en una.</span>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-body">
        <?php if (empty($jugadores)): ?>
            <p class="text-muted">No hay jugadores confirmados para este torneo.</p>
        <?php else: ?>
            <div class="cuadricula-tarjetas-container">
                <div id="area-impresion-tarjetas">
                    <?php foreach ($paginas as $grupo): ?>
                    <div class="cuadricula-tarjetas-grid">
                        <?php
                        $prevKey = null;
                        foreach ($grupo as $j):
                            $gk = imprimir_qr_lote_grupo_key($j);
                            $inicioGrupo = ($prevKey === null || $gk !== $prevKey);
                            $prevKey = $gk;
                            $etiquetaGrupo = $inicioGrupo ? imprimir_qr_lote_etiqueta_grupo($j) : '';
                            $partes = (int) ($j['_grupo_partes'] ?? 1);
                            $parte = (int) ($j['_grupo_parte'] ?? 1);
                            if ($partes > 1 && $etiquetaGrupo !== '') {
                                $etiquetaGrupo .= ' — Parte ' . $parte . '/' . $partes;
                            }
                            $mostrarBadge = $etiquetaGrupo !== '';
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
                            $esEquipoCompartido = strncmp($gk, 'eq:', 3) === 0;
                            $clases = 'tarjeta-id' . ($inicioGrupo && $esEquipoCompartido ? ' tarjeta-id--inicio-grupo' : '');
                        ?>
                        <div class="<?= htmlspecialchars($clases, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="tarjeta-foto">
                                <?php if ($foto_src !== ''): ?>
                                    <img src="<?= htmlspecialchars($foto_src) ?>" alt="" />
                                <?php else: ?>
                                    <div class="tarjeta-foto-placeholder">Sin foto</div>
                                <?php endif; ?>
                            </div>
                            <div class="tarjeta-nombre"><?php if ($inicioGrupo && $mostrarBadge): ?><span class="tarjeta-equipo-line"><?= htmlspecialchars($etiquetaGrupo, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?= $nombre ?></div>
                            <div class="tarjeta-cedula">C.I. <?= $cedula ?></div>
                            <div class="tarjeta-id-jugador">#<?= $id_jugador ?></div>
                            <div class="tarjeta-qr-wrap">
                                <img src="<?= htmlspecialchars($qr_src) ?>" alt="QR" />
                                <div class="qr-label">Consulta mesa y resultados</div>
                            </div>
                        </div>
                        <?php
                        endforeach;
                        for ($pad = count($grupo); $pad < $por_pagina; $pad++):
                        ?>
                        <div class="tarjeta-slot-vacio" aria-hidden="true"></div>
                        <?php endfor; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
