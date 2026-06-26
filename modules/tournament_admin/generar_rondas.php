<?php
/**
 * Generar Rondas del Torneo
 *
 * Usa la misma canalización que el panel de gestión: RoundManagerHandler +
 * TorneoMesaAsignacionResolver (individual / parejas fijas / equipos).
 */

// Verificar que la tabla partiresul existe
if (!$tabla_partiresul_existe) {
    echo '<div class="alert alert-danger">';
    echo '<h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Tabla partiresul no encontrada</h6>';
    echo '<p class="mb-2">La tabla <code>partiresul</code> no existe. Para generar rondas, debe crear esta tabla primero.</p>';
    echo '<p class="mb-0">Ejecute: <code>php scripts/migrate_partiresul_table.php</code></p>';
    echo '</div>';
    return;
}

require_once __DIR__ . '/../../config/csrf.php';

// Verificar si el torneo está finalizado
$torneo_finalizado = isset($torneo['finalizado']) && $torneo['finalizado'] == 1;

$error_message_generar = null;

// Procesar generación de ronda (todas las modalidades)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_ronda'])) {
    if ($torneo_finalizado) {
        $error_message_generar = 'No se pueden generar rondas en un torneo finalizado';
    } else {
        CSRF::validate();
        try {
            if (!defined('TORNEO_GESTION_SKIP_ROUTER')) {
                define('TORNEO_GESTION_SKIP_ROUTER', true);
            }
            require_once __DIR__ . '/../torneo_gestion.php';
            \Tournament\Handlers\RoundManagerHandler::ejecutarGeneracionRonda(
                (int) $torneo_id,
                ['redirect_base' => 'tournament_admin']
            );
        } catch (Throwable $e) {
            error_log('generar_rondas (tournament_admin): ' . $e->getMessage());
            $error_message_generar = 'Error al generar ronda: ' . $e->getMessage();
        }
    }
}

// Obtener última ronda generada
$stmt = $pdo->prepare('SELECT MAX(partida) as ultima_ronda FROM partiresul WHERE id_torneo = ?');
$stmt->execute([$torneo_id]);
$ultima_ronda = (int) $stmt->fetchColumn() ?: 0;
$siguiente_ronda = $ultima_ronda + 1;

// Total inscritos (mismo criterio que estadísticas y RoundManagerHandler)
$stmt = $pdo->prepare(
    'SELECT COUNT(*) as total FROM inscritos WHERE torneo_id = ? AND ' . InscritosHelper::SQL_WHERE_ELEGIBLE_MESA
);
$stmt->execute([$torneo_id]);
$total_inscritos = (int) $stmt->fetchColumn();

$modalidad = (int) ($torneo['modalidad'] ?? 0);
$etiqueta_modalidad = 'Individual / estándar';
if ($modalidad === 3) {
    $etiqueta_modalidad = 'Equipos (mesas vía MesaAsignacionEquiposService)';
} elseif (in_array($modalidad, [2, 4], true)) {
    $etiqueta_modalidad = 'Parejas fijas / interclubes';
}
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-shuffle me-2"></i>Generar Rondas
        </h5>
    </div>
    <div class="card-body">
        <?php if ($torneo_finalizado): ?>
            <div class="alert alert-danger">
                <i class="fas fa-lock me-2"></i>
                <strong>Torneo finalizado:</strong> No se pueden generar rondas.
            </div>
        <?php else: ?>
        <?php if (!empty($error_message_generar)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message_generar) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <h6 class="alert-heading">
                        <i class="fas fa-info-circle me-2"></i>Información
                    </h6>
                    <ul class="mb-0">
                        <li>Modalidad: <strong><?= htmlspecialchars($etiqueta_modalidad) ?></strong></li>
                        <li>Inscritos en el torneo: <strong><?= $total_inscritos ?></strong></li>
                        <li>Última ronda generada: <strong><?= $ultima_ronda > 0 ? 'Ronda #' . $ultima_ronda : 'Ninguna' ?></strong></li>
                        <li>Próxima ronda a generar: <strong>Ronda #<?= $siguiente_ronda ?></strong> (automática; debe estar completa la anterior)</li>
                    </ul>
                </div>
            </div>
        </div>

        <form method="POST" action="">
            <?= CSRF::input(); ?>
            <input type="hidden" name="generar_ronda" value="1">

            <div class="alert alert-secondary">
                <i class="fas fa-diagram-project me-2"></i>
                La asignación de mesas usa las mismas rutinas que
                <strong>torneo_gestion</strong> (suizo, BYE, segmentos/homólogos en equipos, etc.).
                No es el reparto lineal antiguo por cantidad de mesas manual.
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="index.php?page=tournament_admin&torneo_id=<?= (int) $torneo_id ?>&action=dashboard"
                   class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver al administrador
                </a>
                <a href="index.php?page=torneo_gestion&action=panel&torneo_id=<?= (int) $torneo_id ?>"
                   class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt me-2"></i>Panel de gestión del torneo
                </a>
                <button type="submit" class="btn btn-primary"<?= $total_inscritos < 4 ? ' disabled title="Se requieren al menos 4 inscritos"' : '' ?>>
                    <i class="fas fa-magic me-2"></i>Generar siguiente ronda
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
