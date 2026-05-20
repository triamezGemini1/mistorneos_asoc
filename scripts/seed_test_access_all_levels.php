<?php

declare(strict_types=1);

/**
 * Crea desde cero usuarios y datos mínimos para probar TODOS los niveles de acceso.
 * Idempotente: re-ejecutar actualiza contraseñas y repara vínculos org/club.
 *
 * Uso:
 *   C:\wamp64\bin\php\php8.2.18\php.exe scripts/seed_test_access_all_levels.php
 */
if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/lib/security.php';

const TEST_PASSWORD = 'MistorneosTest2026';
const TEST_ENTIDAD = 1;

$pdo = DB::pdo();

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $q = $pdo->quote($column);
        $cache[$key] = (bool) $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}")->fetch();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function upsertUser(PDO $pdo, array $data): int
{
    $username = $data['username'];
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existingId = $stmt->fetchColumn();

    $payload = array_merge($data, [
        'password' => TEST_PASSWORD,
        'status' => 0,
        'nacionalidad' => $data['nacionalidad'] ?? 'V',
        'sexo' => $data['sexo'] ?? 'M',
        'email' => $data['email'] ?? ($username . '@test.mistorneos.local'),
        'cedula' => $data['cedula'] ?? ('99' . str_pad((string) abs(crc32('mt_' . $username)), 7, '0', STR_PAD_LEFT)),
    ]);

    if ($existingId) {
        $payload['password'] = TEST_PASSWORD;
        $hash = Security::hashPassword(TEST_PASSWORD);
        $sql = 'UPDATE usuarios SET password_hash = ?, role = ?, status = 0, nombre = ?, email = ?, cedula = ?, club_id = ?, entidad = ?';
        $params = [
            $hash,
            $payload['role'],
            $payload['nombre'],
            $payload['email'],
            $payload['cedula'],
            (int) ($payload['club_id'] ?? 0),
            (int) ($payload['entidad'] ?? TEST_ENTIDAD),
        ];
        if (hasColumn($pdo, 'usuarios', 'cod_org') && isset($payload['cod_org'])) {
            $sql .= ', cod_org = ?';
            $params[] = (int) $payload['cod_org'];
        }
        $sql .= ' WHERE id = ?';
        $params[] = (int) $existingId;
        $pdo->prepare($sql)->execute($params);

        return (int) $existingId;
    }

    if (($payload['role'] ?? '') === 'usuario') {
        $payload['_allow_club_for_usuario'] = true;
    }

    $result = Security::createUser($payload);
    if (!$result['success']) {
        throw new RuntimeException("No se pudo crear {$username}: " . implode('; ', $result['errors']));
    }

    return (int) $result['user_id'];
}

echo "=== Seed acceso — todos los niveles ===\n";
echo 'Contraseña: ' . TEST_PASSWORD . "\n";
echo 'Base de datos: ' . ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '?') . "\n\n";

$pdo->beginTransaction();

try {
    // 1) Club provisional (admin_club requiere club_id)
    $clubNombre = 'CLUB PRUEBA MIS TORNEOS';
    $stmt = $pdo->prepare('SELECT id FROM clubes WHERE nombre = ? LIMIT 1');
    $stmt->execute([$clubNombre]);
    $clubId = (int) ($stmt->fetchColumn() ?: 0);

    if ($clubId <= 0) {
        $clubFields = ['nombre', 'estatus', 'entidad'];
        $clubValues = [$clubNombre, 1, TEST_ENTIDAD];
        if (hasColumn($pdo, 'clubes', 'delegado')) {
            $clubFields[] = 'delegado';
            $clubValues[] = 'Delegado Prueba';
        }
        $ph = implode(',', array_fill(0, count($clubFields), '?'));
        $pdo->prepare('INSERT INTO clubes (' . implode(',', $clubFields) . ") VALUES ({$ph})")->execute($clubValues);
        $clubId = (int) $pdo->lastInsertId();
        echo "[OK] Club creado id={$clubId}\n";
    } else {
        echo "[OK] Club existente id={$clubId}\n";
    }

    // 2) Usuarios por rol
    $idFederacion = upsertUser($pdo, [
        'username' => 'test_federacion',
        'role' => 'admin_general',
        'nombre' => 'Admin General Prueba',
        'entidad' => TEST_ENTIDAD,
        'club_id' => 0,
        'cedula' => '99100001',
    ]);
    echo "[OK] test_federacion (admin_general) id={$idFederacion}\n";

    $idAsociacion = upsertUser($pdo, [
        'username' => 'test_asociacion',
        'role' => 'admin_club',
        'nombre' => 'Admin Asociación Prueba',
        'entidad' => TEST_ENTIDAD,
        'club_id' => $clubId,
        'cedula' => '99100002',
    ]);
    echo "[OK] test_asociacion (admin_club) id={$idAsociacion}\n";

    $pdo->prepare('UPDATE clubes SET admin_club_id = ? WHERE id = ?')->execute([$idAsociacion, $clubId]);

    $idTorneo = upsertUser($pdo, [
        'username' => 'test_torneo',
        'role' => 'admin_torneo',
        'nombre' => 'Admin Torneo Prueba',
        'entidad' => TEST_ENTIDAD,
        'club_id' => $clubId,
        'cedula' => '99100003',
    ]);
    echo "[OK] test_torneo (admin_torneo) id={$idTorneo}\n";

    $idOperador = upsertUser($pdo, [
        'username' => 'test_operador',
        'role' => 'operador',
        'nombre' => 'Operador Prueba',
        'entidad' => TEST_ENTIDAD,
        'club_id' => $clubId,
        'cedula' => '99100004',
    ]);
    echo "[OK] test_operador (operador) id={$idOperador}\n";

    $idAtleta = upsertUser($pdo, [
        'username' => 'test_atleta',
        'role' => 'usuario',
        'nombre' => 'Atleta Prueba',
        'entidad' => TEST_ENTIDAD,
        'club_id' => $clubId,
        'cedula' => '99110005',
    ]);
    echo "[OK] test_atleta (usuario) id={$idAtleta}\n";

    // 3) Organización territorial (nombre SIN "Federación Venezolana" para dashboard asociación)
    $orgNombre = 'ASOCIACION PRUEBA MIS TORNEOS';
    $stmt = $pdo->prepare('SELECT id FROM organizaciones WHERE nombre = ? LIMIT 1');
    $stmt->execute([$orgNombre]);
    $orgId = (int) ($stmt->fetchColumn() ?: 0);

    if ($orgId <= 0) {
        $orgFields = ['nombre', 'entidad', 'admin_user_id', 'estatus', 'email'];
        $orgValues = [$orgNombre, TEST_ENTIDAD, $idAsociacion, 1, 'asoc-prueba@test.mistorneos.local'];
        if (hasColumn($pdo, 'organizaciones', 'tipo_org')) {
            $orgFields[] = 'tipo_org';
            $orgValues[] = 0;
        }
        if (hasColumn($pdo, 'organizaciones', 'cod_org')) {
            $orgFields[] = 'cod_org';
            $orgValues[] = 0;
        }
        $ph = implode(',', array_fill(0, count($orgFields), '?'));
        $pdo->prepare('INSERT INTO organizaciones (' . implode(',', $orgFields) . ") VALUES ({$ph})")->execute($orgValues);
        $orgId = (int) $pdo->lastInsertId();
        echo "[OK] Organización creada id={$orgId}\n";
    } else {
        $pdo->prepare('UPDATE organizaciones SET admin_user_id = ?, estatus = 1, entidad = ? WHERE id = ?')
            ->execute([$idAsociacion, TEST_ENTIDAD, $orgId]);
        echo "[OK] Organización existente id={$orgId}\n";
    }

    if (hasColumn($pdo, 'organizaciones', 'cod_org')) {
        $pdo->prepare('UPDATE organizaciones SET cod_org = ? WHERE id = ? AND (cod_org IS NULL OR cod_org = 0)')
            ->execute([$orgId, $orgId]);
    }

    if (hasColumn($pdo, 'clubes', 'cod_org')) {
        $pdo->prepare('UPDATE clubes SET cod_org = ?, entidad = ? WHERE id = ?')->execute([$orgId, TEST_ENTIDAD, $clubId]);
    }

    // 4) Organización FVD (solo si se quiere probar nombre federación en Context)
    $orgFvdNombre = 'FEDERACION VENEZOLANA DE DOMINO PRUEBA';
    $stmt = $pdo->prepare('SELECT id FROM organizaciones WHERE nombre = ? LIMIT 1');
    $stmt->execute([$orgFvdNombre]);
    if (!$stmt->fetchColumn()) {
        $fields = ['nombre', 'entidad', 'admin_user_id', 'estatus'];
        $values = [$orgFvdNombre, TEST_ENTIDAD, $idFederacion, 1];
        if (hasColumn($pdo, 'organizaciones', 'tipo_org')) {
            $fields[] = 'tipo_org';
            $values[] = 0;
        }
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare('INSERT INTO organizaciones (' . implode(',', $fields) . ") VALUES ({$ph})")->execute($values);
        echo "[OK] Org FVD prueba creada (opcional)\n";
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

$base = 'http://localhost/mistorneos/public';
if (php_sapi_name() !== 'cli' && class_exists('AppHelpers')) {
    $base = rtrim(AppHelpers::getPublicUrl(), '/');
}

echo "\n" . str_repeat('=', 72) . "\n";
echo "CREDENCIALES DE PRUEBA (contraseña: " . TEST_PASSWORD . ")\n";
echo str_repeat('=', 72) . "\n";
printf("%-20s %-18s %-18s %s\n", 'Usuario', 'Rol', 'Panel', 'Notas');
echo str_repeat('-', 72) . "\n";

$rows = [
    ['test_federacion', 'admin_general', 'Dashboard global', '10 tarjetas estadísticas, menú completo'],
    ['test_asociacion', 'admin_club', 'Mi organización', "Org: ASOCIACION PRUEBA, club_id={$clubId}"],
    ['test_torneo', 'admin_torneo', 'Dashboard torneo', "club_id={$clubId}"],
    ['test_operador', 'operador', 'Dashboard operador', "club_id={$clubId}"],
    ['test_atleta', 'usuario', 'Portal usuario', 'Sin panel admin'],
];

foreach ($rows as $r) {
    printf("%-20s %-18s %-18s %s\n", $r[0], $r[1], $r[2], $r[3]);
}

echo "\nLogin:  {$base}/login.php\n";
echo "Inicio: {$base}/index.php?page=home\n";
echo "\nSwitch de rol (admin_general): usar test_federacion y cambiar perfil en Mi Perfil.\n";
