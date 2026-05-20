<?php
/**
 * Vista de detalle completo del afiliado
 * Muestra información personal, torneos participados, resultados, etc.
 */

if (!defined('APP_BOOTSTRAPPED')) { 
    require_once __DIR__ . '/../../config/bootstrap.php'; 
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

$current_user = Auth::user();
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : null;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

if (!$club_id || !$user_id) {
    header('Location: index.php?page=clubs&error=' . urlencode('Parámetros inválidos'));
    exit;
}

// Verificar permisos para admin_club
if ($current_user['role'] === 'admin_club') {
    if (!ClubHelper::isClubSupervised($current_user['club_id'], $club_id)) {
        header('Location: index.php?page=clubs&error=' . urlencode('No tiene permisos para ver este afiliado'));
        exit;
    }
} else {
    Auth::requireRole(['admin_general', 'admin_torneo']);
}

try {
    // Obtener datos del club
    $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id = ?");
    $stmt->execute([$club_id]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$club) {
        header('Location: index.php?page=clubs&error=' . urlencode('Club no encontrado'));
        exit;
    }
    
    [$scopeSql, $scopeParams] = ClubHelper::afiliadosMatchSqlAndParams(DB::pdo(), $club, $club_id);

    $stmt = DB::pdo()->prepare("
        SELECT u.* FROM usuarios u
        WHERE u.id = ?
          AND {$scopeSql}
    ");
    $stmt->execute(array_merge([$user_id], $scopeParams));
    $afiliado_detail = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$afiliado_detail) {
        header('Location: index.php?page=clubs&action=detail&id=' . $club_id . '&error=' . urlencode('Afiliado no encontrado'));
        exit;
    }
    
    // Obtener torneos del afiliado con detalles completos
    $stmt = DB::pdo()->prepare("
        SELECT 
            i.*,
            t.id as torneo_id,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.costo as torneo_costo,
            t.modalidad as torneo_modalidad,
            t.clase as torneo_clase,
            t.rondas as torneo_rondas,
            t.puntos as torneo_puntos,
            c.nombre as club_organizador,
            (SELECT COUNT(*) FROM inscripciones WHERE torneo_id = t.id) as total_inscritos_torneo,
            (SELECT COUNT(*) FROM inscripciones WHERE torneo_id = t.id AND sexo = 'M') as hombres_torneo,
            (SELECT COUNT(*) FROM inscripciones WHERE torneo_id = t.id AND sexo = 'F') as mujeres_torneo
        FROM inscripciones i
        INNER JOIN tournaments t ON i.torneo_id = t.id
        LEFT JOIN clubes c ON t.club_responsable = c.id
        WHERE i.cedula = ?
        ORDER BY t.fechator DESC
    ");
    $stmt->execute([$afiliado_detail['cedula']]);
    $afiliado_torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas
    $stats = [
        'total_torneos' => count($afiliado_torneos),
        'torneos_activos' => 0,
        'torneos_finalizados' => 0,
        'total_inscripciones' => 0
    ];
    
    foreach ($afiliado_torneos as $t) {
        if (strtotime($t['torneo_fecha']) >= strtotime('today')) {
            $stats['torneos_activos']++;
        } else {
            $stats['torneos_finalizados']++;
        }
        $stats['total_inscripciones']++;
    }
    
} catch (Exception $e) {
    header('Location: index.php?page=clubs&error=' . urlencode('Error al cargar datos: ' . $e->getMessage()));
    exit;
}

$from_page_af = isset($_GET['from']) ? (string) $_GET['from'] : null;
$from_admin_id_af = isset($_GET['admin_id']) ? (int) $_GET['admin_id'] : null;
$genero_af = isset($_GET['genero']) ? (string) $_GET['genero'] : null;
$back_to_club = 'index.php?page=clubs&action=detail&id=' . (int) $club['id'];
if ($from_page_af !== null && $from_page_af !== '') {
    $back_to_club .= '&from=' . urlencode($from_page_af);
}
if ($from_admin_id_af > 0) {
    $back_to_club .= '&admin_id=' . $from_admin_id_af;
}
if ($genero_af !== null && $genero_af !== '') {
    $back_to_club .= '&genero=' . urlencode(strtoupper($genero_af));
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="<?= htmlspecialchars($back_to_club) ?>" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="fas fa-arrow-left me-1"></i>Volver al Club
            </a>
            <h2 class="mb-0">
                <i class="fas fa-user me-2"></i><?= htmlspecialchars($afiliado_detail['nombre']) ?>
            </h2>
            <small class="text-muted">Detalle Completo del Afiliado</small>
        </div>
    </div>
    
    <!-- Información Personal -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Información Personal</h5>
                </div>
                <div class="card-body">
                    <p><strong>Nombre:</strong> <?= htmlspecialchars($afiliado_detail['nombre']) ?></p>
                    <p><strong>Sexo:</strong> 
                        <?php if ($afiliado_detail['sexo'] === 'M'): ?>
                            <span class="badge bg-primary">Masculino</span>
                        <?php elseif ($afiliado_detail['sexo'] === 'F'): ?>
                            <span class="badge bg-danger">Femenino</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars($afiliado_detail['sexo'] ?? 'N/A') ?></span>
                        <?php endif; ?>
                    </p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($afiliado_detail['email'] ?? 'N/A') ?></p>
                    <p><strong>Celular:</strong> <?= htmlspecialchars($afiliado_detail['celular'] ?? 'N/A') ?></p>
                    <p><strong>Club:</strong> <?= htmlspecialchars($club['nombre']) ?></p>
                    <p><strong>Registrado:</strong> <?= date('d/m/Y', strtotime($afiliado_detail['created_at'])) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Estadísticas</h5>
                </div>
                <div class="card-body">
                    <p><strong>Total de Torneos:</strong> <span class="badge bg-primary fs-6"><?= $stats['total_torneos'] ?></span></p>
                    <p><strong>Torneos Activos:</strong> <span class="badge bg-success"><?= $stats['torneos_activos'] ?></span></p>
                    <p><strong>Torneos Finalizados:</strong> <span class="badge bg-secondary"><?= $stats['torneos_finalizados'] ?></span></p>
                    <p><strong>Total Inscripciones:</strong> <span class="badge bg-info"><?= $stats['total_inscripciones'] ?></span></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Torneos del Afiliado -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Torneos Participados (<?= count($afiliado_torneos) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($afiliado_torneos)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-trophy fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Este afiliado no ha participado en ningún torneo aún</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Torneo</th>
                                <th>Club Organizador</th>
                                <th>Costo</th>
                                <th>Identificador</th>
                                <th>Categoría</th>
                                <th>Total Inscritos</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($afiliado_torneos as $torneo): ?>
                                <?php 
                                $es_futuro = strtotime($torneo['torneo_fecha']) >= strtotime('today');
                                $es_pasado = strtotime($torneo['torneo_fecha']) < strtotime('today');
                                ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($torneo['torneo_fecha'])) ?></td>
                                    <td><strong><?= htmlspecialchars($torneo['torneo_nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($torneo['club_organizador'] ?? 'N/A') ?></td>
                                    <td>$<?= number_format($torneo['torneo_costo'] ?? 0, 0) ?></td>
                                    <td><span class="badge bg-info"><?= $torneo['identificador'] ?? 'N/A' ?></span></td>
                                    <td><?= htmlspecialchars($torneo['categ'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?= (int)($torneo['total_inscritos_torneo'] ?? 0) ?></span>
                                        <small class="text-muted">
                                            (<?= (int)($torneo['hombres_torneo'] ?? 0) ?>H / <?= (int)($torneo['mujeres_torneo'] ?? 0) ?>M)
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($es_futuro): ?>
                                            <span class="badge bg-success">Próximo</span>
                                        <?php elseif ($es_pasado): ?>
                                            <span class="badge bg-secondary">Finalizado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Hoy</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?page=tournaments&action=view&id=<?= $torneo['torneo_id'] ?>" 
                                           class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-eye me-1"></i>Ver Torneo
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

