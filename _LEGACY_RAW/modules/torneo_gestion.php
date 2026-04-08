<?php
/**
 * Módulo de Gestión Completa de Torneos
 * Integra funcionalidades de:
 * - AdminTorneoController: Dashboard y gestión básica
 * - RondasController: Gestión de rondas, cuadrícula
 * - TorneoGestionController: Panel avanzado, resultados, posiciones, resumen individual, hojas de anotación
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Core/TorneoMesaAsignacionResolver.php';
require_once __DIR__ . '/torneo_gestion/rondas_mesas.php';
require_once __DIR__ . '/torneo_gestion/actions_inscritos.php';
require_once __DIR__ . '/torneo_gestion/render_views.php';
require_once __DIR__ . '/../lib/PanelTorneoViewData.php';
require_once __DIR__ . '/../lib/ResultadosPartidaEfectividad.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/torneo_gestion/resultados_posiciones.php';

$current_user = Auth::user();
$user_role = $current_user['role'] ?? '';
$user_id = (int)($current_user['id'] ?? $current_user['user_id'] ?? 0);

// Jugadores (usuario) solo pueden ver resumen_individual (el propio) y posiciones
if ($user_role === 'usuario') {
    $action = $_GET['action'] ?? '';
    $torneo_id = (int)($_GET['torneo_id'] ?? 0);
    $inscrito_id = (int)($_GET['inscrito_id'] ?? 0);
    $allowed = ($torneo_id > 0 && in_array($action, ['resumen_individual', 'posiciones']));
    if ($allowed && $action === 'resumen_individual') {
        $allowed = ($inscrito_id > 0 && $inscrito_id === $user_id);
    }
    if (!$allowed) {
        require_once __DIR__ . '/../lib/app_helpers.php';
        header('Location: ' . rtrim(AppHelpers::getBaseUrl(), '/') . '/public/user_portal.php');
        exit;
    }
} else {
    Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
}

$current_user = Auth::user();
$user_role = $current_user['role'];
$user_id = $current_user['id'];
$is_admin_general = Auth::isAdminGeneral();
$is_admin_torneo = Auth::isAdminTorneo();
$is_admin_club = Auth::isAdminClub();

// Función auxiliar para determinar la URL base según el contexto
function getBaseUrl() {
    $script = basename($_SERVER['PHP_SELF'] ?? '');
    if ($script === 'panel_torneo.php') return 'panel_torneo.php';
    if ($script === 'admin_torneo.php') return 'admin_torneo.php';
    return 'index.php?page=torneo_gestion';
}

// Función auxiliar para construir URLs de redirección
function buildRedirectUrl($action, $params = []) {
    $base = getBaseUrl();
    $url = $base;
    
    $usa_script_simple = ($base === 'admin_torneo.php' || $base === 'panel_torneo.php');
    if ($usa_script_simple) {
        $url .= '?action=' . $action;
        foreach ($params as $key => $value) {
            $url .= '&' . $key . '=' . urlencode($value);
        }
    } else {
        $url .= '&action=' . $action;
        foreach ($params as $key => $value) {
            $url .= '&' . $key . '=' . urlencode($value);
        }
    }
    
    return $url;
}

/**
 * Verifica si existe la columna 'locked' en la tabla tournaments
 */
function tournamentsLockedColumnExists(): bool {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tournaments' AND COLUMN_NAME = 'locked'");
        $stmt->execute();
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Asegura que exista la columna 'locked' en tournaments
 */
function ensureTournamentsLockedColumn(): void {
    if (!tournamentsLockedColumnExists()) {
        try {
            $pdo = DB::pdo();
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_tournaments_locked (locked)");
        } catch (Exception $e) {
            // Ignorar si falla (podría no tener permisos); el flujo continuará sin lock persistente
        }
    }
}

/**
 * Retorna si el torneo está cerrado (locked)
 */
function isTorneoLocked(int $torneoId): bool {
    try {
        if (!tournamentsLockedColumnExists()) {
            return false;
        }
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT locked FROM tournaments WHERE id = ?");
        $stmt->execute([$torneoId]);
        $locked = $stmt->fetchColumn();
        return (int)$locked === 1;
    } catch (Exception $e) {
        return false;
    }
}

// Obtener acción y parámetros (normalizado para evitar espacios)
$action = trim((string)($_GET['action'] ?? 'index'));
if ($action === '') {
    $action = 'index';
}
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : null;
$ronda = isset($_GET['ronda']) ? (int)$_GET['ronda'] : null;
$mesa = isset($_GET['mesa']) ? (int)$_GET['mesa'] : null;
$inscrito_id = isset($_GET['inscrito_id']) ? (int)$_GET['inscrito_id'] : null;

// Plantilla CSV carga masiva (GET, sin layout)
if ($action === 'carga_masiva_equipos_plantilla' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $torneo_id) {
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    require_once __DIR__ . '/../lib/CargaMasivaEquiposSitioService.php';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_carga_equipos_torneo_' . $torneo_id . '.csv"');
    echo CargaMasivaEquiposSitioService::contenidoPlantillaCsv();
    exit;
}

// Manejar acciones POST - DEBE estar antes de cualquier output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? $action;
    
    // Verificar CSRF
    $csrf_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (!$csrf_token || !$session_token || !hash_equals($session_token, $csrf_token)) {
        $_SESSION['error'] = 'Token de seguridad inválido. Por favor, recarga la página e intenta nuevamente.';
        // Si hay torneo_id en POST, redirigir al panel; de lo contrario, al índice
        $redirect_torneo_id = (int)($_POST['torneo_id'] ?? 0);
        if ($redirect_torneo_id > 0) {
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $redirect_torneo_id]));
        } else {
            header('Location: ' . buildRedirectUrl('index'));
        }
        exit;
    }
    
    // Bloquear acciones de modificación si el torneo está cerrado (verificar actas hace su propia comprobación para admin_general)
    $torneo_id_check = (int)($_POST['torneo_id'] ?? 0);
    $allow_when_locked = in_array($post_action, ['cerrar_torneo', 'verificar_acta_aprobar', 'verificar_acta_rechazar'], true);
    if ($torneo_id_check && isTorneoLocked($torneo_id_check) && !$allow_when_locked) {
        $_SESSION['error'] = 'Este torneo está cerrado y no admite modificaciones.';
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id_check]));
        exit;
    }
    
    if (torneo_gestion_actions_inscritos_should_handle_post($post_action)) {
        torneo_gestion_actions_inscritos_dispatch_post($post_action, $user_id, $is_admin_general);
    }

    switch ($post_action) {
        case 'guardar_resultados':
        case 'actualizar_resultado_ajax':
            torneo_gestion_resultados_posiciones_handle_post($post_action, $user_id, $is_admin_general);
            break;

        case 'actualizar_estadisticas':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            actualizarEstadisticasManual($torneo_id, $user_id, $is_admin_general);
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
            exit;
            
        case 'cerrar_torneo':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            ensureTournamentsLockedColumn();
            try {
                if ($torneo_id > 0 && tournamentsLockedColumnExists()) {
                    $stmt = DB::pdo()->prepare("UPDATE tournaments SET locked = 1 WHERE id = ?");
                    $stmt->execute([$torneo_id]);
                    $_SESSION['success'] = 'Torneo cerrado definitivamente. No se podrán realizar más cambios.';
                } else {
                    $_SESSION['error'] = 'No fue posible cerrar el torneo (estructura no disponible).';
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error al cerrar el torneo: ' . $e->getMessage();
            }
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
            exit;

        case 'enviar_notificacion_torneo':
            $torneo_id = (int)($_POST['torneo_id'] ?? 0);
            enviarNotificacionTorneo($torneo_id, $user_id, $is_admin_general);
            break;

        case 'verificar_acta_aprobar':
            verificarActaAprobar($user_id, $is_admin_general);
            break;

        case 'verificar_acta_rechazar':
            verificarActaRechazar($user_id, $is_admin_general);
            break;

        case 'activar_participantes':
            if (!empty($_POST['confirmar'])) {
                $torneo_id_act = (int)($_GET['torneo_id'] ?? $_POST['torneo_id'] ?? 0);
                if ($torneo_id_act <= 0) {
                    $_SESSION['error'] = 'Torneo no especificado.';
                    header('Location: ' . buildRedirectUrl('index'));
                    exit;
                }
                verificarPermisosTorneo($torneo_id_act, $user_id, $is_admin_general);
                require_once __DIR__ . '/../lib/UserActivationHelper.php';
                $activados = UserActivationHelper::activateTournamentParticipants(DB::pdo(), $torneo_id_act);
                $msg = $activados > 0
                    ? "Se activaron {$activados} participante(s). Ya pueden acceder al sistema y recibir notificaciones."
                    : "No había participantes por activar o ya estaban activos.";
                header('Location: ' . buildRedirectUrl('activar_participantes', ['torneo_id' => $torneo_id_act, 'success' => $msg]));
                exit;
            }
            break;

        default:
            $_SESSION['error'] = 'Acción POST no válida';
            // Si hay torneo_id en POST, redirigir al panel; de lo contrario, al índice
            $redirect_torneo_id = (int)($_POST['torneo_id'] ?? 0);
            if ($redirect_torneo_id > 0) {
                header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $redirect_torneo_id]));
            } else {
                header('Location: ' . buildRedirectUrl('index'));
            }
            exit;
    }
}

// Determinar qué vista mostrar
$view_file = null;
$view_data = [];
$error_message = null;

try {
    switch ($action) {
        case 'index':
            $filtro_torneos = isset($_GET['filtro']) && in_array($_GET['filtro'], ['realizados', 'en_proceso', 'por_realizar'], true) ? $_GET['filtro'] : null;
            $torneos = obtenerTorneosGestion($user_id, $is_admin_general, $filtro_torneos);
            if ($is_admin_general && !empty($torneos)) {
                $club_ids = array_unique(array_filter(array_column($torneos, 'club_responsable')));
                $entidad_map = [];
                try {
                    $cols = DB::pdo()->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
                    $codeCol = $nameCol = null;
                    foreach ($cols as $c) {
                        $f = strtolower($c['Field'] ?? '');
                        if (in_array($f, ['codigo', 'cod_entidad', 'id', 'code'])) $codeCol = $f;
                        if (in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'])) $nameCol = $f;
                    }
                    if ($codeCol && $nameCol) {
                        $entidad_map = DB::pdo()->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol}")->fetchAll(PDO::FETCH_KEY_PAIR);
                    }
                } catch (Exception $e) { /* ignore */ }
                if (!empty($club_ids)) {
                    $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
                    $stmt_ent = DB::pdo()->prepare("SELECT club_id, entidad FROM usuarios WHERE role = 'admin_club' AND club_id IN ($placeholders)");
                    $stmt_ent->execute(array_values($club_ids));
                    $club_to_entidad = [];
                    foreach ($stmt_ent->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $club_to_entidad[(int)$row['club_id']] = (int)($row['entidad'] ?? 0);
                    }
                    foreach ($torneos as &$t) {
                        $ent = $club_to_entidad[(int)($t['club_responsable'] ?? 0)] ?? 0;
                        $t['entidad_nombre'] = $ent > 0 ? ($entidad_map[$ent] ?? 'Entidad ' . $ent) : 'Sin entidad';
                    }
                    unset($t);
                    usort($torneos, function ($a, $b) {
                        $na = $a['entidad_nombre'] ?? '';
                        $nb = $b['entidad_nombre'] ?? '';
                        $c = strcmp($na, $nb);
                        if ($c !== 0) return $c;
                        return strcmp($a['fechator'] ?? '', $b['fechator'] ?? '');
                    });
                }
            }
            $use_standalone = (basename($_SERVER['PHP_SELF'] ?? '') === 'admin_torneo.php');
            $view_file = $use_standalone ? __DIR__ . '/gestion_torneos/index-moderno.php' : __DIR__ . '/gestion_torneos/index.php';
            $view_data = ['torneos' => $torneos, 'filtro_torneos' => $filtro_torneos, 'is_admin_general' => $is_admin_general];
            break;
            
        case 'dashboard':
            // Redirigir 'dashboard' a 'panel' si hay torneo_id, de lo contrario a 'index'
            if ($torneo_id > 0) {
                header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
                exit;
            } else {
                header('Location: ' . buildRedirectUrl('index'));
                exit;
            }
            break;
            
        case 'panel':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            if (!obtenerTorneo($torneo_id, $user_id, $is_admin_general)) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/gestion_torneos/panel.php';
            $script_actual = basename($_SERVER['PHP_SELF'] ?? '');
            $use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
            $base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
            $view_data = PanelTorneoViewData::build((int) $torneo_id);
            $view_data['base_url'] = $base_url;
            $view_data['use_standalone'] = $use_standalone;
            $view_data['user_id'] = $user_id;
            $view_data['is_admin_general'] = $is_admin_general;
            break;
            
        case 'panel_equipos':
            // Redirigir panel_equipos a panel (ahora es común para todos los tipos)
            // Este caso se mantiene solo para compatibilidad con enlaces antiguos
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            // Redirigir al panel común
            header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
            exit;
            break;
            
        case 'cronometro':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/gestion_torneos/cronometro.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id];
            $use_cronometro_standalone = true;
            break;

        case 'activar_participantes':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/gestion_torneos/activar_participantes.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id];
            break;
            
        case 'gestionar_inscripciones_equipos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/gestionar_inscripciones_equipos.php';
            $view_data = obtenerDatosGestionarInscripcionesEquipos($torneo_id);
            break;
            
        case 'inscribir_equipo_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscribir_equipo_sitio.php';
            $view_data = obtenerDatosInscribirEquipoSitio($torneo_id);
            break;

        case 'carga_masiva_equipos_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $stmt = DB::pdo()->prepare('SELECT id, nombre, modalidad, locked FROM tournaments WHERE id = ?');
            $stmt->execute([$torneo_id]);
            $torneo_cm = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$torneo_cm || (int)($torneo_cm['modalidad'] ?? 0) !== 3) {
                throw new Exception('Solo torneos modalidad equipos (4 integrantes).');
            }
            $view_file = __DIR__ . '/gestion_torneos/carga_masiva_equipos_sitio.php';
            $view_data = ['torneo' => $torneo_cm, 'torneo_id' => $torneo_id];
            break;

        case 'guardar_pareja_fija':
            if (!$torneo_id || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Solicitud inválida');
            }
            torneo_gestion_actions_inscritos_dispatch_guardar_pareja_fija((int)$torneo_id, $user_id, $is_admin_general);
            exit;

        case 'gestionar_inscripciones_parejas_fijas':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/gestionar_inscripciones_parejas_fijas.php';
            $view_data = obtenerDatosGestionarInscripcionesParejasFijas($torneo_id);
            break;

        case 'inscribir_pareja_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscribir_pareja_sitio.php';
            $view_data = obtenerDatosInscribirParejaSitio($torneo_id);
            break;
            
        case 'mesas':
        case 'asignar_mesas_operador':
        case 'rondas':
        case 'cuadricula':
        case 'hojas_anotacion':
        case 'reasignar_mesa':
        case 'agregar_mesa':
        case 'verificar_mesa':
        case 'gestionar_mesas':
        case 'imprimir_actas':
        case 'reimprimir_todas':
            $routed = torneo_gestion_rondas_mesas_try_route_get(
                $action,
                $torneo_id,
                $ronda,
                $mesa,
                $user_id,
                $user_role,
                $is_admin_general
            );
            if ($routed === null) {
                throw new Exception('Ruta de rondas/mesas no reconocida');
            }
            $view_file = $routed['view_file'];
            $view_data = $routed['view_data'];
            break;

        case 'posiciones':
        case 'registrar_resultados':
        case 'registrar_resultados_v2':
        case 'resumen_individual':
        case 'podio':
        case 'podios':
        case 'cuadro_honor':
        case 'podios_equipos':
        case 'resultados_por_club':
        case 'resultados_equipos_resumido':
        case 'resultados_equipos_detallado':
        case 'resultados_general':
        case 'resultados_reportes':
        case 'resultados_reportes_print':
        case 'resultados_ronda':
        case 'ver_resultados':
            $routedRp = torneo_gestion_resultados_posiciones_try_route_get(
                $action,
                $torneo_id,
                $ronda,
                $mesa,
                $inscrito_id,
                $user_id,
                $user_role,
                $is_admin_general
            );
            if ($routedRp === null) {
                throw new Exception('Ruta de resultados/clasificación no reconocida');
            }
            $view_file = $routedRp['view_file'];
            $view_data = $routedRp['view_data'];
            if (!empty($routedRp['use_reportes_print_standalone'])) {
                $use_reportes_print_standalone = true;
            }
            break;

        case 'galeria_fotos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            // Reutilizamos la vista de administración de torneo para mantener funcionalidades y estilos
            $view_file = __DIR__ . '/tournament_admin/galeria_fotos.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id];
            break;
            
        case 'inscripciones':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscripciones.php';
            $view_data = obtenerDatosInscripciones($torneo_id);
            break;

        case 'notificaciones':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/notificaciones_torneo.php';
            $view_data = obtenerDatosNotificacionesTorneo($torneo_id);
            break;

        case 'equipos':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/equipos.php';
            $view_data = obtenerDatosEquiposAdmin($torneo_id);
            break;
            
        case 'inscribir_sitio':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $view_file = __DIR__ . '/gestion_torneos/inscribir-sitio.php';
            $view_data = obtenerDatosInscribirSitio($torneo_id, $user_id, $is_admin_general);
            break;

        case 'sustituir_jugador':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo_check = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo_check) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $modalidad = (int)($torneo_check['modalidad'] ?? 0);
            if ($modalidad === 3) {
                $_SESSION['error'] = 'La sustitución de jugadores no aplica a torneos por equipos.';
                header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
                exit;
            }
            $rondas = obtenerRondasGeneradas($torneo_id);
            if (empty($rondas)) {
                $_SESSION['error'] = 'La sustitución solo está disponible cuando el torneo ha iniciado (rondas generadas).';
                header('Location: ' . buildRedirectUrl('inscripciones', ['torneo_id' => $torneo_id]));
                exit;
            }
            $view_file = __DIR__ . '/gestion_torneos/sustituir-jugador.php';
            $view_data = obtenerDatosSustituirJugador($torneo_id, $user_id, $is_admin_general);
            break;
            
        case 'equipos_detalle':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/equipos_detalle.php';
            $view_data = ['torneo' => $torneo, 'torneo_id' => $torneo_id, 'pdo' => DB::pdo()];
            break;
            
        case 'verificar_actas_index':
            $view_file = __DIR__ . '/tournament_admin/verificar_actas_index.php';
            $view_data = obtenerTorneosConActasPendientes($user_id, $is_admin_general);
            break;

        case 'verificar_resultados':
            if (!$torneo_id) {
                throw new Exception('Debe especificar un torneo');
            }
            verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
            $torneo = obtenerTorneo($torneo_id, $user_id, $is_admin_general);
            if (!$torneo) {
                throw new Exception('Torneo no encontrado o sin permisos');
            }
            $view_file = __DIR__ . '/tournament_admin/views/verificar_resultados.php';
            $view_data = obtenerDatosVerificarActasLista($torneo_id);
            $view_data['torneo'] = $torneo;
            $view_data['torneo_id'] = $torneo_id;
            $view_data['jugadores'] = [];
            $view_data['torneo_finalizado'] = isTorneoLocked($torneo_id);
            $view_data['is_admin_general'] = $is_admin_general;
            $view_data['can_edit'] = !$view_data['torneo_finalizado'] || $is_admin_general;
            $ronda_vr = (int)($_GET['ronda'] ?? $_REQUEST['ronda'] ?? 0);
            $mesa_vr = (int)($_GET['mesa'] ?? $_REQUEST['mesa'] ?? 0);
            if ($ronda_vr > 0 && $mesa_vr > 0) {
                $acta_data = obtenerDatosVerificarActa($torneo_id, $ronda_vr, $mesa_vr);
                if ($acta_data) {
                    $view_data['jugadores'] = $acta_data['jugadores'];
                    $view_data['ronda'] = $ronda_vr;
                    $view_data['mesa'] = $mesa_vr;
                }
            }
            break;

        case 'new':
        case 'view':
        case 'edit':
            // Crear/ver/editar torneo: delegar al módulo tournaments. index.php debería redirigir ANTES del layout.
            // Si llegamos aquí, usar meta refresh como fallback para evitar "headers already sent".
            $params = ['page' => 'tournaments', 'action' => $action];
            if ($action !== 'new' && isset($_GET['id']) && (int)$_GET['id'] > 0) {
                $params['id'] = (int)$_GET['id'];
            }
            $base = (defined('URL_BASE') && URL_BASE !== '') ? rtrim(URL_BASE, '/') . '/' : '';
            $target = $base . 'index.php?' . http_build_query($params);
            if (!headers_sent()) {
                header('Location: ' . $target);
                exit;
            }
            torneo_gestion_render_meta_refresh_redirect($target);
            exit;

        default:
            // Fallback para producción: si la acción es verificar_actas_index y no coincidió antes (p. ej. deploy antiguo)
            if ($action === 'verificar_actas_index') {
                $view_file = __DIR__ . '/tournament_admin/verificar_actas_index.php';
                $view_data = obtenerTorneosConActasPendientes($user_id, $is_admin_general);
                break;
            }
            throw new Exception('Acción no válida: ' . $action);
    }
    
    // Enriquecer torneo con datos de organización si faltan (para panel_torneo header)
    if (isset($view_data['torneo']) && !empty($view_data['torneo']['club_responsable']) && empty($view_data['torneo']['organizacion_logo'])) {
        try {
            $stmt = DB::pdo()->prepare("SELECT nombre, logo FROM organizaciones WHERE id = ?");
            $stmt->execute([$view_data['torneo']['club_responsable']]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($org) {
                $view_data['torneo']['organizacion_nombre'] = $org['nombre'] ?? 'N/A';
                $view_data['torneo']['organizacion_logo'] = !empty($org['logo']) ? $org['logo'] : null;
            }
        } catch (Exception $e) { /* ignorar */ }
    }
    
    // Cronómetro: página aparte sin layout (pantalla dedicada)
    if (!empty($use_cronometro_standalone) && $view_file && file_exists($view_file)) {
        torneo_gestion_render_view_extract_include($view_file, $view_data);
        exit;
    }

    // Reportes resultados: vista imprimible sin layout
    if (!empty($use_reportes_print_standalone) && $view_file && file_exists($view_file)) {
        torneo_gestion_render_view_extract_include($view_file, $view_data);
        exit;
    }
    
    // Si se invoca desde panel_torneo.php, no renderizar aquí; panel_torneo lo hará con un solo contenedor
    $is_panel_standalone_page = (basename($_SERVER['PHP_SELF'] ?? '') === 'panel_torneo.php');
    if ($is_panel_standalone_page) {
        return; // panel_torneo.php hace el render
    }
    
    // Determinar si usar layout independiente o layout normal
    $use_standalone_layout = (basename($_SERVER['PHP_SELF']) === 'admin_torneo.php');
    
    if ($use_standalone_layout) {
        // Usar layout independiente para admin_torneo.php
        ob_start();
        if ($view_file && file_exists($view_file)) {
            torneo_gestion_render_view_in_lvd_shell($view_file, $view_data);
        } else {
            throw new Exception('Vista no encontrada: ' . basename($view_file));
        }
        $content = ob_get_clean();
        
        // Asegurar que $torneo y $torneo_id estén disponibles para el layout
        if (!isset($torneo) && isset($view_data['torneo'])) {
            $torneo = $view_data['torneo'];
        }
        if (!isset($torneo_id) && isset($torneo['id'])) {
            $torneo_id = $torneo['id'];
        } elseif (!isset($torneo_id)) {
            $torneo_id = (int)($_GET['torneo_id'] ?? $_REQUEST['torneo_id'] ?? 0);
        }
        
        // Obtener acción actual
        $action = $_GET['action'] ?? $_REQUEST['action'] ?? '';
        
        $page_title = $page_title ?? 'Administrador de Torneos';
        include __DIR__ . '/../public/includes/admin_torneo_layout.php';
    } else {
        // Usar layout normal (incluido desde index.php)
        if ($view_file && file_exists($view_file)) {
            torneo_gestion_render_view_in_lvd_shell($view_file, $view_data);
        } else {
            throw new Exception('Vista no encontrada: ' . basename($view_file));
        }
    }
    
} catch (Exception $e) {
    $use_standalone_layout = (basename($_SERVER['PHP_SELF']) === 'admin_torneo.php');
    
    if ($use_standalone_layout) {
        // Mostrar error en layout independiente
        ob_start();
        $error_message = $e->getMessage();
        $view_file = __DIR__ . '/gestion_torneos/index.php';
        $view_data = ['torneos' => [], 'error_message' => $error_message];
        torneo_gestion_render_view_in_lvd_shell($view_file, $view_data);
        $content = ob_get_clean();
        
        $page_title = 'Error - Administrador de Torneos';
        include __DIR__ . '/../public/includes/admin_torneo_layout.php';
    } else {
        // Mostrar error en layout normal
        $error_message = $e->getMessage();
        $view_file = __DIR__ . '/gestion_torneos/index.php';
        $view_data = ['torneos' => [], 'error_message' => $error_message];
        torneo_gestion_render_view_in_lvd_shell($view_file, $view_data);
    }
}

// =================================================================
// FUNCIONES AUXILIARES
// =================================================================

/**
 * Obtiene lista de actas pendientes de verificación (origen QR, estatus pendiente_verificacion)
 */
function obtenerDatosVerificarActasLista($torneo_id) {
    $pdo = DB::pdo();
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('estatus', $cols)) {
        return ['actas_pendientes' => []];
    }
    $has_origen = in_array('origen_dato', $cols);
    $sql = "
        SELECT DISTINCT partida, mesa
        FROM partiresul
        WHERE id_torneo = ? AND mesa > 0 AND estatus = 'pendiente_verificacion'"
        . ($has_origen ? " AND origen_dato = 'qr'" : "") . "
        ORDER BY partida ASC, mesa ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    return ['actas_pendientes' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/**
 * Obtiene torneos con actas pendientes de verificación (QR) según el rol del usuario.
 * Usado por verificar_actas_index para listar torneos con mesas pendientes.
 *
 * @param int $user_id
 * @param bool $is_admin_general
 * @return array ['torneos' => array, 'total_actas_pendientes' => int]
 */
function obtenerTorneosConActasPendientes($user_id, $is_admin_general) {
    $pdo = DB::pdo();
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('estatus', $cols)) {
        return ['torneos' => [], 'total_actas_pendientes' => 0];
    }
    $has_origen = in_array('origen_dato', $cols);
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_t = !empty($tournament_filter['where']) ? "AND " . $tournament_filter['where'] : "";
    $params = $tournament_filter['params'];

    $extra_where = $has_origen ? " AND pr.origen_dato = 'qr'" : "";
    $sql = "
        SELECT t.id, t.nombre, t.fechator, t.club_responsable,
               o.nombre as organizacion_nombre,
               COUNT(DISTINCT CONCAT(pr.partida, '-', pr.mesa)) as actas_pendientes
        FROM partiresul pr
        INNER JOIN tournaments t ON pr.id_torneo = t.id
        LEFT JOIN organizaciones o ON t.club_responsable = o.id
        WHERE pr.mesa > 0 AND pr.estatus = 'pendiente_verificacion' $extra_where
        AND t.estatus = 1
        $where_t
        GROUP BY t.id, t.nombre, t.fechator, t.club_responsable, o.nombre
        HAVING actas_pendientes > 0
        ORDER BY t.fechator DESC, t.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($torneos, 'actas_pendientes'));
    return ['torneos' => $torneos, 'total_actas_pendientes' => (int)$total];
}

/**
 * Obtiene datos de una acta específica para verificación
 */
function obtenerDatosVerificarActa($torneo_id, $ronda, $mesa) {
    $pdo = DB::pdo();
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_estatus = in_array('estatus', $cols);
    $sql = "
        SELECT pr.id, pr.id_usuario, pr.secuencia, pr.resultado1, pr.resultado2, pr.efectividad,
               pr.ff, pr.tarjeta, pr.sancion, pr.foto_acta, pr.estatus, pr.origen_dato,
               u.nombre as nombre_completo
        FROM partiresul pr
        INNER JOIN usuarios u ON pr.id_usuario = u.id
        WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
        ORDER BY pr.secuencia ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($jugadores) !== 4) {
        return null;
    }
    if ($has_estatus) {
        $estatus_primero = $jugadores[0]['estatus'] ?? '';
        if ($estatus_primero !== 'pendiente_verificacion') {
            return null; // Ya verificada
        }
    }
    return ['jugadores' => $jugadores];
}

/**
 * Obtiene torneos disponibles para gestión, opcionalmente filtrados por categoría.
 * Categorías: realizados (cerrados), en_proceso (en curso), por_realizar (futuros).
 *
 * @param int $user_id
 * @param bool $is_admin_general
 * @param string|null $filtro 'realizados' | 'en_proceso' | 'por_realizar' | null (todos)
 * @return array
 */
function obtenerTorneosGestion($user_id, $is_admin_general, $filtro = null) {
    $pdo = DB::pdo();
    
    $tournament_filter = Auth::getTournamentFilterForRole('t');
    $where_clause = !empty($tournament_filter['where']) ? "WHERE " . $tournament_filter['where'] : "";
    $params = $tournament_filter['params'];
    
    $sql = "SELECT t.*, o.nombre as organizacion_nombre,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id) as total_inscritos,
            (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND estatus IN ('confirmado', 'solvente')) as inscritos_confirmados
            FROM tournaments t
            LEFT JOIN organizaciones o ON t.club_responsable = o.id
            $where_clause
            ORDER BY t.fechator DESC, t.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hoy = date('Y-m-d');
    
    foreach ($torneos as &$torneo) {
        $rondas_generadas = obtenerRondasGeneradas($torneo['id']);
        $torneo['rondas_generadas'] = count($rondas_generadas);
        $ultima_ronda = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
        $torneo['ultima_ronda'] = $ultima_ronda;
        $torneo['ronda_actual'] = $ultima_ronda;
        $torneo['proxima_ronda'] = $ultima_ronda + 1;
        $torneo['rondas_totales'] = $torneo['rondas'] ?? 0;
        $torneo['rondas_faltantes'] = max(0, ($torneo['rondas_totales'] ?? 0) - $ultima_ronda);
        $torneo['porcentaje_progreso'] = ($torneo['rondas_totales'] > 0) ? round(($ultima_ronda / $torneo['rondas_totales']) * 100) : 0;

        $locked = (int)($torneo['locked'] ?? 0) === 1;
        $fecha = $torneo['fechator'] ?? null;
        $fecha_ok = $fecha ? (strtotime($fecha) <= strtotime($hoy)) : false;

        if ($locked) {
            $torneo['categoria'] = 'realizados';
        } elseif ($fecha_ok || $ultima_ronda > 0) {
            $torneo['categoria'] = 'en_proceso';
        } else {
            $torneo['categoria'] = 'por_realizar';
        }
    }
    unset($torneo);

    if ($filtro !== null && in_array($filtro, ['realizados', 'en_proceso', 'por_realizar'], true)) {
        $torneos = array_values(array_filter($torneos, function ($t) use ($filtro) {
            return ($t['categoria'] ?? '') === $filtro;
        }));
        if ($filtro === 'por_realizar') {
            usort($torneos, function ($a, $b) {
                $fa = $a['fechator'] ?? '';
                $fb = $b['fechator'] ?? '';
                return strcmp($fa, $fb);
            });
        }
    }

    return $torneos;
}

/**
 * Obtiene datos de un torneo
 */
function obtenerTorneo($torneo_id, $user_id, $is_admin_general) {
    $pdo = DB::pdo();
    
    // Obtener torneo (la tabla clubes NO tiene admin_id, se relaciona vía usuarios.club_id)
    $sql = "SELECT t.*, c.nombre as club_nombre
            FROM tournaments t
            LEFT JOIN clubes c ON t.club_responsable = c.id
            WHERE t.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar permisos usando Auth::canAccessTournament
    if ($torneo && !Auth::canAccessTournament($torneo_id)) {
        return null; // Sin permisos
    }
    
    return $torneo;
}

/**
 * Verifica permisos sobre un torneo
 */
function verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general) {
    // Usar Auth::canAccessTournament que ya maneja todos los roles correctamente
    if (!Auth::canAccessTournament($torneo_id)) {
        throw new Exception('No tiene permisos para acceder a este torneo');
    }
    return obtenerTorneo($torneo_id, $user_id, $is_admin_general);
}

/**
 * Obtiene rondas generadas de un torneo
 */
function obtenerRondasGeneradas($torneo_id) {
    $pdo = DB::pdo();
    
    $sql = "SELECT 
                partida as num_ronda,
                COUNT(DISTINCT mesa) as total_mesas,
                COUNT(*) as total_jugadores,
                COUNT(CASE WHEN mesa = 0 THEN 1 END) as jugadores_bye,
                MAX(fecha_partida) as fecha_generacion
            FROM partiresul
            WHERE id_torneo = ?
            GROUP BY partida
            ORDER BY partida ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/**
 * Obtiene datos de inscripciones de un torneo
 */
function obtenerDatosInscripciones($torneo_id) {
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar si el torneo ha iniciado (tiene rondas generadas)
    $rondas_generadas = obtenerRondasGeneradas($torneo_id);
    $torneo_iniciado = !empty($rondas_generadas);
    
    // Obtener todos los inscritos del torneo
    $sql = "SELECT 
                i.*,
                u.nombre as nombre_completo,
                u.username,
                u.sexo,
                c.nombre as nombre_club,
                c.id as club_id
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? AND i.estatus != 4
            ORDER BY c.nombre ASC, u.nombre ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener jugadores retirados (estatus = 4) para sustitución
    $sql_retirados = "SELECT i.*, u.nombre as nombre_completo, u.username, u.cedula, c.nombre as nombre_club
        FROM inscritos i
        INNER JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ? AND i.estatus = 4
        ORDER BY u.nombre ASC";
    $stmt = $pdo->prepare($sql_retirados);
    $stmt->execute([$torneo_id]);
    $retirados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $total_inscritos = count($inscritos);
    $confirmados = 0;
    $hombres = 0;
    $mujeres = 0;
    
    foreach ($inscritos as $inscrito) {
        if (in_array($inscrito['estatus'], [1, 2, 'confirmado', 'solvente'])) {
            $confirmados++;
        }
        if ($inscrito['sexo'] == 1 || strtoupper($inscrito['sexo']) === 'M') {
            $hombres++;
        } elseif ($inscrito['sexo'] == 2 || strtoupper($inscrito['sexo']) === 'F') {
            $mujeres++;
        }
    }
    
    // Resumen por club
    $resumen_clubes = [];
    foreach ($inscritos as $inscrito) {
        $club_id = $inscrito['club_id'] ?? 0;
        $club_nombre = $inscrito['nombre_club'] ?? 'Sin Club';
        
        if (!isset($resumen_clubes[$club_id])) {
            $resumen_clubes[$club_id] = [
                'id' => $club_id,
                'nombre' => $club_nombre,
                'total' => 0,
                'hombres' => 0,
                'mujeres' => 0
            ];
        }
        
        $resumen_clubes[$club_id]['total']++;
        if ($inscrito['sexo'] == 1 || strtoupper($inscrito['sexo']) === 'M') {
            $resumen_clubes[$club_id]['hombres']++;
        } elseif ($inscrito['sexo'] == 2 || strtoupper($inscrito['sexo']) === 'F') {
            $resumen_clubes[$club_id]['mujeres']++;
        }
    }
    
    return [
        'torneo' => $torneo,
        'inscritos' => $inscritos,
        'retirados' => $retirados,
        'total_inscritos' => $total_inscritos,
        'confirmados' => $confirmados,
        'hombres' => $hombres,
        'mujeres' => $mujeres,
        'resumen_clubes' => array_values($resumen_clubes),
        'torneo_iniciado' => $torneo_iniciado
    ];
}

/**
 * Obtiene datos para la pantalla de notificaciones del torneo (plantillas + inscritos)
 */
function obtenerDatosNotificacionesTorneo($torneo_id) {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT t.*, o.nombre as organizacion_nombre FROM tournaments t LEFT JOIN organizaciones o ON t.club_responsable = o.id WHERE t.id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        return ['torneo' => null, 'plantillas' => [], 'ultima_ronda' => 0, 'total_inscritos' => 0];
    }
    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);
    $plantillas = $nm->listarPlantillas('torneo');
    $ultima_ronda = 0;
    try {
        $modalidad = (int)($torneo['modalidad'] ?? 0);
        $mesaService = TorneoMesaAsignacionResolver::servicioPorModalidad($modalidad);
        $ultima_ronda = $mesaService->obtenerUltimaRonda($torneo_id);
    } catch (Exception $e) {}
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND estatus != 4");
    $stmt->execute([$torneo_id]);
    $total_inscritos = (int) $stmt->fetchColumn();

    $inscritos_prueba = [];
    if ($total_inscritos > 0) {
        $ronda_ref = $ultima_ronda > 0 ? $ultima_ronda : 1;
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.telegram_chat_id,
                   COALESCE(i.posicion, 0) AS posicion, COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
                   COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND i.estatus != 4
            ORDER BY i.id
            LIMIT 50
        ");
        $stmt->execute([$torneo_id]);
        $inscritos_prueba = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mesaPareja = [];
        $stmtMesa = $pdo->prepare("
            SELECT pr.id_usuario, pr.mesa, pr_p.id_usuario AS pareja_id, u_pareja.nombre AS pareja_nombre
            FROM partiresul pr
            LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
            LEFT JOIN usuarios u_pareja ON u_pareja.id = pr_p.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
        ");
        $stmtMesa->execute([$torneo_id, $ronda_ref]);
        while ($row = $stmtMesa->fetch(PDO::FETCH_ASSOC)) {
            $mesaPareja[(int)$row['id_usuario']] = [
                'mesa' => (string)$row['mesa'],
                'pareja_id' => (int)($row['pareja_id'] ?? 0),
                'pareja' => trim((string)($row['pareja_nombre'] ?? '')) ?: '—',
            ];
        }
        require_once __DIR__ . '/../lib/app_helpers.php';
        foreach ($inscritos_prueba as &$ins) {
            $uid = (int)$ins['id'];
            $ins['mesa'] = $mesaPareja[$uid]['mesa'] ?? '—';
            $ins['pareja_id'] = $mesaPareja[$uid]['pareja_id'] ?? 0;
            $ins['pareja'] = $mesaPareja[$uid]['pareja'] ?? '—';
            $ins['url_resumen'] = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneo_id, 'inscrito_id' => $uid, 'from' => 'notificaciones']);
        }
        unset($ins);
    }

    return [
        'torneo' => $torneo,
        'torneo_id' => (int) $torneo_id,
        'plantillas' => $plantillas,
        'ultima_ronda' => $ultima_ronda,
        'total_inscritos' => $total_inscritos,
        'inscritos_prueba' => $inscritos_prueba,
    ];
}

/**
 * Envía notificación masiva según plantilla: a inscritos del torneo o a todos los usuarios del administrador.
 * Si POST prueba=1 e inscrito_id=X, envía solo una notificación de prueba a ese inscrito (con prefijo [Prueba]).
 */
function enviarNotificacionTorneo($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }
    $pdo = DB::pdo();
    $clave_plantilla = trim((string)($_POST['plantilla_clave'] ?? ''));
    $ronda = (int)($_POST['ronda'] ?? 0);
    $es_prueba = !empty($_POST['prueba']);
    $inscrito_id_prueba = $es_prueba ? (int)($_POST['inscrito_id'] ?? 0) : 0;

    if ($clave_plantilla === '') {
        $_SESSION['error'] = 'Debe seleccionar una plantilla.';
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }

    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);
    $plantilla = $nm->obtenerPlantilla($clave_plantilla);
    if (!$plantilla) {
        $_SESSION['error'] = 'Plantilla no encontrada.';
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }

    if ($es_prueba && $inscrito_id_prueba > 0) {
        enviarNotificacionPrueba($pdo, $nm, $torneo_id, $inscrito_id_prueba, $plantilla, $ronda);
        $_SESSION['success'] = 'Notificación de prueba encolada para 1 inscrito. Revisa la campanita con ese usuario.';
        header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
        exit;
    }

    $stmt = $pdo->prepare("SELECT nombre, club_responsable FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $torneo_nombre = $torneo_row['nombre'] ?? 'Torneo';
    $club_responsable = (int)($torneo_row['club_responsable'] ?? 0);

    $destinatarios = isset($plantilla['destinatarios']) ? trim((string)$plantilla['destinatarios']) : 'inscritos';
    if ($destinatarios !== 'todos_usuarios_admin') {
        $destinatarios = 'inscritos';
    }

    if ($destinatarios === 'todos_usuarios_admin') {
        require_once __DIR__ . '/../lib/ClubHelper.php';
        $club_ids = ClubHelper::getClubesSupervised($club_responsable);
        if (empty($club_ids)) {
            $club_ids = [$club_responsable];
        }
        $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT id, nombre, telegram_chat_id
            FROM usuarios
            WHERE club_id IN ($placeholders) AND role = 'usuario' AND status = 'approved'
        ");
        $stmt->execute(array_values($club_ids));
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($jugadores)) {
            $_SESSION['error'] = 'No hay usuarios en los clubes del administrador.';
            header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
            exit;
        }
        $items = [];
        foreach ($jugadores as $j) {
            $mensaje = $nm->procesarMensaje($plantilla['cuerpo_mensaje'], [
                'nombre' => (string)($j['nombre'] ?? ''),
                'ronda' => (string)$ronda,
                'torneo' => $torneo_nombre,
                'ganados' => '—',
                'perdidos' => '—',
                'efectividad' => '—',
                'puntos' => '—',
                'mesa' => '—',
                'pareja' => '—',
            ]);
            $items[] = [
                'id' => (int)$j['id'],
                'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
                'mensaje' => $mensaje,
                'url_destino' => '',
            ];
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.telegram_chat_id,
                   COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
                   COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ? AND i.estatus != 4
        ");
        $stmt->execute([$torneo_id]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($jugadores)) {
            $_SESSION['error'] = 'No hay inscritos activos en este torneo.';
            header('Location: ' . buildRedirectUrl('notificaciones', ['torneo_id' => $torneo_id]));
            exit;
        }

        $mesaPareja = [];
        if ($ronda > 0) {
            $stmtMesa = $pdo->prepare("
                SELECT pr.id_usuario, pr.mesa, u_pareja.nombre AS pareja_nombre
                FROM partiresul pr
                LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                    AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
                LEFT JOIN usuarios u_pareja ON u_pareja.id = pr_p.id_usuario
                WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            ");
            $stmtMesa->execute([$torneo_id, $ronda]);
            while ($row = $stmtMesa->fetch(PDO::FETCH_ASSOC)) {
                $mesaPareja[(int)$row['id_usuario']] = [
                    'mesa' => (string)$row['mesa'],
                    'pareja' => trim((string)($row['pareja_nombre'] ?? '')) ?: '—',
                ];
            }
        }

        require_once __DIR__ . '/../lib/app_helpers.php';
        $items = [];
        foreach ($jugadores as $j) {
            $uid = (int)$j['id'];
            $mp = $mesaPareja[$uid] ?? null;
            $url_resumen = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneo_id, 'inscrito_id' => $uid, 'from' => 'notificaciones']);
            $mensaje = $nm->procesarMensaje($plantilla['cuerpo_mensaje'], [
                'nombre' => (string)($j['nombre'] ?? ''),
                'ronda' => (string)$ronda,
                'torneo' => $torneo_nombre,
                'ganados' => (string)($j['ganados'] ?? '0'),
                'perdidos' => (string)($j['perdidos'] ?? '0'),
                'efectividad' => (string)($j['efectividad'] ?? '0'),
                'puntos' => (string)($j['puntos'] ?? '0'),
                'mesa' => $mp ? (string)$mp['mesa'] : '—',
                'pareja' => $mp ? (string)$mp['pareja'] : '—',
                'url_resumen' => $url_resumen,
            ]);
            $items[] = [
                'id' => $uid,
                'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
                'mensaje' => $mensaje,
                'url_destino' => $url_resumen,
            ];
        }
    }

    $nm->programarMasivoPersonalizado($items);
    $_SESSION['success'] = 'Notificaciones encoladas: ' . count($items) . ' mensaje(s). Se enviarán por Telegram y aparecerán en la campanita web.';
    header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Envía una sola notificación de prueba a un inscrito (datos reales de inscritos).
 */
function enviarNotificacionPrueba(PDO $pdo, NotificationManager $nm, int $torneo_id, int $inscrito_id, array $plantilla, int $ronda): void {
    $stmt = $pdo->prepare("SELECT nombre FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo_nombre = $stmt->fetchColumn() ?: 'Torneo';
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.telegram_chat_id,
               COALESCE(i.posicion, 0) AS posicion, COALESCE(i.ganados, 0) AS ganados, COALESCE(i.perdidos, 0) AS perdidos,
               COALESCE(i.efectividad, 0) AS efectividad, COALESCE(i.puntos, 0) AS puntos
        FROM inscritos i
        INNER JOIN usuarios u ON i.id_usuario = u.id
        WHERE i.torneo_id = ? AND i.id_usuario = ? AND i.estatus != 4
    ");
    $stmt->execute([$torneo_id, $inscrito_id]);
    $j = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$j) {
        $_SESSION['error'] = 'Inscrito no encontrado.';
        return;
    }
    $mesaPareja = [];
    if ($ronda > 0) {
        $stmtMesa = $pdo->prepare("
            SELECT pr.id_usuario, pr.mesa, pr_p.id_usuario AS pareja_id, u_pareja.nombre AS pareja_nombre
            FROM partiresul pr
            LEFT JOIN partiresul pr_p ON pr_p.id_torneo = pr.id_torneo AND pr_p.partida = pr.partida AND pr_p.mesa = pr.mesa
                AND pr_p.secuencia = CASE pr.secuencia WHEN 1 THEN 2 WHEN 2 THEN 1 WHEN 3 THEN 4 WHEN 4 THEN 3 END
            LEFT JOIN usuarios u_pareja ON u_pareja.id = pr_p.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0 AND pr.id_usuario = ?
        ");
        $stmtMesa->execute([$torneo_id, $ronda, $inscrito_id]);
        $row = $stmtMesa->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $mesaPareja = [
                'mesa' => (string)$row['mesa'],
                'pareja_id' => (int)($row['pareja_id'] ?? 0),
                'pareja' => trim((string)($row['pareja_nombre'] ?? '')) ?: '—',
            ];
        }
    }
    require_once __DIR__ . '/../lib/app_helpers.php';
    $url_resumen = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'resumen_individual', 'torneo_id' => $torneo_id, 'inscrito_id' => $inscrito_id, 'from' => 'notificaciones']);
    $url_clasificacion = AppHelpers::url('index.php', ['page' => 'torneo_gestion', 'action' => 'posiciones', 'torneo_id' => $torneo_id, 'from' => 'notificaciones']);
    $mensaje = $nm->procesarMensaje($plantilla['cuerpo_mensaje'], [
        'nombre' => (string)($j['nombre'] ?? ''),
        'ronda' => (string)$ronda,
        'torneo' => $torneo_nombre,
        'ganados' => (string)($j['ganados'] ?? '0'),
        'perdidos' => (string)($j['perdidos'] ?? '0'),
        'efectividad' => (string)($j['efectividad'] ?? '0'),
        'puntos' => (string)($j['puntos'] ?? '0'),
        'mesa' => $mesaPareja['mesa'] ?? '—',
        'pareja' => $mesaPareja['pareja'] ?? '—',
        'url_resumen' => $url_resumen,
    ]);
    $nm->programarMasivoPersonalizado([[
        'id' => (int)$j['id'],
        'telegram_chat_id' => trim((string)($j['telegram_chat_id'] ?? '')) ?: null,
        'mensaje' => '[Prueba] ' . $mensaje,
        'url_destino' => $url_resumen,
        'datos_json' => [
            'tipo' => 'nueva_ronda',
            'ronda' => (string) $ronda,
            'mesa' => $mesaPareja['mesa'] ?? '—',
            'usuario_id' => (int)$j['id'],
            'nombre' => (string)($j['nombre'] ?? ''),
            'pareja_id' => (int)($mesaPareja['pareja_id'] ?? 0),
            'pareja_nombre' => $mesaPareja['pareja'] ?? '—',
            'posicion' => (string)($j['posicion'] ?? '0'),
            'ganados' => (string)($j['ganados'] ?? '0'),
            'perdidos' => (string)($j['perdidos'] ?? '0'),
            'efectividad' => (string)($j['efectividad'] ?? '0'),
            'puntos' => (string)($j['puntos'] ?? '0'),
            'url_resumen' => $url_resumen,
            'url_clasificacion' => $url_clasificacion,
        ],
    ]]);
}

/**
 * Obtiene datos de equipos para el administrador
 */
function obtenerDatosEquiposAdmin($torneo_id) {
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener todos los equipos del torneo (de todos los clubes)
    $stmt = $pdo->prepare("
        SELECT e.*, c.nombre as nombre_club,
               (SELECT COUNT(*) FROM inscritos i WHERE i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo AND i.estatus != 4) AS total_jugadores
        FROM equipos e
        LEFT JOIN clubes c ON e.id_club = c.id
        WHERE e.id_torneo = ?
        ORDER BY c.nombre ASC, e.nombre_equipo ASC
    ");
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por club
    $equipos_por_club = [];
    foreach ($equipos as $equipo) {
        $club_id = $equipo['id_club'];
        if (!isset($equipos_por_club[$club_id])) {
            $equipos_por_club[$club_id] = [
                'nombre' => $equipo['nombre_club'] ?? 'Sin Club',
                'equipos' => []
            ];
        }
        $equipos_por_club[$club_id]['equipos'][] = $equipo;
    }
    
    return [
        'torneo' => $torneo,
        'equipos' => $equipos,
        'equipos_por_club' => $equipos_por_club,
        'total_equipos' => count($equipos)
    ];
}


/**
 * Obtiene datos para el panel de control de torneos por equipos
 */
function obtenerDatosPanelEquipos($torneo_id) {
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    
    // Obtener total de equipos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM equipos WHERE id_torneo = ?");
    $stmt->execute([$torneo_id]);
    $total_equipos = (int)$stmt->fetchColumn();
    
    // Obtener total de jugadores inscritos (con codigo_equipo real; 000-000 = individual en sitio)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo != '' AND codigo_equipo != '000-000' AND estatus != 4");
    $stmt->execute([$torneo_id]);
    $total_jugadores_inscritos = (int)$stmt->fetchColumn();
    
    // Obtener total de clubes con equipos
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT id_club) FROM equipos WHERE id_torneo = ?");
    $stmt->execute([$torneo_id]);
    $total_clubes_con_equipos = (int)$stmt->fetchColumn();
    
    // Obtener jugadores disponibles (NO inscritos - sin codigo_equipo y estatus != 4)
    $current_user = Auth::user();
    $user_club_id_raw = Auth::getUserClubId();
    $user_club_id = ($user_club_id_raw !== null && (int)$user_club_id_raw > 0) ? (int)$user_club_id_raw : null;
    $is_admin_general = Auth::isAdminGeneral();
    $is_admin_club = Auth::isAdminClub();
    
    $jugadores_disponibles = [];
    
    if ($is_admin_general) {
        // Admin general: todos los usuarios que no están inscritos
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM usuarios u
            LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND ins.estatus != 4
            WHERE u.role = 'usuario' 
              AND (u.status IN ('approved', 'active', 'activo') OR u.status = 1)
              AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
        ");
        $stmt->execute([$torneo_id]);
        $total_jugadores_disponibles = (int)$stmt->fetchColumn();
    } else if ($user_club_id) {
        // Admin club o usuario: jugadores del territorio que no están inscritos
        if ($is_admin_club) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
            $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
        } else {
            $clubes_ids = [$user_club_id];
        }
        
        if (!empty($clubes_ids)) {
            $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM usuarios u
                LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND ins.estatus != 4
                WHERE u.role = 'usuario' 
                  AND (u.status IN ('approved', 'active', 'activo') OR u.status = 1)
                  AND u.club_id IN ({$placeholders})
                  AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
            ");
            $stmt->execute(array_merge([$torneo_id], $clubes_ids));
            $total_jugadores_disponibles = (int)$stmt->fetchColumn();
        } else {
            $total_jugadores_disponibles = 0;
        }
    } else {
        $total_jugadores_disponibles = 0;
    }
    
    // Obtener información de rondas (igual que panel individual)
    $rondas_generadas = obtenerRondasGeneradas($torneo_id);
    $ultima_ronda = !empty($rondas_generadas) ? max(array_column($rondas_generadas, 'num_ronda')) : 0;
    $proxima_ronda = $ultima_ronda + 1;
    
    // Calcular si se puede generar la próxima ronda
    $puede_generar = true;
    $mesas_incompletas = 0;
    $total_mesas_ronda = 0;
    if ($ultima_ronda > 0) {
        $mesas_incompletas = contarMesasIncompletas($torneo_id, $ultima_ronda);
        $puede_generar = $mesas_incompletas === 0;
        
        // Contar total de mesas de la última ronda
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT mesa) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0");
        $stmt->execute([$torneo_id, $ultima_ronda]);
        $total_mesas_ronda = (int)$stmt->fetchColumn();
    }
    
    // Estadísticas adicionales
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND registrado = 1");
    $stmt->execute([$torneo_id]);
    $total_partidas = (int)$stmt->fetchColumn();
    
    // Obtener información del club responsable
    $club_nombre = 'N/A';
    if (!empty($torneo['club_responsable'])) {
        $stmt = $pdo->prepare("SELECT nombre FROM clubes WHERE id = ?");
        $stmt->execute([$torneo['club_responsable']]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        $club_nombre = $club['nombre'] ?? 'N/A';
    }
    $torneo['club_nombre'] = $club_nombre;
    
    return [
        'torneo' => $torneo,
        'total_equipos' => $total_equipos,
        'total_jugadores_inscritos' => $total_jugadores_inscritos,
        'total_clubes_con_equipos' => $total_clubes_con_equipos,
        'total_jugadores_disponibles' => $total_jugadores_disponibles,
        'jugadores_por_equipo' => max(2, (int)($torneo['pareclub'] ?? 4)),
        // Información de rondas
        'rondas_generadas' => $rondas_generadas,
        'ultima_ronda' => $ultima_ronda,
        'proxima_ronda' => $proxima_ronda,
        'puede_generar_ronda' => $puede_generar,
        'mesas_incompletas' => $mesas_incompletas,
        'estadisticas' => [
            'total_equipos' => $total_equipos,
            'total_jugadores' => $total_jugadores_inscritos,
            'total_partidas' => $total_partidas,
            'mesas_ronda' => $total_mesas_ronda
        ]
    ];
}

/**
 * Obtiene datos para gestionar inscripciones de equipos (listado completo y por club)
 */
function obtenerDatosGestionarInscripcionesEquipos($torneo_id) {
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    
    $pdo = DB::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    
    // Obtener todos los equipos del torneo ordenados por club y código de equipo (secuencial)
    $stmt = $pdo->prepare("
        SELECT 
            e.*, 
            c.nombre as nombre_club,
            c.id as club_id
        FROM equipos e
        LEFT JOIN clubes c ON e.id_club = c.id
        WHERE e.id_torneo = ?
        ORDER BY 
            COALESCE(c.nombre, 'ZZZ') ASC,
            e.codigo_equipo ASC,
            e.nombre_equipo ASC
    ");
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agregar jugadores a cada equipo usando codigo_equipo desde inscritos
    foreach ($equipos as &$equipo) {
        $jugadores = [];
        if (!empty($equipo['codigo_equipo'])) {
            $stmt_jugadores = $pdo->prepare("
                SELECT 
                    i.id as id_inscrito,
                    i.id_usuario,
                    i.codigo_equipo,
                    u.cedula,
                    u.nombre,
                    u.id as usuario_id
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                WHERE i.torneo_id = ? 
                    AND i.codigo_equipo = ?
                    AND i.estatus != 4
                ORDER BY i.id ASC
            ");
            $stmt_jugadores->execute([$torneo_id, $equipo['codigo_equipo']]);
            $jugadores = $stmt_jugadores->fetchAll(PDO::FETCH_ASSOC);
        }
        $equipo['jugadores'] = $jugadores;
        $equipo['total_jugadores'] = count($jugadores);
    }
    unset($equipo);
    
    // Agrupar equipos por club manteniendo el orden secuencial
    $equipos_por_club = [];
    $club_ids_orden = [];
    foreach ($equipos as $equipo) {
        $club_id = $equipo['club_id'] ?? 0;
        $club_nombre = $equipo['nombre_club'] ?? 'Sin Club';
        
        if (!isset($equipos_por_club[$club_id])) {
            $equipos_por_club[$club_id] = [
                'id' => $club_id,
                'nombre' => $club_nombre,
                'equipos' => []
            ];
            $club_ids_orden[] = $club_id;
        }
        $equipos_por_club[$club_id]['equipos'][] = $equipo;
    }
    
    // Reordenar equipos_por_club según el orden de club_ids_orden para mantener el orden secuencial
    $equipos_por_club_ordenado = [];
    foreach ($club_ids_orden as $club_id) {
        if (isset($equipos_por_club[$club_id])) {
            $equipos_por_club_ordenado[] = $equipos_por_club[$club_id];
        }
    }
    $equipos_por_club = $equipos_por_club_ordenado;
    
    $modalidad = (int)($torneo['modalidad'] ?? 0);
    $es_parejas = in_array($modalidad, [2, 4], true);
    $jugadores_por_equipo = $es_parejas ? 2 : max(2, (int)($torneo['pareclub'] ?? 4));
    
    return [
        'torneo' => $torneo,
        'equipos' => $equipos,
        'equipos_por_club' => $equipos_por_club,
        'jugadores_por_equipo' => $jugadores_por_equipo,
        'es_parejas' => $es_parejas,
    ];
}

/**
 * Obtiene datos para gestionar inscripciones de parejas fijas (modalidad 4).
 */
function obtenerDatosGestionarInscripcionesParejasFijas($torneo_id) {
    require_once __DIR__ . '/../lib/ParejasFijasHelper.php';
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    if ((int)($torneo['modalidad'] ?? 0) !== ParejasFijasHelper::MODALIDAD_PAREJAS_FIJAS) {
        throw new Exception('Este torneo no es de parejas fijas');
    }
    $parejas = ParejasFijasHelper::listarParejas($pdo, $torneo_id);
    $club_ids = array_unique(array_column($parejas, 'id_club'));
    $nombres_club = [];
    if (!empty($club_ids)) {
        $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id IN ($placeholders)");
        $stmt->execute(array_values($club_ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nombres_club[(int)$r['id']] = $r['nombre'];
        }
    }
    foreach ($parejas as &$p) {
        $p['nombre_club'] = $nombres_club[$p['id_club']] ?? 'Sin club';
    }
    unset($p);
    $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
    $stmt->execute();
    $clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.cedula
        FROM usuarios u
        WHERE u.status = 0
        AND NOT EXISTS (SELECT 1 FROM inscritos i WHERE i.torneo_id = ? AND i.id_usuario = u.id AND i.estatus != 4)
        ORDER BY u.nombre ASC
    ");
    $stmt->execute([$torneo_id]);
    $jugadores_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'torneo' => $torneo,
        'parejas' => $parejas,
        'clubes' => $clubes,
        'jugadores_disponibles' => $jugadores_disponibles,
        'solo_inscribir' => false,
    ];
}

/**
 * Datos para la vista inscribir_equipo_sitio (equipos en sitio).
 *
 * Rendimiento:
 * - Admin general: no carga listado masivo de usuarios; jugadores_disponibles = [] y jugadores_lista_lazy = true.
 * - Admin club: una query con club_id IN (…) para jugadores del territorio no asignados a equipo.
 * - Torneo: caché APCu 120 s si está disponible; sin APCu, SELECT directo (sin error).
 * - Clubes extra por equipos ya inscritos: un solo SELECT … id IN (…) (evita N+1).
 *
 * @param int $torneo_id ID del torneo
 *
 * @return array{
 *     torneo: array,
 *     jugadores_disponibles: array<int, array>,
 *     clubes_disponibles: array<int, array>,
 *     equipos_registrados: array<int, array>,
 *     total_jugadores_disponibles: int,
 *     total_equipos: int,
 *     jugadores_por_equipo: int,
 *     is_admin_club: bool,
 *     jugadores_lista_lazy: bool
 * }
 */
function obtenerDatosInscribirEquipoSitio(int $torneo_id): array
{
    require_once __DIR__ . '/../lib/ClubHelper.php';
    require_once __DIR__ . '/../lib/EquiposHelper.php';
    require_once __DIR__ . '/../config/auth.php';

    $pdo = DB::pdo();

    $user_club_id_raw = Auth::getUserClubId();
    $user_club_id = ($user_club_id_raw !== null && (int) $user_club_id_raw > 0)
        ? (int) $user_club_id_raw
        : null;
    $is_admin_general = Auth::isAdminGeneral();
    $is_admin_club = Auth::isAdminClub();

    static $torneoRequestCache = [];
    $memKey = 't_' . $torneo_id;
    if (isset($torneoRequestCache[$memKey])) {
        $torneo = $torneoRequestCache[$memKey];
    } elseif (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $apcuKey = 'inscribir_eq_sitio_torneo_' . $torneo_id;
        $torneo = @apcu_fetch($apcuKey);
        if (! is_array($torneo)) {
            $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
            $stmt->execute([$torneo_id]);
            $torneo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($torneo)) {
                @apcu_store($apcuKey, $torneo, 120);
            }
        }
        if (is_array($torneo)) {
            $torneoRequestCache[$memKey] = $torneo;
        } else {
            $torneo = null;
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ?');
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($torneo)) {
            $torneoRequestCache[$memKey] = $torneo;
        }
    }

    if (! is_array($torneo)) {
        throw new Exception('Torneo no encontrado');
    }

    $jugadores_disponibles = [];
    $jugadores_lista_lazy = false;
    $modalidad = (int)($torneo['modalidad'] ?? 0);
    $es_parejas = ($modalidad === 2);

    if ($is_admin_general && !$es_parejas) {
        $jugadores_disponibles = [];
        $jugadores_lista_lazy = true;
    } else {
        // Parejas: siempre listar disponibles de la entidad. Equipos: idem si tiene club.
        $clubes_ids = [];
        if ($es_parejas && $is_admin_general) {
            $org_torneo_id = Auth::getTournamentOrganizacionId($torneo_id);
            if ($org_torneo_id) {
                $stmt = $pdo->prepare("SELECT id FROM clubes WHERE organizacion_id = ? AND (estatus = 1 OR estatus = '1' OR estatus = 'activo')");
                $stmt->execute([$org_torneo_id]);
                $clubes_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
            }
        } elseif ($user_club_id) {
            if ($is_admin_club) {
                $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
                $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
            } else {
                $clubes_ids = [$user_club_id];
            }
        }
        if (!empty($clubes_ids)) {
            $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT u.id as id_usuario, u.nombre, u.cedula, u.sexo,
                       u.club_id as club_id, c.nombre as club_nombre,
                       ins.id as id_inscrito
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND (ins.estatus IS NULL OR ins.estatus != 4)
                WHERE u.role = 'usuario'
                  AND (u.status IN ('approved', 'active', 'activo') OR u.status = 1)
                  AND u.club_id IN ({$placeholders})
                  AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
                ORDER BY COALESCE(u.nombre, u.username) ASC
            ");
            $stmt->execute(array_merge([$torneo_id], $clubes_ids));
            $jugadores_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($es_parejas) {
            $jugadores_lista_lazy = false;
        }
    }
    
    // Agregar campo id para compatibilidad
    foreach ($jugadores_disponibles as &$jugador) {
        $jugador['id'] = $jugador['id_inscrito'] ?? null;
        $jugador['club_nombre'] = $jugador['club_nombre'] ?? 'Sin Club';
    }
    unset($jugador);
    
    // Clubes solo de la organización del torneo (organización activa del evento)
    $org_torneo_id = Auth::getTournamentOrganizacionId($torneo_id);
    $clubes_disponibles = [];
    $where_club_activo = "(c.estatus = 1 OR c.estatus = '1' OR c.estatus = 'activo')";
    if ($org_torneo_id) {
        $stmt = $pdo->prepare("SELECT c.id, c.nombre FROM clubes c WHERE c.organizacion_id = ? AND {$where_club_activo} ORDER BY c.nombre ASC");
        $stmt->execute([$org_torneo_id]);
        $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($is_admin_general) {
        $stmt = $pdo->query("SELECT id, nombre FROM clubes WHERE (estatus = 1 OR estatus = '1' OR estatus = 'activo') ORDER BY nombre ASC");
        $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user_club_id) {
        $stmt = $pdo->prepare('SELECT organizacion_id FROM clubes WHERE id = ? LIMIT 1');
        $stmt->execute([$user_club_id]);
        $org_usuario = $stmt->fetchColumn();
        if ($org_usuario) {
            $stmt = $pdo->prepare("SELECT c.id, c.nombre FROM clubes c WHERE c.organizacion_id = ? AND {$where_club_activo} ORDER BY c.nombre ASC");
            $stmt->execute([(int)$org_usuario]);
            $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($clubes_disponibles) && $is_admin_club) {
            $clubes_disponibles = ClubHelper::getClubesSupervisedWithData($user_club_id);
            $clubes_disponibles = array_map(static function ($r) {
                return ['id' => (int)($r['id'] ?? 0), 'nombre' => (string)($r['nombre'] ?? '')];
            }, $clubes_disponibles);
        }
        if (empty($clubes_disponibles) && $user_club_id) {
            $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id = ? AND (estatus = 1 OR estatus = '1' OR estatus = 'activo')");
            $stmt->execute([$user_club_id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($club) {
                $clubes_disponibles = [$club];
            }
        }
    }

    // Obtener equipos registrados (id_club desde tabla equipos para el select al editar)
    $stmt = $pdo->prepare("
        SELECT e.id, e.codigo_equipo, e.nombre_equipo, e.id_club AS id_club,
               COALESCE(NULLIF(TRIM(c.nombre), ''), CONCAT('Club #', e.id_club)) AS nombre_club
        FROM equipos e
        LEFT JOIN clubes c ON c.id = e.id_club
        WHERE e.id_torneo = ?
        ORDER BY e.codigo_equipo ASC, e.nombre_equipo ASC
    ");
    $stmt->execute([$torneo_id]);
    $equipos_registrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Asegurar id_club entero por fila (por si el JOIN devolvía ambigüedad)
    foreach ($equipos_registrados as &$eq) {
        $eq['id_club'] = (int)($eq['id_club'] ?? 0);
    }
    unset($eq);

    // Clubes usados por equipos ya inscritos y aún no en el selector (una sola consulta, sin N+1)
    $ids_select = array_map('intval', array_column($clubes_disponibles, 'id'));
    $faltan_club = [];
    foreach ($equipos_registrados as $eq) {
        $cid = (int)($eq['id_club'] ?? 0);
        if ($cid > 0 && !in_array($cid, $ids_select, true)) {
            $faltan_club[$cid] = true;
        }
    }
    if ($faltan_club !== []) {
        $ids_faltan = array_keys($faltan_club);
        $phc = implode(',', array_fill(0, count($ids_faltan), '?'));
        $stc = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id IN ($phc)");
        $stc->execute($ids_faltan);
        foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $clubes_disponibles[] = [
                'id' => (int)$fila['id'],
                'nombre' => ($fila['nombre'] ?? '') . ' (equipo)',
            ];
            $ids_select[] = (int)$fila['id'];
        }
    }
    usort($clubes_disponibles, static function ($a, $b) {
        return strcasecmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
    });
    // Jugadores por equipo: una consulta (evita N+1 y retrasa menos el HTML completo)
    $por_codigo = [];
    $codigos_eq = [];
    foreach ($equipos_registrados as $eq) {
        $c = trim((string)($eq['codigo_equipo'] ?? ''));
        if ($c !== '') {
            $codigos_eq[$c] = true;
        }
    }
    $codigos_list = array_keys($codigos_eq);
    if ($codigos_list !== []) {
        $ph = implode(',', array_fill(0, count($codigos_list), '?'));
        $stj = $pdo->prepare("
            SELECT i.codigo_equipo, i.id AS id_inscrito, i.id_usuario, u.cedula,
                   COALESCE(NULLIF(TRIM(u.nombre), ''), u.username) AS nombre
            FROM inscritos i
            INNER JOIN usuarios u ON u.id = i.id_usuario
            WHERE i.torneo_id = ? AND i.codigo_equipo IN ($ph)
              AND (i.estatus IS NULL OR i.estatus = '' OR i.estatus NOT IN ('retirado', 4))
            ORDER BY i.codigo_equipo ASC, nombre ASC
        ");
        $stj->execute(array_merge([$torneo_id], $codigos_list));
        foreach ($stj->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ck = (string)($row['codigo_equipo'] ?? '');
            if ($ck === '') {
                continue;
            }
            unset($row['codigo_equipo']);
            $por_codigo[$ck][] = $row;
        }
    }
    foreach ($equipos_registrados as &$eq) {
        $c = trim((string)($eq['codigo_equipo'] ?? ''));
        $eq['jugadores'] = ($c !== '' && isset($por_codigo[$c])) ? $por_codigo[$c] : [];
    }
    unset($eq);

    $modalidad = (int)($torneo['modalidad'] ?? 0);
    $es_parejas = ($modalidad === 2);
    $jugadores_por_equipo = $es_parejas ? 2 : max(2, (int)($torneo['pareclub'] ?? 4));

    return [
        'torneo' => $torneo,
        'jugadores_disponibles' => $jugadores_disponibles,
        'clubes_disponibles' => $clubes_disponibles,
        'equipos_registrados' => $equipos_registrados,
        'total_jugadores_disponibles' => count($jugadores_disponibles),
        'total_equipos' => count($equipos_registrados),
        'jugadores_por_equipo' => $jugadores_por_equipo,
        'es_parejas' => $es_parejas,
        'is_admin_club' => $is_admin_club,
        'jugadores_lista_lazy' => $jugadores_lista_lazy,
    ];
}

/**
 * Obtiene datos para inscribir pareja en sitio (modalidad 4): clubes, jugadores disponibles, parejas registradas.
 * Misma lógica que equipos pero para 2 jugadores; búsqueda por cédula en la vista.
 */
function obtenerDatosInscribirParejaSitio($torneo_id) {
    require_once __DIR__ . '/../lib/ClubHelper.php';
    require_once __DIR__ . '/../lib/ParejasFijasHelper.php';
    require_once __DIR__ . '/../config/auth.php';

    $pdo = DB::pdo();
    $current_user = Auth::user();
    $user_club_id_raw = Auth::getUserClubId();
    $user_club_id = ($user_club_id_raw !== null && (int)$user_club_id_raw > 0) ? (int)$user_club_id_raw : null;
    $is_admin_general = Auth::isAdminGeneral();
    $is_admin_club = Auth::isAdminClub();

    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        throw new Exception('Torneo no encontrado');
    }
    if ((int)($torneo['modalidad'] ?? 0) !== ParejasFijasHelper::MODALIDAD_PAREJAS_FIJAS) {
        throw new Exception('Este torneo no es de parejas fijas');
    }

    $jugadores_disponibles = [];
    if ($is_admin_general) {
        $stmt = $pdo->prepare("
            SELECT u.id as id_usuario, u.nombre, u.cedula, u.sexo,
                   u.club_id as club_id, c.nombre as club_nombre,
                   ins.id as id_inscrito, ins.codigo_equipo
            FROM usuarios u
            LEFT JOIN clubes c ON u.club_id = c.id
            LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND ins.estatus != 4
            WHERE u.role = 'usuario'
              AND (u.status IN ('approved', 'active', 'activo') OR u.status = 1)
              AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
            ORDER BY COALESCE(u.nombre, u.username) ASC
        ");
        $stmt->execute([$torneo_id]);
        $jugadores_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($user_club_id) {
        if ($is_admin_club) {
            $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
            $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
        } else {
            $clubes_ids = [$user_club_id];
        }
        if (!empty($clubes_ids)) {
            $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT u.id as id_usuario, u.nombre, u.cedula, u.sexo,
                       u.club_id as club_id, c.nombre as club_nombre,
                       ins.id as id_inscrito, ins.codigo_equipo
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                LEFT JOIN inscritos ins ON ins.id_usuario = u.id AND ins.torneo_id = ? AND ins.estatus != 4
                WHERE u.role = 'usuario'
                  AND (u.status IN ('approved', 'active', 'activo') OR u.status = 1)
                  AND u.club_id IN ({$placeholders})
                  AND (ins.id IS NULL OR ins.codigo_equipo IS NULL OR ins.codigo_equipo = '')
                ORDER BY COALESCE(u.nombre, u.username) ASC
            ");
            $stmt->execute(array_merge([$torneo_id], $clubes_ids));
            $jugadores_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    foreach ($jugadores_disponibles as &$j) {
        $j['id'] = $j['id_usuario'];
        $j['club_nombre'] = $j['club_nombre'] ?? 'Sin Club';
    }
    unset($j);

    $clubes_disponibles = [];
    if ($is_admin_general) {
        $stmt = $pdo->query("SELECT id, nombre FROM clubes WHERE (estatus = 1 OR estatus = '1' OR estatus = 'activo') ORDER BY nombre ASC");
        $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($user_club_id) {
        if ($is_admin_club) {
            $clubes_disponibles = ClubHelper::getClubesSupervisedWithData($user_club_id);
            $club_ids = array_column($clubes_disponibles, 'id');
            if (!in_array($user_club_id, $club_ids)) {
                $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id = ? AND (estatus = 1 OR estatus = '1' OR estatus = 'activo')");
                $stmt->execute([$user_club_id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($club) {
                    array_unshift($clubes_disponibles, $club);
                }
            }
        } else {
            $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id = ? AND (estatus = 1 OR estatus = '1' OR estatus = 'activo')");
            $stmt->execute([$user_club_id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($club) {
                $clubes_disponibles = [$club];
            }
        }
    }

    $parejas_registradas = ParejasFijasHelper::listarParejas($pdo, $torneo_id);
    $club_ids_p = array_unique(array_column($parejas_registradas, 'id_club'));
    $nombres_club_p = [];
    if (!empty($club_ids_p)) {
        $ph = implode(',', array_fill(0, count($club_ids_p), '?'));
        $stmt = $pdo->prepare("SELECT id, nombre FROM clubes WHERE id IN ($ph)");
        $stmt->execute(array_values($club_ids_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nombres_club_p[(int)$r['id']] = $r['nombre'];
        }
    }
    foreach ($parejas_registradas as &$pr) {
        $pr['nombre_club'] = $nombres_club_p[$pr['id_club']] ?? 'Sin club';
    }
    unset($pr);

    $torneo_iniciado = false;
    try {
        $stmt = $pdo->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) AS ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
        $stmt->execute([$torneo_id]);
        $ultima_ronda = (int)($stmt->fetchColumn() ?? 0);
        $torneo_iniciado = $ultima_ronda >= 1;
    } catch (Exception $e) {
        // ignorar
    }

    return [
        'torneo' => $torneo,
        'jugadores_disponibles' => $jugadores_disponibles,
        'clubes_disponibles' => $clubes_disponibles,
        'parejas_registradas' => $parejas_registradas,
        'torneo_iniciado' => $torneo_iniciado,
    ];
}

/**
 * Obtiene datos para inscribir jugador en sitio.
 * Usa configuración centralizada (DB::pdo()) y consultas directas por ID para aprovechar índices.
 * No modifica lógica de negocio ni resultados de torneos.
 */
function obtenerDatosInscribirSitio($torneo_id, $user_id, $is_admin_general) {
    $pdo = DB::pdo();

    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo || empty($torneo['id'])) {
        throw new Exception('Torneo no encontrado.');
    }

    $current_user = Auth::user();
    $user_club_id = $current_user['club_id'] ?? null;
    $user_entidad = (int)($current_user['entidad'] ?? 0);
    $is_admin_club = Auth::isAdminClub();
    $torneo_entidad = (int)($torneo['entidad'] ?? 0);

    $tiene_numfvd = (bool) @$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'numfvd'")->fetch();
    $existe_atletas = false;
    try {
        @$pdo->query("SELECT 1 FROM atletas LIMIT 1");
        $existe_atletas = true;
    } catch (Throwable $e) {
        // tabla atletas no existe
    }

    // No ejecutar UPDATE usuarios+atletas aquí: evita full table scan sobre 32M registros.
    // La sincronización numfvd puede hacerse vía cron/tarea programada si se necesita.

    $orderBy = $tiene_numfvd
        ? "ORDER BY COALESCE(u.numfvd, '') ASC, COALESCE(u.nombre, u.username) ASC"
        : "ORDER BY COALESCE(u.nombre, u.username) ASC";
    $activoCond = "(u.status = 'approved' OR u.status = 1 OR u.status = 0)";
    $usuarios_territorio = [];

    $entidad_filtro = $user_entidad > 0 ? $user_entidad : ($torneo_entidad > 0 ? $torneo_entidad : null);
    $tiene_entidad_col = (bool) @$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'entidad'")->fetch();

    if ($is_admin_general) {
        $sql = "
            SELECT u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre, c.id as club_id" . ($tiene_numfvd ? ", u.numfvd" : "") . "
            FROM usuarios u
            LEFT JOIN clubes c ON u.club_id = c.id
            WHERE u.role = 'usuario' AND " . $activoCond;
        if ($tiene_entidad_col && $entidad_filtro !== null) {
            $sql .= " AND u.entidad = " . (int) $entidad_filtro;
        }
        $sql .= " " . $orderBy;
        $stmt = $pdo->query($sql);
        $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($user_club_id) {
        if ($is_admin_club) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
            $clubes_supervisados = ClubHelper::getClubesSupervised($user_club_id);
            $clubes_ids = array_merge([$user_club_id], $clubes_supervisados);
            if (!empty($clubes_ids)) {
                $placeholders = str_repeat('?,', count($clubes_ids) - 1) . '?';
                $sql = "
                    SELECT u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre, c.id as club_id" . ($tiene_numfvd ? ", u.numfvd" : "") . "
                    FROM usuarios u
                    LEFT JOIN clubes c ON u.club_id = c.id
                    WHERE u.role = 'usuario' AND " . $activoCond . " AND u.club_id IN ($placeholders)";
                if ($tiene_entidad_col && $entidad_filtro !== null) {
                    $sql .= " AND u.entidad = " . (int) $entidad_filtro;
                }
                $sql .= " " . $orderBy;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($clubes_ids);
                $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $sql = "
                SELECT u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre, c.id as club_id" . ($tiene_numfvd ? ", u.numfvd" : "") . "
                FROM usuarios u
                LEFT JOIN clubes c ON u.club_id = c.id
                WHERE u.role = 'usuario' AND " . $activoCond . " AND u.club_id = ?";
            if ($tiene_entidad_col && $entidad_filtro !== null) {
                $sql .= " AND u.entidad = " . (int) $entidad_filtro;
            }
            $sql .= " " . $orderBy;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_club_id]);
            $usuarios_territorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $stmt = $pdo->prepare("
        SELECT i.id_usuario, i.estatus, i.id_club,
               u.id, u.username, u.nombre, u.cedula, c.nombre as club_nombre
        FROM inscritos i
        LEFT JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ? AND i.estatus != 4
        ORDER BY COALESCE(u.nombre, u.username) ASC
    ");
    $stmt->execute([$torneo_id]);
    $usuarios_inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $usuarios_inscritos_ids = array_column($usuarios_inscritos, 'id_usuario');

    $usuarios_disponibles = array_values(array_filter($usuarios_territorio, function ($u) use ($usuarios_inscritos_ids) {
        return !in_array($u['id'], $usuarios_inscritos_ids);
    }));

    // Obtener lista de clubes (solo del territorio del administrador)
    $clubes_disponibles = [];
    if ($is_admin_general) {
        $stmt = $pdo->query("SELECT id, nombre FROM clubes WHERE estatus = 1 ORDER BY nombre ASC");
        $clubes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($user_club_id) {
        if ($is_admin_club) {
            require_once __DIR__ . '/../lib/ClubHelper.php';
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
    
    return [
        'torneo' => $torneo,
        'usuarios_disponibles' => array_values($usuarios_disponibles),
        'usuarios_inscritos' => $usuarios_inscritos,
        'clubes_disponibles' => $clubes_disponibles
    ];
}

/**
 * Obtiene datos para sustituir jugador retirado (torneo iniciado).
 * Solo para modalidad individual/parejas (no equipos).
 */
function obtenerDatosSustituirJugador($torneo_id, $user_id, $is_admin_general) {
    $datos_inscribir = obtenerDatosInscribirSitio($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();

    $stmt = $pdo->prepare("SELECT i.*, u.nombre as nombre_completo, u.username, u.cedula, c.nombre as nombre_club
        FROM inscritos i
        INNER JOIN usuarios u ON i.id_usuario = u.id
        LEFT JOIN clubes c ON i.id_club = c.id
        WHERE i.torneo_id = ? AND i.estatus = 4
        ORDER BY u.nombre ASC");
    $stmt->execute([$torneo_id]);
    $retirados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_merge($datos_inscribir, ['retirados' => $retirados]);
}

/**
 * Cuenta mesas incompletas de una ronda
 */
function contarMesasIncompletas($torneo_id, $ronda) {
    $pdo = DB::pdo();
    
    $sql = "SELECT COUNT(DISTINCT pr.mesa) as mesas_incompletas
            FROM partiresul pr
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa > 0
            AND (pr.registrado = 0 OR pr.registrado IS NULL)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return (int)($result['mesas_incompletas'] ?? 0);
}

/**
 * Devuelve los números de mesa asignados a un operador para un torneo y ronda (ámbito del operador).
 * Si el usuario no es operador o no tiene asignación, devuelve null (sin restricción).
 */
function obtenerMesasAsignadasOperador($torneo_id, $ronda, $user_id, $user_role) {
    require_once __DIR__ . '/../lib/OperadorMesaAmbitoService.php';

    return OperadorMesaAmbitoService::mesasPermitidas(
        DB::pdo(),
        (int)$torneo_id,
        (int)$ronda,
        (int)$user_id,
        (string)$user_role
    );
}










/**
 * Actualiza estadísticas manualmente
 */
function actualizarEstadisticasManual($torneo_id, $user_id, $is_admin_general) {
    try {
        verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
        
        actualizarEstadisticasInscritos($torneo_id);
        $_SESSION['success'] = 'Estadísticas y puntos de ranking actualizados exitosamente';
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al actualizar estadísticas: ' . $e->getMessage();
    }
    
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
        exit;
}

/**
 * Actualizar estadísticas de todos los inscritos basándose en PartiResul
 */
function actualizarEstadisticasInscritos($torneo_id) {
    $pdo = DB::pdo();
    
    // Modalidad parejas: estadísticas por código de equipo (idénticas para ambos jugadores).
    $stmtModalidad = $pdo->prepare("SELECT modalidad FROM tournaments WHERE id = ?");
    $stmtModalidad->execute([$torneo_id]);
    $modalidadTorneo = (int)($stmtModalidad->fetchColumn() ?? 0);
    if (in_array($modalidadTorneo, [2, 4], true)) {
        actualizarEstadisticasInscritosParejasPorCodigoEquipo($torneo_id);
        recalcularClasificacionEquiposYJugadores($torneo_id);
        return;
    }
    
    // Obtener puntos del torneo
    $stmt = $pdo->prepare("SELECT puntos FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        throw new Exception("Torneo no encontrado");
    }
    $puntosTorneo = (int)($torneo['puntos'] ?? 100);
    
    // Obtener todos los inscritos del torneo (excepto retirados)
    // Filtro: estatus != 4 (4 = retirado) porque estatus es numérico
    $stmt = $pdo->prepare("SELECT id, id_usuario, torneo_id 
                           FROM inscritos 
                           WHERE torneo_id = ? AND estatus != 4");
    $stmt->execute([$torneo_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($inscritos)) {
        return; // No hay inscritos para actualizar
    }
    
    // Procesar cada inscrito
    foreach ($inscritos as $inscrito) {
        $idUsuario = (int)$inscrito['id_usuario'];
        
        // Obtener todas las partidas donde participó (incluye mesas reales y BYE con mesa=0)
        $stmt = $pdo->prepare("SELECT DISTINCT partida, mesa 
                               FROM partiresul 
                               WHERE id_torneo = ? 
                                   AND id_usuario = ? 
                                   AND registrado = 1
                               ORDER BY partida, mesa");
        $stmt->execute([$torneo_id, $idUsuario]);
        $mesasJugador = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Inicializar contadores
        $totalGanados = 0;
        $totalPerdidos = 0;
        $totalEfectividad = 0;
        $totalPuntos = 0;
        $totalSancion = 0;
        $totalChancletas = 0;
        $totalZapatos = 0;
        $ultimaTarjeta = 0;
        $fechaUltimaTarjeta = null;
        
        // Procesar cada partida/mesa (mesa > 0 = mesa real, mesa = 0 = BYE)
        foreach ($mesasJugador as $mesaInfo) {
            $partida = (int)$mesaInfo['partida'];
            $mesa = (int)$mesaInfo['mesa'];
            
            // BYE (mesa = 0): una sola fila por jugador, partida ganada, resultado1 = puntos_torneo, efectividad ya calculada
            if ($mesa === 0) {
                $stmt = $pdo->prepare("SELECT resultado1, resultado2, efectividad, sancion, fecha_partida 
                                       FROM partiresul 
                                       WHERE id_torneo = ? AND id_usuario = ? AND partida = ? AND mesa = 0 AND registrado = 1 LIMIT 1");
                $stmt->execute([$torneo_id, $idUsuario, $partida]);
                $rowBye = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($rowBye) {
                    $totalGanados++;
                    $totalEfectividad += (int)($rowBye['efectividad'] ?? 0);
                    $totalPuntos += (int)($rowBye['resultado1'] ?? 0);
                    $totalSancion += (int)($rowBye['sancion'] ?? 0);
                }
                continue;
            }
            
            // Mesa real (mesa > 0): obtener los 4 jugadores y calcular ganado/perdido
            $stmt = $pdo->prepare("SELECT * FROM partiresul 
                                   WHERE id_torneo = ? AND partida = ? AND mesa = ?
                                   ORDER BY secuencia");
            $stmt->execute([$torneo_id, $partida, $mesa]);
            $jugadoresMesa = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Buscar el jugador actual en esta mesa
            $jugadorActual = null;
            foreach ($jugadoresMesa as $jugador) {
                if ((int)$jugador['id_usuario'] == $idUsuario) {
                    $jugadorActual = $jugador;
                    break;
                }
            }
            
            if (!$jugadorActual) {
                continue;
            }
            
            // Detectar forfaits y tarjetas graves en la mesa
            $hayForfaitMesa = false;
            $hayTarjetaGraveMesa = false;
            
            foreach ($jugadoresMesa as $jugador) {
                if ((int)$jugador['ff'] == 1) {
                    $hayForfaitMesa = true;
                }
                $tarjeta = (int)$jugador['tarjeta'];
                if ($tarjeta == 3 || $tarjeta == 4) {
                    $hayTarjetaGraveMesa = true;
                }
            }
            
            // Extraer datos del jugador actual
            $resultado1 = (int)$jugadorActual['resultado1'];
            $resultado2 = (int)$jugadorActual['resultado2'];
            $efectividad = (int)$jugadorActual['efectividad'];
            $ff = (int)$jugadorActual['ff'];
            $tarjeta = (int)$jugadorActual['tarjeta'];
            $sancion = (int)$jugadorActual['sancion'];
            $chancleta = (int)$jugadorActual['chancleta'];
            $zapato = (int)$jugadorActual['zapato'];
            $fechaPartida = $jugadorActual['fecha_partida'];
            
            // Determinar si ganó o perdió (con sanción: restar del resultado1; si no es mayor que resultado2 → perdida)
            $gano = false;
            
            if ($hayForfaitMesa) {
                $gano = ($ff == 0);
            } elseif ($hayTarjetaGraveMesa) {
                $gano = !($tarjeta == 3 || $tarjeta == 4);
            } else {
                if ($sancion > 0) {
                    $resultadoAjustado = max(0, $resultado1 - $sancion);
                    $gano = ($resultadoAjustado > $resultado2);
                } else {
                    $gano = ($resultado1 > $resultado2);
                }
            }
            
            // Actualizar contadores
            if ($gano) {
                $totalGanados++;
            } else {
                $totalPerdidos++;
            }
            
            $totalEfectividad += $efectividad;
            $totalPuntos += $resultado1;
            $totalSancion += $sancion;
            
            // Chancletas y zapatos solo si ganó
            if ($gano) {
                $totalChancletas += $chancleta;
                $totalZapatos += $zapato;
            }
            
            // Obtener última tarjeta (más reciente)
            if ($tarjeta > 0) {
                if ($fechaUltimaTarjeta === null || $fechaPartida > $fechaUltimaTarjeta) {
                    $ultimaTarjeta = $tarjeta;
                    $fechaUltimaTarjeta = $fechaPartida;
                }
            }
        }
        
        // Actualizar estadísticas en Inscritos
        $stmt = $pdo->prepare("UPDATE inscritos SET
                                ganados = ?,
                                perdidos = ?,
                                efectividad = ?,
                                puntos = ?,
                                sancion = ?,
                                chancletas = ?,
                                zapatos = ?,
                                tarjeta = ?
                            WHERE torneo_id = ? AND id_usuario = ?");
        
        $stmt->execute([
            $totalGanados,
            $totalPerdidos,
            $totalEfectividad,
            $totalPuntos,
            $totalSancion,
            $totalChancletas,
            $totalZapatos,
            $ultimaTarjeta,
            $torneo_id,
            $idUsuario
        ]);
    }
    
    // Recalcular posiciones y clasificación completas (inscritos + equipos + numeración interna)
    recalcularClasificacionEquiposYJugadores($torneo_id);
}

/**
 * Recalcula estadísticas en torneos de parejas por código de equipo.
 * El resultado se replica idéntico a ambos jugadores de la pareja.
 */
function actualizarEstadisticasInscritosParejasPorCodigoEquipo($torneo_id) {
    $pdo = DB::pdo();
    require_once __DIR__ . '/../lib/ParejasResultadosService.php';
    ParejasResultadosService::recalcularInscritosPorCodigoEquipo($pdo, (int)$torneo_id);
    return;

    $stmt = $pdo->prepare("
        SELECT codigo_equipo, id_usuario
        FROM inscritos
        WHERE torneo_id = ?
          AND estatus != 4
          AND codigo_equipo IS NOT NULL
          AND codigo_equipo != ''
          AND codigo_equipo != '000-000'
        ORDER BY codigo_equipo ASC, id_usuario ASC
    ");
    $stmt->execute([$torneo_id]);
    $filasInscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($filasInscritos)) {
        return;
    }

    $usuariosPorCodigo = [];
    foreach ($filasInscritos as $filaInscrito) {
        $codigo = trim((string)($filaInscrito['codigo_equipo'] ?? ''));
        $idUsuario = (int)($filaInscrito['id_usuario'] ?? 0);
        if ($codigo === '' || $idUsuario <= 0) {
            continue;
        }
        if (!isset($usuariosPorCodigo[$codigo])) {
            $usuariosPorCodigo[$codigo] = [];
        }
        $usuariosPorCodigo[$codigo][] = $idUsuario;
    }

    $stmtPartidasEquipo = $pdo->prepare("
        SELECT DISTINCT pr.partida, pr.mesa
        FROM partiresul pr
        INNER JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
        WHERE pr.id_torneo = ?
          AND i.codigo_equipo = ?
          AND pr.registrado = 1
        ORDER BY pr.partida ASC, pr.mesa ASC
    ");

    $stmtMesaDetalle = $pdo->prepare("
        SELECT pr.id_usuario, pr.resultado1, pr.resultado2, pr.efectividad, pr.ff, pr.tarjeta, pr.sancion, pr.chancleta, pr.zapato, pr.fecha_partida,
               i.codigo_equipo
        FROM partiresul pr
        LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = pr.id_usuario
        WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? AND pr.registrado = 1
        ORDER BY pr.secuencia ASC
    ");

    $stmtUpdateCodigo = $pdo->prepare("
        UPDATE inscritos SET
            ganados = ?,
            perdidos = ?,
            efectividad = ?,
            puntos = ?,
            sancion = ?,
            chancletas = ?,
            zapatos = ?,
            tarjeta = ?
        WHERE torneo_id = ? AND codigo_equipo = ? AND estatus != 4
    ");

    foreach ($usuariosPorCodigo as $codigoEquipo => $usuariosEquipo) {
        $totalGanados = 0;
        $totalPerdidos = 0;
        $totalEfectividad = 0;
        $totalPuntos = 0;
        $totalSancion = 0;
        $totalChancletas = 0;
        $totalZapatos = 0;
        $ultimaTarjeta = 0;
        $fechaUltimaTarjeta = null;

        $stmtPartidasEquipo->execute([$torneo_id, $codigoEquipo]);
        $partidasEquipo = $stmtPartidasEquipo->fetchAll(PDO::FETCH_ASSOC);

        foreach ($partidasEquipo as $partidaInfo) {
            $partida = (int)($partidaInfo['partida'] ?? 0);
            $mesa = (int)($partidaInfo['mesa'] ?? 0);

            $stmtMesaDetalle->execute([$torneo_id, $partida, $mesa]);
            $filasMesa = $stmtMesaDetalle->fetchAll(PDO::FETCH_ASSOC);
            if (empty($filasMesa)) {
                continue;
            }

            $filasEquipo = [];
            $filasOponente = [];
            foreach ($filasMesa as $filaMesa) {
                $codigoFila = trim((string)($filaMesa['codigo_equipo'] ?? ''));
                if ($codigoFila === $codigoEquipo) {
                    $filasEquipo[] = $filaMesa;
                } else {
                    $filasOponente[] = $filaMesa;
                }
            }
            if (empty($filasEquipo)) {
                continue;
            }

            $refEquipo = $filasEquipo[0];
            $resultado1 = (int)($refEquipo['resultado1'] ?? 0);
            $resultado2 = (int)($refEquipo['resultado2'] ?? 0);
            $efectividad = (int)($refEquipo['efectividad'] ?? 0);
            $sancion = (int)($refEquipo['sancion'] ?? 0);

            $hayForfaitEquipo = false;
            $hayForfaitOponente = false;
            $hayTarjetaGraveEquipo = false;
            $hayTarjetaGraveOponente = false;
            $chancletaEquipo = 0;
            $zapatoEquipo = 0;

            foreach ($filasEquipo as $filaEquipo) {
                if ((int)($filaEquipo['ff'] ?? 0) === 1) {
                    $hayForfaitEquipo = true;
                }
                $tarjetaFila = (int)($filaEquipo['tarjeta'] ?? 0);
                if ($tarjetaFila === 3 || $tarjetaFila === 4) {
                    $hayTarjetaGraveEquipo = true;
                }
                $chancletaEquipo = max($chancletaEquipo, (int)($filaEquipo['chancleta'] ?? 0));
                $zapatoEquipo = max($zapatoEquipo, (int)($filaEquipo['zapato'] ?? 0));
                if ($tarjetaFila > 0) {
                    $fechaFila = (string)($filaEquipo['fecha_partida'] ?? '');
                    if ($fechaUltimaTarjeta === null || ($fechaFila !== '' && $fechaFila > $fechaUltimaTarjeta)) {
                        $ultimaTarjeta = $tarjetaFila;
                        $fechaUltimaTarjeta = $fechaFila;
                    }
                }
            }
            foreach ($filasOponente as $filaOponente) {
                if ((int)($filaOponente['ff'] ?? 0) === 1) {
                    $hayForfaitOponente = true;
                }
                $tarjetaFila = (int)($filaOponente['tarjeta'] ?? 0);
                if ($tarjetaFila === 3 || $tarjetaFila === 4) {
                    $hayTarjetaGraveOponente = true;
                }
            }

            $gano = false;
            if ($mesa === 0) {
                $gano = true; // BYE
            } elseif ($hayForfaitEquipo || $hayForfaitOponente) {
                $gano = (!$hayForfaitEquipo && $hayForfaitOponente);
            } elseif ($hayTarjetaGraveEquipo || $hayTarjetaGraveOponente) {
                $gano = (!$hayTarjetaGraveEquipo && $hayTarjetaGraveOponente);
            } else {
                if ($sancion > 0) {
                    $resultadoAjustado = max(0, $resultado1 - $sancion);
                    $gano = ($resultadoAjustado > $resultado2);
                } else {
                    $gano = ($resultado1 > $resultado2);
                }
            }

            if ($gano) {
                $totalGanados++;
                $totalChancletas += $chancletaEquipo;
                $totalZapatos += $zapatoEquipo;
            } else {
                $totalPerdidos++;
            }
            $totalEfectividad += $efectividad;
            $totalPuntos += $resultado1;
            $totalSancion += $sancion;
        }

        $stmtUpdateCodigo->execute([
            $totalGanados,
            $totalPerdidos,
            $totalEfectividad,
            $totalPuntos,
            $totalSancion,
            $totalChancletas,
            $totalZapatos,
            $ultimaTarjeta,
            $torneo_id,
            $codigoEquipo
        ]);
    }
}

/**
 * Recalcula toda la clasificación para torneos por equipos:
 * 1) Recalcula posiciones de inscritos (usa estadísticas vigentes en inscritos/partiresul)
 * 2) Actualiza estadísticas de equipos y su posición
 * 3) Sincroniza clasiequi en inscritos y numera 1..4 dentro de cada código de equipo
 */
function recalcularClasificacionEquiposYJugadores($torneo_id) {
    // Paso 1: recalcular posiciones individuales
    recalcularPosiciones($torneo_id);
    // Paso 2: actualizar stats y posición de equipos (sincroniza clasiequi en inscritos)
    actualizarEstadisticasEquipos($torneo_id);
    // Paso 3: numerar 1..4 dentro de cada equipo según clasificación individual
    asignarNumeroSecuencialPorEquipo($torneo_id);
}

/**
 * Actualiza las estadísticas de equipos desde la tabla inscritos
 * Suma los valores de puntos, ganados, perdidos y calcula efectividad promedio
 * por codigo_equipo
 */
function actualizarEstadisticasEquipos($torneo_id) {
    $pdo = DB::pdo();
    
    // Verificar si el torneo es modalidad equipos (modalidad 3)
    $stmt = $pdo->prepare("SELECT modalidad FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$torneo || (int)($torneo['modalidad'] ?? 0) !== 3) {
        // No es torneo de equipos, no hay nada que actualizar
        return;
    }
    
    // Obtener estadísticas agregadas por codigo_equipo desde inscritos
    // Suma de puntos, ganados, perdidos, efectividad (suma de todas las efectividades), sanciones
    $nP = InscritosHelper::sqlExprColumnaNumerica('puntos');
    $nG = InscritosHelper::sqlExprColumnaNumerica('ganados');
    $nPe = InscritosHelper::sqlExprColumnaNumerica('perdidos');
    $nE = InscritosHelper::sqlExprColumnaNumerica('efectividad');
    $nS = InscritosHelper::sqlExprColumnaNumerica('sancion');
    $sql = "SELECT 
                codigo_equipo,
                SUM($nP) as puntos_equipo,
                SUM($nG) as ganados_equipo,
                SUM($nPe) as perdidos_equipo,
                SUM($nE) as efectividad_equipo,
                SUM($nS) as sancion_equipo,
                COUNT(*) as total_jugadores
            FROM inscritos
            WHERE torneo_id = ? 
                AND codigo_equipo IS NOT NULL AND codigo_equipo != '000-000' 
                AND codigo_equipo != ''
                AND estatus != 4
            GROUP BY codigo_equipo";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $estadisticasEquipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($estadisticasEquipos)) {
        // No hay equipos con inscritos, no hay nada que actualizar
        return;
    }
    
    // Actualizar cada equipo con sus estadísticas agregadas
    $stmtUpdate = $pdo->prepare("
        UPDATE equipos 
        SET puntos = ?,
            ganados = ?,
            perdidos = ?,
            efectividad = ?,
            sancion = ?,
            fecha_actualizacion = CURRENT_TIMESTAMP
        WHERE id_torneo = ? AND codigo_equipo = ?
    ");
    
    foreach ($estadisticasEquipos as $stats) {
        $codigoEquipo = $stats['codigo_equipo'];
        $puntosEquipo = (int)($stats['puntos_equipo'] ?? 0);
        $ganadosEquipo = (int)($stats['ganados_equipo'] ?? 0);
        $perdidosEquipo = (int)($stats['perdidos_equipo'] ?? 0);
        $efectividadEquipo = (int)($stats['efectividad_equipo'] ?? 0); // Suma de efectividades de todos los jugadores
        $sancionEquipo = (int)($stats['sancion_equipo'] ?? 0);
        
        $stmtUpdate->execute([
            $puntosEquipo,
            $ganadosEquipo,
            $perdidosEquipo,
            $efectividadEquipo,
            $sancionEquipo,
            $torneo_id,
            $codigoEquipo
        ]);
    }
    
    // Recalcular posiciones de equipos después de actualizar estadísticas
    recalcularPosicionesEquipos($torneo_id);
}

/**
 * Recalcular posiciones de equipos según sus estadísticas
 * Orden: 1. Puntos DESC, 2. Ganados DESC, 3. Efectividad DESC
 */
function recalcularPosicionesEquipos($torneo_id) {
    $pdo = DB::pdo();
    
    // Obtener equipos ordenados por clasificación (ganados DESC, efectividad DESC, puntos DESC)
    $sql = "SELECT codigo_equipo, puntos, ganados, efectividad
            FROM equipos
            WHERE id_torneo = ? AND estatus = 0
            ORDER BY ganados DESC, efectividad DESC, puntos DESC, codigo_equipo ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id]);
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($equipos)) {
        return;
    }
    
    // Actualizar posiciones secuencialmente
    $stmtUpdate = $pdo->prepare("
        UPDATE equipos 
        SET posicion = ?
        WHERE id_torneo = ? AND codigo_equipo = ?
    ");
    // Preparar update para sincronizar clasiequi en inscritos con la posición del equipo
    $stmtUpdateInscritos = $pdo->prepare("
        UPDATE inscritos
        SET clasiequi = ?
        WHERE torneo_id = ? AND codigo_equipo = ? AND estatus != 4
    ");
    
    $posicion = 1;
    foreach ($equipos as $equipo) {
        $stmtUpdate->execute([
            $posicion,
            $torneo_id,
            $equipo['codigo_equipo']
        ]);
        
        // Sincronizar campo clasiequi en inscritos con la clasificación del equipo
        $stmtUpdateInscritos->execute([
            $posicion,
            $torneo_id,
            $equipo['codigo_equipo']
        ]);
        $posicion++;
    }
}

/**
 * Asigna numero 1..4 dentro de cada equipo según clasificación individual:
 * Orden: ganados DESC, efectividad DESC, puntos DESC, id_usuario ASC.
 */
function asignarNumeroSecuencialPorEquipo($torneo_id) {
    $pdo = DB::pdo();
    $stmtEquipos = $pdo->prepare("
        SELECT DISTINCT codigo_equipo
        FROM inscritos
        WHERE torneo_id = ? AND codigo_equipo IS NOT NULL AND codigo_equipo != '' AND codigo_equipo != '000-000' AND estatus != 4
    ");
    $stmtEquipos->execute([$torneo_id]);
    $codigos = $stmtEquipos->fetchAll(PDO::FETCH_COLUMN);

    $og = InscritosHelper::sqlExprColumnaNumerica('ganados');
    $oe = InscritosHelper::sqlExprColumnaNumerica('efectividad');
    $op = InscritosHelper::sqlExprColumnaNumerica('puntos');
    $stmtJugadores = $pdo->prepare("
        SELECT id
        FROM inscritos
        WHERE torneo_id = ? AND codigo_equipo = ? AND estatus != 4
        ORDER BY 
            $og DESC,
            $oe DESC,
            $op DESC,
            id_usuario ASC
    ");
    $stmtUpdateNumero = $pdo->prepare("UPDATE inscritos SET numero = ? WHERE id = ?");

    foreach ($codigos as $codigo) {
        $stmtJugadores->execute([$torneo_id, $codigo]);
        $jugadoresEquipo = $stmtJugadores->fetchAll(PDO::FETCH_ASSOC);

        $numeroSecuencial = 1;
        foreach ($jugadoresEquipo as $jug) {
            $stmtUpdateNumero->execute([$numeroSecuencial, $jug['id']]);
            $numeroSecuencial++;
        }
    }
}

/**
 * Recalcular posiciones de todos los inscritos
 */
/**
 * Recalcular posiciones de todos los inscritos
 * Orden de clasificación: 1. Ganados DESC, 2. Efectividad DESC, 3. Puntos DESC
 * Las posiciones deben ser consecutivas (1, 2, 3, 4...) sin repeticiones
 */
function recalcularPosiciones($torneo_id) {
    try {
        $pdo = DB::pdo();
        
        error_log("recalcularPosiciones: Iniciando para torneo_id = $torneo_id");
        
        // Obtener información del torneo para saber el tipo
        $stmt = $pdo->prepare("SELECT modalidad, nombre FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$torneo) {
            error_log("recalcularPosiciones: Torneo no encontrado");
            return;
        }
        
        // Mapear modalidad a tipo de torneo
        // modalidad puede ser INT (1=Individual, 2=Parejas, 3=Equipos, 4=Parejas fijas) o texto
        $modalidad = $torneo['modalidad'] ?? 1;
        $tipoTorneo = 1; // Por defecto Individual
        
        if (is_numeric($modalidad)) {
            // Si es numérico, usar directamente
            $tipoTorneo = (int)$modalidad;
        } else {
            // Si es texto, convertir
            $modalidad_str = strtolower(trim((string)$modalidad));
            if (stripos($modalidad_str, 'pareja') !== false) {
                $tipoTorneo = 2;
            } elseif (stripos($modalidad_str, 'equipo') !== false) {
                $tipoTorneo = 3;
            }
        }
        
        // Asegurar que el tipo esté en el rango válido (1-4)
        if ($tipoTorneo < 1 || $tipoTorneo > 4) {
            $tipoTorneo = 1;
        }

        // Parejas (2 y 4): clasificación por codigo_equipo (misma posición para ambos integrantes).
        if (in_array($tipoTorneo, [2, 4], true)) {
            recalcularPosicionesParejasPorEquipo($torneo_id, 2);
            return;
        }
        
        // Definir límite de posiciones según tipo de torneo
        // Individual: hasta posición 30, Parejas: hasta posición 20, Equipos: hasta posición 10
        $limitePosiciones = 30; // Por defecto Individual
        if ($tipoTorneo == 2) {
            $limitePosiciones = 20; // Parejas
        } elseif ($tipoTorneo == 3) {
            $limitePosiciones = 10; // Equipos
        }
        
        error_log("recalcularPosiciones: Tipo torneo = $tipoTorneo, Límite posiciones = $limitePosiciones");
        
        // Primero, resetear todas las posiciones a 0 para evitar conflictos
        $stmt = $pdo->prepare("UPDATE inscritos SET posicion = 0 WHERE torneo_id = ?");
        $stmt->execute([$torneo_id]);
        $reseteados = $stmt->rowCount();
        error_log("recalcularPosiciones: Reseteados $reseteados registros");
        
        // Obtener inscritos ordenados por: 1. ganados DESC, 2. efectividad DESC, 3. puntos DESC
        // Filtro: estatus != 4 (4 = retirado) porque estatus es numérico
        // Asegurar que los valores sean numéricos en el ORDER BY usando CAST
        $rg = InscritosHelper::sqlExprColumnaNumerica('ganados');
        $re = InscritosHelper::sqlExprColumnaNumerica('efectividad');
        $rp = InscritosHelper::sqlExprColumnaNumerica('puntos');
        $stmt = $pdo->prepare("SELECT id, id_usuario, 
                               $rg as ganados, 
                               $re as efectividad, 
                               $rp as puntos
                               FROM inscritos 
                               WHERE torneo_id = ? AND estatus != 4
                               ORDER BY $rg DESC, 
                                        $re DESC, 
                                        $rp DESC");
        $stmt->execute([$torneo_id]);
        $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("recalcularPosiciones: Encontrados " . count($inscritos) . " inscritos");
        
        if (empty($inscritos)) {
            error_log("recalcularPosiciones: No hay inscritos para actualizar");
            return;
        }

        // Comprobar si existe la tabla clasiranking (opcional: puntos de ranking por posición)
        $existeClasiRanking = false;
        try {
            $stmt = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'clasiranking' LIMIT 1");
            $existeClasiRanking = ($stmt && $stmt->fetch() !== false);
        } catch (Exception $e) {
            // Ignorar
        }

        $tienePuntosAsistencia = $existeClasiRanking && clasiranking_tiene_columna_puntos_asistencia($pdo);
        
        // Actualizar posiciones consecutivamente (1, 2, 3, 4...) y calcular puntos de ranking
        // Cada jugador recibe una posición única, incluso si hay empates en los valores
        $posicion = 1;
        $actualizados = 0;
        $puntosRankingActualizados = 0;
        
        // Para posiciones 31+: obtener puntos_por_partida_ganada y puntos_asistencia (clasificacion 30 o la última disponible)
        $puntosPorPartidaGanadaPos31 = null;
        $puntosAsistenciaPos31 = 1;
        if ($existeClasiRanking && $limitePosiciones >= 30) {
            try {
                $sqlPos31 = $tienePuntosAsistencia
                    ? "SELECT puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia
                       FROM clasiranking
                       WHERE tipo_torneo = ? AND clasificacion <= 30
                       ORDER BY clasificacion DESC LIMIT 1"
                    : "SELECT puntos_por_partida_ganada FROM clasiranking
                       WHERE tipo_torneo = ? AND clasificacion <= 30
                       ORDER BY clasificacion DESC LIMIT 1";
                $stmt = $pdo->prepare($sqlPos31);
                $stmt->execute([$tipoTorneo]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $puntosPorPartidaGanadaPos31 = (int)$r['puntos_por_partida_ganada'];
                    $puntosAsistenciaPos31 = $tienePuntosAsistencia ? (int)($r['puntos_asistencia'] ?? 1) : 1;
                }
            } catch (Exception $e) {
            }
        }
        
        foreach ($inscritos as $inscrito) {
            $id = (int)$inscrito['id'];
            $ganados = (int)($inscrito['ganados'] ?? 0);
            
            // Calcular puntos de ranking: (ganados × puntos_por_partida_ganada) + puntos_asistencia
            // Retirados no se procesan aquí (excluidos en el SELECT)
            $ptosrnk = 1; // Por defecto, punto por participación
            
            if ($existeClasiRanking) {
                try {
                    if ($posicion <= $limitePosiciones) {
                        // Posiciones 1 a limitePosiciones: puntos_posicion + (ganados × puntos_por_partida_ganada) + puntos_asistencia
                        $ranking = null;
                        $puntosAsistencia = 1;
                        $sqlRank = $tienePuntosAsistencia
                            ? "SELECT puntos_posicion, puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia
                               FROM clasiranking
                               WHERE tipo_torneo = ? AND clasificacion = ?
                               LIMIT 1"
                            : "SELECT puntos_posicion, puntos_por_partida_ganada
                               FROM clasiranking
                               WHERE tipo_torneo = ? AND clasificacion = ?
                               LIMIT 1";
                        $stmt = $pdo->prepare($sqlRank);
                        $stmt->execute([$tipoTorneo, $posicion]);
                        $ranking = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($ranking && $tienePuntosAsistencia) {
                            $puntosAsistencia = (int)($ranking['puntos_asistencia'] ?? 1);
                        }
                        if (!empty($ranking)) {
                            $puntosPorPosicion = (int)$ranking['puntos_posicion'];
                            $puntosPorPartidaGanada = (int)$ranking['puntos_por_partida_ganada'];
                            $ptosrnk = $puntosPorPosicion + ($ganados * $puntosPorPartidaGanada) + $puntosAsistencia;
                        }
                    } elseif ($posicion >= 31 && $puntosPorPartidaGanadaPos31 !== null) {
                        // Posiciones 31 en adelante: (ganados × puntos_por_partida_ganada) + puntos_asistencia
                        $ptosrnk = ($ganados * $puntosPorPartidaGanadaPos31) + $puntosAsistenciaPos31;
                    }
                } catch (Exception $e) {
                    // Si falla (ej. columna puntos_asistencia no existe), mantener ptosrnk = 1
                }
            }
            
            // Actualizar posición y puntos de ranking
            $stmt = $pdo->prepare("UPDATE inscritos SET posicion = ?, ptosrnk = ? WHERE id = ?");
            $result = $stmt->execute([$posicion, $ptosrnk, $id]);
            if ($result) {
                $actualizados++;
                $puntosRankingActualizados++;
            } else {
                error_log("recalcularPosiciones: Error al actualizar posición para inscrito id=$id");
            }
            $posicion++;
        }
        
        error_log("recalcularPosiciones: Actualizadas $actualizados posiciones y $puntosRankingActualizados puntos de ranking");
        
        // Retirados: eliminar puntos de ranking (ptosrnk = 0)
        $stmt = $pdo->prepare("UPDATE inscritos SET ptosrnk = 0 WHERE torneo_id = ? AND estatus = 4");
        $stmt->execute([$torneo_id]);
        
        // Verificar que no hay duplicados
        $stmt = $pdo->prepare("SELECT posicion, COUNT(*) as cantidad 
                               FROM inscritos 
                               WHERE torneo_id = ? AND posicion > 0
                               GROUP BY posicion 
                               HAVING cantidad > 1");
        $stmt->execute([$torneo_id]);
        $duplicados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($duplicados)) {
            error_log("ADVERTENCIA: Se encontraron posiciones duplicadas en el torneo $torneo_id: " . json_encode($duplicados));
        } else {
            error_log("recalcularPosiciones: No se encontraron posiciones duplicadas");
        }
        
    } catch (Exception $e) {
        error_log("ERROR en recalcularPosiciones: " . $e->getMessage());
        error_log("ERROR stack trace: " . $e->getTraceAsString());
        throw $e;
    }
}

/**
 * Indica si la tabla clasiranking tiene la columna opcional puntos_asistencia.
 * En bases antiguas puede no existir (ver sql/add_puntos_asistencia_clasiranking.sql).
 */
function clasiranking_tiene_columna_puntos_asistencia(PDO $pdo) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = false;
    try {
        $stmt = $pdo->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clasiranking'
               AND COLUMN_NAME = 'puntos_asistencia'
             LIMIT 1"
        );
        $cache = ($stmt && $stmt->fetch() !== false);
    } catch (Exception $e) {
        // Ignorar: asumimos que la columna no está disponible
    }
    return $cache;
}

/**
 * Recalcula posiciones para torneos de parejas por unidad de equipo (codigo_equipo).
 * Ambos integrantes reciben la misma posición y puntos de ranking.
 */
function recalcularPosicionesParejasPorEquipo($torneo_id, $tipoRanking = 2) {
    $pdo = DB::pdo();

    // Reset posiciones y ranking para inscritos activos del torneo.
    $stmt = $pdo->prepare("UPDATE inscritos SET posicion = 0, ptosrnk = 0 WHERE torneo_id = ? AND estatus != 4");
    $stmt->execute([$torneo_id]);

    // Clasificación por pareja (codigo_equipo), NO por jugador.
    $pg = InscritosHelper::sqlExprColumnaNumerica('ganados');
    $pe = InscritosHelper::sqlExprColumnaNumerica('efectividad');
    $pp = InscritosHelper::sqlExprColumnaNumerica('puntos');
    $stmt = $pdo->prepare("
        SELECT
            codigo_equipo,
            MAX($pg) AS ganados,
            MAX($pe) AS efectividad,
            MAX($pp) AS puntos
        FROM inscritos
        WHERE torneo_id = ?
          AND estatus != 4
          AND codigo_equipo IS NOT NULL
          AND codigo_equipo != ''
          AND codigo_equipo != '000-000'
        GROUP BY codigo_equipo
        ORDER BY
            MAX($pg) DESC,
            MAX($pe) DESC,
            MAX($pp) DESC,
            codigo_equipo ASC
    ");
    $stmt->execute([$torneo_id]);
    $parejas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($parejas)) {
        return;
    }

    $existeClasiRanking = false;
    try {
        $stmt = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'clasiranking' LIMIT 1");
        $existeClasiRanking = ($stmt && $stmt->fetch() !== false);
    } catch (Exception $e) {
    }

    $tienePuntosAsistencia = $existeClasiRanking && clasiranking_tiene_columna_puntos_asistencia($pdo);
    $sqlRanking = $tienePuntosAsistencia
        ? "SELECT puntos_posicion, puntos_por_partida_ganada, COALESCE(puntos_asistencia, 1) AS puntos_asistencia
           FROM clasiranking
           WHERE tipo_torneo = ? AND clasificacion = ?
           LIMIT 1"
        : "SELECT puntos_posicion, puntos_por_partida_ganada
           FROM clasiranking
           WHERE tipo_torneo = ? AND clasificacion = ?
           LIMIT 1";
    $stmtRanking = $pdo->prepare($sqlRanking);
    $stmtUpdatePareja = $pdo->prepare("
        UPDATE inscritos
        SET posicion = ?, ptosrnk = ?
        WHERE torneo_id = ?
          AND codigo_equipo = ?
          AND estatus != 4
    ");

    $posicion = 1;
    foreach ($parejas as $par) {
        $codigo = (string)$par['codigo_equipo'];
        $ganados = (int)($par['ganados'] ?? 0);
        $ptosrnk = 1;

        if ($existeClasiRanking) {
            try {
                $stmtRanking->execute([(int)$tipoRanking, $posicion]);
                $ranking = $stmtRanking->fetch(PDO::FETCH_ASSOC);
                if ($ranking) {
                    $ptosPosicion = (int)($ranking['puntos_posicion'] ?? 0);
                    $ptosGanada = (int)($ranking['puntos_por_partida_ganada'] ?? 0);
                    $ptosAsistencia = $tienePuntosAsistencia ? (int)($ranking['puntos_asistencia'] ?? 1) : 1;
                    $ptosrnk = $ptosPosicion + ($ganados * $ptosGanada) + $ptosAsistencia;
                }
            } catch (Exception $e) {
            }
        }

        $stmtUpdatePareja->execute([$posicion, $ptosrnk, $torneo_id, $codigo]);
        $posicion++;
    }
}


/**
 * Aprueba una acta QR: marca estatus=confirmado, actualiza puntos si se corrigieron, recalcula rankings.
 * Registra en partiresul el estatus confirmado y actualiza efectividad.
 *
 * @param int $user_id
 * @param bool $is_admin_general
 */
function verificarActaAprobar($user_id, $is_admin_general) {
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $ronda = (int)($_POST['ronda'] ?? 0);
    $mesa = (int)($_POST['mesa'] ?? 0);
    $jugadores_raw = $_POST['jugadores'] ?? [];
    if ($torneo_id <= 0 || $ronda <= 0 || $mesa <= 0) {
        $_SESSION['error'] = 'Parámetros inválidos.';
        header('Location: ' . buildRedirectUrl('verificar_resultados', ['torneo_id' => $torneo_id]));
        exit;
    }
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $torneo_finalizado = isTorneoLocked($torneo_id);
    if ($torneo_finalizado && !$is_admin_general) {
        $_SESSION['error'] = 'No puede aprobar actas en un torneo finalizado. Solo el administrador general puede realizar correcciones.';
        header('Location: ' . buildRedirectUrl('verificar_resultados', ['torneo_id' => $torneo_id]));
        exit;
    }
    require_once __DIR__ . '/../lib/SancionesHelper.php';
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_estatus = in_array('estatus', $cols);
    if (!$has_estatus) {
        $_SESSION['error'] = 'La tabla partiresul no tiene la columna estatus.';
        header('Location: ' . buildRedirectUrl('panel', ['torneo_id' => $torneo_id]));
        exit;
    }
    $stmt = $pdo->prepare("SELECT puntos FROM tournaments WHERE id = ?");
    $stmt->execute([$torneo_id]);
    $puntosTorneo = (int)($stmt->fetchColumn() ?: 200);
    $stmt = $pdo->prepare("SELECT pr.id, pr.id_usuario, pr.secuencia, pr.resultado1, pr.resultado2, pr.sancion FROM partiresul pr WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? ORDER BY pr.secuencia");
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids_usuarios = array_column($rows, 'id_usuario');
    $tarjeta_previa = SancionesHelper::getTarjetaPreviaDesdePartidasAnteriores($pdo, $torneo_id, $ronda, $ids_usuarios);
    try {
        $pdo->beginTransaction();
        foreach ($rows as $row) {
            $partiresul_id = (int)$row['id'];
            $j = $jugadores_raw[$partiresul_id] ?? [];
            $resultado1 = (int)($j['resultado1'] ?? $row['resultado1'] ?? 0);
            $resultado2 = (int)($j['resultado2'] ?? $row['resultado2'] ?? 0);
            $sancion_input = (int)($j['sancion'] ?? 0);
            $tarjeta_inscritos = (int)($tarjeta_previa[(int)$row['id_usuario']] ?? 0);
            $procesado = SancionesHelper::procesar($sancion_input, 0, $tarjeta_inscritos);
            $tarjeta = $procesado['tarjeta'];
            $sancion_guardar = $procesado['sancion_guardar'];
            $sancion_calc = $procesado['sancion_para_calculo'];
            $resultado1_ajust = max(0, $resultado1 - $sancion_calc);
            $efectividad = ResultadosPartidaEfectividad::calcularEfectividad(
                $resultado1_ajust,
                $resultado2,
                $puntosTorneo,
                0,
                $tarjeta,
                0
            );
            $pdo->prepare("
                UPDATE partiresul SET resultado1 = ?, resultado2 = ?, efectividad = ?, tarjeta = ?, sancion = ?, estatus = 'confirmado'
                WHERE id = ?
            ")->execute([$resultado1, $resultado2, $efectividad, $tarjeta, $sancion_guardar, $partiresul_id]);
        }
        $pdo->commit();
        actualizarEstadisticasInscritos($torneo_id);
        try {
            enviarNotificacionesResultadosAprobados($pdo, $torneo_id, $ronda, $mesa);
        } catch (Exception $e) {
            error_log("Error al enviar notificaciones de acta aprobada: " . $e->getMessage());
        }
        $_SESSION['success'] = 'Acta aprobada y rankings actualizados. Notificaciones enviadas a los jugadores.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error al aprobar: ' . $e->getMessage();
    }
    $redirect_action = (!empty($_POST['redirect_action']) && $_POST['redirect_action'] === 'verificar_resultados') ? 'verificar_resultados' : 'verificar_actas_index';
    header('Location: ' . buildRedirectUrl($redirect_action, ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Rechaza una acta QR: limpia resultados y foto, pone estatus pendiente_verificacion para re-escaneo.
 *
 * @param int $user_id
 * @param bool $is_admin_general
 */
function verificarActaRechazar($user_id, $is_admin_general) {
    $torneo_id = (int)($_POST['torneo_id'] ?? 0);
    $ronda = (int)($_POST['ronda'] ?? 0);
    $mesa = (int)($_POST['mesa'] ?? 0);
    if ($torneo_id <= 0 || $ronda <= 0 || $mesa <= 0) {
        $_SESSION['error'] = 'Parámetros inválidos.';
        header('Location: ' . buildRedirectUrl('verificar_resultados', ['torneo_id' => $torneo_id]));
        exit;
    }
    verificarPermisosTorneo($torneo_id, $user_id, $is_admin_general);
    $pdo = DB::pdo();
    $torneo_finalizado = isTorneoLocked($torneo_id);
    if ($torneo_finalizado && !$is_admin_general) {
        $_SESSION['error'] = 'No puede rechazar actas en un torneo finalizado. Solo el administrador general puede realizar correcciones.';
        $redirect_action = (!empty($_POST['redirect_action']) && $_POST['redirect_action'] === 'verificar_resultados') ? 'verificar_resultados' : 'verificar_actas_index';
        header('Location: ' . buildRedirectUrl($redirect_action, ['torneo_id' => $torneo_id]));
        exit;
    }
    $cols = $pdo->query("SHOW COLUMNS FROM partiresul")->fetchAll(PDO::FETCH_COLUMN);
    $has_estatus = in_array('estatus', $cols);
    $has_foto = in_array('foto_acta', $cols);
    try {
        $updates = ["registrado = 0", "resultado1 = 0", "resultado2 = 0", "efectividad = 0", "ff = 0", "tarjeta = 0", "sancion = 0"];
        if ($has_estatus) $updates[] = "estatus = 'pendiente_verificacion'";
        if ($has_foto) $updates[] = "foto_acta = NULL";
        $pdo->prepare("UPDATE partiresul SET " . implode(', ', $updates) . " WHERE id_torneo = ? AND partida = ? AND mesa = ?")
            ->execute([$torneo_id, $ronda, $mesa]);
        actualizarEstadisticasInscritos($torneo_id);
        $_SESSION['success'] = 'Acta rechazada. El jugador puede volver a escanear y enviar el acta.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al rechazar: ' . $e->getMessage();
    }
    $redirect_action = (!empty($_POST['redirect_action']) && $_POST['redirect_action'] === 'verificar_resultados') ? 'verificar_resultados' : 'verificar_actas_index';
    header('Location: ' . buildRedirectUrl($redirect_action, ['torneo_id' => $torneo_id]));
    exit;
}

/**
 * Envía notificaciones a jugadores cuando se aprueba un acta.
 * Mensaje con cláusula de veracidad: resultado definitivo, revisión ante juez en 2 rondas.
 *
 * @param PDO $pdo
 * @param int $torneo_id
 * @param int $ronda
 * @param int $mesa
 */
function enviarNotificacionesResultadosAprobados(PDO $pdo, int $torneo_id, int $ronda, int $mesa): void {
    $hasTg = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'telegram_chat_id'")->rowCount() > 0;
    $sql = "SELECT pr.id_usuario, pr.resultado1, pr.resultado2, pr.sancion, pr.tarjeta,
            u.nombre" . ($hasTg ? ", u.telegram_chat_id" : "") . "
            FROM partiresul pr
            INNER JOIN usuarios u ON u.id = pr.id_usuario
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ? AND pr.registrado = 1
            ORDER BY pr.secuencia";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$torneo_id, $ronda, $mesa]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($filas) === 0) return;

    require_once __DIR__ . '/../lib/app_helpers.php';
    require_once __DIR__ . '/../lib/NotificationManager.php';
    $nm = new NotificationManager($pdo);

    $tarjetaTexto = [1 => 'Amarilla', 3 => 'Roja', 4 => 'Negra'];
    $items = [];
    foreach ($filas as $row) {
        $id_usuario = (int)$row['id_usuario'];
        $nombre = trim((string)($row['nombre'] ?? ''));
        $r1 = (int)($row['resultado1'] ?? 0);
        $r2 = (int)($row['resultado2'] ?? 0);
        $sancion = (int)($row['sancion'] ?? 0);
        $tarjeta = (int)($row['tarjeta'] ?? 0);
        $puntos = "{$r1} a {$r2}";
        if ($sancion > 0 || $tarjeta > 0) {
            $partes = [];
            if ($sancion > 0) $partes[] = "sancion {$sancion} pts";
            if ($tarjeta > 0) $partes[] = "tarjeta " . ($tarjetaTexto[$tarjeta] ?? $tarjeta);
            $puntos .= " (" . implode(", ", $partes) . ")";
        }
        $mensaje = "Resultados registrados: {$puntos}. Nota: Pasadas dos rondas, se tomará como verídico este resultado. Cualquier discrepancia debe ser reportada físicamente ante la mesa de control antes de ese plazo.";

        $url_resumen = AppHelpers::url('index.php', [
            'page' => 'torneo_gestion',
            'action' => 'resumen_individual',
            'torneo_id' => $torneo_id,
            'inscrito_id' => $id_usuario,
            'from' => 'notificaciones',
        ]);
        $url_clasificacion = AppHelpers::url('index.php', [
            'page' => 'torneo_gestion',
            'action' => 'posiciones',
            'torneo_id' => $torneo_id,
            'from' => 'notificaciones',
        ]);
        $tarjetaStr = $tarjeta > 0 ? ($tarjetaTexto[$tarjeta] ?? (string)$tarjeta) : '';
        $items[] = [
            'id' => $id_usuario,
            'telegram_chat_id' => $hasTg && !empty(trim((string)($row['telegram_chat_id'] ?? ''))) ? trim((string)$row['telegram_chat_id']) : null,
            'mensaje' => $mensaje,
            'url_destino' => $url_resumen,
            'datos_json' => [
                'tipo' => 'resultados_aprobados',
                'ronda' => (string)$ronda,
                'mesa' => (string)$mesa,
                'usuario_id' => $id_usuario,
                'nombre' => $nombre,
                'resultado1' => (string)$r1,
                'resultado2' => (string)$r2,
                'sancion' => (string)$sancion,
                'tarjeta_texto' => $tarjetaStr,
                'url_resumen' => $url_resumen,
                'url_clasificacion' => $url_clasificacion,
            ],
        ];
    }
    if (!empty($items)) {
        $nm->programarMasivoPersonalizado($items);
    }
}