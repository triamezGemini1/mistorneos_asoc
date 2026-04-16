<?php
/**
 * Migración: club principal → organización (nominal)
 * - Admin_club con club_id (club principal): se crea o vincula organización con datos del club.
 * - Ese club principal pasa a ser club normal con organizacion_id.
 * - Clubes asociados (clubes_asociados) pasan a tener organizacion_id de la misma org.
 * Ejecutar una vez: php scripts/migrate_club_principal_to_organizacion.php
 */
$base = dirname(__DIR__);
require_once $base . '/config/bootstrap.php';
require_once $base . '/config/db.php';

$pdo = DB::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Migración: club principal → organización\n";
echo str_repeat('-', 50) . "\n";

// 1) Admin_club con club_id que aún no tienen organización
$stmt = $pdo->query("
    SELECT u.id as user_id, u.club_id, c.id as club_id, c.nombre, c.direccion, c.delegado, c.telefono, c.email, c.logo
    FROM usuarios u
    INNER JOIN clubes c ON c.id = u.club_id
    LEFT JOIN organizaciones o ON o.admin_user_id = u.id AND o.estatus = 1
    WHERE u.role = 'admin_club' AND u.club_id IS NOT NULL AND o.id IS NULL
");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($admins as $a) {
    $user_id = (int)$a['user_id'];
    $club_id = (int)$a['club_id'];
    echo "Admin user_id=$user_id, club_id=$club_id ({$a['nombre']})\n";

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO organizaciones (nombre, direccion, responsable, telefono, email, entidad, admin_user_id, logo, estatus, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([
            $a['nombre'],
            $a['direccion'] ?? null,
            $a['delegado'] ?? null,
            $a['telefono'] ?? null,
            $a['email'] ?? null,
            $user_id,
            $a['logo'] ?? null
        ]);
        $org_id = (int)$pdo->lastInsertId();
        echo "  -> Organización creada id=$org_id\n";

        $pdo->prepare("UPDATE clubes SET cod_org = ? WHERE id = ?")->execute([$org_id, $club_id]);
        echo "  -> Club $club_id asignado a organización $org_id\n";

        // Tabla clubes_asociados ya no existe; omitir migración de asociados
        $asociados = [];
        try {
            $stmt = $pdo->prepare("SELECT club_asociado_id FROM clubes_asociados WHERE club_principal_id = ?");
            $stmt->execute([$club_id]);
            $asociados = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $ignored) {}
        foreach ($asociados as $aid) {
            $pdo->prepare("UPDATE clubes SET cod_org = ? WHERE id = ?")->execute([$org_id, $aid]);
            echo "  -> Club asociado $aid asignado a organización $org_id\n";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 2) Admin_club que ya tienen organización pero su club_id (principal) no tiene organizacion_id
$stmt = $pdo->query("
    SELECT u.id as user_id, u.club_id, o.id as org_id
    FROM usuarios u
    INNER JOIN clubes c ON c.id = u.club_id
    INNER JOIN organizaciones o ON o.admin_user_id = u.id AND o.estatus = 1
    WHERE u.role = 'admin_club' AND u.club_id IS NOT NULL AND (c.cod_org IS NULL OR c.cod_org != o.id)
");
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pendientes as $p) {
    $club_id = (int)$p['club_id'];
    $org_id = (int)$p['org_id'];
    echo "Vincular club principal $club_id a org $org_id\n";
    try {
        $pdo->prepare("UPDATE clubes SET cod_org = ? WHERE id = ?")->execute([$org_id, $club_id]);
        try {
            $stmt2 = $pdo->prepare("SELECT club_asociado_id FROM clubes_asociados WHERE club_principal_id = ?");
            $stmt2->execute([$club_id]);
            foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $pdo->prepare("UPDATE clubes SET cod_org = ? WHERE id = ?")->execute([$org_id, $aid]);
            }
        } catch (Exception $ignored) {}
        echo "  OK\n";
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nMigración finalizada.\n";
