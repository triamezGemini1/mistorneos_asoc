<?php
/**
 * Crea o actualiza credenciales de prueba para FVD (CLI).
 *
 * Uso: php scripts/seed_credenciales_fvd_prueba.php
 *      php scripts/seed_credenciales_fvd_prueba.php --password=MiClave123
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Ejecutar solo por CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/lib/security.php';
require_once $root . '/lib/FvdConfig.php';

$opts = getopt('', ['password::']);
$passwordPlano = isset($opts['password']) && (string)$opts['password'] !== ''
    ? (string)$opts['password']
    : 'FvdPrueba2025';

$pdo = DB::pdo();
$orgId = FvdConfig::ORGANIZACION_ID;
$hash = Security::hashPassword($passwordPlano);

/** @return array{id:int,nombre:string}|null */
function pickClub(PDO $pdo, int $orgId): ?array
{
    try {
        $st = $pdo->prepare('SELECT id, nombre FROM clubes WHERE cod_org = ? AND estatus = 1 ORDER BY id ASC LIMIT 1');
        $st->execute([$orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['id' => (int)$row['id'], 'nombre' => (string)$row['nombre']];
        }
    } catch (Throwable $e) {
        // cod_org puede no existir
    }
    $st = $pdo->query('SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY id ASC LIMIT 1');
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'nombre' => (string)$row['nombre']] : null;
}

/** @return int */
function pickEntidad(PDO $pdo): int
{
    try {
        $id = (int)$pdo->query('SELECT id FROM entidad ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        // intentar codigo
    }
    try {
        return (int)$pdo->query('SELECT codigo FROM entidad ORDER BY codigo ASC LIMIT 1')->fetchColumn();
    } catch (Throwable $e) {
        return 1;
    }
}

function upsertUsuario(PDO $pdo, array $def, string $hash): int
{
    $username = $def['username'];
    $cedula = $def['cedula'];

    $st = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? OR cedula = ? ORDER BY id ASC LIMIT 1');
    $st->execute([$username, $cedula]);
    $existingId = (int)$st->fetchColumn();

    if ($existingId > 0) {
        $sql = 'UPDATE usuarios SET username = ?, password_hash = ?, role = ?, status = 0, nombre = ?, email = ?, cedula = ?, club_id = ?, entidad = ? WHERE id = ?';
        $pdo->prepare($sql)->execute([
            $username,
            $hash,
            $def['role'],
            $def['nombre'],
            $def['email'],
            $cedula,
            $def['club_id'],
            $def['entidad'],
            $existingId,
        ]);
        return $existingId;
    }

    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );

    $pdo->prepare('
        INSERT INTO usuarios (username, password_hash, email, role, status, nombre, cedula, club_id, entidad, uuid, created_at)
        VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())
    ')->execute([
        $username,
        $hash,
        $def['email'],
        $def['role'],
        $def['nombre'],
        $def['cedula'],
        $def['club_id'],
        $def['entidad'],
        $uuid,
    ]);

    return (int)$pdo->lastInsertId();
}

function linkAdminOrganizacion(PDO $pdo, int $userId, int $orgId): void
{
    $pdo->prepare('UPDATE organizaciones SET admin_user_id = ?, estatus = 1 WHERE id = ?')
        ->execute([$userId, $orgId]);
}

$club = pickClub($pdo, $orgId);
$entidad = pickEntidad($pdo);
$clubId = $club['id'] ?? null;

$cuentas = [
    [
        'label' => 'Super Admin (admin_general + Control Especial)',
        'username' => 'fvd_superadmin',
        'role' => 'admin_general',
        'nombre' => 'Super Admin FVD Prueba',
        'email' => 'superadmin@fvd.local',
        'cedula' => '99100001',
        'club_id' => null,
        'entidad' => 0,
        'link_org' => false,
    ],
    [
        'label' => 'Admin General',
        'username' => 'fvd_admingral',
        'role' => 'admin_general',
        'nombre' => 'Admin General FVD Prueba',
        'email' => 'admingral@fvd.local',
        'cedula' => '99100002',
        'club_id' => null,
        'entidad' => 0,
        'link_org' => false,
    ],
    [
        'label' => 'Admin Asociación (admin_club)',
        'username' => 'fvd_admin_asoc',
        'role' => 'admin_club',
        'nombre' => 'Admin Asociación FVD Prueba',
        'email' => 'adminasoc@fvd.local',
        'cedula' => '99100003',
        'club_id' => $clubId,
        'entidad' => $entidad,
        'link_org' => true,
    ],
    [
        'label' => 'Usuario / Atleta',
        'username' => 'fvd_usuario',
        'role' => 'usuario',
        'nombre' => 'Usuario FVD Prueba',
        'email' => 'usuario@fvd.local',
        'cedula' => '99100004',
        'club_id' => $clubId,
        'entidad' => $entidad,
        'link_org' => false,
    ],
];

echo "=== Credenciales de prueba FVD ===\n";
echo "Organización anclada: ID {$orgId} — " . FvdConfig::ORGANIZACION_NOMBRE . "\n";
if ($club) {
    echo "Club de referencia: #{$club['id']} {$club['nombre']}\n";
}
echo "Entidad de referencia: {$entidad}\n";
echo str_repeat('-', 60) . "\n";

foreach ($cuentas as $cuenta) {
    $userId = upsertUsuario($pdo, $cuenta, $hash);
    if (!empty($cuenta['link_org'])) {
        linkAdminOrganizacion($pdo, $userId, $orgId);
    }
    printf(
        "%s\n  Usuario: %s\n  Rol:     %s\n  ID BD:   %d\n\n",
        $cuenta['label'],
        $cuenta['username'],
        $cuenta['role'],
        $userId
    );
}

echo str_repeat('=', 60) . "\n";
echo "Contraseña (todos): {$passwordPlano}\n";
$loginPath = $root . '/public/login.php';
echo "Login: http://localhost/mistorneos_fvd/public/login.php\n";
echo "       (o la URL base de tu instalación WAMP + /public/login.php)\n";
echo str_repeat('=', 60) . "\n";
