<?php
/**
 * Inscripción Directa de Jugador desde Link de WhatsApp
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/ClubHelper.php';
require_once __DIR__ . '/includes/branding_init.php';

$pdo = DB::pdo();
$base_url = app_base_url();

$torneo_id = isset($_GET['torneo']) ? (int)$_GET['torneo'] : 0;
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

$error = '';
$success = '';
$torneo_data = null;
$jugador_data = null;
$invitacion_valida = false;

// Verificar invitación
if ($torneo_id && $token) {
    try {
        // Verificar que existe la tabla
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS player_invitations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                torneo_id INT NOT NULL,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                enviado_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                usado_at DATETIME NULL,
                FOREIGN KEY (torneo_id) REFERENCES tournaments(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Obtener datos de la invitación
        $stmt = $pdo->prepare("
            SELECT pi.*, t.*, u.nombre as jugador_nombre, u.cedula, u.club_id,
                   c.nombre as club_nombre, cr.nombre as club_organizador_nombre
            FROM player_invitations pi
            JOIN tournaments t ON pi.torneo_id = t.id
            JOIN users u ON pi.user_id = u.id
            LEFT JOIN clubes c ON u.club_id = c.id
            LEFT JOIN clubes cr ON t.club_responsable = cr.id
            WHERE pi.token = ? AND pi.torneo_id = ? AND pi.usado_at IS NULL
        ");
        $stmt->execute([$token, $torneo_id]);
        $invitacion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($invitacion) {
            $invitacion_valida = true;
            $torneo_data = $invitacion;
            $jugador_data = [
                'id' => $invitacion['user_id'],
                'nombre' => $invitacion['jugador_nombre'],
                'cedula' => $invitacion['cedula'],
                'club_id' => $invitacion['club_id'],
                'club_nombre' => $invitacion['club_nombre']
            ];
        } else {
            $error = 'Invitación no válida o ya utilizada';
        }
    } catch (Exception $e) {
        $error = 'Error al verificar invitación: ' . $e->getMessage();
    }
} else {
    $error = 'Parámetros de invitación inválidos';
}

// Procesar inscripción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitacion_valida) {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    if (!RateLimiter::canSubmit('player_register', 30)) {
        $error = 'Por favor espera 30 segundos antes de intentar de nuevo.';
    } else {
    CSRF::validate();
    
    $nombre = trim($_POST['nombre'] ?? '');
    $cedula = trim($_POST['cedula'] ?? '');
    $sexo = trim($_POST['sexo'] ?? '');
    $fechnac = trim($_POST['fechnac'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
    $categ = trim($_POST['categ'] ?? '');
    $club_inscripcion = (int)($_POST['club_inscripcion'] ?? $jugador_data['club_id']);
    
    if (empty($nombre) || empty($cedula) || empty($sexo) || empty($fechnac)) {
        $error = 'Nombre, cédula, sexo y fecha de nacimiento son requeridos';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Verificar si ya está inscrito
            $stmt = $pdo->prepare("SELECT id FROM inscripciones WHERE torneo_id = ? AND cedula = ?");
            $stmt->execute([$torneo_id, $cedula]);
            if ($stmt->fetch()) {
                throw new Exception('Ya estás inscrito en este torneo');
            }
            
            // Generar identificador único
            $identificador = strtoupper(substr($nombre, 0, 3) . substr($cedula, -4) . rand(100, 999));
            
            // Insertar inscripción
            $stmt = $pdo->prepare("
                INSERT INTO inscripciones 
                (torneo_id, club_id, cedula, nombre, sexo, fechnac, celular, email, nacionalidad, categ, identificador, estatus, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $torneo_id,
                $club_inscripcion,
                $cedula,
                $nombre,
                $sexo,
                $fechnac,
                $celular ?: null,
                $email ?: null,
                $nacionalidad,
                $categ ?: null,
                $identificador
            ]);
            
            // Marcar invitación como usada
            $stmt = $pdo->prepare("UPDATE player_invitations SET usado_at = NOW() WHERE token = ? AND torneo_id = ?");
            $stmt->execute([$token, $torneo_id]);
            
            $pdo->commit();
            RateLimiter::recordSubmit('player_register');
            $success = '¡Inscripción exitosa! Ya estás registrado en el torneo.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error al inscribirse: ' . $e->getMessage();
        }
    }
    }
}

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Abierto', 2 => 'Por Categorías'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#48bb78">
    <title><?= htmlspecialchars(Branding::pageTitle('Inscripción al Torneo')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a365d;
            --accent: #48bb78;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .header i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .torneo-info {
            background: #f7fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(72, 187, 120, 0.25);
        }
        
        .btn-register {
            background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        
        .btn-register:hover {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
        }
        
        @media (max-width: 576px) {
            .header {
                padding: 1.5rem;
            }
            .header i {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="register-card">
                    <div class="header">
                        <?php 
                        require_once __DIR__ . '/../lib/app_helpers.php';
                        $logo_url = AppHelpers::getAppLogo();
                        ?>
                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($brand_name) ?>" style="height: 60px; margin-bottom: 1rem;">
                        <h3 class="mb-1">Inscripción al Torneo</h3>
                        <p class="mb-0 opacity-75">Completa tus datos para participar</p>
                    </div>
                    
                    <div class="p-4">
                        <?php if ($error && !$invitacion_valida): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                            </div>
                            <div class="text-center">
                                <a href="<?= $base_url ?>/public/landing.php" class="btn btn-secondary">
                                    <i class="fas fa-home me-1"></i>Volver al Inicio
                                </a>
                            </div>
                        <?php elseif ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                            </div>
                            <div class="text-center">
                                <a href="<?= $base_url ?>/public/user_portal.php" class="btn btn-success">
                                    <i class="fas fa-user me-1"></i>Ir a Mi Portal
                                </a>
                            </div>
                        <?php elseif ($invitacion_valida && $torneo_data): ?>
                            <!-- Información del Torneo -->
                            <div class="torneo-info">
                                <h5 class="text-primary mb-3">
                                    <i class="fas fa-trophy me-2"></i><?= htmlspecialchars($torneo_data['nombre']) ?>
                                </h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($torneo_data['fechator'])) ?></p>
                                        <p class="mb-2"><strong>Lugar:</strong> <?= htmlspecialchars($torneo_data['lugar'] ?? 'Por definir') ?></p>
                                        <p class="mb-2"><strong>Modalidad:</strong> <?= $modalidades[$torneo_data['modalidad']] ?? 'N/A' ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Clase:</strong> <?= $clases[$torneo_data['clase']] ?? 'N/A' ?></p>
                                        <?php if ($torneo_data['costo'] > 0): ?>
                                            <p class="mb-2"><strong>Costo:</strong> $<?= number_format($torneo_data['costo'], 2) ?></p>
                                        <?php endif; ?>
                                        <p class="mb-2"><strong>Organizador:</strong> <?= htmlspecialchars($torneo_data['club_organizador_nombre'] ?? $torneo_data['club_nombre'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Formulario de Inscripción -->
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                
                                <h6 class="text-muted mb-3"><i class="fas fa-user me-2"></i>Datos del Jugador</h6>
                                
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Nacionalidad *</label>
                                        <select name="nacionalidad" class="form-select" required>
                                            <option value="V" <?= ($jugador_data['cedula'] ?? '') ? 'selected' : '' ?>>V</option>
                                            <option value="E">E</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="cedula" class="form-label">Cédula *</label>
                                        <input type="text" name="cedula" id="cedula" class="form-control" 
                                               value="<?= htmlspecialchars($jugador_data['cedula'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label">Nombre Completo *</label>
                                        <input type="text" name="nombre" class="form-control" 
                                               value="<?= htmlspecialchars($jugador_data['nombre'] ?? '') ?>" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Sexo *</label>
                                        <select name="sexo" class="form-select" required>
                                            <option value="">Seleccione</option>
                                            <option value="M">Masculino</option>
                                            <option value="F">Femenino</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Fecha de Nacimiento *</label>
                                        <input type="date" name="fechnac" class="form-control" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Categoría</label>
                                        <input type="text" name="categ" class="form-control" 
                                               placeholder="Ej: Sub-20, Senior">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Celular</label>
                                        <input type="text" name="celular" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" name="email" id="email" class="form-control">
                                    </div>
                                </div>
                                
                                <?php if ($jugador_data['club_id']): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Club de Inscripción</label>
                                        <select name="club_inscripcion" class="form-select">
                                            <?php
                                            $clubes_disponibles = ClubHelper::getClubesSupervisedWithData($jugador_data['club_id']);
                                            $asociacionesFvd = [];
                                            $clubesAfiliados = [];
                                            foreach ($clubes_disponibles as $club) {
                                                if ((int)($club['id'] ?? 0) >= 1 && (int)($club['id'] ?? 0) <= 39) {
                                                    $asociacionesFvd[] = $club;
                                                } else {
                                                    $clubesAfiliados[] = $club;
                                                }
                                            }
                                            ?>
                                            <optgroup label="Asociaciones Estadales (FVD)">
                                                <?php if (empty($asociacionesFvd)): ?>
                                                    <option value="" disabled>Sin asociaciones disponibles</option>
                                                <?php else: ?>
                                                    <?php foreach ($asociacionesFvd as $club): ?>
                                                        <option value="<?= (int)$club['id'] ?>" <?= (int)$club['id'] === (int)$jugador_data['club_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($club['nombre']) ?><?= !empty($club['es_principal']) ? ' (Principal)' : '' ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </optgroup>
                                            <optgroup label="Clubes Afiliados">
                                                <?php if (empty($clubesAfiliados)): ?>
                                                    <option value="" disabled>Sin clubes afiliados disponibles</option>
                                                <?php else: ?>
                                                    <?php foreach ($clubesAfiliados as $club): ?>
                                                        <option value="<?= (int)$club['id'] ?>" <?= (int)$club['id'] === (int)$jugador_data['club_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($club['nombre']) ?><?= !empty($club['es_principal']) ? ' (Principal)' : '' ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </optgroup>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($error): ?>
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <button type="submit" class="btn btn-register btn-primary w-100 mt-3">
                                    <i class="fas fa-check me-2"></i>Confirmar Inscripción
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <?php $pr_base = (function_exists('app_base_url') ? app_base_url() : '/'); ?>
    <script src="<?= htmlspecialchars(rtrim($pr_base, '/') . '/assets/form-utils.js') ?>" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[method="POST"]');
            if (form && typeof preventDoubleSubmit === 'function') preventDoubleSubmit(form);
            if (typeof initCedulaValidation === 'function') initCedulaValidation('cedula');
            if (typeof initEmailValidation === 'function') initEmailValidation('email');
        });
    </script>
</body>
</html>

