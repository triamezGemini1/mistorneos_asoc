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

// Crear storage/logs y storage/sessions antes de comprobar permisos
if (is_file($root . '/lib/StoragePaths.php')) {
    require_once $root . '/lib/StoragePaths.php';
    StoragePaths::ensureWritableDirs($root);
}

// --- Bootstrap y BD (credenciales vía config, no auth.php) ---
$envLabel = 'N/A';
$dbOk = false;
$dbMsg = '';
$appEnv = 'N/A';
$modernHome = 'N/A';

try {
    if (!is_file($root . '/config/bootstrap.php')) {
        throw new RuntimeException('Falta config/bootstrap.php');
    }
    require_once $root . '/config/bootstrap.php';
    require_once $root . '/config/db_config.php';

    $modernHome = class_exists('Env') ? (Env::bool('MODERN_HOME', false) ? 'true' : 'false') : 'sin Env';

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

// --- Carpetas escribibles (tras bootstrap / mkdir) ---
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

$checks[] = [
    'grupo' => 'Aplicación',
    'nombre' => 'APP_ENV / entorno',
    'ok' => $envLabel !== 'N/A',
    'detalle' => $envLabel,
];
$checks[] = [
    'grupo' => 'Aplicación',
    'nombre' => 'MODERN_HOME (.env)',
    'ok' => true,
    'detalle' => $modernHome . ' — false = inicio legacy (recomendado en beta)',
];
$checks[] = [
    'grupo' => 'Base de datos',
    'nombre' => 'DB::pdo() (config/db_config.php)',
    'ok' => $dbOk,
    'detalle' => $dbMsg,
];

// --- Inicio (page=home) sin sesión: archivos y carga de estadísticas ---
$homeFiles = [
    'modules/home.php' => $root . '/modules/home.php',
    'admin_general home' => $root . '/modules/admin_general/views/home.php',
    'views Dashboard federacion' => $root . '/views/modules/Dashboard/federacion.php',
    'core Dashboard factory' => $root . '/core/Modules/Dashboard/DashboardControllerFactory.php',
    'output.css Tailwind' => $root . '/public/assets/dist/output.css',
];

$estructuraFiles = [
    'layout menú Estructura' => $root . '/public/includes/layout.php',
    'organizaciones_particulares' => $root . '/modules/organizaciones_particulares.php',
    'torneos_estructura' => $root . '/modules/torneos_estructura.php',
    'TorneosEstructuraService' => $root . '/lib/TorneosEstructuraService.php',
    'OrganizacionDashboardStats' => $root . '/lib/OrganizacionDashboardStats.php',
];
foreach ($estructuraFiles as $label => $path) {
    $checks[] = [
        'grupo' => 'Estructura org/torneos',
        'nombre' => $label,
        'ok' => is_file($path),
        'detalle' => is_file($path) ? 'Presente' : 'FALTA — deploy incompleto (FTP #609/#610 fallaron)',
    ];
}

$layoutHasParticulares = false;
$layoutPath = $root . '/public/includes/layout.php';
if (is_file($layoutPath)) {
    $layoutSnippet = (string) file_get_contents($layoutPath, false, null, 0, 200000);
    $layoutHasParticulares = str_contains($layoutSnippet, 'organizaciones_particulares')
        && str_contains($layoutSnippet, "context' => 'particulares'");
}
$checks[] = [
    'grupo' => 'Estructura org/torneos',
    'nombre' => 'Menú Org. particulares en layout',
    'ok' => $layoutHasParticulares,
    'detalle' => $layoutHasParticulares
        ? 'layout.php actualizado'
        : 'layout.php viejo — subir public/includes/layout.php',
];
foreach ($homeFiles as $label => $path) {
    $checks[] = [
        'grupo' => 'Inicio (home)',
        'nombre' => $label,
        'ok' => is_file($path),
        'detalle' => is_file($path) ? 'Presente' : 'FALTA — subir en deploy',
    ];
}

$statsOk = false;
$statsDetail = 'Bootstrap no cargado';
if ($dbOk) {
    try {
        require_once $root . '/lib/OrganizacionesData.php';
        OrganizacionesData::loadStatsGlobales();
        $statsOk = true;
        $statsDetail = 'OrganizacionesData::loadStatsGlobales OK';
    } catch (Throwable $e) {
        $statsDetail = $e->getMessage();
    }
}
$checks[] = [
    'grupo' => 'Inicio (home)',
    'nombre' => 'Estadísticas admin_general',
    'ok' => $statsOk,
    'detalle' => $statsDetail,
];

if ($modernHome === 'true' && $dbOk) {
    $modernOk = false;
    $modernDetail = '';
    try {
        $contextType = \Core\Http\Context::FEDERACION;
        $controller = \Core\Modules\Dashboard\DashboardControllerFactory::make($contextType);
        if ($controller === null) {
            $modernDetail = 'Factory devolvió null';
        } else {
            ob_start();
            $controller->index();
            $out = ob_get_clean();
            $modernOk = is_string($out) && strlen($out) > 100;
            $modernDetail = $modernOk
                ? 'Render MODERN_HOME OK (' . strlen($out) . ' bytes)'
                : 'Salida vacía o muy corta';
        }
    } catch (Throwable $e) {
        $modernDetail = $e->getMessage();
    }
    $checks[] = [
        'grupo' => 'Inicio (home)',
        'nombre' => 'Render MODERN_HOME (Federación)',
        'ok' => $modernOk,
        'detalle' => $modernDetail,
    ];
}

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
        <code>sql/migracion_estructura_organizaciones_2026.sql</code>,
        <code>sql/fix_cod_org_organizaciones_particulares.sql</code>.</p>
</body>
</html>
