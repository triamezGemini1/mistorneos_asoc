<?php
/**
 * Procesar inscripción de jugador en sitio
 * Maneja el POST del formulario de inscripción
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/InscritosHelper.php';
require_once __DIR__ . '/../../lib/UserActivationHelper.php';

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);
CSRF::validate();

// Obtener parámetros
$torneo_id = (int)($_POST['torneo_id'] ?? $_GET['torneo_id'] ?? 0);
$id_usuario = (int)($_POST['id_usuario'] ?? 0);
$cedula = trim($_POST['cedula'] ?? '');
$id_club = !empty($_POST['id_club']) ? (int)$_POST['id_club'] : null;

// Inscripción en sitio: pendiente de pago hasta validar en listado
$estatus = InscritosHelper::ESTATUS_PENDIENTE_NUM;

$inscrito_por = Auth::user()['id'];
$current_user = Auth::user();
$user_club_id = $current_user['club_id'] ?? null;

if ($torneo_id <= 0) {
    header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('ID de torneo inválido'));
    exit;
}

// Verificar acceso al torneo
if (!Auth::canAccessTournament($torneo_id)) {
    header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('No tiene permisos para acceder a este torneo'));
    exit;
}

$pdo = DB::pdo();

// Verificar que la tabla inscritos existe
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'inscritos'");
    if ($stmt->rowCount() === 0) {
        header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('La tabla inscritos no existe. Ejecute la migración primero.'));
        exit;
    }
} catch (Exception $e) {
    header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('Error al verificar tabla inscritos'));
    exit;
}

// Si se proporciona cédula pero no id_usuario: buscar 1) número, 2) V+number, 3) E+number
if (empty($id_usuario) && !empty($cedula)) {
    $cedula_num = preg_replace('/\D/', '', trim($cedula));
    $usuario_encontrado = null;
    if ($cedula_num !== '') {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? LIMIT 1");
        foreach ([$cedula_num, 'V' . $cedula_num, 'E' . $cedula_num] as $v) {
            $stmt->execute([$v]);
            $usuario_encontrado = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($usuario_encontrado) break;
        }
    }
    if ($usuario_encontrado) {
        $id_usuario = (int)$usuario_encontrado['id'];
    } else {
        header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('No encontrado. No hay usuario con esa cédula en la plataforma.'));
        exit;
    }
}

if ($id_usuario <= 0) {
    header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('Debe seleccionar un usuario o proporcionar una cédula válida'));
    exit;
}

// Bloquear según modalidad: equipos (>=1 ronda), individual/parejas (>=2 rondas)
try {
    $stmt = $pdo->prepare("SELECT MAX(CAST(partida AS UNSIGNED)) AS ultima_ronda FROM partiresul WHERE id_torneo = ? AND mesa > 0");
    $stmt->execute([$torneo_id]);
    $ultima_ronda = (int)($stmt->fetchColumn() ?? 0);
    $es_equipos = isset($torneo['modalidad']) ? ((int)$torneo['modalidad'] === 3) : false;
    $bloquear = $es_equipos ? ($ultima_ronda >= 1) : ($ultima_ronda >= 2);
    if ($bloquear) {
        header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('El torneo ya inició. No se permiten nuevas inscripciones.'));
        exit;
    }
} catch (Exception $e) {
    // Si falla la consulta, continuar pero registrar
    error_log("Error verificando rondas: " . $e->getMessage());
}

try {
    // Verificar que no esté ya inscrito
    $stmt = $pdo->prepare("SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ?");
    $stmt->execute([$id_usuario, $torneo_id]);
    
    if ($stmt->fetch()) {
        header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('Este usuario ya está inscrito en el torneo'));
        exit;
    }
    
    require_once __DIR__ . '/../../lib/AsociacionAdminHelper.php';
    $id_club = AsociacionAdminHelper::resolverIdClubInscripcion(
        $pdo,
        $id_usuario,
        (int) $inscrito_por,
        null,
        $id_club,
        $user_club_id !== null ? (int) $user_club_id : null
    );
    
    // Insertar inscripción usando función centralizada
    $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
        'id_usuario' => $id_usuario,
        'torneo_id' => $torneo_id,
        'id_club' => $id_club,
        'estatus' => $estatus,
        'inscrito_por' => $inscrito_por,
        'numero' => 0 // Se asignará después si es necesario para equipos
    ]);
    UserActivationHelper::activateUser($pdo, $id_usuario);
    header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&success=' . urlencode('Jugador inscrito exitosamente'));
    exit;
    
} catch (Exception $e) {
    error_log("Error al inscribir jugador: " . $e->getMessage());
    header('Location: ../../public/index.php?page=tournament_admin&torneo_id=' . $torneo_id . '&action=inscribir_sitio&error=' . urlencode('Error al inscribir: ' . $e->getMessage()));
    exit;
}

