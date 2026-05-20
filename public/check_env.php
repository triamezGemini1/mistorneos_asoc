<?php
/**
 * Verificación post-despliegue (entorno, permisos, BD).
 * URL: https://tu-dominio/mistorneos/public/check_env.php
 * Eliminar o proteger con IP tras validar.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$checks = [];

// --- Carpetas escribibles ---
$writableDirs = [
    'storage' => $root . '/storage',
    'storage/logs' => $root . '/storage/logs',
    'storage/cache' => $root . '/storage/cache',
    'storage/sessions' => $root . '/storage/sessions',
    'upload' => $root . '/upload',
    'uploads' => $root . '/uploads',
];

foreach ($writableDirs as $label => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $checks[] = [
        'grupo' => 'Permisos',
        'nombre' => $label,
        'ok' => $writable,
        'detalle' => !$exists ? 'No existe' : ($writable ? 'Escribible' : 'Sin permiso de escritura'),
    ];
}

// --- Bootstrap y BD (credenciales vía config, no auth.php) ---
$envLabel = 'N/A';
$dbOk = false;
$dbMsg = '';
$appEnv = 'N/A';

try {
    if (!is_file($root . '/config/bootstrap.php')) {
        throw new RuntimeException('Falta config/bootstrap.php');
    }
    require_once $root . '/config/bootstrap.php';
    require_once $root . '/config/db_config.php';

    $appEnv = (string) ($_ENV['APP_ENV'] ?? ($GLOBALS['APP_CONFIG']['app']['env'] ?? 'desconocido'));
    $envLabel = class_exists('Environment') && method_exists('Environment', 'isProduction')
        ? (Environment::isProduction() ? 'production' : 'development')
        : $appEnv;

    $pdo = DB::pdo();
    $pdo->query('SELECT 1');
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $dbOk = true;
    $dbMsg = 'Conexión OK — base: ' . (string) $dbName;

    // Comprobar columnas/tablas clave post-migración (no bloquea si faltan)
    $schemaHints = [];
    try {
        $hasTipoOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'tipo_org'")->fetch();
        $schemaHints[] = $hasTipoOrg ? 'tipo_org: sí' : 'tipo_org: no (ejecutar migraciones SQL)';
    } catch (Throwable $e) {
        $schemaHints[] = 'organizaciones: ' . $e->getMessage();
    }
    if ($schemaHints !== []) {
        $dbMsg .= ' · ' . implode(' · ', $schemaHints);
    }
} catch (Throwable $e) {
    $dbMsg = $e->getMessage();
}

$checks[] = [
    'grupo' => 'Aplicación',
    'nombre' => 'APP_ENV / entorno',
    'ok' => $envLabel !== 'N/A',
    'detalle' => $envLabel,
];
$checks[] = [
    'grupo' => 'Base de datos',
    'nombre' => 'DB::pdo() (config/db_config.php)',
    'ok' => $dbOk,
    'detalle' => $dbMsg,
];

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Check entorno — producción</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.35rem; }
        .banner { padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .banner.ok { background: #d1fae5; color: #065f46; }
        .banner.fail { background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e7eb; }
        .status-ok { color: #059669; font-weight: 600; }
        .status-fail { color: #dc2626; font-weight: 600; }
        .muted { color: #6b7280; font-size: 0.875rem; }
    </style>
</head>
<body>
    <h1>Verificación post-despliegue</h1>
    <p class="muted">Generado: <?= htmlspecialchars(date('Y-m-d H:i:s')) ?> · Elimine este archivo cuando termine.</p>

    <div class="banner <?= $allOk ? 'ok' : 'fail' ?>">
        <?= $allOk ? '✓ Entorno listo' : '✗ Revise los puntos en rojo antes de dar por cerrado el deploy' ?>
    </div>

    <table>
        <thead>
            <tr><th>Área</th><th>Prueba</th><th>Estado</th><th>Detalle</th></tr>
        </thead>
        <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['grupo']) ?></td>
                    <td><?= htmlspecialchars($c['nombre']) ?></td>
                    <td class="<?= $c['ok'] ? 'status-ok' : 'status-fail' ?>"><?= $c['ok'] ? 'OK' : 'FALLO' ?></td>
                    <td><?= htmlspecialchars($c['detalle']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="muted">SQL recomendados tras subir archivos: <code>sql/migracion_produccion_2026.sql</code>,
        <code>sql/fix_cod_org_organizaciones_particulares.sql</code>.</p>
</body>
</html>
