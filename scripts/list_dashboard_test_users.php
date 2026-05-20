<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
require_once $root . '/config/db.php';

$pdo = DB::pdo();

$hasTipoOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'tipo_org'")->fetch();
$hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch();
$orgCols = 'o.id AS org_id, o.nombre AS org_nombre, o.entidad AS org_entidad';
if ($hasCodOrg) {
    $orgCols .= ', o.cod_org';
}
if ($hasTipoOrg) {
    $orgCols .= ', o.tipo_org';
}

echo "=== Usuarios por rol (status=0 activo) ===\n";
echo 'tipo_org: ' . ($hasTipoOrg ? 'sí' : 'no') . ' | cod_org: ' . ($hasCodOrg ? 'sí' : 'no') . "\n\n";

$roles = ['admin_general', 'admin_club', 'admin_torneo', 'operador', 'usuario'];

foreach ($roles as $role) {
    echo "--- {$role} ---\n";
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.nombre, u.club_id, u.entidad, u.status,
                {$orgCols},
                c.nombre AS club_nombre
         FROM usuarios u
         LEFT JOIN organizaciones o ON o.admin_user_id = u.id AND o.estatus = 1
         LEFT JOIN clubes c ON c.id = u.club_id
         WHERE u.role = ? AND u.status = 0
         ORDER BY u.id ASC
         LIMIT 8"
    );
    $stmt->execute([$role]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        echo "  (sin usuarios activos)\n";
    }
    foreach ($rows as $r) {
        $extra = '';
        if ($role === 'admin_club' && !empty($r['org_id'])) {
            $tipo = $hasTipoOrg ? (int) ($r['tipo_org'] ?? -1) : -1;
            $ctx = $tipo === 1 ? 'CLUB' : ($tipo === 0 ? 'ASOCIACION' : 'ASOCIACION?');
            $extra = " | org#{$r['org_id']} {$r['org_nombre']}" . ($hasTipoOrg ? " tipo_org={$tipo} → {$ctx}" : '');
        }
        if (in_array($role, ['admin_torneo', 'operador'], true) && !empty($r['club_id'])) {
            $extra = " | club#{$r['club_id']} {$r['club_nombre']}";
        }
        echo "  id={$r['id']} user={$r['username']} nombre={$r['nombre']}{$extra}\n";
    }
    echo "\n";
}

echo "=== Organizaciones activas (muestra) ===\n";
$orgSelect = 'id, nombre, entidad, admin_user_id, estatus';
if ($hasCodOrg) {
    $orgSelect .= ', cod_org';
}
if ($hasTipoOrg) {
    $orgSelect .= ', tipo_org';
}
$stmt = $pdo->query(
    "SELECT {$orgSelect} FROM organizaciones WHERE estatus = 1 ORDER BY id ASC LIMIT 15"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $tipo = $hasTipoOrg ? (' tipo_org=' . ($o['tipo_org'] ?? 'NULL')) : '';
    echo "  org#{$o['id']} {$o['nombre']}{$tipo} admin_user={$o['admin_user_id']}\n";
}

echo "\n=== admin_torneo / operador (cualquier status) ===\n";
$stmt = $pdo->query(
    "SELECT u.id, u.username, u.role, u.status, u.club_id, c.nombre AS club_nombre
     FROM usuarios u
     LEFT JOIN clubes c ON c.id = u.club_id
     WHERE u.role IN ('admin_torneo', 'operador')
     ORDER BY u.status ASC, u.id ASC
     LIMIT 15"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['role']} id={$r['id']} user={$r['username']} status={$r['status']} club#{$r['club_id']} {$r['club_nombre']}\n";
}

echo "\n=== Sugerencia dashboard CLUB (admin_club con club_id) ===\n";
$stmt = $pdo->query(
    "SELECT u.id, u.username, u.club_id, c.nombre AS club_nombre, o.id AS org_id, o.nombre AS org_nombre
     FROM usuarios u
     INNER JOIN clubes c ON c.id = u.club_id AND c.estatus = 1
     LEFT JOIN organizaciones o ON o.admin_user_id = u.id
     WHERE u.role = 'admin_club' AND u.status = 0 AND u.club_id > 0
     LIMIT 5"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  user={$r['username']} club#{$r['club_id']} {$r['club_nombre']}\n";
}

echo "\n=== Torneos activos hoy (muestra para mesas) ===\n";
$hasFin = (bool) $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'finalizado'")->fetch();
$finSql = $hasFin ? ' AND COALESCE(t.finalizado,0)=0' : '';
$stmt = $pdo->query(
    "SELECT t.id, t.nombre, t.fechator, t.club_responsable
     FROM tournaments t
     WHERE t.estatus = 1 {$finSql}
       AND (t.fechator >= CURDATE() OR t.fechator IS NULL)
     ORDER BY t.fechator ASC LIMIT 5"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
    echo "  torneo#{$t['id']} {$t['nombre']} fecha={$t['fechator']} resp={$t['club_responsable']}\n";
}
