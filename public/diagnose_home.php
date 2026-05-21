<?php
/**
 * Diagnóstico de page=home (HTTP 500 tras login).
 * URL: .../public/diagnose_home.php
 * Eliminar tras corregir el error.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$lines = [];

function line(string $label, bool $ok, string $detail = ''): void
{
    global $lines;
    $lines[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

try {
    require_once $root . '/lib/StoragePaths.php';
    $created = StoragePaths::ensureWritableDirs($root);
    if ($created !== []) {
        line('Storage dirs creados', true, implode(', ', $created));
    }

    require_once $root . '/config/bootstrap.php';
    require_once $root . '/config/db_config.php';

    $modern = class_exists('Env') && Env::bool('MODERN_HOME', false);
    line('MODERN_HOME', true, $modern ? 'true (Tailwind)' : 'false (legacy Bootstrap)');

    $sessionActive = session_status() === PHP_SESSION_ACTIVE;
    $hasUser = $sessionActive && isset($_SESSION['user']) && is_array($_SESSION['user']);
    line('Sesión', true, $sessionActive
        ? ($hasUser ? 'Usuario: ' . ($_SESSION['user']['username'] ?? $_SESSION['user']['email'] ?? '?') . ' · rol=' . ($_SESSION['user']['role'] ?? '?')
            : 'Activa sin $_SESSION[user] (inicie sesión y recargue esta URL)')
        : 'Sin sesión activa');

    if ($hasUser) {
        require_once $root . '/config/auth.php';
        $role = (string) ($_SESSION['user']['role'] ?? '');
        line('Auth::user()', true, 'rol=' . $role);

        if (class_exists(\Core\Http\Context::class)) {
            $ctx = \Core\Http\Context::resolve();
            line('Context::resolve()', true, $ctx);
        }

        if ($modern) {
            try {
                $controller = \Core\Modules\Dashboard\DashboardControllerFactory::make(\Core\Http\Context::resolve());
                if ($controller === null) {
                    line('Dashboard factory', false, 'null — caerá a legacy');
                } else {
                    ob_start();
                    $controller->index();
                    $html = (string) ob_get_clean();
                    line('MODERN_HOME render', strlen($html) > 200, strlen($html) . ' bytes');
                }
            } catch (Throwable $e) {
                line('MODERN_HOME render', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        try {
            if ($role === 'admin_general' && ($_SESSION['user']['role'] ?? '') === 'admin_general') {
                require_once $root . '/lib/OrganizacionesData.php';
                require_once $root . '/lib/ActasPendientesHelper.php';
                OrganizacionesData::loadStatsGlobales();
                ActasPendientesHelper::contar();
                line('Legacy admin_general home', true, 'OrganizacionesData + Actas OK');
            } else {
                require_once $root . '/lib/DashboardData.php';
                DashboardData::loadAll($role, $_SESSION['user']);
                line('Legacy admin_dashboard data', true, 'DashboardData::loadAll OK');
            }
        } catch (Throwable $e) {
            line('Legacy home data', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    } else {
        line('Prueba con login', true, 'Abra login en otra pestaña, inicie sesión, vuelva aquí');
    }
} catch (Throwable $e) {
    line('Bootstrap', false, $e->getMessage());
}

$allOk = !in_array(false, array_column($lines, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Diagnóstico home</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
    .ok { color: #059669; } .fail { color: #dc2626; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 0.5rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
  </style>
</head>
<body>
  <h1>Diagnóstico <code>page=home</code></h1>
  <p class="<?= $allOk ? 'ok' : 'fail' ?>"><?= $allOk ? 'Sin errores detectados en estas pruebas.' : 'Hay fallos — corrija antes de usar Inicio.' ?></p>
  <table>
    <thead><tr><th>Prueba</th><th>Estado</th><th>Detalle</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['label']) ?></td>
        <td class="<?= $row['ok'] ? 'ok' : 'fail' ?>"><?= $row['ok'] ? 'OK' : 'FALLO' ?></td>
        <td><?= htmlspecialchars($row['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><a href="check_env.php">check_env.php</a> · <a href="index.php?page=home">index.php?page=home</a></p>
</body>
</html>
