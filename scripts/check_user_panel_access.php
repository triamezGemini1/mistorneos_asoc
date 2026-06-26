<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/lib/AsociacionAdminHelper.php';
require_once dirname(__DIR__) . '/config/auth.php';

$pdo = DB::pdo();
$users = ['trinoamez', 'Trinoamez', 'usuario11489924', 'test_asociacion', 'test_torneo'];

echo "Auth::isOperativoSoloAsociacion existe: " . (method_exists('Auth', 'isOperativoSoloAsociacion') ? 'sí' : 'NO') . PHP_EOL;

foreach ($users as $uname) {
    $st = $pdo->prepare('SELECT id, username, role, club_id FROM usuarios WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$uname, $uname]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        echo "{$uname}: no encontrado" . PHP_EOL;
        continue;
    }
    $uid = (int) $u['id'];
    $role = (string) $u['role'];
    $org = AsociacionAdminHelper::usuarioAdministraOrganizacionActiva($pdo, $uid);
    $part = AsociacionAdminHelper::usuarioAdministraOrganizacionParticular($pdo, $uid);
    $oper = AsociacionAdminHelper::esOperativoSoloAsociacion($pdo, $uid, $role);
    $crear = AsociacionAdminHelper::puedeCrearYAdministrarTorneos($pdo, $uid, $role);
    $club = AsociacionAdminHelper::clubOperativo($pdo, $uid, $role);
    echo "{$uname} (id={$uid}, role={$role}): particular=" . ($part ? 'SÍ' : 'no')
        . " operativo=" . ($oper ? 'SÍ' : 'no')
        . " crear_torneo=" . ($crear ? 'SÍ' : 'no')
        . " club=" . ($club ? (int) $club['id'] : 'null');
    if ($org) {
        echo " org_id=" . ($org['id'] ?? '') . " tipo_org=" . ($org['tipo_org'] ?? 'n/d') . " " . ($org['nombre'] ?? '');
    }
    echo PHP_EOL;
}
