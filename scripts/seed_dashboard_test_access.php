<?php

declare(strict_types=1);

/**
 * Prepara usuarios y contraseñas conocidas para probar los dashboards Fase 2.
 *
 * Uso (PHP 8.2+):
 *   C:\wamp64\bin\php\php8.2.18\php.exe scripts/seed_dashboard_test_access.php
 *
 * Contraseña común para todas las cuentas de prueba creadas/actualizadas: MistorneosTest2026
 */
if (php_sapi_name() !== 'cli') {
    exit("Ejecutar solo por CLI.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/lib/security.php';

const TEST_PASSWORD = 'MistorneosTest2026';

$pdo = DB::pdo();
$hash = Security::hashPassword(TEST_PASSWORD);

/** @var list<array{username: string, role: string, nombre: string, club_id?: int, entidad?: int, note: string}> */
$accounts = [
    ['username' => 'Trinoamez', 'role' => 'admin_general', 'nombre' => 'Trino Amezquita (Federación)', 'note' => 'Dashboard Federación'],
    ['username' => 'fvd_superadmin', 'role' => 'admin_general', 'nombre' => 'Super Admin FVD Prueba', 'note' => 'Dashboard Federación (alternativo)'],
    ['username' => 'damazava', 'role' => 'admin_club', 'nombre' => 'Damely Zamora (Asociación)', 'club_id' => 4, 'note' => 'Dashboard Asociación — org LA ESTACION DEL DOMINÓ'],
    ['username' => 'Addccaracas', 'role' => 'admin_club', 'nombre' => 'Patrizia Rosciano (Asociación)', 'club_id' => 13, 'note' => 'Dashboard Asociación — DISTRITO CAPITAL'],
    ['username' => 'test_admin_torneo', 'role' => 'admin_torneo', 'nombre' => 'Operador Torneo Prueba', 'club_id' => 4, 'note' => 'Dashboard Club — se crea si no existe'],
    ['username' => 'test_operador', 'role' => 'operador', 'nombre' => 'Operador Mesa Prueba', 'club_id' => 4, 'note' => 'Dashboard Club — se crea si no existe'],
];

echo "=== Seed acceso dashboards (Fase 2) ===\n";
echo 'Contraseña unificada: ' . TEST_PASSWORD . "\n\n";

foreach ($accounts as $acc) {
    $username = $acc['username'];
    $stmt = $pdo->prepare('SELECT id, role, club_id FROM usuarios WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $sql = 'UPDATE usuarios SET password_hash = ?, role = ?, status = 0, nombre = ?';
        $params = [$hash, $acc['role'], $acc['nombre']];
        if (isset($acc['club_id'])) {
            $sql .= ', club_id = ?';
            $params[] = $acc['club_id'];
        }
        $sql .= ' WHERE id = ?';
        $params[] = (int) $row['id'];
        $pdo->prepare($sql)->execute($params);
        echo "[OK] Actualizado: {$username} (id={$row['id']}) — {$acc['note']}\n";
        continue;
    }

    if (!in_array($acc['role'], ['admin_torneo', 'operador'], true)) {
        echo "[SKIP] No existe {$username}; créelo manualmente o use otro usuario del listado.\n";
        continue;
    }

    $clubId = (int) ($acc['club_id'] ?? 0);
    $cedula = 'T' . str_pad((string) abs(crc32($username)), 8, '0', STR_PAD_LEFT);
    $cols = $pdo->query('SHOW COLUMNS FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);
    $fields = ['username', 'password_hash', 'role', 'status', 'nombre', 'email', 'cedula', 'nacionalidad', 'sexo', 'entidad', 'club_id'];
    $values = [
        $username,
        $hash,
        $acc['role'],
        0,
        $acc['nombre'],
        $username . '@test.mistorneos.local',
        $cedula,
        'V',
        'M',
        0,
        $clubId,
    ];
    if (in_array('uuid', $cols, true)) {
        $fields[] = 'uuid';
        $values[] = Security::uuidV4();
    }
    if (in_array('is_active', $cols, true)) {
        $fields[] = 'is_active';
        $values[] = 1;
    }

    $ph = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO usuarios (' . implode(',', $fields) . ') VALUES (' . $ph . ')';
    $pdo->prepare($sql)->execute($values);
    $newId = (int) $pdo->lastInsertId();
    echo "[OK] Creado: {$username} (id={$newId}) — {$acc['note']}\n";
}

echo "\n=== Resumen de acceso ===\n";
printf("%-22s %-18s %-12s %s\n", 'Usuario', 'Contraseña', 'Dashboard', 'Notas');
echo str_repeat('-', 90) . "\n";

$rows = [
    ['Trinoamez', TEST_PASSWORD, 'Federación', 'index.php?page=home'],
    ['fvd_superadmin', TEST_PASSWORD, 'Federación', 'alternativo admin_general'],
    ['damazava', TEST_PASSWORD, 'Asociación', 'org#4 — evitar evguacara (nombre FVD → Federación)'],
    ['Addccaracas', TEST_PASSWORD, 'Asociación', 'org#3 Distrito Capital'],
    ['test_admin_torneo', TEST_PASSWORD, 'Club', 'club_id=4 — mesas / torneos activos'],
    ['test_operador', TEST_PASSWORD, 'Club', 'club_id=4 — mismo panel Club'],
    ['ramaguza', 'npi2025*', 'Invitado (legacy)', 'role=usuario → admin_dashboard legacy, no Fase 2'],
];

foreach ($rows as $r) {
    printf("%-22s %-18s %-12s %s\n", $r[0], $r[1], $r[2], $r[3]);
}

echo "\nURL login: http://localhost/mistorneos/public/login.php\n";
echo "URL home:  http://localhost/mistorneos/public/index.php?page=home\n";
echo "\n* Si ramaguza no usa npi2025, ejecute seed_usuarios_prueba o cambie su clave manualmente.\n";
