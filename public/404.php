<?php
/**
 * Página 404 Personalizada
 * Muestra un mensaje amigable cuando se accede a una URL que no existe
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/includes/branding_init.php';

$base_url = app_base_url();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="<?= htmlspecialchars($brand_theme_color) ?>">
    
    <!-- SEO Meta Tags -->
    <title><?= htmlspecialchars(Branding::pageTitle('Página No Encontrada')) ?></title>
    <meta name="description" content="La página que buscas no existe o ha sido movida.">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', system-ui, sans-serif;
        }
        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 3rem;
            max-width: 600px;
            text-align: center;
        }
        .error-code {
            font-size: 8rem;
            font-weight: bold;
            color: #1a365d;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 2rem;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 1rem;
        }
        .error-message {
            color: #6b7280;
            font-size: 1.1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            border: none;
            padding: 12px 32px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 10px;
            color: white;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            min-height: 44px;
            min-width: 120px;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            color: white;
        }
        .btn-secondary-custom {
            background: #e5e7eb;
            border: none;
            padding: 12px 32px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 10px;
            color: #374151;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            min-height: 44px;
            min-width: 120px;
        }
        .btn-secondary-custom:hover {
            background: #d1d5db;
            color: #374151;
        }
        .icon-container {
            font-size: 5rem;
            color: #9ca3af;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 768px) {
            .error-container {
                padding: 2rem 1.5rem;
                margin: 1rem;
            }
            .error-code {
                font-size: 5rem;
            }
            .error-title {
                font-size: 1.5rem;
            }
            .btn-primary-custom,
            .btn-secondary-custom {
                display: block;
                width: 100%;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="icon-container">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="error-code">404</div>
        <h1 class="error-title">Página No Encontrada</h1>
        <p class="error-message">
            Lo sentimos, la página que buscas no existe o ha sido movida.<br>
            Puede que el enlace esté roto o que hayas escrito mal la dirección.
        </p>
        <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
            <a href="<?= htmlspecialchars($base_url) ?>/public/landing.php" class="btn-primary-custom">
                <i class="fas fa-home me-2"></i>Ir al Inicio
            </a>
            <a href="<?= htmlspecialchars($base_url) ?>/public/resultados.php" class="btn-secondary-custom">
                <i class="fas fa-trophy me-2"></i>Ver Torneos
            </a>
        </div>
        <div class="mt-4">
            <p class="text-muted small">
                <i class="fas fa-info-circle me-1"></i>
                Si crees que esto es un error, por favor contacta al administrador.
            </p>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>












