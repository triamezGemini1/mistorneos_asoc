<?php
/**
 * Añade columna entidad a clubes y tournaments si no existe.
 * Rellena entidad desde la organización correspondiente.
 * Requiere: clubes.cod_org y organizaciones.entidad.
 */
$base = dirname(__DIR__);
require_once $base . '/config/bootstrap.php';
require_once $base . '/config/db.php';

$pdo = DB::pdo();
echo "=== Migración: entidad en clubes y tournaments ===\n";

// 1) Clubes: añadir entidad si no existe
try {
    $cols = $pdo->query("SHOW COLUMNS FROM clubes LIKE 'entidad'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE clubes ADD COLUMN entidad INT NOT NULL DEFAULT 0 COMMENT 'Entidad de la organización' AFTER organizacion_id");
        $pdo->exec("ALTER TABLE clubes ADD KEY idx_clubes_entidad (entidad)");
        echo "Columna clubes.entidad añadida.\n";
    } else {
        echo "Columna clubes.entidad ya existe.\n";
    }
} catch (Exception $e) {
    echo "Error clubes.entidad: " . $e->getMessage() . "\n";
}

// Rellenar clubes.entidad desde organizaciones
try {
    $stmt = $pdo->query("
        UPDATE clubes c
        INNER JOIN organizaciones o ON o.id = c.cod_org
        SET c.entidad = o.entidad
        WHERE c.cod_org IS NOT NULL
    ");
    $n = $stmt->rowCount();
    echo "Clubes actualizados con entidad desde organización: $n\n";
} catch (Exception $e) {
    echo "Error actualizando clubes.entidad: " . $e->getMessage() . "\n";
}

// 2) Tournaments: añadir entidad y owner_user_id si no existen
try {
    $cols = $pdo->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('entidad', $cols)) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN entidad INT NOT NULL DEFAULT 0 COMMENT 'Entidad de la organización' AFTER organizacion_id");
        $pdo->exec("ALTER TABLE tournaments ADD KEY idx_tournaments_entidad (entidad)");
        echo "Columna tournaments.entidad añadida.\n";
    }
    if (!in_array('owner_user_id', $cols)) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN owner_user_id INT NULL COMMENT 'Usuario que registró el torneo' AFTER organizacion_id");
        echo "Columna tournaments.owner_user_id añadida.\n";
    }
} catch (Exception $e) {
    echo "Error tournaments: " . $e->getMessage() . "\n";
}

// Rellenar tournaments.entidad desde organizaciones (club_responsable = id organización)
try {
    $stmt = $pdo->query("
        UPDATE tournaments t
        INNER JOIN organizaciones o ON o.id = t.club_responsable
        SET t.entidad = o.entidad
        WHERE t.club_responsable IS NOT NULL
    ");
    $n = $stmt->rowCount();
    echo "Tournaments actualizados con entidad desde organización: $n\n";
} catch (Exception $e) {
    echo "Error actualizando tournaments.entidad: " . $e->getMessage() . "\n";
}

echo "=== Fin migración ===\n";
