<?php
require_once __DIR__ . '/includes/branding_lite.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(Branding::pageTitle('Servicio No Disponible')) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 3rem;
            max-width: 600px;
            text-align: center;
        }
        .icon {
            font-size: 5rem;
            color: #f59e0b;
            margin-bottom: 1rem;
        }
        h1 {
            color: #1a365d;
            margin-bottom: 1rem;
        }
        p {
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .solutions {
            text-align: left;
            background: #f3f4f6;
            padding: 1.5rem;
            border-radius: 10px;
            margin: 2rem 0;
        }
        .solutions h3 {
            color: #1a365d;
            margin-top: 0;
        }
        .solutions ol {
            color: #374151;
            line-height: 1.8;
        }
        .btn {
            display: inline-block;
            padding: 12px 32px;
            background: #1a365d;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            margin: 0.5rem;
            min-height: 44px;
            min-width: 120px;
        }
        .btn:hover {
            background: #152b4a;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="icon">⚠️</div>
        <h1>Servicio Temporalmente No Disponible</h1>
        <p>Lo sentimos, el servicio no está disponible en este momento. Estamos trabajando para solucionarlo.</p>
        
        <div class="solutions">
            <h3>Si eres el administrador del sistema:</h3>
            <ol>
                <li><strong>Verifica que MySQL esté corriendo:</strong>
                    <ul>
                        <li>Abre WAMP Server</li>
                        <li>Verifica que el icono esté <strong>VERDE</strong></li>
                        <li>Si está naranja o rojo: Clic derecho → Tools → Services → MySQL → Start</li>
                    </ul>
                </li>
                <li><strong>Verifica la conexión:</strong>
                    <ul>
                        <li>Accede a: <a href="check_mysql.php">check_mysql.php</a></li>
                    </ul>
                </li>
                <li><strong>Revisa los logs de error:</strong>
                    <ul>
                        <li>Logs de Apache: <code>C:\wamp64\logs\apache_error.log</code></li>
                        <li>Logs de PHP: <code>C:\wamp64\logs\php_error.log</code></li>
                    </ul>
                </li>
            </ol>
        </div>
        
        <div>
            <a href="landing.php" class="btn">Ir al Inicio</a>
            <a href="check_mysql.php" class="btn">Verificar MySQL</a>
        </div>
    </div>
</body>
</html>












