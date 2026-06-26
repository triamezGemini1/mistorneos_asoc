<?php
/**
 * Diagnóstico page=asociacion_panel — eliminar tras corregir.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$rows = [];

function diag(string $label, bool $ok, string $detail = ''): void
{
    global $rows;
    $rows[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

try {
    require_once $root . '/config/bootstrap.php';
    require_once $root . '/config/db_config.php';
    require_once $root . '/config/auth.php';
    require_once $root . '/lib/AsociacionAdminHelper.php';
    require_once $root . '/lib/app_helpers.php';

    $hasUser = session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['user']) && is_array($_SESSION['user']);
    diag('Sesión', $hasUser, $hasUser
        ? 'user=' . ($_SESSION['user']['username'] ?? '?') . ' rol=' . ($_SESSION['user']['role'] ?? '?') . ' id=' . ($_SESSION['user']['id'] ?? '?')
        : 'Sin login — abra login.php, inicie sesión y recargue esta URL');

    if (!$hasUser) {
        throw new RuntimeException('Inicie sesión primero.');
    }

    $pdo = DB::pdo();
    $uid = (int) Auth::id();
    $role = (string) ($_SESSION['user']['role'] ?? '');

    diag('Auth::id()', $uid > 0, (string) $uid);
    diag('isOperativoSoloAsociacion', true, Auth::isOperativoSoloAsociacion() ? 'sí' : 'no');

    $org = AsociacionAdminHelper::usuarioAdministraOrganizacionActiva($pdo, $uid);
    diag('usuarioAdministraOrganizacionActiva', true, $org === null
        ? 'ninguna'
        : 'id=' . ($org['id'] ?? '') . ' nombre=' . ($org['nombre'] ?? '') . ' tipo_org=' . ($org['tipo_org'] ?? 'n/d'));

    $esPart = AsociacionAdminHelper::usuarioAdministraOrganizacionParticular($pdo, $uid);
    diag('usuarioAdministraOrganizacionParticular', !$esPart, $esPart
        ? 'SÍ — index.php redirige a home (panel FVD bloqueado)'
        : 'no');

    $club = AsociacionAdminHelper::clubOperativo($pdo, $uid, $role);
    diag('clubOperativo', $club !== null, $club === null
        ? 'null — el módulo muestra aviso sin club/delegado'
        : 'club id=' . ($club['id'] ?? '') . ' ' . ($club['nombre'] ?? ''));

    if ($club !== null && !$esPart) {
        $orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::ORGANIZACION_ID : 1;
        try {
            $t1 = AsociacionAdminHelper::listarTorneosFvdParaClub($pdo, $club, $orgFvd, 5);
            diag('listarTorneosFvdParaClub', true, count($t1) . ' torneos');
        } catch (Throwable $e) {
            diag('listarTorneosFvdParaClub', false, $e->getMessage());
        }
        try {
            $t2 = AsociacionAdminHelper::listarTorneosFvdMasivos($pdo, $orgFvd, 5);
            diag('listarTorneosFvdMasivos', true, count($t2) . ' torneos');
        } catch (Throwable $e) {
            diag('listarTorneosFvdMasivos', false, $e->getMessage());
        }
    }

    diag('URL panel', true, AppHelpers::dashboard('asociacion_panel'));
    diag('URL home', true, AppHelpers::dashboard('home'));
} catch (Throwable $e) {
    diag('Error fatal', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diagnóstico asociacion_panel</title>';
echo '<style>body{font-family:sans-serif;padding:1.5rem}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:8px}.ok{color:green}.bad{color:#b00}</style></head><body>';
echo '<h1>Diagnóstico asociacion_panel</h1><table><tr><th>Prueba</th><th>Estado</th><th>Detalle</th></tr>';
foreach ($rows as $r) {
    $cls = $r['ok'] ? 'ok' : 'bad';
    echo '<tr><td>' . htmlspecialchars($r['label']) . '</td><td class="' . $cls . '">' . ($r['ok'] ? 'OK' : 'FALLO') . '</td><td>' . htmlspecialchars($r['detail']) . '</td></tr>';
}
echo '</table><p><a href="index.php?page=asociacion_panel">Probar panel</a> · <a href="index.php?page=home">Home</a></p></body></html>';
