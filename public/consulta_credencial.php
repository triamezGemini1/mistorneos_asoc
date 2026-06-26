<?php
require_once __DIR__ . '/../config/session_start_early.php';
/**
 * Consulta de Información de Torneo
 * Patrón en bloque: db_config → auth_service → requireAuth. Interfaz: header/footer unificados.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth_service.php';
AuthService::requireAuth();
require_once __DIR__ . '/includes/branding_init.php';

$pdo = DB::pdo();
$base_url = app_base_url();

$torneos = [];
$error_message = null;
$torneo_data = null;
$jugador_data = null;
$inscripcion_data = null;
$listado_general = [];
$estadisticas = [];

// Obtener lista de torneos activos
try {
    $stmt = $pdo->query("
        SELECT id, nombre, fechator, lugar, estatus
        FROM tournaments 
        WHERE estatus = 1 
        ORDER BY fechator DESC 
        LIMIT 50
    ");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error al cargar torneos: " . $e->getMessage();
}

// Procesar búsqueda
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar'])) {
    $torneo_id = isset($_POST['torneo_id']) ? (int)$_POST['torneo_id'] : 0;
    $cedula = trim($_POST['cedula'] ?? '');
    
    if (empty($torneo_id) || empty($cedula)) {
        $error_message = "Por favor, complete todos los campos";
    } else {
        try {
            // Obtener datos completos del torneo
            $stmt = $pdo->prepare("
                SELECT t.*, c.nombre as club_organizador, c.delegado, c.telefono as club_telefono, 
                       c.email as club_email, c.logo as club_logo
                FROM tournaments t
                LEFT JOIN clubes c ON t.club_responsable = c.id
                WHERE t.id = ? AND t.estatus = 1
            ");
            $stmt->execute([$torneo_id]);
            $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$torneo_data) {
                $error_message = "Torneo no encontrado o inactivo";
            } else {
                // Buscar inscripción del jugador
                $stmt = $pdo->prepare("
                    SELECT i.*, c.nombre as club_nombre, u.uuid as identificador_unico
                    FROM inscripciones i
                    LEFT JOIN clubes c ON i.club_id = c.id
                    LEFT JOIN usuarios u ON i.cedula = u.cedula AND u.role = 'usuario'
                    WHERE i.torneo_id = ? AND i.cedula = ? AND i.estatus = 1
                    LIMIT 1
                ");
                $stmt->execute([$torneo_id, $cedula]);
                $inscripcion_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($inscripcion_data) {
                    $jugador_data = $inscripcion_data;
                    
                    // Obtener listado general del torneo (todos los inscritos)
                    $stmt = $pdo->prepare("
                        SELECT i.id, i.identificador, i.nombre, i.cedula, i.sexo, i.categ,
                               c.nombre as club_nombre, i.estatus
                        FROM inscripciones i
                        LEFT JOIN clubes c ON i.club_id = c.id
                        WHERE i.torneo_id = ? AND i.estatus = 1
                        ORDER BY i.identificador ASC, i.nombre ASC
                    ");
                    $stmt->execute([$torneo_id]);
                    $listado_general = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Estadísticas del torneo
                    $stmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as total_inscritos,
                            COUNT(CASE WHEN sexo = 'M' THEN 1 END) as masculinos,
                            COUNT(CASE WHEN sexo = 'F' THEN 1 END) as femeninos,
                            COUNT(DISTINCT club_id) as total_clubes
                        FROM inscripciones
                        WHERE torneo_id = ? AND estatus = 1
                    ");
                    $stmt->execute([$torneo_id]);
                    $estadisticas = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error_message = "No se encontró inscripción para esta cédula en el torneo seleccionado.";
                }
            }
        } catch (Exception $e) {
            $error_message = "Error en la búsqueda: " . $e->getMessage();
        }
    }
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Abierto', 2 => 'Por Categorías'];

$header_title = Branding::pageTitle('Consulta de Torneo');
?>
<!DOCTYPE html>
<html lang="es">
<?php include_once __DIR__ . '/../includes/header.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a365d;
            --accent: #48bb78;
            --secondary: #2d3748;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .main-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 1200px;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        
        .header i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            color: var(--accent);
        }
        
        .info-section {
            background: #f7fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-section h5 {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--accent);
            padding-bottom: 0.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #4a5568;
        }
        
        .info-value {
            color: #1a202c;
            font-weight: 500;
        }
        
        .jugador-badge {
            background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .stats-card {
            background: white;
            border: 2px solid var(--accent);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stats-label {
            color: #718096;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table thead {
            background: var(--primary);
            color: white;
        }
        
        .table tbody tr:hover {
            background: #f7fafc;
        }
        
        .uuid-display {
            background: #1a202c;
            color: var(--accent);
            font-family: 'Courier New', monospace;
            padding: 1rem;
            border-radius: 8px;
            font-size: 1.1rem;
            text-align: center;
            word-break: break-all;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(72, 187, 120, 0.25);
        }
        
        .btn-search {
            background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        
        .btn-search:hover {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
        }
        
        @media (max-width: 768px) {
            .header {
                padding: 2rem 1.5rem;
            }
            .header i {
                font-size: 2.5rem;
            }
            .info-section {
                padding: 1rem;
            }
            .stats-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-card">
            <div class="header">
                <?php 
                require_once __DIR__ . '/../lib/app_helpers.php';
                $logo_url = AppHelpers::getAppLogo();
                ?>
                <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($brand_name) ?>" style="height: 60px; margin-bottom: 1rem;">
                <h2 class="mb-1">Consulta de Información de Torneo</h2>
                <p class="mb-0 opacity-75"><?= htmlspecialchars($brand_name) ?></p>
            </div>
            
            <div class="p-4">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($jugador_data && $torneo_data): ?>
                <!-- INFORMACIÓN ENCONTRADA -->
                
                <!-- Badge del Jugador -->
                <div class="jugador-badge">
                    <h4 class="mb-2">
                        <i class="fas fa-check-circle me-2"></i>Inscripción Confirmada
                    </h4>
                    <h5 class="mb-0"><?= htmlspecialchars($jugador_data['nombre']) ?></h5>
                    <small class="opacity-75">Cédula: <?= htmlspecialchars($jugador_data['cedula']) ?></small>
                </div>
                
                <div class="row g-4">
                    <!-- Información del Torneo -->
                    <div class="col-lg-6">
                        <div class="info-section">
                            <h5><i class="fas fa-trophy me-2"></i>Información del Torneo</h5>
                            <div class="info-row">
                                <span class="info-label">Nombre:</span>
                                <span class="info-value"><?= htmlspecialchars($torneo_data['nombre']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Fecha:</span>
                                <span class="info-value"><?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Lugar:</span>
                                <span class="info-value"><?= htmlspecialchars($torneo_data['lugar'] ?? 'Por definir') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Modalidad:</span>
                                <span class="info-value"><?= $modalidades[$torneo_data['modalidad']] ?? 'N/A' ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Clase:</span>
                                <span class="info-value"><?= $clases[$torneo_data['clase']] ?? 'N/A' ?></span>
                            </div>
                            <?php if ($torneo_data['costo'] > 0): ?>
                            <div class="info-row">
                                <span class="info-label">Costo:</span>
                                <span class="info-value">$<?= number_format($torneo_data['costo'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="info-row">
                                <span class="info-label">Organizador:</span>
                                <span class="info-value"><?= htmlspecialchars($torneo_data['club_organizador']) ?></span>
                            </div>
                            <?php if ($torneo_data['club_telefono']): ?>
                            <div class="info-row">
                                <span class="info-label">Contacto:</span>
                                <span class="info-value"><?= htmlspecialchars($torneo_data['club_telefono']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Información del Jugador -->
                    <div class="col-lg-6">
                        <div class="info-section">
                            <h5><i class="fas fa-user me-2"></i>Mi Participación</h5>
                            <div class="info-row">
                                <span class="info-label">Nombre:</span>
                                <span class="info-value"><?= htmlspecialchars($jugador_data['nombre']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Cédula:</span>
                                <span class="info-value"><?= htmlspecialchars($jugador_data['cedula']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Club:</span>
                                <span class="info-value"><?= htmlspecialchars($jugador_data['club_nombre'] ?? 'N/A') ?></span>
                            </div>
                            <?php if ($jugador_data['sexo']): ?>
                            <div class="info-row">
                                <span class="info-label">Sexo:</span>
                                <span class="info-value"><?= $jugador_data['sexo'] === 'M' ? 'Masculino' : 'Femenino' ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($jugador_data['categ']): ?>
                            <div class="info-row">
                                <span class="info-label">Categoría:</span>
                                <span class="info-value"><?= htmlspecialchars($jugador_data['categ']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($jugador_data['identificador']): ?>
                            <div class="info-row">
                                <span class="info-label">N° Identificador:</span>
                                <span class="info-value"><strong class="text-primary">#<?= htmlspecialchars((string)$jugador_data['identificador']) ?></strong></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($jugador_data['identificador_unico']): ?>
                            <div class="mt-3">
                                <label class="info-label d-block mb-2">Identificador Único (UUID):</label>
                                <div class="uuid-display">
                                    <?= htmlspecialchars($jugador_data['identificador_unico']) ?>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary mt-2 w-100" onclick="copiarUUID()">
                                    <i class="fas fa-copy me-1"></i>Copiar UUID
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas del Torneo -->
                <?php if ($estadisticas): ?>
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stats-card">
                            <div class="stats-number"><?= $estadisticas['total_inscritos'] ?></div>
                            <div class="stats-label">Total Inscritos</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stats-card">
                            <div class="stats-number"><?= $estadisticas['masculinos'] ?></div>
                            <div class="stats-label">Masculinos</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stats-card">
                            <div class="stats-number"><?= $estadisticas['femeninos'] ?></div>
                            <div class="stats-label">Femeninos</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stats-card">
                            <div class="stats-number"><?= $estadisticas['total_clubes'] ?></div>
                            <div class="stats-label">Clubes</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Listado General del Torneo -->
                <div class="info-section">
                    <h5>
                        <i class="fas fa-list me-2"></i>Listado General del Torneo
                        <span class="badge bg-primary ms-2"><?= count($listado_general) ?> participantes</span>
                    </h5>
                    
                    <?php if (empty($listado_general)): ?>
                        <p class="text-muted text-center py-3">No hay participantes inscritos</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nombre</th>
                                        <th>Cédula</th>
                                        <th>Club</th>
                                        <th>Categoría</th>
                                        <th>Sexo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($listado_general as $index => $participante): ?>
                                        <tr class="<?= $participante['cedula'] === $jugador_data['cedula'] ? 'table-success' : '' ?>">
                                            <td>
                                                <?php if ($participante['identificador']): ?>
                                                    <strong>#<?= htmlspecialchars((string)$participante['identificador']) ?></strong>
                                                <?php else: ?>
                                                    <?= $index + 1 ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($participante['nombre']) ?>
                                                <?php if ($participante['cedula'] === $jugador_data['cedula']): ?>
                                                    <span class="badge bg-success ms-1">Tú</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($participante['cedula']) ?></td>
                                            <td><?= htmlspecialchars($participante['club_nombre'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($participante['categ'] ?? '-') ?></td>
                                            <td>
                                                <?php if ($participante['sexo']): ?>
                                                    <?= $participante['sexo'] === 'M' ? 'M' : 'F' ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Acciones -->
                <div class="text-center mt-4">
                    <a href="modules/registrants/generate_credential.php?action=single&id=<?= $jugador_data['id'] ?>" 
                       class="btn btn-primary btn-lg me-2">
                        <i class="fas fa-download me-2"></i>Descargar Credencial PDF
                    </a>
                    <button type="button" class="btn btn-outline-secondary btn-lg" onclick="location.reload()">
                        <i class="fas fa-search me-2"></i>Nueva Búsqueda
                    </button>
                </div>
                
                <?php else: ?>
                <!-- FORMULARIO DE BÚSQUEDA -->
                <form method="POST" action="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-trophy me-2"></i>Selecciona el Torneo
                            </label>
                            <select class="form-select form-select-lg" name="torneo_id" required>
                                <option value="">-- Seleccione un torneo --</option>
                                <?php foreach ($torneos as $torneo): ?>
                                    <option value="<?= $torneo['id'] ?>" 
                                            <?= (isset($_POST['torneo_id']) && $_POST['torneo_id'] == $torneo['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($torneo['nombre']) ?> - 
                                        <?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-id-card me-2"></i>Tu Cédula de Identidad
                            </label>
                            <input type="text" 
                                   class="form-control form-control-lg" 
                                   name="cedula" 
                                   placeholder="Ej: 12345678 o V12345678"
                                   value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>"
                                   required>
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>Ingresa solo números (puede incluir V, E, J, P al inicio)
                            </small>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" name="buscar" class="btn btn-search btn-primary btn-lg px-5">
                            <i class="fas fa-search me-2"></i>Consultar Información
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-4 pt-3 border-top">
                    <small class="text-muted">
                        <i class="fas fa-lock me-1"></i>
                        Consulta segura y privada - Solo tú puedes ver tu información
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="<?= $base_url ?>/public/landing.php" class="text-white text-decoration-none">
                <i class="fas fa-home me-1"></i>Volver al Inicio
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script>
    function copiarUUID() {
        const uuid = '<?= htmlspecialchars($jugador_data['identificador_unico'] ?? '') ?>';
        if (uuid) {
            navigator.clipboard.writeText(uuid).then(() => {
                alert('✅ UUID copiado al portapapeles');
            }).catch(() => {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = uuid;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('✅ UUID copiado al portapapeles');
            });
        }
    }
    </script>
<?php include_once __DIR__ . '/../includes/footer.php'; ?>
