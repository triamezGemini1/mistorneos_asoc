<?php
/**
 * Gestión de Reportes de Pago de Usuarios
 * Permite a administradores revisar, confirmar o rechazar los reportes de pago de usuarios individuales
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/BankValidator.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$pdo = DB::pdo();
$user = Auth::user();
$user_club_id = Auth::getUserClubId();
$action = $_GET['action'] ?? 'list';
$reporte_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = '';
$success = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validate();
    
    $accion = $_POST['accion'] ?? '';
    $reporte_id_post = (int)($_POST['reporte_id'] ?? 0);
    
    if ($reporte_id_post > 0) {
        try {
            // Obtener datos del reporte y verificar que admin_club solo pueda actuar sobre sus torneos
            $stmt = $pdo->prepare("
                SELECT rpu.*, t.club_responsable
                FROM reportes_pago_usuarios rpu
                INNER JOIN tournaments t ON rpu.torneo_id = t.id
                WHERE rpu.id = ?
            ");
            $stmt->execute([$reporte_id_post]);
            $reporte_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // admin_club solo puede confirmar/rechazar reportes de torneos de su club
            if ($reporte_data && $user['role'] === 'admin_club' && $user_club_id) {
                if ((int)($reporte_data['club_responsable'] ?? 0) !== (int)$user_club_id) {
                    $reporte_data = null;
                    $error = 'No tienes permiso para gestionar este reporte de pago';
                }
            }
            
            if ($reporte_data) {
                if ($accion === 'confirmar') {
                    $id_usuario = (int) ($reporte_data['id_usuario'] ?? 0);
                    $torneo_id = (int) ($reporte_data['torneo_id'] ?? 0);
                    $inscrito_id = (int) ($reporte_data['inscrito_id'] ?? 0);
                    require_once __DIR__ . '/../lib/InscripcionPagoService.php';
                    if ($inscrito_id <= 0 && $id_usuario > 0 && $torneo_id > 0) {
                        $stI = $pdo->prepare('SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? LIMIT 1');
                        $stI->execute([$id_usuario, $torneo_id]);
                        $inscrito_id = (int) ($stI->fetchColumn() ?: 0);
                    }
                    if ($inscrito_id > 0 && $torneo_id > 0) {
                        $resVal = InscripcionPagoService::validarPagoInscripcion($pdo, $inscrito_id, $torneo_id);
                        $success = $resVal['ok'] ? $resVal['message'] : $resVal['message'];
                        if (!$resVal['ok']) {
                            $error = $resVal['message'];
                        }
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE reportes_pago_usuarios 
                            SET estatus = 'confirmado', updated_at = NOW() 
                            WHERE id = ?
                        ");
                        $stmt->execute([$reporte_id_post]);
                        $success = 'Reporte de pago confirmado exitosamente';
                    }
                } elseif ($accion === 'rechazar') {
                    $stmt = $pdo->prepare("
                        UPDATE reportes_pago_usuarios 
                        SET estatus = 'rechazado', updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$reporte_id_post]);
                    $success = 'Reporte de pago rechazado';
                }
            }
        } catch (Exception $e) {
            $error = 'Error al procesar la acción: ' . $e->getMessage();
        }
    }
}

// Obtener filtros. Acceso desde panel de control: torneo_id obligatorio (sin selector).
$filtro_estatus = $_GET['estatus'] ?? 'todos';
$filtro_torneo = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;

if ($filtro_torneo <= 0) {
    $dashboard = class_exists('AppHelpers') ? (AppHelpers::dashboard('home') ?? 'index.php') : 'index.php';
    header('Location: ' . $dashboard . '?error=' . urlencode('Acceda a Reportes de Pago desde el Panel de Control de un torneo (evento masivo).'));
    exit;
}
if (!Auth::canAccessTournament($filtro_torneo)) {
    $dashboard = class_exists('AppHelpers') ? (AppHelpers::dashboard('home') ?? 'index.php') : 'index.php';
    header('Location: ' . $dashboard . '?error=' . urlencode('No tiene permiso para este torneo.'));
    exit;
}

// Construir consulta
$where = [];
$params = [];

if ($filtro_estatus !== 'todos') {
    $where[] = "rpu.estatus = ?";
    $params[] = $filtro_estatus;
}

if ($filtro_torneo > 0) {
    $where[] = "rpu.torneo_id = ?";
    $params[] = $filtro_torneo;
}

// admin_club solo ve reportes de torneos de su club (si no tiene club_id, no ve nada)
if ($user['role'] === 'admin_club') {
    if ($user_club_id) {
        $where[] = "t.club_responsable = ?";
        $params[] = $user_club_id;
    } else {
        $where[] = "1 = 0"; // Sin club asignado: no mostrar reportes
    }
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Obtener reportes de pago
$reportes = [];
try {
    $sql = "
        SELECT 
            rpu.*,
            u.id as usuario_id,
            u.nombre as usuario_nombre,
            u.cedula as usuario_cedula,
            t.nombre as torneo_nombre,
            t.fechator as torneo_fecha,
            t.costo as torneo_costo,
            t.cuenta_id,
            cb.banco as cuenta_banco,
            cb.numero_cuenta as cuenta_numero,
            cb.tipo_cuenta as cuenta_tipo,
            cb.telefono_afiliado as cuenta_telefono,
            cb.nombre_propietario as cuenta_propietario
        FROM reportes_pago_usuarios rpu
        INNER JOIN usuarios u ON rpu.id_usuario = u.id
        INNER JOIN tournaments t ON rpu.torneo_id = t.id
        LEFT JOIN cuentas_bancarias cb ON t.cuenta_id = cb.id
        $where_sql
        ORDER BY rpu.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo reportes de pago: " . $e->getMessage());
    $error = 'Error al cargar los reportes de pago';
}

// Obtener torneos para filtro (admin_club solo ve torneos de su club)
$torneos = [];
try {
    $torneos_sql = "
        SELECT DISTINCT t.id, t.nombre 
        FROM tournaments t
        INNER JOIN reportes_pago_usuarios rpu ON t.id = rpu.torneo_id
        WHERE t.es_evento_masivo = 1
    ";
    $torneos_params = [];
    if ($user['role'] === 'admin_club' && $user_club_id) {
        $torneos_sql .= " AND t.club_responsable = ?";
        $torneos_params[] = $user_club_id;
    } elseif ($user['role'] === 'admin_club' && !$user_club_id) {
        $torneos_sql .= " AND 1 = 0"; // Sin club: no hay torneos
    }
    $torneos_sql .= " ORDER BY t.nombre ASC";
    $stmt = $pdo->prepare($torneos_sql);
    $stmt->execute($torneos_params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo torneos: " . $e->getMessage());
}

// Estadísticas
$stats = [
    'total' => count($reportes),
    'pendientes' => count(array_filter($reportes, fn($r) => $r['estatus'] === 'pendiente')),
    'confirmados' => count(array_filter($reportes, fn($r) => $r['estatus'] === 'confirmado')),
    'rechazados' => count(array_filter($reportes, fn($r) => $r['estatus'] === 'rechazado')),
    'monto_total' => array_sum(array_column(array_filter($reportes, fn($r) => $r['estatus'] === 'confirmado'), 'monto'))
];

$csrf_token = CSRF::token();
?>
<div class="fade-in">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-money-bill-wave me-2"></i>
                Reportes de Pago de Usuarios
            </h1>
            <p class="text-muted mb-0">
                <?php if ($user['role'] === 'admin_club'): ?>
                Revisa los reportes de pago de tus torneos (eventos masivos)
                <?php else: ?>
                Revisa y gestiona los reportes de pago de usuarios en eventos masivos
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-info mb-2">
                        <i class="fas fa-list fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['total'] ?></h4>
                    <p class="text-muted mb-0">Total</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-warning mb-2">
                        <i class="fas fa-clock fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['pendientes'] ?></h4>
                    <p class="text-muted mb-0">Pendientes</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-success mb-2">
                        <i class="fas fa-check-circle fs-1"></i>
                    </div>
                    <h4 class="mb-1"><?= $stats['confirmados'] ?></h4>
                    <p class="text-muted mb-0">Confirmados</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="text-primary mb-2">
                        <i class="fas fa-dollar-sign fs-1"></i>
                    </div>
                    <h4 class="mb-1">$<?= number_format($stats['monto_total'], 2) ?></h4>
                    <p class="text-muted mb-0">Total Confirmado</p>
                </div>
            </div>
        </div>
    </div>

    <?php
    $torneo_nombre_actual = '';
    foreach ($torneos as $t) {
        if ((int)$t['id'] === $filtro_torneo) {
            $torneo_nombre_actual = $t['nombre'];
            break;
        }
    }
    if ($torneo_nombre_actual === '' && !empty($reportes[0]['torneo_nombre'])) {
        $torneo_nombre_actual = $reportes[0]['torneo_nombre'];
    }
    ?>
    <div class="alert alert-secondary py-2 mb-3">
        <i class="fas fa-trophy me-2"></i><strong>Torneo:</strong> <?= htmlspecialchars($torneo_nombre_actual ?: 'Torneo #' . $filtro_torneo) ?>
    </div>
    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="reportes_pago_usuarios">
                <input type="hidden" name="torneo_id" value="<?= (int)$filtro_torneo ?>">
                
                <div class="col-md-6">
                    <label class="form-label">Estado</label>
                    <select name="estatus" class="form-select">
                        <option value="todos" <?= $filtro_estatus === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendiente" <?= $filtro_estatus === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="confirmado" <?= $filtro_estatus === 'confirmado' ? 'selected' : '' ?>>Confirmados</option>
                        <option value="rechazado" <?= $filtro_estatus === 'rechazado' ? 'selected' : '' ?>>Rechazados</option>
                    </select>
                </div>
                
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Reportes -->
    <div class="card">
        <div class="card-header bg-dark text-warning fw-bold">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Lista de Reportes de Pago
                <span class="badge bg-light text-dark ms-2"><?= count($reportes) ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($reportes)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>ID Usuario</th>
                                <th>Torneo</th>
                                <th>Fecha/Hora Pago</th>
                                <th>Tipo</th>
                                <th>Banco</th>
                                <th>Monto</th>
                                <th>Referencia</th>
                                <th>Cantidad</th>
                                <th>Estado</th>
                                <th>Fecha Reporte</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportes as $reporte): ?>
                            <tr>
                                <td><strong>#<?= $reporte['id'] ?></strong></td>
                                <td>
                                    <i class="fas fa-user text-muted me-2"></i>
                                    <?= htmlspecialchars($reporte['usuario_nombre']) ?>
                                </td>
                                <td><code><?= $reporte['usuario_id'] ?></code></td>
                                <td>
                                    <i class="fas fa-trophy text-warning me-2"></i>
                                    <?= htmlspecialchars($reporte['torneo_nombre']) ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y', strtotime($reporte['fecha'])) ?><br>
                                    <small class="text-muted"><?= $reporte['hora'] ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= ucfirst($reporte['tipo_pago']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($reporte['banco'] ?? 'N/A') ?></td>
                                <td>
                                    <strong class="text-success">$<?= number_format($reporte['monto'], 2) ?></strong>
                                </td>
                                <td>
                                    <?php if ($reporte['referencia']): ?>
                                        <code><?= htmlspecialchars($reporte['referencia']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= $reporte['cantidad_inscritos'] ?> persona(s)</span>
                                </td>
                                <td>
                                    <?php
                                    $estatus_classes = [
                                        'pendiente' => 'bg-warning',
                                        'confirmado' => 'bg-success',
                                        'rechazado' => 'bg-danger'
                                    ];
                                    $estatus_texts = [
                                        'pendiente' => 'Pendiente',
                                        'confirmado' => 'Confirmado',
                                        'rechazado' => 'Rechazado'
                                    ];
                                    $class = $estatus_classes[$reporte['estatus']] ?? 'bg-secondary';
                                    $text = $estatus_texts[$reporte['estatus']] ?? 'Desconocido';
                                    ?>
                                    <span class="badge <?= $class ?>"><?= $text ?></span>
                                </td>
                                <td>
                                    <small><?= date('d/m/Y H:i', strtotime($reporte['created_at'])) ?></small>
                                </td>
                                <td>
                                    <?php if ($reporte['estatus'] === 'pendiente'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Confirmar este pago?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="reporte_id" value="<?= $reporte['id'] ?>">
                                            <input type="hidden" name="accion" value="confirmar">
                                            <button type="submit" class="btn btn-success btn-sm" title="Confirmar">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Rechazar este pago?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="reporte_id" value="<?= $reporte['id'] ?>">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Rechazar">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">
                                        <i class="fas fa-<?= $reporte['estatus'] === 'confirmado' ? 'check-circle text-success' : 'times-circle text-danger' ?>"></i>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <!-- Botón para ver detalles -->
                                    <button type="button" class="btn btn-info btn-sm" 
                                            onclick="verDetalles(<?= htmlspecialchars(json_encode($reporte)) ?>)"
                                            title="Ver detalles">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox text-muted fs-1 mb-3"></i>
                    <h5 class="text-muted">No hay reportes de pago</h5>
                    <p class="text-muted">Los reportes de pago aparecerán aquí cuando los usuarios los envíen</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal para ver detalles -->
<div class="modal fade" id="modalDetalles" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Detalles del Reporte de Pago
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDetallesBody">
                <!-- Contenido dinámico -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
function verDetalles(reporte) {
    const modal = new bootstrap.Modal(document.getElementById('modalDetalles'));
    const body = document.getElementById('modalDetallesBody');
    
    const estatusBadge = {
        'pendiente': '<span class="badge bg-warning">Pendiente</span>',
        'confirmado': '<span class="badge bg-success">Confirmado</span>',
        'rechazado': '<span class="badge bg-danger">Rechazado</span>'
    };
    
    body.innerHTML = `
        <div class="row g-3">
            <div class="col-md-6">
                <strong>ID Reporte:</strong><br>
                <code>#${reporte.id}</code>
            </div>
            <div class="col-md-6">
                <strong>Estado:</strong><br>
                ${estatusBadge[reporte.estatus] || ''}
            </div>
            <div class="col-md-6">
                <strong>Usuario:</strong><br>
                ${reporte.usuario_nombre}<br>
                <small class="text-muted">ID: ${reporte.usuario_id} | Cédula: ${reporte.usuario_cedula}</small>
            </div>
            <div class="col-md-6">
                <strong>Torneo:</strong><br>
                ${reporte.torneo_nombre}<br>
                <small class="text-muted">Fecha: ${new Date(reporte.torneo_fecha).toLocaleDateString('es-ES')}</small>
            </div>
            <div class="col-md-6">
                <strong>Cantidad de Inscritos:</strong><br>
                <span class="badge bg-secondary">${reporte.cantidad_inscritos} persona(s)</span>
            </div>
            <div class="col-md-6">
                <strong>Monto:</strong><br>
                <span class="h5 text-success">$${parseFloat(reporte.monto).toFixed(2)}</span>
            </div>
            <div class="col-md-6">
                <strong>Fecha del Pago:</strong><br>
                ${new Date(reporte.fecha).toLocaleDateString('es-ES')}
            </div>
            <div class="col-md-6">
                <strong>Hora del Pago:</strong><br>
                ${reporte.hora}
            </div>
            <div class="col-md-6">
                <strong>Tipo de Pago:</strong><br>
                <span class="badge bg-info">${reporte.tipo_pago.charAt(0).toUpperCase() + reporte.tipo_pago.slice(1)}</span>
            </div>
            <div class="col-md-6">
                <strong>Banco:</strong><br>
                ${reporte.banco || 'N/A'}
            </div>
            <div class="col-12">
                <strong>Referencia:</strong><br>
                ${reporte.referencia ? '<code>' + reporte.referencia + '</code>' : '<span class="text-muted">No proporcionada</span>'}
            </div>
            ${reporte.comentarios ? `
            <div class="col-12">
                <strong>Comentarios:</strong><br>
                <div class="bg-light p-3 rounded">${reporte.comentarios.replace(/\n/g, '<br>')}</div>
            </div>
            ` : ''}
            <div class="col-md-6">
                <strong>Fecha de Reporte:</strong><br>
                <small>${new Date(reporte.created_at).toLocaleString('es-ES')}</small>
            </div>
            ${reporte.updated_at && reporte.updated_at !== reporte.created_at ? `
            <div class="col-md-6">
                <strong>Última Actualización:</strong><br>
                <small>${new Date(reporte.updated_at).toLocaleString('es-ES')}</small>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.show();
}
</script>

