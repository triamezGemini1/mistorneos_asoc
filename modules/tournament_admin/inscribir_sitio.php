<?php
/**
 * Inscribir Jugador en Sitio (durante el torneo)
 * - Limita el ámbito territorial al administrador del torneo
 * - Permite inscribir atletas de otros ámbitos usando cédula o identificador único
 */

// Verificar que la tabla inscritos existe
if (!$tabla_inscritos_existe) {
    echo '<div class="alert alert-danger">';
    echo '<h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Tabla inscritos no encontrada</h6>';
    echo '<p class="mb-2">La tabla <code>inscritos</code> no existe. Para inscribir jugadores, debe crear esta tabla primero.</p>';
    echo '<p class="mb-0">Ejecute: <code>php scripts/migrate_inscritos_table_final.php</code></p>';
    echo '</div>';
    return;
}

// Obtener información del usuario actual y su club
$current_user = Auth::user();
$user_club_id = $current_user['club_id'] ?? null;
$is_admin_general = Auth::isAdminGeneral();
$is_admin_club = Auth::isAdminClub();

// Determinar si el torneo debe bloquear inscripción según modalidad:
// - Equipos (modalidad 3): bloquea si hay al menos 1 ronda
// - Individual/Parejas: bloquea si ronda > 1
$torneo_iniciado = false;
try {
    $stmt = $pdo->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) AS ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
    $stmt->execute([$torneo_id]);
    $ultima_ronda = (int)($stmt->fetchColumn() ?? 0);
    $es_equipos = isset($torneo['modalidad']) && (int)$torneo['modalidad'] === 3;
    if ($es_equipos) {
        $torneo_iniciado = $ultima_ronda >= 1;
    } else {
        $torneo_iniciado = $ultima_ronda >= 2;
    }
} catch (Exception $e) {
    $torneo_iniciado = false;
}

// Obtener usuarios de la entidad del administrador
$usuarios_territorio = [];
$entidad_admin = isset($current_user['entidad']) ? (int)$current_user['entidad'] : 0;
$roles_permitidos = ['usuario', 'admin_club'];

if ($is_admin_general) {
    // Admin general: todos los usuarios (solo afiliados y admin_club)
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre, c.id as club_id
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.role IN ('usuario','admin_club')
          AND (u.status = 'approved' OR u.status = 1)
        ORDER BY COALESCE(u.nombre, u.username) ASC
    ");
    $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($entidad_admin > 0) {
    // Admin_club / Admin_torneo: todos los usuarios de su entidad (sin importar club)
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre, c.id as club_id
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.role IN ('usuario','admin_club')
          AND (u.status = 'approved' OR u.status = 1)
          AND u.entidad = ?
        ORDER BY COALESCE(u.nombre, u.username) ASC
    ");
    $stmt->execute([$entidad_admin]);
    $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener usuarios ya inscritos
$stmt = $pdo->prepare("
    SELECT i.id_usuario, i.estatus, i.id_club,
           u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre
    FROM inscritos i
    LEFT JOIN usuarios u ON i.id_usuario = u.id
    LEFT JOIN clubes c ON i.id_club = c.id
    WHERE i.torneo_id = ?
    ORDER BY COALESCE(u.nombre, u.username) ASC
");
$stmt->execute([$torneo_id]);
$usuarios_inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$usuarios_inscritos_ids = array_column($usuarios_inscritos, 'id_usuario');

// Separar usuarios disponibles e inscritos
$usuarios_disponibles = array_filter($usuarios_territorio, function($u) use ($usuarios_inscritos_ids) {
    return !in_array($u['id'], $usuarios_inscritos_ids);
});

// Obtener lista de clubes (solo del territorio del administrador)
$clubes_disponibles = [];
if ($is_admin_general) {
    $stmt = $pdo->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
    $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else if ($user_club_id) {
    if ($is_admin_club) {
        require_once __DIR__ . '/../../lib/ClubHelper.php';
        $clubes_disponibles = ClubHelper::getClubesSupervisedWithData($user_club_id);
    } else {
        $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id = ? AND estatus = 1");
        $stmt->execute([$user_club_id]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($club) {
            $clubes_disponibles = [$club];
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="fas fa-user-plus me-2"></i>Inscribir Jugador en Sitio
        </h5>
    </div>
    <div class="card-body">
        <?php if ($torneo_iniciado): ?>
            <div class="alert alert-warning mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                El torneo ya inició (hay rondas generadas). No se permiten nuevas inscripciones. Solo se muestra información de inscritos para control administrativo.
            </div>
            
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-list-check me-2"></i>Inscritos del Torneo
                        <span class="badge bg-light text-dark ms-2"><?= count($usuarios_inscritos) ?></span>
                    </h6>
                </div>
                <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nombre</th>
                                    <th>ID</th>
                                    <th>Club</th>
                                    <th>Estatus</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios_inscritos as $usuario): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($usuario['nombre'] ?? $usuario['username']) ?></strong></td>
                                    <td><code><?= (int)$usuario['id'] ?></code></td>
                                    <td><?= htmlspecialchars($usuario['club_nombre'] ?? 'Sin club') ?></td>
                                    <td><?= InscritosHelper::renderEstatusBadge($usuario['estatus']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($usuarios_inscritos)): ?>
                                <tr><td colspan="4" class="text-center text-muted">No hay inscritos</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <!-- Pestañas para elegir método de inscripción -->
        <ul class="nav nav-tabs mb-4" id="inscripcionTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="territorio-tab" data-bs-toggle="tab" data-bs-target="#territorio" type="button" role="tab">
                    <i class="fas fa-users me-2"></i>Atletas de Mi Entidad
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cedula-tab" data-bs-toggle="tab" data-bs-target="#cedula" type="button" role="tab">
                    <i class="fas fa-id-card me-2"></i>Buscar por Cédula/ID
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="inscripcionTabsContent">
            <!-- Tab: Atletas del Territorio -->
            <div class="tab-pane fade show active" id="territorio" role="tabpanel">
                <!-- Listados: Disponibles e Inscritos -->
                <div class="row">
                    <!-- Listado de Disponibles -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-list me-2"></i>Atletas Disponibles
                                    <span class="badge bg-light text-dark ms-2" id="count_disponibles"><?= count($usuarios_disponibles) ?></span>
                                </h6>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Nombre</th>
                                                <th>ID Usuario</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbody_disponibles">
                                            <?php foreach ($usuarios_disponibles as $usuario): 
                                                $nombre_completo = !empty($usuario['nombre']) ? $usuario['nombre'] : $usuario['username'];
                                            ?>
                                                <tr style="cursor: pointer;" 
                                                    class="table-row-hover"
                                                    data-id="<?= $usuario['id'] ?>"
                                                    data-nombre="<?= htmlspecialchars($nombre_completo) ?>"
                                                    data-cedula="<?= htmlspecialchars($usuario['cedula'] ?? '') ?>"
                                                    data-club-id="<?= $usuario['club_id'] ?? '' ?>">
                                                    <td><strong><?= htmlspecialchars($nombre_completo) ?></strong></td>
                                                    <td><code><?= $usuario['id'] ?></code></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Listado de Inscritos -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-check-circle me-2"></i>Atletas Inscritos
                                    <span class="badge bg-light text-dark ms-2" id="count_inscritos"><?= count($usuarios_inscritos) ?></span>
                                </h6>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Nombre</th>
                                                <th>ID Usuario</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbody_inscritos">
                                            <?php foreach ($usuarios_inscritos as $inscrito): 
                                                $nombre_completo = !empty($inscrito['nombre']) ? $inscrito['nombre'] : $inscrito['username'];
                                            ?>
                                                <tr style="cursor: pointer;" 
                                                    class="table-row-hover"
                                                    data-id="<?= $inscrito['id_usuario'] ?>"
                                                    data-nombre="<?= htmlspecialchars($nombre_completo) ?>"
                                                    data-cedula="<?= htmlspecialchars($inscrito['cedula'] ?? '') ?>"
                                                    data-club-id="<?= $inscrito['id_club'] ?? '' ?>">
                                                    <td><strong><?= htmlspecialchars($nombre_completo) ?></strong></td>
                                                    <td><code><?= $inscrito['id_usuario'] ?></code></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab: Búsqueda por Cédula (flujo: nacionalidad → cédula → on blur busca inscritos → usuarios → externa → formulario nuevo) -->
            <div class="tab-pane fade" id="cedula" role="tabpanel">
                <div class="row">
                    <div class="col-md-10">
                        <div class="card">
                            <div class="card-body">
                                <input type="hidden" id="torneo_id" value="<?= (int)$torneo_id ?>">
                                <input type="hidden" id="user_id" value="0">
                                <!-- Mensaje de búsqueda (SweetAlert2 o este div si Swal no está disponible) -->
                                <div id="mensaje_formulario_cedula" class="mb-3 d-none" role="alert" aria-live="polite" style="min-height: 0;"></div>

                                <div class="row mb-3">
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold">Nacionalidad <span class="text-danger">*</span></label>
                                        <select id="select_nacionalidad_cedula" class="form-select">
                                            <option value="V" selected>V</option>
                                            <option value="E">E</option>
                                            <option value="J">J</option>
                                            <option value="P">P</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Nº Cédula <span class="text-danger">*</span></label>
                                        <input type="text" id="input_cedula" class="form-control" placeholder="Solo números, ej: 12345678" maxlength="15" inputmode="numeric" autocomplete="off">
                                        <small class="text-muted">Al salir del campo se busca automáticamente</small>
                                    </div>
                                </div>

                                <div class="mb-3 d-none" id="wrap_acciones_cedula">
                                    <label class="form-label fw-bold">Club</label>
                                    <select id="select_club_cedula" class="form-select">
                                        <option value="">-- Usar club del usuario --</option>
                                        <?php foreach ($clubes_disponibles as $club): ?>
                                            <option value="<?= $club['id'] ?>"><?= htmlspecialchars($club['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3 d-none" id="wrap_estatus_cedula">
                                    <label class="form-label fw-bold">Estatus</label>
                                    <select id="select_estatus_cedula" class="form-select">
                                        <?php foreach (InscritosHelper::getEstatusFormOptions() as $opt): ?>
                                            <option value="<?= $opt['value'] ?>" <?= $opt['value'] == 1 ? 'selected' : '' ?>><?= $opt['label'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3 d-none" id="wrap_btn_inscribir_cedula">
                                    <button type="button" class="btn btn-success me-2" id="btn_inscribir_cedula">
                                        <i class="fas fa-save me-2"></i>Inscribir
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="btn_otra_busqueda_cedula">
                                        <i class="fas fa-redo me-2"></i>Otra búsqueda
                                    </button>
                                </div>

                                <!-- Resultado: datos encontrados (usuario o persona externa) -->
                                <div id="resultado_busqueda" class="d-none">
                                    <div class="card border-info">
                                        <div class="card-body">
                                            <h6 class="card-title">Datos encontrados</h6>
                                            <div id="info_usuario_encontrado"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Formulario nuevo usuario (cuando no está en usuarios ni en BD externa, o datos externos para completar) -->
                                <div id="form_nuevo_usuario_inscribir" class="d-none card border-warning mt-3">
                                    <div class="card-header bg-warning text-dark">Registrar e inscribir</div>
                                    <div class="card-body">
                                        <p class="text-muted small">Complete los datos para crear el usuario e inscribirlo en el torneo.</p>
                                        <div class="row g-2">
                                            <div class="col-md-2"><label class="form-label">Nacionalidad</label><select id="form_nac" class="form-select"><option value="V">V</option><option value="E">E</option><option value="J">J</option><option value="P">P</option></select></div>
                                            <div class="col-md-2"><label class="form-label">Cédula</label><input type="text" id="form_cedula" class="form-control" placeholder="Solo números"></div>
                                            <div class="col-md-4"><label class="form-label">Nombre completo</label><input type="text" id="form_nombre" class="form-control" required></div>
                                            <div class="col-md-2"><label class="form-label">Fecha nac.</label><input type="date" id="form_fechnac" class="form-control"></div>
                                            <div class="col-md-2"><label class="form-label">Sexo</label><select id="form_sexo" class="form-select"><option value="M">M</option><option value="F">F</option><option value="O">O</option></select></div>
                                            <div class="col-md-4"><label class="form-label">Teléfono</label><input type="text" id="form_telefono" class="form-control" placeholder="Opcional"></div>
                                            <div class="col-md-4"><label class="form-label">Email</label><input type="email" id="form_email" class="form-control" placeholder="Opcional"></div>
                                            <div class="col-md-4"><label class="form-label">Club</label><select id="form_club" class="form-select"><option value="">-- Seleccione --</option><?php foreach ($clubes_disponibles as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option><?php endforeach; ?></select></div>
                                        </div>
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-warning" id="btn_registrar_inscribir">
                                                <i class="fas fa-user-plus me-2"></i>Registrar e inscribir
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary ms-2" id="btn_cancelar_form_nuevo">Cancelar</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <?php
                                    $base_ins = function_exists('app_base_url') ? rtrim(app_base_url(), '/') . '/public' : '';
                                    $url_panel_ins = class_exists('AppHelpers')
                                        ? AppHelpers::urlPanelTorneoReturn((int) $torneo_id)
                                        : (($base_ins !== '' ? $base_ins . '/' : '') . 'index.php?page=torneo_gestion&action=panel&torneo_id=' . (int) $torneo_id);
                                    ?>
                                    <a href="<?= htmlspecialchars($url_panel_ins) ?>" class="btn btn-secondary"><i class="fas fa-times me-2"></i>Volver al panel</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.table-row-hover {
    cursor: pointer;
    transition: background-color 0.2s;
}
.table-row-hover:hover {
    background-color: #e3f2fd !important;
}
.table-row-hover:active {
    background-color: #bbdefb !important;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<!-- Config global para inscripciones.js (motor único). Cargar ANTES del script. -->
<script>
window.INSCRIPCIONES_CONFIG = {
    API_URL: 'tournament_admin_toggle_inscripcion.php',
    BUSCAR_API: 'api/search_persona.php',
    TORNEOS_ID: <?= (int)$torneo_id ?>,
    CSRF_TOKEN: '<?= htmlspecialchars(CSRF::token(), ENT_QUOTES) ?>',
    showMessage: function(message, type) {
        var alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alertDiv.innerHTML = message + ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        var cardBody = document.querySelector('.card-body');
        if (cardBody) {
            cardBody.insertBefore(alertDiv, cardBody.firstChild);
            setTimeout(function() { alertDiv.remove(); }, 3000);
        }
    },
    onInscritoExitoso: function(data, usuarioEncontrado, clubId) {
        var tbodyInscritos = document.getElementById('tbody_inscritos');
        var inputCedula = document.getElementById('input_cedula') || document.getElementById('cedula');
        var cedulaStr = inputCedula ? inputCedula.value.trim() : '';
        if (tbodyInscritos && typeof agregarFilaInscrito === 'function') {
            agregarFilaInscrito(usuarioEncontrado.id, usuarioEncontrado.nombre || usuarioEncontrado.username || 'Usuario', cedulaStr, clubId);
        }
        if (typeof updateCounters === 'function') updateCounters();
        if (typeof showMessage === 'function') showMessage('Jugador inscrito exitosamente', 'success');
    },
    onRegistrarInscribirExitoso: function(data, nom, cedulaStr, clubId) {
        var tbodyInscritos = document.getElementById('tbody_inscritos');
        if (tbodyInscritos && typeof agregarFilaInscrito === 'function') {
            agregarFilaInscrito(data.id_usuario, nom, cedulaStr, clubId);
        }
        if (typeof updateCounters === 'function') updateCounters();
        if (typeof showMessage === 'function') showMessage(data.message || 'Usuario registrado e inscrito.', 'success');
    }
};
</script>
<!-- SweetAlert2 y motor único de búsqueda/inscripción (cache-bust) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11?v=<?php echo time(); ?>"></script>
<script src="js/inscripciones.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var TORNEOS_ID = window.INSCRIPCIONES_CONFIG && window.INSCRIPCIONES_CONFIG.TORNEOS_ID;
    var API_URL = window.INSCRIPCIONES_CONFIG && window.INSCRIPCIONES_CONFIG.API_URL;
    var CSRF_TOKEN = window.INSCRIPCIONES_CONFIG && window.INSCRIPCIONES_CONFIG.CSRF_TOKEN;
    var tbodyDisponibles = document.getElementById('tbody_disponibles');
    var tbodyInscritos = document.getElementById('tbody_inscritos');

    function showMessage(message, type) {
        if (window.INSCRIPCIONES_CONFIG && window.INSCRIPCIONES_CONFIG.showMessage) {
            window.INSCRIPCIONES_CONFIG.showMessage(message, type);
        }
    }
    function updateCounters() {
        var countDisponibles = document.getElementById('count_disponibles');
        var countInscritos = document.getElementById('count_inscritos');
        if (countDisponibles && tbodyDisponibles) countDisponibles.textContent = tbodyDisponibles.children.length;
        if (countInscritos && tbodyInscritos) countInscritos.textContent = tbodyInscritos.children.length;
    }
    function agregarFilaInscrito(id, nombre, cedula, clubId) {
        if (!tbodyInscritos) return;
        var newRow = document.createElement('tr');
        newRow.style.cursor = 'pointer';
        newRow.className = 'table-row-hover';
        newRow.dataset.id = id;
        newRow.dataset.nombre = nombre;
        newRow.dataset.cedula = cedula;
        newRow.dataset.clubId = clubId || '';
        newRow.style.animation = 'fadeIn 0.3s';
        newRow.innerHTML = '<td><strong>' + (nombre || '') + '</strong></td><td><code>' + id + '</code></td>';
        tbodyInscritos.appendChild(newRow);
    }

    if (tbodyDisponibles) {
        tbodyDisponibles.addEventListener('click', function(e) {
            var row = e.target.closest('tr');
            if (row && row.dataset.id) {
                inscribirJugador(parseInt(row.dataset.id), row.dataset.nombre, row.dataset.cedula || '', row.dataset.clubId || '', row);
            }
        });
    }
    if (tbodyInscritos) {
        tbodyInscritos.addEventListener('click', function(e) {
            var row = e.target.closest('tr');
            if (row && row.dataset.id) {
                desinscribirJugador(parseInt(row.dataset.id), row.dataset.nombre, row.dataset.cedula || '', row.dataset.clubId || '', row);
            }
        });
    }

    function inscribirJugador(idUsuario, nombre, cedula, clubId, rowElement) {
        if (!idUsuario || !TORNEOS_ID) { showMessage('Error: Faltan datos necesarios para inscribir', 'danger'); return; }
        var formData = new FormData();
        formData.append('action', 'inscribir');
        formData.append('torneo_id', TORNEOS_ID);
        formData.append('id_usuario', idUsuario);
        if (clubId) formData.append('id_club', clubId);
        formData.append('estatus', '0');
        formData.append('csrf_token', CSRF_TOKEN);
        rowElement.style.opacity = '0.5';
        rowElement.style.pointerEvents = 'none';
        fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) {
                return r.text().then(function(t) {
                    if (!r.ok) throw new Error(t || ('Error ' + r.status));
                    try { return JSON.parse(t); } catch (e) { throw new Error(t && t.length < 300 ? t.replace(/<[^>]+>/g,' ').trim() : 'Respuesta no JSON'); }
                });
            })
            .then(function(data) {
                rowElement.style.opacity = '1';
                rowElement.style.pointerEvents = 'auto';
                if (data.success) {
                    var newRow = rowElement.cloneNode(true);
                    newRow.style.animation = 'fadeIn 0.3s';
                    tbodyInscritos.appendChild(newRow);
                    rowElement.remove();
                    updateCounters();
                    showMessage('Jugador inscrito exitosamente', 'success');
                } else {
                    showMessage(data.error || 'Error al inscribir jugador', 'danger');
                }
            })
            .catch(function(err) {
                rowElement.style.opacity = '1';
                rowElement.style.pointerEvents = 'auto';
                showMessage('Error al inscribir jugador: ' + (err.message || err), 'danger');
            });
    }
    function desinscribirJugador(idUsuario, nombre, cedula, clubId, rowElement) {
        if (!idUsuario || !TORNEOS_ID) { showMessage('Error: Faltan datos necesarios para desinscribir', 'danger'); return; }
        var formData = new FormData();
        formData.append('action', 'desinscribir');
        formData.append('torneo_id', TORNEOS_ID);
        formData.append('id_usuario', idUsuario);
        formData.append('csrf_token', CSRF_TOKEN);
        rowElement.style.opacity = '0.5';
        rowElement.style.pointerEvents = 'none';
        fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) {
                return r.text().then(function(t) {
                    if (!r.ok) throw new Error(t || ('Error ' + r.status));
                    try { return JSON.parse(t); } catch (e) { throw new Error(t && t.length < 300 ? t.replace(/<[^>]+>/g,' ').trim() : 'Respuesta no JSON'); }
                });
            })
            .then(function(data) {
                rowElement.style.opacity = '1';
                rowElement.style.pointerEvents = 'auto';
                if (data.success) {
                    var newRow = rowElement.cloneNode(true);
                    newRow.style.animation = 'fadeIn 0.3s';
                    tbodyDisponibles.appendChild(newRow);
                    rowElement.remove();
                    updateCounters();
                    showMessage('Jugador desinscrito exitosamente', 'success');
                } else {
                    showMessage(data.error || 'Error al desinscribir jugador', 'danger');
                }
            })
            .catch(function(err) {
                rowElement.style.opacity = '1';
                rowElement.style.pointerEvents = 'auto';
                showMessage('Error al desinscribir jugador: ' + err.message, 'danger');
            });
    }
    window.agregarFilaInscrito = agregarFilaInscrito;
    window.updateCounters = updateCounters;
    window.showMessage = showMessage;
});
</script>
