<?php
/**
 * Clubes de la organización (admin organización)
 * Muestra los clubes de la organización del admin con sus estadísticas
 *
 * Regla de identificación:
 * - Listado / modal / POST editar: siempre el club por PK `clubes.id` (parámetro `club_id`). No sustituir por `cod_org`.
 * - Alcance “qué clubes pertenecen a la federación”: `ClubHelper` + código canónico (distinto; no es la PK del club).
 */

if (!defined('APP_BOOTSTRAPPED')) { 
    require_once __DIR__ . '/../../config/bootstrap.php'; 
}
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';
require_once __DIR__ . '/../../lib/OrganizacionDashboardStats.php';

/**
 * Normaliza una fila de club para listado / modal (mismos campos que espera editarClub() en JS).
 * `fed_codigo_resuelto` es solo informativo (columna Cód.); el identificador de edición sigue siendo `id` (PK).
 *
 * @param array<string, mixed> $c
 * @return array<string, mixed>
 */
function clubes_asociados_normalize_club_row(PDO $pdo, array $c): array
{
    if (!isset($c['delegado_user_id'])) {
        $c['delegado_user_id'] = null;
    }
    if (!isset($c['cod_org'])) {
        $c['cod_org'] = null;
    }
    if (!isset($c['entidad'])) {
        $c['entidad'] = null;
    }
    $co = isset($c['cod_org']) ? (int) $c['cod_org'] : 0;
    $en = isset($c['entidad']) ? (int) $c['entidad'] : 0;
    $c['fed_codigo_resuelto'] = $co > 0 ? $co : ($en > 0 ? $en : 0);
    // PK explícita para edición/JSON (nunca confundir con cod_org de federación ni con otro identificador).
    $c['club_id_pk'] = (int) ($c['id'] ?? 0);
    if (!isset($c['rif']) || trim((string) $c['rif']) === '') {
        $c['rif'] = 'J000000000000';
    }
    if (!isset($c['permite_inscripcion_linea'])) {
        $c['permite_inscripcion_linea'] = 0;
    }
    if (empty($c['delegado']) && !empty($c['delegado_user_id'])) {
        try {
            $stmt_del = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ?');
            $stmt_del->execute([$c['delegado_user_id']]);
            $nombre_del = $stmt_del->fetchColumn();
            if ($nombre_del) {
                $c['delegado'] = $nombre_del;
            }
        } catch (Exception $e) {
            error_log('Error obteniendo nombre de delegado: ' . $e->getMessage());
        }
    }

    return $c;
}

// Solo admin_club (admin organización) puede acceder
Auth::requireRole(['admin_club']);

$current_user = Auth::user();
$admin_club_user_id = Auth::id();
/** @var int|null Código canónico cod_org de la federación (no PK de organizaciones) */
$organizacion_cod_org = Auth::getUserOrganizacionCodOrg();
$message = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$has_cod_org = false;
try {
    $has_cod_org = (bool)DB::pdo()->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}

// Procesar acciones POST con redirección PRG (Post-Redirect-Get)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_msg = '';
    $redirect_error = '';
    
    // Crear nuevo club (estructura tabla: id, rif, nombre, direccion, delegado, delegado_user_id, telefono, email, admin_club_id, organizacion_id, entidad, indica, estatus, permite_inscripcion_linea, logo, created_at, updated_at)
    if ($action === 'crear') {
        $rif = trim($_POST['rif'] ?? '');
        if ($rif === '') $rif = 'J000000000000';
        $nombre = trim($_POST['nombre'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $permite_inscripcion_linea = isset($_POST['permite_inscripcion_linea']) ? 1 : 0;
        $delegado_user_id_post = (int)($_POST['delegado_user_id'] ?? 0);
        
        if (empty($nombre)) {
            $redirect_error = 'El nombre del club es requerido';
        } else {
            try {
                $pdo = DB::pdo();
                $pdo->beginTransaction();
                
                // Responsable: debe ser usuario registrado; si no se elige, se asigna el admin de la organización
                $delegado_user_id_final = $admin_club_user_id;
                $delegado_nombre = trim($current_user['nombre'] ?? $current_user['username'] ?? '');
                if ($delegado_user_id_post > 0) {
                    $club_ids_pre = ClubHelper::getClubesByAdminClubId($admin_club_user_id);
                    $es_admin = ($delegado_user_id_post === $admin_club_user_id);
                    if ($es_admin) {
                        $delegado_user_id_final = $admin_club_user_id;
                        $delegado_nombre = trim($current_user['nombre'] ?? $current_user['username'] ?? '');
                    } elseif (!empty($club_ids_pre)) {
                        $ph = implode(',', array_fill(0, count($club_ids_pre), '?'));
                        $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ? AND club_id IN ($ph) AND role = 'usuario' AND (status = 'approved' OR status = 1) LIMIT 1");
                        $stmt->execute(array_merge([$delegado_user_id_post], $club_ids_pre));
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $delegado_nombre = trim($row['nombre'] ?? '');
                            $delegado_user_id_final = $delegado_user_id_post;
                        }
                    }
                }
                
                $org_id_val = $organizacion_cod_org ?: null;
                $insert_ok = false;
                if (!$org_id_val) {
                    $redirect_error = 'Su organización no está definida. No se puede crear un club sin entidad.';
                } else {
                    $stmt_ent = $pdo->prepare($has_cod_org
                        ? "SELECT entidad FROM organizaciones WHERE (id = ? OR cod_org = ?) AND estatus = 1"
                        : "SELECT entidad FROM organizaciones WHERE id = ? AND estatus = 1");
                    $stmt_ent->execute($has_cod_org ? [$org_id_val, $org_id_val] : [$org_id_val]);
                    $entidad_val = (int)$stmt_ent->fetchColumn();
                    if ($entidad_val <= 0) {
                        $redirect_error = 'La organización no tiene entidad definida. No se puede crear un club sin entidad.';
                    } else {
                        $tiene_entidad_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'entidad'")->fetch();
                        $tiene_email_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'email'")->fetch();
                        $tiene_rif_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'rif'")->fetch();
                        $tiene_indica_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'indica'")->fetch();
                        $tiene_permite_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'permite_inscripcion_linea'")->fetch();
                        $tiene_logo_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'logo'")->fetch();
                        $logo_path = null;
                        if ($tiene_logo_col && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                            if (in_array($ext, $allowed) && $_FILES['logo']['size'] <= 5 * 1024 * 1024) {
                                $upload_dir = __DIR__ . '/../../upload/logos';
                                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                                $logo_name = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
                                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . '/' . $logo_name)) {
                                    $logo_path = 'upload/logos/' . $logo_name;
                                }
                            }
                        }
                        try {
                            $org_fk_col = ClubHelper::clubOrganizacionFkColumn();
                            $cols = ['nombre', 'delegado', 'delegado_user_id', 'telefono', 'direccion', 'estatus', 'admin_club_id', 'created_at'];
                            $vals = ['?', '?', '?', '?', '?', '1', '?', 'NOW()'];
                            $params = [$nombre, $delegado_nombre, $delegado_user_id_final, $telefono, $direccion, $admin_club_user_id];
                            if ($org_fk_col !== null) {
                                array_splice($cols, -1, 0, [$org_fk_col]);
                                array_splice($vals, -1, 0, ['?']);
                                $params[] = $org_id_val;
                            }
                            if ($tiene_rif_col) { array_splice($cols, 0, 0, ['rif']); array_splice($vals, 0, 0, ['?']); array_splice($params, 0, 0, [$rif]); }
                            if ($tiene_email_col) { $cols[] = 'email'; $vals[] = '?'; $params[] = $email; }
                            if ($tiene_entidad_col) { $cols[] = 'entidad'; $vals[] = '?'; $params[] = $entidad_val; }
                            if ($tiene_indica_col) { $cols[] = 'indica'; $vals[] = '0'; }
                            if ($tiene_permite_col) { $cols[] = 'permite_inscripcion_linea'; $vals[] = '?'; $params[] = $permite_inscripcion_linea; }
                            if ($tiene_logo_col) { $cols[] = 'logo'; $vals[] = '?'; $params[] = $logo_path; }
                            $stmt = $pdo->prepare("INSERT INTO clubes (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")");
                            $stmt->execute($params);
                            $insert_ok = true;
                        } catch (PDOException $e) {
                            error_log("Club insert error: " . $e->getMessage());
                            throw $e;
                        }
                    }
                }
                if ($insert_ok) {
                    $nuevo_club_id = $pdo->lastInsertId();
                    if (empty($current_user['club_id'])) {
                        $stmt = $pdo->prepare("UPDATE usuarios SET club_id = ? WHERE id = ?");
                        $stmt->execute([$nuevo_club_id, $admin_club_user_id]);
                    }
                    $redirect_msg = "Club '$nombre' creado correctamente";
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $redirect_error = 'Error al crear el club: ' . $e->getMessage();
            }
        }
    }
    
    // Editar club: solo por PK (clubes.id). Nunca interpretar como cod_org ni como id de organización.
    if ($action === 'editar') {
        $club_id = (int)($_POST['club_id'] ?? 0);
        $rif = trim($_POST['rif'] ?? '');
        if ($rif === '') $rif = 'J000000000000';
        $nombre = trim($_POST['nombre'] ?? '');
        $delegado_user_id = (int)($_POST['delegado_user_id'] ?? 0);
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $permite_inscripcion_linea = isset($_POST['permite_inscripcion_linea']) ? 1 : 0;
        
        if (!ClubHelper::isClubManagedByAdmin($admin_club_user_id, $club_id)) {
            $redirect_error = 'No tiene permisos para editar este club';
        } elseif (empty($nombre)) {
            $redirect_error = 'El nombre del club es requerido';
        } else {
            try {
                $pdo = DB::pdo();
                $club_ids = ClubHelper::getClubesByAdminClubId($admin_club_user_id);
                // Responsable: usuario registrado; si no se elige (0), se asigna el admin de la organización
                $delegado_nombre = trim($current_user['nombre'] ?? $current_user['username'] ?? '');
                $delegado_user_id_final = $admin_club_user_id;
                if ($delegado_user_id > 0) {
                    $es_admin = ($delegado_user_id === $admin_club_user_id);
                    $es_usuario = false;
                    if (!empty($club_ids)) {
                        $ph = implode(',', array_fill(0, count($club_ids), '?'));
                        $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ? AND (id = ? OR (club_id IN ($ph) AND role = 'usuario' AND (status = 'approved' OR status = 1))) LIMIT 1");
                        $stmt->execute(array_merge([$delegado_user_id, $admin_club_user_id], $club_ids));
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $delegado_nombre = trim($row['nombre'] ?? '');
                            $delegado_user_id_final = $delegado_user_id;
                        }
                    } elseif ($es_admin) {
                        $delegado_user_id_final = $admin_club_user_id;
                    }
                }
                $tiene_email_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'email'")->fetch();
                $tiene_rif_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'rif'")->fetch();
                $tiene_permite_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'permite_inscripcion_linea'")->fetch();
                $tiene_logo_col = (bool)$pdo->query("SHOW COLUMNS FROM clubes LIKE 'logo'")->fetch();
                $logo_path = null;
                if ($tiene_logo_col && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed) && $_FILES['logo']['size'] <= 5 * 1024 * 1024) {
                        $upload_dir = __DIR__ . '/../../upload/logos';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $stmt_old = $pdo->prepare("SELECT logo FROM clubes WHERE id = ?");
                        $stmt_old->execute([$club_id]);
                        $old_logo = $stmt_old->fetchColumn();
                        if ($old_logo && file_exists(__DIR__ . '/../../' . $old_logo)) @unlink(__DIR__ . '/../../' . $old_logo);
                        $logo_name = 'logo_' . $club_id . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . '/' . $logo_name)) {
                            $logo_path = 'upload/logos/' . $logo_name;
                        }
                    }
                }
                $set_parts = ['nombre = ?', 'delegado = ?', 'delegado_user_id = ?', 'telefono = ?', 'direccion = ?', 'updated_at = NOW()'];
                $set_params = [$nombre, $delegado_nombre, $delegado_user_id_final, $telefono, $direccion];
                if ($tiene_rif_col) { $set_parts[] = 'rif = ?'; $set_params[] = $rif; }
                if ($tiene_email_col) { $set_parts[] = 'email = ?'; $set_params[] = $email; }
                if ($tiene_permite_col) { $set_parts[] = 'permite_inscripcion_linea = ?'; $set_params[] = $permite_inscripcion_linea; }
                if ($tiene_logo_col && $logo_path !== null) { $set_parts[] = 'logo = ?'; $set_params[] = $logo_path; }
                $set_params[] = $club_id;
                try {
                    $stmt = $pdo->prepare("UPDATE clubes SET " . implode(', ', $set_parts) . " WHERE id = ?");
                    $stmt->execute($set_params);
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'delegado_user_id') !== false) {
                        $set_parts = array_filter($set_parts, function($x) { return strpos($x, 'delegado_user_id') === false; });
                        $set_params = [$nombre, $delegado_nombre, $telefono, $direccion];
                        if ($tiene_rif_col) { $set_params[] = $rif; }
                        if ($tiene_email_col) { $set_params[] = $email; }
                        if ($tiene_permite_col) { $set_params[] = $permite_inscripcion_linea; }
                        if ($tiene_logo_col && $logo_path !== null) { $set_params[] = $logo_path; }
                        $set_params[] = $club_id;
                        $stmt = $pdo->prepare("UPDATE clubes SET " . implode(', ', $set_parts) . " WHERE id = ?");
                        $stmt->execute($set_params);
                    } else {
                        throw $e;
                    }
                }
                $redirect_msg = "Club '$nombre' actualizado correctamente. Responsable: " . ($delegado_nombre ?: 'Administrador de la organización');
            } catch (Exception $e) {
                $redirect_error = 'Error al actualizar: ' . $e->getMessage();
                error_log("Error al actualizar club $club_id: " . $e->getMessage());
            }
        }
    }
    
    // Eliminar club
    if ($action === 'eliminar') {
        $club_id = (int)($_POST['club_id'] ?? 0);
        
        if (!ClubHelper::isClubManagedByAdmin($admin_club_user_id, $club_id)) {
            $redirect_error = 'No tiene permisos para eliminar este club';
        } else {
            try {
                $pdo = DB::pdo();
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE club_id = ? AND role = 'usuario'");
                $stmt->execute([$club_id]);
                $tiene_afiliados = $stmt->fetchColumn() > 0;
                
                if ($tiene_afiliados) {
                    $redirect_error = 'No se puede eliminar: el club tiene afiliados registrados';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM clubes WHERE id = ?");
                    $stmt->execute([$club_id]);
                    if ($stmt->rowCount() > 0) {
                        $redirect_msg = 'Club eliminado correctamente';
                        // Si era el club_id del usuario, limpiarlo
                        if ($current_user['club_id'] == $club_id) {
                            $pdo->prepare("UPDATE usuarios SET club_id = NULL WHERE id = ?")->execute([$admin_club_user_id]);
                        }
                    } else {
                        $redirect_error = 'No se pudo eliminar el club';
                    }
                }
            } catch (Exception $e) {
                $redirect_error = 'Error al eliminar: ' . $e->getMessage();
            }
        }
    }
    
    // Redirigir con mensaje (patrón PRG)
    $redirect_url = 'index.php?page=clubes_asociados';
    if ($redirect_msg) {
        $redirect_url .= '&success=' . urlencode($redirect_msg);
    }
    if ($redirect_error) {
        $redirect_url .= '&error=' . urlencode($redirect_error);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// Obtener clubes por admin_club_id (relación directa)
$mis_clubes = [];
$club_ids = [];
try {
    $club_ids = ClubHelper::getClubesByAdminClubId($admin_club_user_id);
    if (empty($club_ids)) {
        // Siempre por PK de organización (Auth::getUserOrganizacionId). No pasar getUserOrganizacionCodOrg():
        // un mismo entero puede ser cod_org de una org y PK de otra, y (id=? OR cod_org=?) devolvía la fila equivocada.
        $orgPk = Auth::getUserOrganizacionId();
        if ($orgPk) {
            $club_ids = ClubHelper::getClubesByOrganizacionId((int) $orgPk);
        }
    }
    if (!empty($club_ids)) {
            $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
            $select_cols = "c.id, c.nombre, c.delegado, c.telefono, c.direccion, c.estatus";
            $group_cols = "c.id, c.nombre, c.delegado, c.telefono, c.direccion, c.estatus";
            try {
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'delegado_user_id'");
                if ($stmt->rowCount() > 0) {
                    $select_cols .= ", c.delegado_user_id";
                    $group_cols .= ", c.delegado_user_id";
                }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'email'");
                if ($stmt->rowCount() > 0) { $select_cols .= ", c.email"; $group_cols .= ", c.email"; }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'rif'");
                if ($stmt->rowCount() > 0) { $select_cols .= ", c.rif"; $group_cols .= ", c.rif"; }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'indica'");
                if ($stmt->rowCount() > 0) { $select_cols .= ", c.indica"; $group_cols .= ", c.indica"; }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'permite_inscripcion_linea'");
                if ($stmt->rowCount() > 0) { $select_cols .= ", c.permite_inscripcion_linea"; $group_cols .= ", c.permite_inscripcion_linea"; }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'logo'");
                if ($stmt->rowCount() > 0) { $select_cols .= ", c.logo"; $group_cols .= ", c.logo"; }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'cod_org'");
                if ($stmt->rowCount() > 0) {
                    $select_cols .= ", c.cod_org";
                    $group_cols .= ", c.cod_org";
                }
                $stmt = DB::pdo()->query("SHOW COLUMNS FROM clubes LIKE 'entidad'");
                if ($stmt->rowCount() > 0) {
                    $select_cols .= ", c.entidad";
                    $group_cols .= ", c.entidad";
                }
            } catch (Exception $e) {}
            $pdoFed = DB::pdo();
            $fedExprC = OrganizacionDashboardStats::clubFederacionCodigoSqlExpr($pdoFed, 'c');
            $stmt = DB::pdo()->prepare("
                SELECT 
                    $select_cols,
                    COUNT(DISTINCT u.id) as total_afiliados,
                    SUM(CASE WHEN u.sexo = 'M' THEN 1 ELSE 0 END) as hombres,
                    SUM(CASE WHEN u.sexo = 'F' THEN 1 ELSE 0 END) as mujeres,
                    (SELECT COUNT(*) FROM tournaments t WHERE t.club_responsable = ({$fedExprC}) AND t.estatus = 1) as torneos_count,
                    (SELECT COUNT(*) FROM inscritos i 
                     INNER JOIN tournaments t ON i.torneo_id = t.id 
                     WHERE t.club_responsable = ({$fedExprC})) as inscritos_count
                FROM clubes c
                LEFT JOIN usuarios u ON u.club_id = c.id AND u.role = 'usuario' AND (u.status = 'approved' OR u.status = 1)
                WHERE c.id IN ($placeholders)
                GROUP BY $group_cols
                ORDER BY c.nombre ASC
            ");
        $stmt->execute($club_ids);
        $mis_clubes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdoList = DB::pdo();
        foreach ($mis_clubes as &$c) {
            $c = clubes_asociados_normalize_club_row($pdoList, $c);
        }
        unset($c);
    }
} catch (Exception $e) {
    $error = 'Error al cargar clubes: ' . $e->getMessage();
}

// ?club_id= es PK de clubes (misma semántica que el listado). No es cod_org de federación.
$club_id_get = isset($_GET['club_id']) ? (int) $_GET['club_id'] : 0;

// Modal: SELECT por id (PK). No buscar por cod_org aunque coincida numéricamente con otro concepto.
$club_edit_modal_payload = null;
if ($club_id_get > 0 && ClubHelper::isClubManagedByAdmin($admin_club_user_id, $club_id_get)) {
    try {
        $pdoModal = DB::pdo();
        $stmtModal = $pdoModal->prepare('SELECT * FROM clubes WHERE id = ? AND estatus = 1 LIMIT 1');
        $stmtModal->execute([$club_id_get]);
        $rowModal = $stmtModal->fetch(PDO::FETCH_ASSOC);
        if (is_array($rowModal) && (int) ($rowModal['id'] ?? 0) === $club_id_get) {
            $club_edit_modal_payload = clubes_asociados_normalize_club_row($pdoModal, $rowModal);
        }
    } catch (Throwable $e) {
        error_log('clubes_asociados club_edit_modal_payload: ' . $e->getMessage());
    }
}

// Opciones para asignar responsable: admin_club + usuarios registrados de sus clubes
$delegado_opciones = [];
$delegado_opciones[] = ['id' => $admin_club_user_id, 'nombre' => trim($current_user['nombre'] ?? $current_user['username'] ?? 'Administrador'), 'rol' => 'admin_club'];
if (!empty($club_ids)) {
    $ph = implode(',', array_fill(0, count($club_ids), '?'));
    $stmt = DB::pdo()->prepare("
        SELECT u.id, u.nombre, u.celular, c.nombre as club_nombre
        FROM usuarios u
        LEFT JOIN clubes c ON u.club_id = c.id
        WHERE u.club_id IN ($ph) AND u.role = 'usuario' AND (u.status = 'approved' OR u.status = 1)
        ORDER BY u.nombre ASC
    ");
    $stmt->execute($club_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $delegado_opciones[] = ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'celular' => $u['celular'] ?? '', 'club_nombre' => $u['club_nombre'] ?? '', 'rol' => 'usuario'];
    }
}

$mi_organizacion = null;
try {
    $oid = Auth::getUserOrganizacionId();
    if ($oid) {
        $stOrg = DB::pdo()->prepare('SELECT id, nombre, cod_org, entidad FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
        $stOrg->execute([(int) $oid]);
        $mi_organizacion = $stOrg->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $mi_organizacion = null;
}

$hay_club_cod_distinto = false;
if ($organizacion_cod_org && !empty($mis_clubes)) {
    foreach ($mis_clubes as $rowChk) {
        $fedR = (int) ($rowChk['fed_codigo_resuelto'] ?? 0);
        if ($fedR > 0 && $fedR !== (int) $organizacion_cod_org) {
            $hay_club_cod_distinto = true;
            break;
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-building"></i> Clubes de la organización</h2>
            <p class="text-muted mb-0">
                Solo clubes vinculados a tu federación (misma regla en listados, edición y búsquedas).
            </p>
            <?php if (!empty($mi_organizacion)): ?>
                <p class="mb-0 mt-2">
                    <span class="text-muted">Federación:</span>
                    <strong><?= htmlspecialchars(trim((string) ($mi_organizacion['nombre'] ?? ''))) ?></strong>
                    <span class="text-muted">· código</span>
                    <span class="badge bg-dark"><?= (int) ($organizacion_cod_org ?? 0) ?></span>
                </p>
                <p class="small text-muted mb-0 mt-1">
                    La columna <strong>Club</strong> es el nombre del club local (editable). No confundir con el nombre de la federación (Capital, código <?= (int) ($organizacion_cod_org ?? 0) ?>).
                </p>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas', ['tipo' => 'club', 'from' => 'clubes'])) ?>" class="btn btn-outline-warning">
                <i class="fas fa-bell me-1"></i>Notificación / invitación (toda la organización)
            </a>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#crearClubModal">
                <i class="fas fa-plus"></i> Nuevo Club
            </button>
        </div>
    </div>

    <?php if (empty($mis_clubes)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Aún no tienes clubes. Crea el primero para comenzar a gestionar tu organización.
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($hay_club_cod_distinto): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Algún registro tiene <code>cod_org</code> distinto al código de tu federación (<?= (int) ($organizacion_cod_org ?? 0) ?>). Revisa datos o ejecuta la homologación SQL si aún no se aplicó.
        </div>
    <?php endif; ?>

    <!-- Resumen total (si hay clubes) -->
    <?php if (!empty($mis_clubes)): ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card text-center bg-primary text-white">
                <div class="card-body">
                    <h3 class="mb-0"><?= count($mis_clubes) ?></h3>
                    <small>Clubes de la organización</small>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-center bg-info text-white">
                <div class="card-body">
                    <h3 class="mb-0"><?= number_format(array_sum(array_column($mis_clubes, 'total_afiliados'))) ?></h3>
                    <small>Total Afiliados</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Lista de clubes con estadísticas -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-building me-2"></i>Clubes de la organización (<?= count($mis_clubes) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($mis_clubes)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No tienes clubes asignados aún.</p>
                    <p class="text-muted">Crea un nuevo club para comenzar.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><i class="fas fa-building me-2"></i>Club</th>
                                <th class="text-center" title="Código de federación (debe coincidir con el tuyo)"><small>Cód.</small></th>
                                <th>RIF</th>
                                <th>Delegado</th>
                                <th>Teléfono</th>
                                <th class="text-center">Insc. línea</th>
                                <th class="text-center"><i class="fas fa-users me-2"></i>Afiliados</th>
                                <th class="text-center"><i class="fas fa-mars me-2 text-primary"></i>Hombres</th>
                                <th class="text-center"><i class="fas fa-venus me-2 text-danger"></i>Mujeres</th>
                                <th>Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mis_clubes as $club): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($club['logo']) && file_exists(__DIR__ . '/../../' . $club['logo'])): 
                                                $logo_src = class_exists('AppHelpers') ? AppHelpers::url('view_image.php', ['path' => $club['logo']]) : '';
                                            ?>
                                                <img src="<?= htmlspecialchars($logo_src) ?>" alt="" class="rounded" style="width:40px;height:40px;object-fit:contain;background:#f0f0f0">
                                            <?php else: ?>
                                                <div class="rounded d-flex align-items-center justify-content-center bg-light text-muted" style="width:40px;height:40px"><i class="fas fa-building"></i></div>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?= htmlspecialchars(trim((string) ($club['nombre'] ?? ''))) ?></strong>
                                                <br>
                                                <a href="index.php?page=clubs&action=detail&id=<?= (int) ($club['club_id_pk'] ?? $club['id'] ?? 0) ?>" class="btn btn-sm btn-link p-0 text-primary" title="Ver afiliados"><small><i class="fas fa-users me-1"></i>Ver afiliados</small></a>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $fedR = (int) ($club['fed_codigo_resuelto'] ?? 0);
                                        $cc = $fedR > 0 ? $fedR : null;
                                        $okCod = $organizacion_cod_org && $cc !== null && $cc === (int) $organizacion_cod_org;
                                        ?>
                                        <?php if ($cc !== null): ?>
                                            <span class="badge <?= $okCod ? 'bg-success' : 'bg-warning text-dark' ?>" title="COALESCE(cod_org, entidad)"><?= $cc ?><?php if (empty($club['cod_org']) && !empty($club['entidad'])): ?><small class="d-block">(entidad)</small><?php endif; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($club['rif'] ?? 'J000000000000') ?></code></td>
                                    <td><?= htmlspecialchars($club['delegado'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($club['telefono'] ?? '-') ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($club['permite_inscripcion_linea'])): ?>
                                            <span class="badge bg-success">Sí</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= (int)($club['total_afiliados'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= (int)($club['hombres'] ?? 0) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= (int)($club['mujeres'] ?? 0) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($club['estatus']): ?>
                                            <span class="badge bg-success">Activo</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('notificaciones_masivas', ['tipo' => 'club', 'club_id' => (int) ($club['club_id_pk'] ?? $club['id'] ?? 0), 'from' => 'clubes'])) ?>" 
                                           class="btn btn-sm btn-outline-warning" title="Enviar notificación a afiliados del club">
                                            <i class="fas fa-bell"></i>
                                        </a>
                                        <a href="<?= htmlspecialchars(AppHelpers::dashboard('clubs/invitation_link', ['club_id' => (int) ($club['club_id_pk'] ?? $club['id'] ?? 0)])) ?>" 
                                           class="btn btn-sm btn-outline-success" title="Link invitación club">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="editarClubPorId(<?= (int) ($club['club_id_pk'] ?? $club['id'] ?? 0) ?>)"
                                                title="Editar este club (PK <?= (int) ($club['club_id_pk'] ?? $club['id'] ?? 0) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if (($club['total_afiliados'] ?? 0) == 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="eliminarClub(<?= (int) ($club['club_id_pk'] ?? $club['id'] ?? 0) ?>, <?= json_encode($club['nombre']) ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td>TOTAL</td>
                                <td></td>
                                <td colspan="4"></td>
                                <td class="text-center"><?= number_format(array_sum(array_column($mis_clubes, 'total_afiliados'))) ?></td>
                                <td class="text-center"><?= number_format(array_sum(array_column($mis_clubes, 'hombres'))) ?></td>
                                <td class="text-center"><?= number_format(array_sum(array_column($mis_clubes, 'mujeres'))) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info -->
    <div class="card mt-3">
        <div class="card-body">
            <h6><i class="fas fa-info-circle text-info"></i> Información</h6>
            <ul class="small text-muted mb-0">
                <li>Todos los clubes que crees se mostrarán aquí con sus estadísticas</li>
                <li>Los atletas pueden inscribirse eligiendo cualquiera de tus clubes</li>
                <li>Puedes crear torneos asignando cualquiera de tus clubes como responsable</li>
                <li>Si el nombre del club no corresponde (ej. otro estado), usa <strong>Editar</strong> y corrígelo; el vínculo a tu federación es el código en la columna «Cód.»</li>
            </ul>
        </div>
    </div>
</div>

<!-- Modal Crear Club (misma estructura que organizaciones) -->
<div class="modal fade" id="crearClubModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="crear">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Crear Club</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Nombre del Club <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">RIF</label>
                            <input type="text" name="rif" class="form-control" placeholder="J000000000" maxlength="20">
                            <small class="text-muted">Si se deja vacío se guarda J000000000000</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsable / Encargado del Club</label>
                        <select name="delegado_user_id" class="form-select">
                            <option value="0">Administrador de la organización (tú)</option>
                            <?php foreach ($delegado_opciones as $opt): if ((int)$opt['id'] === $admin_club_user_id) continue; ?>
                                <option value="<?= (int)$opt['id'] ?>">
                                    <?= htmlspecialchars($opt['nombre']) ?>
                                    <?php if (!empty($opt['club_nombre'])): ?> – <?= htmlspecialchars($opt['club_nombre']) ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Debe ser un usuario registrado. Si no eliges, se asigna el administrador de la organización.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dirección</label>
                        <textarea name="direccion" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo del club</label>
                        <input type="file" name="logo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="text-muted">JPG, PNG, GIF o WEBP. Máximo 5MB.</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="permite_inscripcion_linea" value="1" id="crear_permite_inscripcion" class="form-check-input">
                            <label class="form-check-label" for="crear_permite_inscripcion">Permite inscripción en línea a afiliados</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Crear Club
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Club (misma estructura que organizaciones) -->
<div class="modal fade" id="editarClubModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="club_id" id="edit_club_id" value="" autocomplete="off"><?php /* PK clubes.id; no cod_org */ ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Club</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Nombre del Club <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">RIF</label>
                            <input type="text" name="rif" id="edit_rif" class="form-control" placeholder="J000000000" maxlength="20">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsable / Encargado del Club</label>
                        <select name="delegado_user_id" id="edit_delegado_user_id" class="form-select">
                            <option value="0">Administrador de la organización</option>
                            <?php foreach ($delegado_opciones as $opt): ?>
                                <option value="<?= (int)$opt['id'] ?>">
                                    <?= htmlspecialchars($opt['nombre']) ?>
                                    <?php if (($opt['rol'] ?? '') === 'admin_club'): ?>
                                        (Administrador)
                                    <?php elseif (!empty($opt['club_nombre'])): ?>
                                        – <?= htmlspecialchars($opt['club_nombre']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Debe ser un usuario registrado. Si no eliges, se asigna el administrador de la organización.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" id="edit_telefono" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dirección</label>
                        <textarea name="direccion" id="edit_direccion" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo del club</label>
                        <div id="edit_logo_actual" class="mb-2"></div>
                        <input type="file" name="logo" id="edit_logo_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="text-muted">JPG, PNG, GIF o WEBP. Máximo 5MB. Si eliges otro archivo, se reemplazará el actual.</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="permite_inscripcion_linea" value="1" id="edit_permite_inscripcion" class="form-check-input">
                            <label class="form-check-label" for="edit_permite_inscripcion">Permite inscripción en línea a afiliados</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Eliminar Club -->
<div class="modal fade" id="eliminarClubModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="club_id" id="delete_club_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash"></i> Eliminar Club</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro de eliminar el club <strong id="delete_club_nombre"></strong>?</p>
                    <p class="text-danger"><small>Esta acción no se puede deshacer.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$mis_clubes_json = '[]';
if ($club_edit_modal_payload !== null) {
    $mis_clubes_json = json_encode([$club_edit_modal_payload], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
?>
<script>
var baseViewImageUrl = '<?= htmlspecialchars(class_exists("AppHelpers") ? AppHelpers::url("view_image.php") : "") ?>';
/** Misma data que la tabla: la edición resuelve siempre por PK dentro de este array. */
var MIS_CLUBES = <?= json_encode($mis_clubes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
/** Payload del servidor solo para ?club_id= (misma fila que SELECT por PK). No mezclar con clics del listado. */
var mis_clubes_json = <?= $mis_clubes_json ?>;
/** Listado de esta pantalla: solo MIS_CLUBES (misma tabla que ves). */
function editarClubPorId(id) {
    var pk = parseInt(id, 10);
    if (!(pk > 0) || !Array.isArray(MIS_CLUBES)) return;
    var i, row;
    for (i = 0; i < MIS_CLUBES.length; i++) {
        row = MIS_CLUBES[i];
        var rowPk = parseInt(row.club_id_pk != null ? row.club_id_pk : row.id, 10);
        if (rowPk === pk) {
            editarClub(row);
            return;
        }
    }
}
/** Entrada por URL (?club_id=): primero payload del servidor; si no, fila del listado. Luego se limpia el payload. */
function clubParaEdicionDesdeUrl(clubId) {
    var pk = parseInt(clubId, 10);
    if (!(pk > 0)) return null;
    var i, row;
    try {
        var extra = JSON.parse(typeof mis_clubes_json !== 'undefined' ? mis_clubes_json : '[]');
        if (Array.isArray(extra)) {
            for (i = 0; i < extra.length; i++) {
                row = extra[i];
                var rowPkE = parseInt(row.club_id_pk != null ? row.club_id_pk : row.id, 10);
                if (rowPkE === pk) return row;
            }
        }
    } catch (e) {}
    if (Array.isArray(MIS_CLUBES)) {
        for (i = 0; i < MIS_CLUBES.length; i++) {
            row = MIS_CLUBES[i];
            var rowPk2 = parseInt(row.club_id_pk != null ? row.club_id_pk : row.id, 10);
            if (rowPk2 === pk) return row;
        }
    }
    return null;
}
/** Abre el modal. `club` debe ser la fila con `id` = PK de `clubes`. */
function editarClub(club) {
    var pk = parseInt(club && (club.club_id_pk != null ? club.club_id_pk : club.id), 10);
    if (!(pk > 0)) {
        return;
    }
    document.getElementById('edit_club_id').value = String(pk);
    document.getElementById('edit_nombre').value = club.nombre || '';
    document.getElementById('edit_rif').value = club.rif || 'J000000000000';
    document.getElementById('edit_delegado_user_id').value = club.delegado_user_id || '0';
    document.getElementById('edit_telefono').value = club.telefono || '';
    var emailEl = document.getElementById('edit_email');
    if (emailEl) emailEl.value = club.email || '';
    document.getElementById('edit_direccion').value = club.direccion || '';
    var permiteEl = document.getElementById('edit_permite_inscripcion');
    if (permiteEl) permiteEl.checked = (club.permite_inscripcion_linea == 1 || club.permite_inscripcion_linea === '1');
    var logoActual = document.getElementById('edit_logo_actual');
    var logoFile = document.getElementById('edit_logo_file');
    if (logoFile) logoFile.value = '';
    if (logoActual) {
        if (club.logo && baseViewImageUrl) {
            var sep = baseViewImageUrl.indexOf('?') >= 0 ? '&' : '?';
            logoActual.innerHTML = '<small class="text-success d-block mb-1">Logo actual:</small><img src="' + baseViewImageUrl + sep + 'path=' + encodeURIComponent(club.logo) + '" alt="Logo" class="img-thumbnail" style="max-height:80px;object-fit:contain">';
        } else {
            logoActual.innerHTML = '<span class="text-muted small">Sin logo</span>';
        }
    }
    const modal = new bootstrap.Modal(document.getElementById('editarClubModal'));
    modal.show();
}

function eliminarClub(id, nombre) {
    document.getElementById('delete_club_id').value = id;
    document.getElementById('delete_club_nombre').textContent = nombre;
    
    const modal = new bootstrap.Modal(document.getElementById('eliminarClubModal'));
    modal.show();
}

(function() {
    var params = new URLSearchParams(window.location.search);
    var clubId = parseInt(params.get('club_id'), 10);
    if (clubId <= 0) return;
    document.addEventListener('DOMContentLoaded', function() {
        var club = clubParaEdicionDesdeUrl(clubId);
        if (club) {
            editarClub(club);
            mis_clubes_json = '[]';
            history.replaceState(null, '', window.location.pathname + '?page=clubes_asociados');
        }
    });
})();
</script>
