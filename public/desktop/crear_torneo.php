<?php
/**
 * Crear Torneo (Desktop). Formulario simplificado para uso local.
 * Inserción en tournaments vía db_bridge; entidad_id = getEntidadId(), uuid = uniqid().
 */
declare(strict_types=1);
require_once __DIR__ . '/desktop_auth.php';
require_once __DIR__ . '/../../desktop/core/db_bridge.php';
require_once __DIR__ . '/db_local.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $clase = (int)($_POST['clase'] ?? 0);
    $modalidad = (int)($_POST['modalidad'] ?? 0);
    $tiempo = (int)($_POST['tiempo'] ?? 35);
    $puntos = (int)($_POST['puntos'] ?? 200);
    $rondas = (int)($_POST['rondas'] ?? 9);
    $costo = (int)($_POST['costo'] ?? 0);

    if ($nombre === '') {
        $error = 'El nombre del torneo es obligatorio.';
    } elseif ($rondas < 1) {
        $error = 'La cantidad de rondas debe ser al menos 1.';
    } else {
        try {
            DB_Local::pdo();
            $pdo = DB::pdo();
            $entidad_id = DB::getEntidadId();
            $uuid = uniqid('torneo_', true);
            $estatus = 1;
            $now = date('Y-m-d H:i:s');

            $cols = $pdo->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
            $hasParentEventId = is_array($cols) && in_array('parent_event_id', $cols, true);
            $sql = "INSERT INTO tournaments (
                nombre, clase, modalidad, tiempo, puntos, rondas, costo, estatus,
                entidad, uuid, afiche, normas, invitacion, fechator, created_at, last_updated";
            $sql .= $hasParentEventId ? ", parent_event_id" : "";
            $sql .= ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
            $sql .= $hasParentEventId ? ", 0" : "";
            $sql .= ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nombre, $clase, $modalidad, $tiempo, $puntos, $rondas, $costo, $estatus,
                $entidad_id, $uuid, null, null, null, $now, $now, $now
            ]);
            $nuevo_id = (int) $pdo->lastInsertId();
            header('Location: panel_torneo.php?torneo_id=' . $nuevo_id . '&msg=creado');
            exit;
        } catch (Throwable $e) {
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Crear Torneo';
$desktopActive = 'torneos';
require_once __DIR__ . '/desktop_layout.php';
?>
<div class="container-fluid py-3">
    <h2 class="h4 mb-3"><i class="fas fa-plus-circle text-primary me-2"></i>Crear Torneo</h2>
    <p class="text-muted small mb-4">Formulario simplificado para uso local. Afiches, normas y publicación se gestionan en la web si aplica.</p>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0">Datos del torneo</h5>
        </div>
        <div class="card-body">
            <form method="post" action="crear_torneo.php">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Nombre del Torneo</label>
                        <input type="text" name="nombre" class="form-control" required
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" placeholder="Ej: Torneo Local 2026">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Clase</label>
                        <select name="clase" class="form-select">
                            <option value="0" <?= (int)($_POST['clase'] ?? 0) === 0 ? 'selected' : '' ?>>Estándar</option>
                            <option value="1" <?= (int)($_POST['clase'] ?? 0) === 1 ? 'selected' : '' ?>>Clase 1</option>
                            <option value="2" <?= (int)($_POST['clase'] ?? 0) === 2 ? 'selected' : '' ?>>Clase 2</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Modalidad</label>
                        <select name="modalidad" class="form-select">
                            <option value="0" <?= (int)($_POST['modalidad'] ?? 0) === 0 ? 'selected' : '' ?>>Individual</option>
                            <option value="3" <?= (int)($_POST['modalidad'] ?? 0) === 3 ? 'selected' : '' ?>>Equipos</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Tiempo por Partida (min)</label>
                        <input type="number" name="tiempo" class="form-control" min="1" max="120"
                               value="<?= (int)($_POST['tiempo'] ?? 35) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Puntos por Victoria</label>
                        <input type="number" name="puntos" class="form-control" min="0"
                               value="<?= (int)($_POST['puntos'] ?? 200) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Cantidad de Rondas</label>
                        <input type="number" name="rondas" class="form-control" min="1" required
                               value="<?= (int)($_POST['rondas'] ?? 9) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Costo de Inscripción</label>
                        <input type="number" name="costo" class="form-control" min="0"
                               value="<?= (int)($_POST['costo'] ?? 0) ?>">
                    </div>
                    <div class="col-12 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Guardar torneo</button>
                        <a href="torneos.php" class="btn btn-outline-secondary ms-2">Volver a Torneos</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</main></body></html>
