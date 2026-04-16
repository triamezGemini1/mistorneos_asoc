<?php
/**
 * Página Pública de Información del Torneo
 * Muestra información accesible para atletas inscritos
 * - Incidencias de cada ronda
 * - Asignación de mesas individual
 * - Resumen del atleta
 * - Listado general del evento
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';

$pdo = DB::pdo();
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org) AND o.estatus = 1"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id AND o.estatus = 1";
$wRegPInc = PartiresulEstatusSql::whereRegistradoUno('p');
$wFfPInc = PartiresulEstatusSql::whereFfUno('p');
$base_url = app_base_url();

// Obtener parámetros
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$cedula = isset($_GET['cedula']) ? trim($_GET['cedula']) : '';
$seccion = isset($_GET['seccion']) ? $_GET['seccion'] : 'general'; // general, mesas, resumen, incidencias

if ($torneo_id <= 0) {
    die('Torneo no válido');
}

// Obtener información del torneo
$torneo_data = null;
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            o.nombre as club_nombre,
            o.responsable as club_delegado,
            o.telefono as club_telefono
        FROM tournaments t
        {$org_join}
        WHERE t.id = ? AND t.estatus = 1
    ");
    $stmt->execute([$torneo_id]);
    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo_data) {
        die('Torneo no encontrado');
    }
} catch (Exception $e) {
    die('Error al cargar información del torneo');
}

// Verificar si existe la tabla partiresul
$tabla_partiresul_existe = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'partiresul'");
    $tabla_partiresul_existe = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Tabla no existe
}

// Obtener datos según la sección
$rondas = [];
$mesas_jugador = [];
$resumen_jugador = [];
$listado_general = [];
$incidencias_rondas = [];

if ($tabla_partiresul_existe) {
    // Obtener lista de rondas
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT partida as ronda
            FROM partiresul
            WHERE id_torneo = ?
            ORDER BY partida ASC
        ");
        $stmt->execute([$torneo_id]);
        $rondas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Error obteniendo rondas: " . $e->getMessage());
    }
    
    // Si hay cédula, obtener información del jugador
    if (!empty($cedula)) {
        // Obtener ID del usuario
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? LIMIT 1");
        $stmt->execute([$cedula]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario) {
            $usuario_id = $usuario['id'];
            
            // Asignación de mesas del jugador
            if ($seccion === 'mesas' || $seccion === 'general') {
                $stmt = $pdo->prepare("
                    SELECT 
                        p.partida as ronda,
                        p.mesa,
                        p.secuencia,
                        CASE 
                            WHEN p.secuencia IN (1, 2) THEN 'Equipo 1'
                            WHEN p.secuencia IN (3, 4) THEN 'Equipo 2'
                            ELSE 'N/A'
                        END as equipo,
                        (SELECT COUNT(*) FROM partiresul p2 
                         WHERE p2.id_torneo = p.id_torneo 
                         AND p2.partida = p.partida 
                         AND p2.mesa = p.mesa) as total_jugadores_mesa
                    FROM partiresul p
                    WHERE p.id_torneo = ? AND p.id_usuario = ?
                    ORDER BY p.partida ASC, p.mesa ASC
                ");
                $stmt->execute([$torneo_id, $usuario_id]);
                $mesas_jugador = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Resumen del jugador
            if ($seccion === 'resumen' || $seccion === 'general') {
                require_once __DIR__ . '/../lib/InscritosPartiresulHelper.php';
                $resumen_jugador = InscritosPartiresulHelper::obtenerEstadisticas($usuario_id, $torneo_id);
                
                // Obtener información del inscrito
                $stmt = $pdo->prepare("
                    SELECT i.*, u.nombre, u.cedula, c.nombre as club_nombre
                    FROM inscritos i
                    LEFT JOIN usuarios u ON i.id_usuario = u.id
                    LEFT JOIN clubes c ON i.id_club = c.id
                    WHERE i.torneo_id = ? AND i.id_usuario = ?
                    LIMIT 1
                ");
                $stmt->execute([$torneo_id, $usuario_id]);
                $inscrito_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($inscrito_info) {
                    $resumen_jugador['nombre'] = $inscrito_info['nombre'];
                    $resumen_jugador['cedula'] = $inscrito_info['cedula'];
                    $resumen_jugador['club'] = $inscrito_info['club_nombre'];
                    $resumen_jugador['puntos'] = $inscrito_info['puntos'] ?? 0;
                    $resumen_jugador['efectividad'] = $inscrito_info['efectividad'] ?? 0;
                    $resumen_jugador['ptosrnk'] = $inscrito_info['ptosrnk'] ?? 0;
                }
            }
        }
    }
    
    // Incidencias de rondas
    if ($seccion === 'incidencias' || $seccion === 'general') {
        foreach ($rondas as $ronda) {
            $stmt = $pdo->prepare("
                SELECT 
                    p.partida as ronda,
                    p.mesa,
                    COUNT(*) as total_partidas,
                    COUNT(CASE WHEN {$wRegPInc} THEN 1 END) as partidas_registradas,
                    COUNT(CASE WHEN {$wFfPInc} THEN 1 END) as forfaits
                FROM partiresul p
                WHERE p.id_torneo = ? AND p.partida = ?
                GROUP BY p.partida, p.mesa
                ORDER BY p.mesa ASC
            ");
            $stmt->execute([$torneo_id, $ronda]);
            $incidencias_rondas[$ronda] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Listado general del evento (inscritos confirmados: estatus 1, 2, 'confirmado', 'solvente')
if ($seccion === 'general' || $seccion === 'listado') {
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            COALESCE(u.nombre, u.username) as nombre_jugador,
            u.cedula,
            c.nombre as club_nombre
        FROM inscritos i
        LEFT JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ?
        AND (i.estatus IN (1, 2, '1', '2', 'confirmado', 'solvente'))
        ORDER BY i.ptosrnk DESC, i.efectividad DESC, i.ganados DESC
    ");
    $stmt->execute([$torneo_id]);
    $listado_general = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Abierto', 2 => 'Por Categorías'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#1a365d">
    <title>Información del Torneo - <?= htmlspecialchars($torneo_data['nombre']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .main-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 1400px;
        }
        .header {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 2rem;
        }
        .nav-tabs .nav-link {
            color: #1a365d;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            background: #1a365d;
            color: white;
            border-color: #1a365d;
        }
        @media (max-width: 576px) {
            .container { padding-left: 0.5rem; padding-right: 0.5rem; }
            .table { font-size: 0.875rem; }
            .nav-tabs .nav-link { padding: 0.5rem 0.4rem; font-size: 0.8rem; }
        }
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-card mx-auto">
            <div class="header text-center">
                <?= AppHelpers::appLogo('mb-3', 'La Estación del Dominó', 48) ?>
                <h2 class="mb-0"><?= htmlspecialchars($torneo_data['nombre']) ?></h2>
                <p class="mb-0 mt-2 opacity-75">Información del Evento</p>
            </div>
            
            <div class="p-4">
                <a href="perfil_jugador.php?torneo_id=<?= (int)$torneo_id ?>" class="btn btn-outline-light mb-3 d-inline-flex align-items-center" style="font-size: 1rem;"><i class="fas fa-arrow-left me-2"></i>Retorno</a>
                <!-- Información del Torneo -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-calendar me-2"></i>Fecha:</strong> <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></p>
                                <?php if ($torneo_data['lugar']): ?>
                                    <p><strong><i class="fas fa-map-marker-alt me-2"></i>Lugar:</strong> <?= htmlspecialchars($torneo_data['lugar']) ?></p>
                                <?php endif; ?>
                                <p><strong><i class="fas fa-building me-2"></i>Club:</strong> <?= htmlspecialchars($torneo_data['club_nombre'] ?? 'N/A') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-users me-2"></i>Modalidad:</strong> <?= $modalidades[$torneo_data['modalidad']] ?? 'N/A' ?></p>
                                <p><strong><i class="fas fa-tag me-2"></i>Clase:</strong> <?= $clases[$torneo_data['clase']] ?? 'N/A' ?></p>
                                <?php if ($torneo_data['club_delegado']): ?>
                                    <p><strong><i class="fas fa-user-tie me-2"></i>Delegado:</strong> <?= htmlspecialchars($torneo_data['club_delegado']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Navegación por Tabs -->
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?= $seccion === 'general' ? 'active' : '' ?>" 
                           href="?torneo_id=<?= $torneo_id ?>&seccion=general<?= $cedula ? '&cedula=' . urlencode($cedula) : '' ?>">
                            <i class="fas fa-list me-2"></i>Listado General
                        </a>
                    </li>
                    <?php if (!empty($cedula)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $seccion === 'mesas' ? 'active' : '' ?>" 
                           href="?torneo_id=<?= $torneo_id ?>&seccion=mesas&cedula=<?= urlencode($cedula) ?>">
                            <i class="fas fa-table me-2"></i>Mis Mesas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $seccion === 'resumen' ? 'active' : '' ?>" 
                           href="?torneo_id=<?= $torneo_id ?>&seccion=resumen&cedula=<?= urlencode($cedula) ?>">
                            <i class="fas fa-chart-line me-2"></i>Mi Resumen
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $seccion === 'incidencias' ? 'active' : '' ?>" 
                           href="?torneo_id=<?= $torneo_id ?>&seccion=incidencias<?= $cedula ? '&cedula=' . urlencode($cedula) : '' ?>">
                            <i class="fas fa-info-circle me-2"></i>Incidencias por Ronda
                        </a>
                    </li>
                </ul>
                
                <!-- Contenido según sección -->
                <div class="tab-content">
                    <!-- Listado General -->
                    <?php if ($seccion === 'general' || $seccion === 'listado'): ?>
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Listado General de Participantes</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($listado_general)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>No hay participantes inscritos aún.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Jugador</th>
                                                    <th>Cédula</th>
                                                    <th>Club</th>
                                                    <th class="text-center">Puntos</th>
                                                    <th class="text-center">Ranking</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $posicion = 1; foreach ($listado_general as $jugador): ?>
                                                    <tr>
                                                        <td><?= $posicion++ ?></td>
                                                        <td><?= htmlspecialchars($jugador['nombre_jugador'] ?? 'N/A') ?></td>
                                                        <td><?= htmlspecialchars($jugador['cedula'] ?? 'N/A') ?></td>
                                                        <td><?= htmlspecialchars($jugador['club_nombre'] ?? 'Sin club') ?></td>
                                                        <td class="text-center"><strong><?= (int)($jugador['puntos'] ?? 0) ?></strong></td>
                                                        <td class="text-center"><span class="badge bg-primary"><?= (int)($jugador['ptosrnk'] ?? 0) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Asignación de Mesas -->
                    <?php if ($seccion === 'mesas' && !empty($cedula)): ?>
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Asignación de Mesas</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($mesas_jugador)): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>No hay asignaciones de mesas disponibles aún.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-info">
                                                <tr>
                                                    <th>Ronda</th>
                                                    <th>Mesa</th>
                                                    <th>Equipo</th>
                                                    <th>Secuencia</th>
                                                    <th>Jugadores en Mesa</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($mesas_jugador as $mesa): ?>
                                                    <tr>
                                                        <td><strong>Ronda #<?= $mesa['ronda'] ?></strong></td>
                                                        <td><span class="badge bg-primary">Mesa #<?= $mesa['mesa'] ?></span></td>
                                                        <td><?= htmlspecialchars($mesa['equipo']) ?></td>
                                                        <td><?= $mesa['secuencia'] ?></td>
                                                        <td><?= $mesa['total_jugadores_mesa'] ?> jugadores</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Resumen del Jugador -->
                    <?php if ($seccion === 'resumen' && !empty($cedula)): ?>
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Mi Resumen</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($resumen_jugador) || !isset($resumen_jugador['nombre'])): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>No se encontró información para esta cédula.
                                    </div>
                                <?php else: ?>
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <h6>Información Personal</h6>
                                            <p><strong>Nombre:</strong> <?= htmlspecialchars($resumen_jugador['nombre']) ?></p>
                                            <p><strong>Cédula:</strong> <?= htmlspecialchars($resumen_jugador['cedula']) ?></p>
                                            <p><strong>Club:</strong> <?= htmlspecialchars($resumen_jugador['club'] ?? 'Sin club') ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Estadísticas</h6>
                                            <div class="row">
                                                <div class="col-6 mb-3">
                                                    <div class="text-center p-3 bg-primary text-white rounded">
                                                        <h3 class="mb-0"><?= $resumen_jugador['total_partidas'] ?? 0 ?></h3>
                                                        <small>Partidas</small>
                                                    </div>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <div class="text-center p-3 bg-success text-white rounded">
                                                        <h3 class="mb-0"><?= $resumen_jugador['ganados'] ?? 0 ?></h3>
                                                        <small>Ganadas</small>
                                                    </div>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <div class="text-center p-3 bg-danger text-white rounded">
                                                        <h3 class="mb-0"><?= $resumen_jugador['perdidos'] ?? 0 ?></h3>
                                                        <small>Perdidas</small>
                                                    </div>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <div class="text-center p-3 bg-warning text-white rounded">
                                                        <h3 class="mb-0"><?= (int)($resumen_jugador['efectividad'] ?? 0) ?></h3>
                                                        <small>Efectividad</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <p><strong>Puntos:</strong> <span class="badge bg-primary fs-6"><?= (int)($resumen_jugador['puntos'] ?? 0) ?></span></p>
                                                <p><strong>Ranking:</strong> <span class="badge bg-success fs-6"><?= (int)($resumen_jugador['ptosrnk'] ?? 0) ?></span></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Incidencias por Ronda -->
                    <?php if ($seccion === 'incidencias'): ?>
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Incidencias por Ronda</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($rondas)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>No hay rondas generadas aún.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($rondas as $ronda): ?>
                                        <div class="card mb-3">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0">Ronda #<?= $ronda ?></h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Mesa</th>
                                                                <th class="text-center">Total Partidas</th>
                                                                <th class="text-center">Registradas</th>
                                                                <th class="text-center">Forfaits</th>
                                                                <th class="text-center">Estado</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (isset($incidencias_rondas[$ronda])): ?>
                                                                <?php foreach ($incidencias_rondas[$ronda] as $incidencia): ?>
                                                                    <tr>
                                                                        <td><strong>Mesa #<?= $incidencia['mesa'] ?></strong></td>
                                                                        <td class="text-center"><?= $incidencia['total_partidas'] ?></td>
                                                                        <td class="text-center">
                                                                            <span class="badge bg-success"><?= $incidencia['partidas_registradas'] ?></span>
                                                                        </td>
                                                                        <td class="text-center">
                                                                            <?php if ($incidencia['forfaits'] > 0): ?>
                                                                                <span class="badge bg-danger"><?= $incidencia['forfaits'] ?></span>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-secondary">0</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-center">
                                                                            <?php if ($incidencia['partidas_registradas'] == $incidencia['total_partidas']): ?>
                                                                                <span class="badge bg-success">Completa</span>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-warning">Pendiente</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <tr>
                                                                    <td colspan="5" class="text-center text-muted">No hay información disponible</td>
                                                                </tr>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>




