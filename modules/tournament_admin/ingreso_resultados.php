<?php
/**
 * Ingreso de Resultados de Partidas
 */

require_once __DIR__ . '/../../lib/InscritosPartiresulHelper.php';
require_once __DIR__ . '/../../config/csrf.php';

// Verificar si el torneo está finalizado (admin_general puede modificar para correcciones)
$torneo_finalizado = isset($torneo['finalizado']) && $torneo['finalizado'] == 1;
$admin_general_puede_corregir = Auth::isAdminGeneral();
if ($torneo_finalizado && !$admin_general_puede_corregir) {
    echo '<div class="alert alert-danger">';
    echo '<h6 class="alert-heading"><i class="fas fa-lock me-2"></i>Torneo Finalizado</h6>';
    echo '<p class="mb-2">Este torneo ha sido finalizado. No se pueden ingresar o modificar resultados.</p>';
    echo '<p class="mb-0">Puede ver los resultados finales en la sección "Mostrar Resultados".</p>';
    echo '</div>';
    return;
}

// Verificar que la tabla partiresul existe
if (!$tabla_partiresul_existe) {
    echo '<div class="alert alert-danger">';
    echo '<h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Tabla partiresul no encontrada</h6>';
    echo '<p class="mb-2">La tabla <code>partiresul</code> no existe. Para ingresar resultados, debe crear esta tabla primero.</p>';
    echo '<p class="mb-0">Ejecute: <code>php scripts/migrate_partiresul_table.php</code></p>';
    echo '</div>';
    return;
}

// Procesar guardado de resultados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_resultados'])) {
    if ($torneo_finalizado && !$admin_general_puede_corregir) {
        $error_message = 'No se pueden modificar resultados de un torneo finalizado';
    } else {
        CSRF::validate();
        
        $partida = (int)($_POST['partida'] ?? 0);
        $mesa = (int)($_POST['mesa'] ?? 0);
        $resultados = $_POST['resultados'] ?? [];
        
        if ($partida <= 0 || $mesa <= 0) {
            $error_message = 'Debe especificar partida y mesa';
        } elseif (empty($resultados)) {
            $error_message = 'Debe ingresar al menos un resultado';
        } else {
        try {
            $pdo->beginTransaction();
            
            foreach ($resultados as $partiresul_id => $resultado) {
                $partiresul_id = (int)$partiresul_id;
                $resultado1 = (int)($resultado['resultado1'] ?? 0);
                $resultado2 = (int)($resultado['resultado2'] ?? 0);
                $efectividad = (int)($resultado['efectividad'] ?? 0);
                $ff = isset($resultado['ff']) ? 1 : 0;
                $tarjeta = (int)($resultado['tarjeta'] ?? 0);
                $sancion = (int)($resultado['sancion'] ?? 0);
                $chancleta = (int)($resultado['chancleta'] ?? 0);
                $zapato = (int)($resultado['zapato'] ?? 0);
                $observaciones = trim($resultado['observaciones'] ?? '');
                $registrado = isset($resultado['registrado']) ? 1 : 0;
                
                // Actualizar partiresul
                $stmt = $pdo->prepare("
                    UPDATE partiresul
                    SET resultado1 = ?,
                        resultado2 = ?,
                        efectividad = ?,
                        ff = ?,
                        tarjeta = ?,
                        sancion = ?,
                        chancleta = ?,
                        zapato = ?,
                        observaciones = ?,
                        registrado = ?
                    WHERE id = ? AND id_torneo = ?
                ");
                $stmt->execute([
                    $resultado1,
                    $resultado2,
                    $efectividad,
                    $ff,
                    $tarjeta,
                    $sancion,
                    $chancleta,
                    $zapato,
                    $observaciones,
                    $registrado,
                    $partiresul_id,
                    $torneo_id
                ]);
                
                // Si se marca como registrado, actualizar estadísticas del inscrito
                if ($registrado) {
                    // Obtener id_usuario de esta partida
                    $stmt_user = $pdo->prepare("SELECT id_usuario FROM partiresul WHERE id = ?");
                    $stmt_user->execute([$partiresul_id]);
                    $id_usuario = (int)$stmt_user->fetchColumn();
                    
                    if ($id_usuario > 0) {
                        InscritosPartiresulHelper::actualizarEstadisticas($id_usuario, $torneo_id);
                    }
                }
            }

            $pdo->prepare('UPDATE partiresul SET registrado = 1 WHERE id_torneo = ? AND partida = ? AND mesa = ?')
                ->execute([$torneo_id, $partida, $mesa]);

            $pdo->commit();

            if (!defined('TORNEO_GESTION_SKIP_AUTH')) {
                define('TORNEO_GESTION_SKIP_AUTH', true);
            }
            if (!defined('TORNEO_GESTION_SKIP_ROUTER')) {
                define('TORNEO_GESTION_SKIP_ROUTER', true);
            }
            require_once __DIR__ . '/../torneo_gestion.php';
            recalcularClasificacionSiRondaCompleta($torneo_id, $partida);

            header('Location: index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=ingreso_resultados&success=' . urlencode('Resultados guardados exitosamente'));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Error al guardar resultados: ' . $e->getMessage();
        }
    }
    }
}

// Obtener parámetros
$partida_filtro = isset($_GET['partida']) ? (int)$_GET['partida'] : 0;
$mesa_filtro = isset($_GET['mesa']) ? (int)$_GET['mesa'] : 0;

// Obtener lista de rondas
$stmt = $pdo->prepare("
    SELECT DISTINCT partida
    FROM partiresul
    WHERE id_torneo = ?
    ORDER BY partida DESC
");
$stmt->execute([$torneo_id]);
$rondas_disponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Obtener mesas de la ronda seleccionada
$mesas_disponibles = [];
if ($partida_filtro > 0) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT mesa
        FROM partiresul
        WHERE id_torneo = ? AND partida = ?
        ORDER BY mesa ASC
    ");
    $stmt->execute([$torneo_id, $partida_filtro]);
    $mesas_disponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Obtener partidas de la mesa seleccionada
$partidas_mesa = [];
if ($partida_filtro > 0 && $mesa_filtro > 0) {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.username,
            i.posicion,
            i.efectividad as efectividad_total,
            i.puntos
        FROM partiresul p
        INNER JOIN inscritos i ON p.id_usuario = i.id_usuario AND p.id_torneo = i.torneo_id
        LEFT JOIN usuarios u ON p.id_usuario = u.id
        WHERE p.id_torneo = ? AND p.partida = ? AND p.mesa = ?
        ORDER BY p.secuencia ASC
    ");
    $stmt->execute([$torneo_id, $partida_filtro, $mesa_filtro]);
    $partidas_mesa = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="card">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">
            <i class="fas fa-edit me-2"></i>Ingreso de Resultados
        </h5>
    </div>
    <div class="card-body">
        <!-- Filtros -->
        <div class="row mb-4">
            <div class="col-md-6">
                <label class="form-label fw-bold">Ronda</label>
                <select class="form-select" id="selectRonda" onchange="cambiarRonda(this.value)">
                    <option value="0">-- Seleccione una ronda --</option>
                    <?php foreach ($rondas_disponibles as $ronda): ?>
                        <option value="<?= $ronda ?>" <?= $partida_filtro == $ronda ? 'selected' : '' ?>>
                            Ronda #<?= $ronda ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Mesa</label>
                <select class="form-select" id="selectMesa" onchange="cambiarMesa(this.value)" <?= empty($mesas_disponibles) ? 'disabled' : '' ?>>
                    <option value="0">-- Seleccione una mesa --</option>
                    <?php foreach ($mesas_disponibles as $mesa): ?>
                        <option value="<?= $mesa ?>" <?= $mesa_filtro == $mesa ? 'selected' : '' ?>>
                            Mesa #<?= $mesa ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($torneo_finalizado && !$admin_general_puede_corregir): ?>
            <div class="alert alert-danger">
                <i class="fas fa-lock me-2"></i>
                <strong>Torneo Finalizado:</strong> No se pueden ingresar o modificar resultados de un torneo finalizado.
            </div>
        <?php elseif ($torneo_finalizado && $admin_general_puede_corregir): ?>
            <div class="alert alert-warning mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Modo corrección (Admin General):</strong> Este torneo está finalizado. Puede modificar resultados para atender solicitudes de los administradores organizadores.
            </div>
        <?php endif; ?>
        <?php if (empty($partidas_mesa)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Seleccione una ronda y mesa para ingresar resultados.
            </div>
        <?php else: ?>
            <form method="POST" action="" id="formResultados">
                <?= CSRF::input(); ?>
                <input type="hidden" name="guardar_resultados" value="1">
                <input type="hidden" name="partida" value="<?= $partida_filtro ?>">
                <input type="hidden" name="mesa" value="<?= $mesa_filtro ?>">
                
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">
                            Ronda #<?= $partida_filtro ?> - Mesa #<?= $mesa_filtro ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Sec.</th>
                                        <th>Jugador</th>
                                        <th>Pos.</th>
                                        <th>Resultado 1</th>
                                        <th>Resultado 2</th>
                                        <th>Efectividad</th>
                                        <th>FF</th>
                                        <th>Tarjeta</th>
                                        <th>Sanción</th>
                                        <th>Chancleta</th>
                                        <th>Zapato</th>
                                        <th>Registrado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($partidas_mesa as $partida): ?>
                                        <tr>
                                            <td><?= $partida['secuencia'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($partida['username'] ?? 'N/A') ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($partida['posicion'] > 0): ?>
                                                    <span class="badge bg-primary">#<?= $partida['posicion'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][resultado1]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['resultado1'] ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][resultado2]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['resultado2'] ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][efectividad]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['efectividad'] ?>">
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" name="resultados[<?= $partida['id'] ?>][ff]" 
                                                       value="1" <?= $partida['ff'] ? 'checked' : '' ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][tarjeta]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['tarjeta'] ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][sancion]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['sancion'] ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][chancleta]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['chancleta'] ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="resultados[<?= $partida['id'] ?>][zapato]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?= $partida['zapato'] ?>" min="0">
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" name="resultados[<?= $partida['id'] ?>][registrado]" 
                                                       value="1" <?= $partida['registrado'] ? 'checked' : '' ?>>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="12">
                                                <label class="form-label small">Observaciones:</label>
                                                <textarea name="resultados[<?= $partida['id'] ?>][observaciones]" 
                                                          class="form-control form-control-sm" rows="2"><?= htmlspecialchars($partida['observaciones'] ?? '') ?></textarea>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Importante:</strong> Marque "Registrado" solo cuando los resultados estén completamente verificados.
                    Al marcar como registrado, las estadísticas del jugador se actualizarán automáticamente.
                </div>
                
                <div class="d-flex justify-content-end gap-2">
                    <a href="admin_torneo.php?action=panel&torneo_id=<?= $torneo_id ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Guardar Resultados
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function cambiarRonda(ronda) {
    const url = new URL(window.location);
    if (ronda > 0) {
        url.searchParams.set('partida', ronda);
        url.searchParams.delete('mesa');
    } else {
        url.searchParams.delete('partida');
        url.searchParams.delete('mesa');
    }
    window.location = url.toString();
}

function cambiarMesa(mesa) {
    const url = new URL(window.location);
    if (mesa > 0) {
        url.searchParams.set('mesa', mesa);
    } else {
        url.searchParams.delete('mesa');
    }
    window.location = url.toString();

}

// Auto-calcular efectividad basado en resultados
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formResultados');
    if (!form) return;
    
    form.querySelectorAll('input[name*="[resultado1]"], input[name*="[resultado2]"]').forEach(function(input) {
        input.addEventListener('change', function() {
            const name = this.name;
            const baseName = name.substring(0, name.lastIndexOf('['));
            const resultado1 = parseFloat(form.querySelector(`input[name="${baseName}[resultado1]"]`).value) || 0;
            const resultado2 = parseFloat(form.querySelector(`input[name="${baseName}[resultado2]"]`).value) || 0;
            
            // Calcular efectividad simple (puede mejorarse según reglas del torneo)
            const efectividadInput = form.querySelector(`input[name="${baseName}[efectividad]"]`);
            if (efectividadInput && !efectividadInput.dataset.manual) {
                if (resultado1 > resultado2) {
                    efectividadInput.value = resultado1 - resultado2;
                } else if (resultado2 > resultado1) {
                    efectividadInput.value = -(resultado2 - resultado1);
                } else {
                    efectividadInput.value = 0;
                }
            }
        });
    });
});
</script>

