<?php
/**
 * Página de Mantenimiento
 * Muestra un mensaje amigable mientras el sitio está en mantenimiento
 */

// IPs permitidas que pueden acceder durante mantenimiento (tu IP)
$allowed_ips = [
    '127.0.0.1',
    '::1',
    // Agrega tu IP pública aquí para poder acceder durante mantenimiento
    // 'TU.IP.PUBLICA.AQUI',
];

// Si tu IP está permitida, redirigir al dashboard (mismo contexto que la app; evita perder subcarpeta)
if (in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed_ips)) {
    header('Location: index.php');
    exit;
}

// Enviar código 503 (Service Unavailable) para SEO
http_response_code(503);
header('Retry-After: 3600'); // Indicar a bots que vuelvan en 1 hora
require_once __DIR__ . '/includes/branding_lite.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(Branding::pageTitle('En Mantenimiento')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 50%, #1a365d 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            padding: 20px;
        }
        
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 24px;
            padding: 3rem 2.5rem;
            max-width: 550px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(10px);
        }
        
        .logo {
            width: 120px;
            height: auto;
            margin-bottom: 1.5rem;
        }
        
        .icon-container {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(72, 187, 120, 0); }
        }
        
        .icon-container i {
            font-size: 3rem;
            color: white;
        }
        
        h1 {
            color: #1a365d;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .subtitle {
            color: #48bb78;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        p {
            color: #4a5568;
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        
        .progress-container {
            background: #e2e8f0;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin: 2rem 0;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #38a169, #48bb78);
            background-size: 200% 100%;
            animation: progressAnimation 2s linear infinite;
            width: 60%;
            border-radius: 10px;
        }
        
        @keyframes progressAnimation {
            0% { background-position: 200% 0; }
            100% { background-position: 0% 0; }
        }
        
        .features {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }
        
        .feature {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #718096;
            font-size: 0.9rem;
        }
        
        .feature i {
            color: #48bb78;
        }
        
        .contact {
            background: #f7fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .contact h3 {
            color: #1a365d;
            font-size: 1rem;
            margin-bottom: 0.75rem;
        }
        
        .contact a {
            color: #48bb78;
            text-decoration: none;
            font-weight: 600;
        }
        
        .contact a:hover {
            text-decoration: underline;
        }
        
        .countdown {
            font-size: 0.9rem;
            color: #718096;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 2rem 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .features {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon-container">
            <i class="fas fa-tools"></i>
        </div>
        
        <h1>¡Estamos Mejorando!</h1>
        <p class="subtitle"><?= htmlspecialchars($brand_name) ?></p>
        
        <p>
            Estamos realizando mejoras en nuestra plataforma para brindarte 
            una mejor experiencia. Volveremos en breve.
        </p>
        
        <div class="progress-container">
            <div class="progress-bar"></div>
        </div>
        
        <div class="features">
            <div class="feature">
                <i class="fas fa-check-circle"></i>
                <span>Más rápido</span>
            </div>
            <div class="feature">
                <i class="fas fa-check-circle"></i>
                <span>Más seguro</span>
            </div>
            <div class="feature">
                <i class="fas fa-check-circle"></i>
                <span>Nuevas funciones</span>
            </div>
        </div>
        
        <div class="contact">
            <h3><i class="fas fa-envelope me-2"></i> ¿Necesitas ayuda urgente?</h3>
            <p style="margin-bottom: 0;">
                Escríbenos a <a href="mailto:info@laestaciondeldominohoy.com">info@laestaciondeldominohoy.com</a>
            </p>
        </div>
        
        <p class="countdown">
            <i class="fas fa-clock"></i> Tiempo estimado: menos de 1 hora
        </p>
    </div>
</body>
</html>











