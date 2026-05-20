<?php
/**
 * Reporte estadístico de torneos realizados
 * Base: Admin Organización (entidad y organización)
 * Jerarquía: Entidad → Organización → Subtotal participantes (rompe control) → Detalles torneo
 * Detalles: nombre, fecha, rondas, cantidad de jugadores participantes
 * Subtotales: por organización, por entidad, total general
 */

if (!defined('APP_BOOTSTRAPPED')) { require __DIR__ . '/../config/bootstrap.php'; }
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/app_helpers.php';

Auth::requireRole(['admin_general', 'admin_club']);

$pdo = DB::pdo();
$current_user = Auth::user();
$is_admin_general = ($current_user['role'] ?? '') === 'admin_general';

// Admin general: reporte unificado por estructura (asociaciones / particulares)
if ($is_admin_general && !isset($_GET['legacy']) && empty($_GET['context'])) {
    $ctx = isset($_GET['particulares']) ? 'particulares' : 'asociaciones';
    header('Location: index.php?page=torneos_estructura&context=' . urlencode($ctx) . '&vista=reporte');
    exit;
}

// Mapa de entidades
$entidad_map = [];
try {
    $codeCol = 'codigo';
    $nameCol = 'nombre';
    $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if (stripos($col['Field'], 'cod') !== false) $codeCol = $col['Field'];
        if (stripos($col['Field'], 'nom') !== false) $nameCol = $col['Field'];
    }
    $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ent) {
        if (isset($ent['codigo'])) {
            $entidad_map[(string)$ent['codigo']] = $ent['nombre'] ?? $ent['codigo'];
        }
    }
} catch (Exception $e) {}

// Filtro para admin_club: solo sus organizaciones (y clubes legacy)
$where_extra = '';
$params_extra = [];
if (!$is_admin_general) {
    require_once __DIR__ . '/../lib/ClubHelper.php';
    $user_id = Auth::id();
    
    // IDs de organizaciones del admin_club (admin_user_id)
    $org_ids = [];
    try {
        $stmt = $pdo->prepare("SELECT id FROM organizaciones WHERE admin_user_id = ? AND estatus = 1");
        $stmt->execute([$user_id]);
        $org_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Exception $e) {}
    
    // IDs de clubes supervisados (legacy: club_responsable puede apuntar a club)
    $club_ids = ClubHelper::getClubesByAdminClubId($user_id);
    if (empty($club_ids) && !empty($current_user['club_id'])) {
        $club_ids = ClubHelper::getClubesSupervised((int)$current_user['club_id']);
    }
    
    $responsable_ids = array_values(array_unique(array_merge($org_ids, $club_ids)));
    if (!empty($responsable_ids)) {
        $placeholders = implode(',', array_fill(0, count($responsable_ids), '?'));
        $where_extra = " AND t.club_responsable IN ($placeholders)";
        $params_extra = $responsable_ids;
    } else {
        $where_extra = " AND 1=0"; // Sin acceso
    }
}

// Obtener torneos realizados: Entidad → Organización → Torneo (nombre, fecha, rondas, participantes)
// club_responsable puede ser org.id o club.id (legacy); organizaciones tienen entidad
$has_locked = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'locked'")->fetchAll();
    $has_locked = !empty($cols);
} catch (Exception $e) {}

$where_fecha = $has_locked 
    ? "((t.fechator IS NOT NULL AND t.fechator <= CURDATE()) OR t.locked = 1)" 
    : "t.fechator IS NOT NULL AND t.fechator <= CURDATE()";

$sql = "
    SELECT 
        t.id, t.nombre, t.fechator, t.rondas, t.estatus,
        t.club_responsable,
        COALESCE(org.nombre, org2.nombre) as org_nombre,
        COALESCE(org.id, org2.id) as org_id,
        COALESCE(org.entidad, org2.entidad, 0) as entidad,
        (SELECT COUNT(*) FROM inscritos i 
         WHERE i.torneo_id = t.id AND i.estatus = 'confirmado') as total_jugadores
    FROM tournaments t
    LEFT JOIN organizaciones org ON t.club_responsable = org.id AND org.estatus = 1
    LEFT JOIN clubes c ON t.club_responsable = c.id AND c.estatus = 1
    LEFT JOIN organizaciones org2 ON c.cod_org = org2.id AND org2.estatus = 1
    WHERE $where_fecha
    $where_extra
    ORDER BY COALESCE(org.entidad, org2.entidad, 999) ASC, COALESCE(org.nombre, org2.nombre) ASC, t.fechator DESC, t.id DESC
";

try {
    if (!empty($params_extra)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_extra);
    } else {
        $stmt = $pdo->query($sql);
    }
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback si organizaciones no existe o estructura legacy
    try {
        $sql_fb = "
            SELECT 
                t.id, t.nombre, t.fechator, t.rondas, t.estatus,
                t.club_responsable,
                c.nombre as org_nombre,
                c.cod_org as org_id,
                COALESCE(u.entidad, 0) as entidad,
                (SELECT COUNT(*) FROM inscritos i 
                 WHERE i.torneo_id = t.id AND i.estatus = 'confirmado') as total_jugadores
            FROM tournaments t
            LEFT JOIN clubes c ON t.club_responsable = c.id
            LEFT JOIN usuarios u ON (c.admin_club_id = u.id AND u.role = 'admin_club')
            WHERE " . ($has_locked ? "((t.fechator IS NOT NULL AND t.fechator <= CURDATE()) OR t.locked = 1)" : "t.fechator IS NOT NULL AND t.fechator <= CURDATE()") . "
            $where_extra
            ORDER BY COALESCE(u.entidad, 999) ASC, c.nombre ASC, t.fechator DESC, t.id DESC
        ";
        if (!empty($params_extra)) {
            $stmt = $pdo->prepare($sql_fb);
            $stmt->execute($params_extra);
        } else {
            $stmt = $pdo->query($sql_fb);
        }
        $torneos = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($torneos as &$row) {
            $row['org_id'] = $row['org_id'] ?? $row['club_responsable'] ?? 0;
            $row['org_nombre'] = $row['org_nombre'] ?? 'Sin organización';
        }
        unset($row);
    } catch (Exception $e2) {
        $torneos = [];
    }
}

// Agrupar: Entidad → Organización → [Torneos]
$por_entidad = [];
$total_general_eventos = 0;
$total_general_jugadores = 0;

foreach ($torneos as $tor) {
    $entidad_id = (int)($tor['entidad'] ?? 0);
    $entidad_nombre = $entidad_map[(string)$entidad_id] ?? ($entidad_id > 0 ? "Entidad {$entidad_id}" : 'Sin Entidad');
    $org_id = (int)($tor['org_id'] ?? 0);
    $org_nombre = $tor['org_nombre'] ?? 'Sin organización';
    $jugadores = (int)($tor['total_jugadores'] ?? 0);

    if (!isset($por_entidad[$entidad_id])) {
        $por_entidad[$entidad_id] = [
            'nombre' => $entidad_nombre,
            'organizaciones' => [],
            'subtotal_eventos' => 0,
            'subtotal_jugadores' => 0
        ];
    }
    if (!isset($por_entidad[$entidad_id]['organizaciones'][$org_id])) {
        $por_entidad[$entidad_id]['organizaciones'][$org_id] = [
            'org_nombre' => $org_nombre,
            'torneos' => [],
            'subtotal_eventos' => 0,
            'subtotal_jugadores' => 0
        ];
    }
    $por_entidad[$entidad_id]['organizaciones'][$org_id]['torneos'][] = $tor;
    $por_entidad[$entidad_id]['organizaciones'][$org_id]['subtotal_eventos']++;
    $por_entidad[$entidad_id]['organizaciones'][$org_id]['subtotal_jugadores'] += $jugadores;
    $por_entidad[$entidad_id]['subtotal_eventos']++;
    $por_entidad[$entidad_id]['subtotal_jugadores'] += $jugadores;
    $total_general_eventos++;
    $total_general_jugadores += $jugadores;
}

// Ordenar torneos por fecha (desc) dentro de cada organización
foreach ($por_entidad as &$ent) {
    foreach ($ent['organizaciones'] as &$org) {
        usort($org['torneos'], function ($a, $b) {
            $da = strtotime($a['fechator'] ?? '');
            $db = strtotime($b['fechator'] ?? '');
            return $db <=> $da;
        });
    }
}
unset($ent, $org);

// Ordenar entidades por nombre; organizaciones por nombre
uasort($por_entidad, function ($a, $b) {
    return strcasecmp($a['nombre'], $b['nombre']);
});
foreach ($por_entidad as &$ent) {
    uasort($ent['organizaciones'], function ($a, $b) {
        return strcasecmp($a['org_nombre'], $b['org_nombre']);
    });
}
unset($ent);
?>

<div class="container-fluid ds-estadisticas-torneos-13">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-chart-line me-2"></i>Estadísticas de Torneos</h1>
            <p class="text-muted mb-0">Reporte jerárquico: Entidad → Organización → Torneos (nombre, fecha, rondas, participantes)</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if (empty($torneos)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No hay torneos realizados registrados.
        </div>
    <?php else: ?>
        <!-- Resumen general -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h2 class="text-primary mb-0"><?= number_format($total_general_eventos) ?></h2>
                        <p class="text-muted mb-0">Total torneos realizados</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h2 class="text-success mb-0"><?= number_format($total_general_jugadores) ?></h2>
                        <p class="text-muted mb-0">Total participantes</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h2 class="text-info mb-0"><?= count($por_entidad) ?></h2>
                        <p class="text-muted mb-0">Entidades con torneos</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla jerárquica: Entidad → Organización → Subtotal (rompe control) → Detalles torneo -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>Detalle por Entidad y Organización</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Entidad</th>
                                <th>Organización</th>
                                <th>Nombre del Torneo</th>
                                <th>Fecha</th>
                                <th class="text-center">Rondas</th>
                                <th class="text-center">Participantes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $ent_actual = null;
                            $org_actual = null;
                            ?>
                            <?php foreach ($por_entidad as $entidad_id => $ent_data): 
                                $ent_nombre = $ent_data['nombre'];
                                $st_ent_eventos = 0;
                                $st_ent_jugadores = 0;
                                foreach ($ent_data['organizaciones'] as $org_id => $data_org):
                                    $org_nombre = $data_org['org_nombre'];
                                    $show_ent = ($ent_actual !== $entidad_id);
                                    $show_org = ($ent_actual !== $entidad_id || $org_actual !== $org_id);
                                    if ($show_ent) $ent_actual = $entidad_id;
                                    if ($show_org) $org_actual = $org_id;
                                    foreach ($data_org['torneos'] as $ev):
                                        $fecha_fmt = !empty($ev['fechator']) ? date('d/m/Y', strtotime($ev['fechator'])) : '—';
                                        $rondas = (int)($ev['rondas'] ?? 0);
                                        $participantes = (int)($ev['total_jugadores'] ?? 0);
                                        ?>
                                        <tr>
                                            <td><?= $show_ent ? '<strong>' . htmlspecialchars($ent_nombre) . '</strong>' : '' ?></td>
                                            <td><?= $show_org ? htmlspecialchars($org_nombre) : '' ?></td>
                                            <td><?= htmlspecialchars($ev['nombre']) ?></td>
                                            <td><?= htmlspecialchars($fecha_fmt) ?></td>
                                            <td class="text-center"><?= $rondas ?></td>
                                            <td class="text-center"><?= number_format($participantes) ?></td>
                                        </tr>
                                        <?php
                                        $show_ent = false;
                                        $show_org = false;
                                    endforeach;
                                    
                                    // Subtotal por Organización (rompe control)
                                    ?>
                                    <tr class="table-info">
                                        <td colspan="2"></td>
                                        <td colspan="2"><em>Subtotal Organización: <?= htmlspecialchars($data_org['org_nombre']) ?></em></td>
                                        <td class="text-center">—</td>
                                        <td class="text-center"><strong><?= number_format($data_org['subtotal_jugadores']) ?></strong> (<?= $data_org['subtotal_eventos'] ?> torneos)</td>
                                    </tr>
                                    <?php
                                    $st_ent_eventos += $data_org['subtotal_eventos'];
                                    $st_ent_jugadores += $data_org['subtotal_jugadores'];
                                endforeach;
                                ?>
                                <!-- Subtotal por Entidad -->
                                <tr class="table-secondary">
                                    <td colspan="2"></td>
                                    <td colspan="2"><strong>Subtotal Entidad: <?= htmlspecialchars($ent_nombre) ?></strong></td>
                                    <td class="text-center">—</td>
                                    <td class="text-center"><strong><?= number_format($st_ent_jugadores) ?></strong> (<?= $st_ent_eventos ?> torneos)</td>
                                </tr>
                                <?php
                                $ent_actual = null;
                                $org_actual = null;
                            endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="4">TOTAL GENERAL</th>
                                <th class="text-center">—</th>
                                <th class="text-center"><?= number_format($total_general_jugadores) ?> participantes (<?= number_format($total_general_eventos) ?> torneos)</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
