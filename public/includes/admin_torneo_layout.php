<?php
/**
 * Layout específico para el Administrador de Torneos
 * Usa cabecera unificada (includes/header.php) para favicon y meta.
 */
$current_user = Auth::user();
$page_title = $page_title ?? 'Administrador de Torneos';
if (! class_exists('Branding', false)) {
    require_once __DIR__ . '/../../lib/Branding.php';
}
$header_title = Branding::pageTitle($page_title);
?>
<!DOCTYPE html>
<html lang="es">
<?php include_once __DIR__ . '/../../includes/header.php'; ?>
    <meta name="theme-color" content="#667eea">
    
    <!-- Preconnect: conexiones tempranas a CDNs -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <!-- Bootstrap 5: carga bloqueante para garantizar estilos visibles -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5568d3;
            --secondary-color: #764ba2;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --dark-color: #1f2937;
            --light-color: #f9fafb;
            --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-color);
        }

        /* Sidebar Moderno */
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-xl);
            transition: transform 0.3s ease;
        }

        .admin-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .admin-sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar-brand {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .sidebar-brand i {
            font-size: 1.5rem;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.25rem 1rem;
        }

        .nav-link-sidebar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .nav-link-sidebar:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            transform: translateX(5px);
        }

        .nav-link-sidebar.active {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            font-weight: 600;
            box-shadow: var(--shadow-md);
        }

        .nav-link-sidebar i {
            width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            margin-top: auto;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 0.5rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        /* Main Content */
        .admin-main {
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        /* Panel de torneos: menú lateral oculto para dar espacio a las operaciones */
        .admin-sidebar {
            display: none !important;
        }
        .admin-main {
            margin-left: 0 !important;
        }
        .mobile-menu-btn {
            display: none !important;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            padding: 1rem 2rem;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .top-bar-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin: 0;
        }

        .top-bar-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn-modern {
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary-modern {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary-modern:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary-modern {
            background: var(--light-color);
            color: var(--dark-color);
            border: 1px solid var(--border-color);
        }

        .btn-secondary-modern:hover {
            background: var(--border-color);
        }

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Cards Modernos */
        .card-modern {
            background: white;
            border-radius: 1rem;
            box-shadow: var(--shadow-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: none;
            transition: all 0.3s ease;
        }

        .card-modern:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .card-header-modern {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Stats Cards */
        .stat-card {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-lg);
        }

        .stat-card.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .stat-card.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }

            .admin-sidebar.show {
                transform: translateX(0);
            }

            .admin-main {
                margin-left: 0;
            }

            .top-bar {
                padding: 1rem;
            }

            .content-area {
                padding: 1rem;
            }

            .mobile-menu-btn {
                display: block;
                background: none;
                border: none;
                color: var(--dark-color);
                font-size: 1.5rem;
                cursor: pointer;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Alertas Modernas */
        .alert-modern {
            border-radius: 0.75rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border: none;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-modern i {
            font-size: 1.25rem;
        }

        /* Tablas Modernas */
        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-modern thead th {
            background: var(--light-color);
            padding: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: var(--dark-color);
            border-bottom: 2px solid var(--border-color);
        }

        .table-modern tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .table-modern tbody tr:hover {
            background: var(--light-color);
        }

        /* Badges Modernos */
        .badge-modern {
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
        }

        /* Loading Spinner */
        .spinner-modern {
            border: 3px solid rgba(102, 126, 234, 0.1);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 0.8s linear infinite;
            margin: 2rem auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Animaciones */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeIn 0.3s ease;
        }

        /* Breadcrumb Moderno */
        .breadcrumb-modern {
            background: none;
            padding: 0;
            margin-bottom: 1.5rem;
        }

        .breadcrumb-modern .breadcrumb-item {
            display: inline-flex;
            align-items: center;
        }

        .breadcrumb-modern .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb-modern .breadcrumb-item.active {
            color: var(--dark-color);
        }

        .breadcrumb-modern .breadcrumb-item + .breadcrumb-item::before {
            content: '/';
            padding: 0 0.5rem;
            color: var(--border-color);
        }

        /* Overlay para móvil */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* Botones de acción */
        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .action-btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }

        /* Panel de Control de Torneos: +1pt en todo el contenido */
        body.page-panel-control-torneos { font-size: 1.083rem; }
    </style>
    <?php
    require_once __DIR__ . '/../../lib/app_helpers.php';
    $_custom13_href = rtrim(AppHelpers::getPublicUrl(), '/') . '/assets/css/custom-13inch.css';
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_custom13_href) ?>">
</head>
<body class="<?= (isset($action) && in_array($action, ['panel', 'panel_equipos'], true)) ? 'page-panel-control-torneos' : '' ?>">
    <!-- Sidebar -->
    <nav class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand d-flex align-items-center">
                <?php 
                require_once __DIR__ . '/../../lib/app_helpers.php';
                $logo_url = AppHelpers::getAppLogo();
                ?>
                <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars(Branding::siteName()) ?>" style="height: 30px; margin-right: 10px;" fetchpriority="high">
                <span>Admin Torneos</span>
            </div>
        </div>

        <div class="sidebar-nav d-flex flex-column" style="height: calc(100vh - 140px);">
            <a href="index.php?page=torneo_gestion&action=index" 
               class="nav-link-sidebar">
                <i class="fas fa-arrow-left"></i>
                <span>Volver al Dashboard</span>
            </a>
            
            <?php 
            // Asegurar que las variables sean valores escalares
            $torneo_id_safe = is_array($torneo_id ?? null) ? (isset($torneo_id['id']) ? (int)$torneo_id['id'] : (int)($torneo_id[0] ?? 0)) : (int)($torneo_id ?? 0);
            
            // Si no hay torneo_id en la variable, intentar obtenerlo de GET o REQUEST
            if ($torneo_id_safe === 0) {
                $torneo_id_safe = (int)($_GET['torneo_id'] ?? $_REQUEST['torneo_id'] ?? 0);
            }
            
            $ronda_safe = is_array($ronda ?? null) ? (int)($ronda[0] ?? 1) : (int)($ronda ?? 1);
            $torneo_nombre_safe = '';
            $torneo_modalidad = null;
            $es_modalidad_equipos = false;
            
            if (isset($torneo)) {
                if (is_array($torneo)) {
                    $torneo_nombre_safe = htmlspecialchars($torneo['nombre'] ?? 'Torneo');
                    $torneo_modalidad = (int)($torneo['modalidad'] ?? 0);
                } else {
                    $torneo_nombre_safe = htmlspecialchars($torneo ?? 'Torneo');
                }
            }
            
            // Si no tenemos la modalidad del torneo, obtenerla de la base de datos
            if ($torneo_id_safe > 0 && $torneo_modalidad === null) {
                try {
                    require_once __DIR__ . '/../../config/db_config.php';
                    $stmt = DB::pdo()->prepare("SELECT modalidad, nombre FROM tournaments WHERE id = ?");
                    $stmt->execute([$torneo_id_safe]);
                    $torneo_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($torneo_data) {
                        $torneo_modalidad = (int)($torneo_data['modalidad'] ?? 0);
                        if (empty($torneo_nombre_safe)) {
                            $torneo_nombre_safe = htmlspecialchars($torneo_data['nombre'] ?? 'Torneo');
                        }
                    }
                } catch (Exception $e) {
                    // Error silencioso
                }
            }
            
            $es_modalidad_equipos = ($torneo_modalidad === 3);
            
            // Verificar si el torneo está iniciado o cerrado (para deshabilitar invitaciones)
            $torneo_iniciado = false;
            if ($torneo_id_safe > 0) {
                try {
                    $stmt = DB::pdo()->prepare("SELECT fechator, locked FROM tournaments WHERE id = ?");
                    $stmt->execute([$torneo_id_safe]);
                    $torneo_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($torneo_info) {
                        $torneo_iniciado = (int)($torneo_info['locked'] ?? 0) === 1
                            || ($torneo_info['fechator'] && strtotime($torneo_info['fechator']) <= strtotime('today'));
                    }
                } catch (Exception $e) {
                    $torneo_iniciado = false;
                }
            }
            ?>
            <?php if ($torneo_id_safe): ?>
                <div class="px-3 py-2 mt-2 mb-2">
                    <div class="small text-white-50 text-uppercase fw-bold mb-2">Torneo Actual</div>
                    <div class="small"><?php echo $torneo_nombre_safe ?: 'Torneo'; ?></div>
                </div>
                
                <a href="panel_torneo.php?torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($action ?? '') === 'panel' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Panel de Control</span>
                </a>
                
                <a href="admin_torneo.php?action=rondas&torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($action ?? '') === 'rondas' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    <span>Historial de Rondas</span>
                </a>
                
                <a href="admin_torneo.php?action=posiciones&torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($action ?? '') === 'posiciones' ? 'active' : ''; ?>">
                    <i class="fas fa-trophy"></i>
                    <span>Posiciones</span>
                </a>

                <a href="admin_torneo.php?action=galeria_fotos&torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($action ?? '') === 'galeria_fotos' ? 'active' : ''; ?>">
                    <i class="fas fa-images"></i>
                    <span>Galería de Fotos</span>
                </a>
                <?php if (($current_user['role'] ?? '') === 'admin_club'): ?>
                <!-- Invitaciones (solo admin_club, deshabilitadas si torneo iniciado o cerrado) -->
                <?php if ($torneo_iniciado): ?>
                <span class="nav-link-sidebar" style="cursor: not-allowed; opacity: 0.7; color: rgba(255,255,255,0.6);" title="No disponible: torneo iniciado o cerrado">
                    <i class="fab fa-whatsapp"></i>
                    <span>Invitaciones a Jugadores</span>
                </span>
                <span class="nav-link-sidebar" style="cursor: not-allowed; opacity: 0.7; color: rgba(255,255,255,0.6);" title="No disponible: torneo iniciado o cerrado">
                    <i class="fas fa-link"></i>
                    <span>Generar Link Invitación</span>
                </span>
                <?php else: ?>
                <a href="index.php?page=invitacion_clubes&torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($_GET['page'] ?? '') === 'invitacion_clubes' ? 'active' : ''; ?>">
                    <i class="fas fa-address-book"></i>
                    <span>Invitación de clubes</span>
                </a>
                <a href="index.php?page=player_invitations&torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($_GET['page'] ?? '') === 'player_invitations' ? 'active' : ''; ?>">
                    <i class="fab fa-whatsapp"></i>
                    <span>Invitaciones a Jugadores</span>
                </a>
                <a href="index.php?page=tournaments/invitation_link&torneo_id=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($_GET['page'] ?? '') === 'tournaments/invitation_link' ? 'active' : ''; ?>">
                    <i class="fas fa-link"></i>
                    <span>Generar Link Invitación</span>
                </a>
                <a href="index.php?page=invitations&filter_torneo=<?php echo $torneo_id_safe; ?>" 
                   class="nav-link-sidebar <?php echo ($_GET['page'] ?? '') === 'invitations' ? 'active' : ''; ?>">
                    <i class="fas fa-paper-plane"></i>
                    <span>Despacho de Invitaciones</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($current_user['nombre'] ?? 'U', 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($current_user['nombre'] ?? 'Usuario'); ?></div>
                    <div style="font-size: 0.75rem; opacity: 0.8;">
                        <?php 
                        $roles = [
                            'admin_general' => 'Admin General',
                            'admin_torneo' => 'Admin Torneo',
                            'admin_club' => 'Admin Organización'
                        ];
                        echo $roles[$current_user['role']] ?? 'Usuario';
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Overlay para móvil -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <div class="admin-main">
        <!-- Top Bar -->
        <div class="top-bar">
            <button class="mobile-menu-btn" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="top-bar-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <div class="top-bar-actions ms-auto">
                <a href="index.php" class="btn-modern btn-secondary-modern">
                    <i class="fas fa-home"></i>
                    <span class="d-none d-md-inline">Dashboard Principal</span>
                </a>
            </div>
        </div>

        <!-- Content Area -->
        <div class="content-area">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert-modern alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert-modern alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['info'])): ?>
                <div class="alert-modern alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle"></i>
                    <div><?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Contenido de la página -->
            <?php echo $content ?? ''; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <!-- SweetAlert2: defer para no bloquear TBT -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    
    <script>
        // Toggle sidebar en móvil
        function toggleSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        }

        // Cerrar sidebar al hacer click en overlay
        document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

        // Cerrar sidebar al hacer click en un link (móvil)
        if (window.innerWidth <= 768) {
            document.querySelectorAll('.nav-link-sidebar').forEach(link => {
                link.addEventListener('click', () => {
                    setTimeout(toggleSidebar, 300);
                });
            });
        }

        // Auto-hide alerts después de 5 segundos
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>

