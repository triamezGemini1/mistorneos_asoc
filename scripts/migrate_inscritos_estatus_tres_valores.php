<?php
/**
 * Migración: unificar estatus de inscritos a solo tres valores.
 *
 * - pendiente: inscrito en línea sin confirmación de pago
 * - confirmado: inscrito en línea con pago verificado, o inscripción en sitio
 * - retirado: retirado
 *
 * Acción: actualiza todos los registros con estatus 'solvente' o 'no_solvente' a 'confirmado'.
 *
 * Uso:
 *   Local:     Asegúrate de tener en .env credenciales de tu MySQL local (APP_ENV=development, DB_*).
 *   Producción: Ejecutar en el servidor o con .env de producción.
 *
 *   php scripts/migrate_inscritos_estatus_tres_valores.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = DB::pdo();
} catch (PDOException $e) {
    echo "Error de conexión a la base de datos:\n  " . $e->getMessage() . "\n\n";
    if (php_sapi_name() === 'cli') {
        echo "Si ejecutas en LOCAL, revisa tu archivo .env:\n";
        echo "  - APP_ENV=development\n";
        echo "  - DB_HOST=localhost\n";
        echo "  - DB_DATABASE=mistorneos_fvd (o el nombre de tu BD local)\n";
        echo "  - DB_USERNAME=root (o tu usuario MySQL local)\n";
        echo "  - DB_PASSWORD= (contraseña de MySQL local)\n\n";
        echo "El usuario actual en .env parece de producción; en local usa credenciales de tu WAMP/XAMPP.\n";
    }
    exit(1);
}

echo "Migración: inscritos.estatus -> solo pendiente, confirmado, retirado\n";
echo str_repeat('-', 60) . "\n";

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM inscritos WHERE estatus IN ('solvente','no_solvente')");
    $count = (int) $stmt->fetchColumn();
    echo "Registros a actualizar (solvente/no_solvente -> confirmado): $count\n";

    if ($count === 0) {
        echo "No hay registros que migrar. Finalizado.\n";
        exit(0);
    }

    $upd = $pdo->prepare("UPDATE inscritos SET estatus = 'confirmado' WHERE estatus IN ('solvente','no_solvente')");
    $upd->execute();
    $affected = $upd->rowCount();
    echo "Actualizados: $affected registros.\n";
    echo "Migración completada correctamente.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
