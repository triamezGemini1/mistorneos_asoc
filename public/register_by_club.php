<?php
/**
 * Registro de Usuario por Club
 *
 * Flujo desde Landing:
 * Paso 1: Tarjetas de entidades con organizaciones
 * Paso 2: Tarjetas de organizaciones de esa entidad
 * Paso 3: Tarjetas de clubes de esa organización + formulario de registro
 *
 * Flujo por invitación directa (club_id en URL):
 * Página única con info del club y formulario de registro (sin selector de entidad)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/LandingDataService.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/ClubHelper.php';
require_once __DIR__ . '/../lib/ClubNavigation.php';
require_once __DIR__ . '/includes/branding_init.php';

/**
 * Sube imagen (foto o cédula). Devuelve ruta relativa o null si no hubo archivo.
 */
function register_by_club_upload_image(array $file, string $subdir, ?string $oldRelativePath, string $namePrefix): ?string
{
    if (!isset($file['tmp_name']) || $file['tmp_name'] === '' || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir la imagen');
    }
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        throw new RuntimeException('Tipo de archivo no permitido. Use JPG, PNG, GIF o WebP');
    }
    $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime = (string) ($file['type'] ?? '');
    if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
        throw new RuntimeException('Tipo de imagen no permitido');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('La imagen no debe superar 2MB');
    }
    $upload_dir = dirname(__DIR__) . '/uploads/' . $subdir . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $filename = $namePrefix . '_' . time() . '.' . $ext;
    $target = $upload_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo guardar la imagen');
    }
    if ($oldRelativePath !== null && $oldRelativePath !== '') {
        $old = dirname(__DIR__) . '/' . ltrim(str_replace(['../', '..\\'], '', $oldRelativePath), '/\\');
        if (is_file($old)) {
            @unlink($old);
        }
    }
    return 'uploads/' . $subdir . '/' . $filename;
}

$edit_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$return_url_param = ClubNavigation::safeReturnUrl($_GET['return_url'] ?? $_POST['return_url'] ?? '');
$es_edicion_afiliado = false;
$afiliado_edit = null;

if ($edit_user_id > 0) {
    require_once __DIR__ . '/../config/auth.php';
    Auth::requireRole(['admin_general', 'admin_club']);
    $es_edicion_afiliado = true;
}

// Si ya está logueado como jugador, redirigir (excepto admin editando afiliado)
if (isset($_SESSION['user']) && !$es_edicion_afiliado) {
    header('Location: index.php');
    exit;
}


$pdo = DB::pdo();
$has_cedula_image_col = false;
try {
    $has_cedula_image_col = (bool) $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cedula_image_path'")->fetch(PDO::FETCH_ASSOC);
    if (!$has_cedula_image_col) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN cedula_image_path VARCHAR(200) NULL DEFAULT NULL COMMENT 'Imagen escaneada de cédula' AFTER photo_path");
        $has_cedula_image_col = true;
    }
} catch (Throwable $ignored) {
    $has_cedula_image_col = false;
}
$base_url = app_base_url();
$landingService = new LandingDataService($pdo);
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}

$step = (int)($_GET['step'] ?? 1);
$entidad_id = isset($_GET['entidad']) ? (int)$_GET['entidad'] : 0;
$org_id = isset($_GET['org']) ? (int)$_GET['org'] : 0;
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
if ($es_edicion_afiliado && $club_id <= 0 && isset($_POST['club_id'])) {
    $club_id = (int) $_POST['club_id'];
}
$es_invitacion = $club_id > 0 && !isset($_GET['step']) && !isset($_GET['entidad']) && !isset($_GET['org']) && !$es_edicion_afiliado;

$error = '';
$success = '';

// Si es invitación directa o edición admin, ir al paso 3 (formulario)
if ($es_invitacion || $es_edicion_afiliado) {
    $step = 3;
}

// Obtener opciones de entidad para el formulario (selector)
function getEntidadesOptions(): array {
    try {
        $pdo = DB::pdo();
        $columns = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) {
            return [];
        }
        $codeCandidates = ['codigo', 'cod_entidad', 'id', 'code'];
        $nameCandidates = ['nombre', 'descripcion', 'entidad', 'nombre_entidad'];
        $codeCol = null;
        $nameCol = null;
        foreach ($columns as $col) {
            $field = strtolower($col['Field'] ?? $col['field'] ?? '');
            if (!$codeCol && in_array($field, $codeCandidates, true)) {
                $codeCol = $col['Field'] ?? $col['field'];
            }
            if (!$nameCol && in_array($field, $nameCandidates, true)) {
                $nameCol = $col['Field'] ?? $col['field'];
            }
        }
        if (!$codeCol && isset($columns[0]['Field'])) {
            $codeCol = $columns[0]['Field'];
        }
        if (!$nameCol && isset($columns[1]['Field'])) {
            $nameCol = $columns[1]['Field'];
        }
        if (!$codeCol || !$nameCol) {
            return [];
        }
        $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("No se pudo obtener entidades: " . $e->getMessage());
        return [];
    }
}

$entidades_options = getEntidadesOptions();

// Datos para cada paso: todas las entidades (las sin organizaciones se mostrarán inactivas)
$todas_entidades = $landingService->getTodasEntidadesParaRegistro();
if (empty($todas_entidades)) {
    $fallback = $landingService->getEntidadesConOrganizacionesRegistro();
    if (!empty($fallback)) {
        $todas_entidades = array_map(fn($e) => array_merge($e, ['tiene_organizaciones' => true]), $fallback);
    }
}
$organizaciones = $entidad_id > 0 ? $landingService->getOrganizacionesPorEntidad($entidad_id) : [];
$clubes = $org_id > 0 ? $landingService->getClubesPorOrganizacion($org_id) : [];

$entidad_info = null;
$org_info = null;
$club_info = null;

if ($entidad_id > 0) {
    foreach ($todas_entidades as $e) {
        if ((int)$e['id'] === $entidad_id) {
            $entidad_info = $e;
            break;
        }
    }
    if (!$entidad_info) {
        $entidad_info = ['id' => $entidad_id, 'nombre' => "Entidad $entidad_id", 'total_organizaciones' => 0, 'total_clubes' => 0];
    }
}
if ($org_id > 0) {
    foreach ($organizaciones as $o) {
        if ((int)$o['id'] === $org_id) {
            $org_info = $o;
            break;
        }
    }
}
if ($club_id > 0) {
    $org_join = $has_cod_org
        ? "LEFT JOIN organizaciones o ON (c.cod_org = o.id OR c.cod_org = o.cod_org)"
        : "LEFT JOIN organizaciones o ON c.cod_org = o.id";
    $stmt = $pdo->prepare("SELECT c.*, o.entidad as org_entidad FROM clubes c {$org_join} WHERE c.id = ?" . ($es_edicion_afiliado ? '' : ' AND c.estatus = 1'));
    $stmt->execute([$club_id]);
    $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($club_info && !$entidad_id && ($club_info['org_entidad'] ?? $club_info['entidad'] ?? 0) > 0) {
        $entidad_id = (int)($club_info['org_entidad'] ?? $club_info['entidad'] ?? 0);
    }
}

// Cargar afiliado para edición (admin)
if ($es_edicion_afiliado && $club_info && $edit_user_id > 0) {
    $current_admin = Auth::user();
    $admin_id = (int) ($current_admin['id'] ?? 0);
    $role = (string) ($current_admin['role'] ?? '');
    $puede_editar = false;
    if ($role === 'admin_general') {
        $puede_editar = true;
    } elseif ($role === 'admin_club') {
        $puede_editar = ClubHelper::isClubManagedByAdmin($admin_id, $club_id);
    }
    if (!$puede_editar) {
        http_response_code(403);
        die('No tiene permisos para editar afiliados de este club.');
    }
    [$scopeSqlAf, $scopeParamsAf] = ClubHelper::afiliadosMatchSqlAndParams($pdo, $club_info, $club_id);
    $afiliado_edit = ClubHelper::fetchAfiliadoInClub($pdo, $club_info, $club_id, $edit_user_id);
    if (!$afiliado_edit) {
        http_response_code(404);
        die('Afiliado no encontrado en este club.');
    }
    if ($return_url_param === null) {
        $return_url_param = ClubNavigation::detailUrl($club_id, ['from' => 'clubes_asociados']);
    }
}

// Validar club en invitación
if ($es_invitacion && !$club_info) {
    $error = 'El club de la invitación no existe o está inactivo.';
    $step = 1;
    $club_id = 0;
}

// Determinar entidad para el formulario (invitación: del club; flujo normal: POST o del club seleccionado)
$entidad_form = isset($_POST['entidad']) ? (int)$_POST['entidad'] : 0;
if ($entidad_form <= 0 && $club_info) {
    $entidad_form = (int)($club_info['org_entidad'] ?? $club_info['entidad'] ?? 0);
}
if ($entidad_form <= 0 && $entidad_id > 0) {
    $entidad_form = $entidad_id;
}
$entidad_nombre = '';
foreach ($entidades_options as $e) {
    if ((int)($e['codigo'] ?? 0) === $entidad_form || (string)($e['codigo'] ?? '') === (string)$entidad_form) {
        $entidad_nombre = $e['nombre'] ?? "Entidad $entidad_form";
        break;
    }
}
if ($entidad_nombre === '' && $entidad_form > 0) {
    $entidad_nombre = "Entidad $entidad_form";
}

// Procesar registro o actualización de afiliado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $club_id > 0 && $club_info) {
    $post_user_id = (int) ($_POST['user_id'] ?? 0);
    $modo_editar = $post_user_id > 0 && $es_edicion_afiliado;

    if ($modo_editar) {
        CSRF::validate();
        $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
        $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        $sexo = strtoupper(trim($_POST['sexo'] ?? ''));
        if (!in_array($sexo, ['M', 'F', 'O'], true)) {
            $sexo = null;
        }
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $fechnac = trim($_POST['fechnac'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim((string) ($_POST['password'] ?? ''));
        $password_confirm = trim((string) ($_POST['password_confirm'] ?? ''));
        $entidad_form = (int) ($_POST['entidad'] ?? $entidad_form);
        $return_after = ClubNavigation::safeReturnUrl($_POST['return_url'] ?? '') ?? ClubNavigation::detailUrl($club_id, ['from' => 'clubes_asociados']);

        if ($cedula === '' || $nombre === '' || $username === '' || $entidad_form <= 0) {
            $error = 'Todos los campos marcados con * son requeridos';
        } elseif ($password !== '' && strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres';
        } elseif ($password !== '' && $password !== $password_confirm) {
            $error = 'Las contraseñas no coinciden';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El email no es válido';
        } else {
            try {
                [$scopeSqlAf, $scopeParamsAf] = ClubHelper::afiliadosMatchSqlAndParams($pdo, $club_info, $club_id);
                if (! ClubHelper::afiliadoBelongsToClub($pdo, $club_info, $club_id, $post_user_id)) {
                    $error = 'Afiliado no pertenece a este club';
                } else {
                    $stmtDup = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? AND id != ?');
                    $stmtDup->execute([$cedula, $post_user_id]);
                    if ($stmtDup->fetch()) {
                        $error = 'Ya existe otro usuario con esta cédula';
                    } else {
                        $stmtDupU = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? AND id != ?');
                        $stmtDupU->execute([$username, $post_user_id]);
                        if ($stmtDupU->fetch()) {
                            $error = 'El nombre de usuario ya está en uso';
                        } else {
                            $photo_path = (string) ($afiliado_edit['photo_path'] ?? '');
                            $cedula_image_path = (string) ($afiliado_edit['cedula_image_path'] ?? '');
                            try {
                                $newPhoto = register_by_club_upload_image(
                                    $_FILES['photo'] ?? [],
                                    'photos',
                                    $photo_path !== '' ? $photo_path : null,
                                    'user_' . $post_user_id
                                );
                                if ($newPhoto !== null) {
                                    $photo_path = $newPhoto;
                                }
                                if ($has_cedula_image_col) {
                                    $newCedulaImg = register_by_club_upload_image(
                                        $_FILES['cedula_imagen'] ?? [],
                                        'cedulas',
                                        $cedula_image_path !== '' ? $cedula_image_path : null,
                                        'cedula_' . $post_user_id
                                    );
                                    if ($newCedulaImg !== null) {
                                        $cedula_image_path = $newCedulaImg;
                                    }
                                }
                            } catch (RuntimeException $uploadEx) {
                                $error = $uploadEx->getMessage();
                            }
                            $afiliado_edit['photo_path'] = $photo_path;
                            if ($has_cedula_image_col) {
                                $afiliado_edit['cedula_image_path'] = $cedula_image_path;
                            }
                            if ($error === '') {
                            if ($password !== '') {
                                $hash = Security::hashPassword($password);
                                if ($has_cedula_image_col) {
                                    $stmtUp = $pdo->prepare('UPDATE usuarios SET nacionalidad = ?, cedula = ?, nombre = ?, sexo = ?, email = ?, celular = ?, fechnac = ?, username = ?, password_hash = ?, entidad = ?, club_id = ?, photo_path = ?, cedula_image_path = ?, updated_at = NOW() WHERE id = ?');
                                    $stmtUp->execute([
                                        in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                                        $cedula,
                                        $nombre,
                                        $sexo,
                                        $email ?: null,
                                        $celular ?: null,
                                        $fechnac ?: null,
                                        $username,
                                        $hash,
                                        $entidad_form,
                                        $club_id,
                                        $photo_path !== '' ? $photo_path : null,
                                        $cedula_image_path !== '' ? $cedula_image_path : null,
                                        $post_user_id,
                                    ]);
                                } else {
                                    $stmtUp = $pdo->prepare('UPDATE usuarios SET nacionalidad = ?, cedula = ?, nombre = ?, sexo = ?, email = ?, celular = ?, fechnac = ?, username = ?, password_hash = ?, entidad = ?, club_id = ?, photo_path = ?, updated_at = NOW() WHERE id = ?');
                                    $stmtUp->execute([
                                        in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                                        $cedula,
                                        $nombre,
                                        $sexo,
                                        $email ?: null,
                                        $celular ?: null,
                                        $fechnac ?: null,
                                        $username,
                                        $hash,
                                        $entidad_form,
                                        $club_id,
                                        $photo_path !== '' ? $photo_path : null,
                                        $post_user_id,
                                    ]);
                                }
                            } else {
                                if ($has_cedula_image_col) {
                                    $stmtUp = $pdo->prepare('UPDATE usuarios SET nacionalidad = ?, cedula = ?, nombre = ?, sexo = ?, email = ?, celular = ?, fechnac = ?, username = ?, entidad = ?, club_id = ?, photo_path = ?, cedula_image_path = ?, updated_at = NOW() WHERE id = ?');
                                    $stmtUp->execute([
                                        in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                                        $cedula,
                                        $nombre,
                                        $sexo,
                                        $email ?: null,
                                        $celular ?: null,
                                        $fechnac ?: null,
                                        $username,
                                        $entidad_form,
                                        $club_id,
                                        $photo_path !== '' ? $photo_path : null,
                                        $cedula_image_path !== '' ? $cedula_image_path : null,
                                        $post_user_id,
                                    ]);
                                } else {
                                    $stmtUp = $pdo->prepare('UPDATE usuarios SET nacionalidad = ?, cedula = ?, nombre = ?, sexo = ?, email = ?, celular = ?, fechnac = ?, username = ?, entidad = ?, club_id = ?, photo_path = ?, updated_at = NOW() WHERE id = ?');
                                    $stmtUp->execute([
                                        in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                                        $cedula,
                                        $nombre,
                                        $sexo,
                                        $email ?: null,
                                        $celular ?: null,
                                        $fechnac ?: null,
                                        $username,
                                        $entidad_form,
                                        $club_id,
                                        $photo_path !== '' ? $photo_path : null,
                                        $post_user_id,
                                    ]);
                                }
                            }
                            header('Location: ' . $return_after . (str_contains($return_after, '?') ? '&' : '?') . 'success=' . urlencode('Afiliado actualizado correctamente'));
                            exit;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Error al actualizar: ' . $e->getMessage();
            }
        }
        $edit_user_id = $post_user_id;
        $afiliado_edit = $afiliado_edit ?: ['id' => $post_user_id];
        $step = 3;
    } else {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    if (!RateLimiter::canSubmit('register_by_club', 30)) {
        $error = 'Por favor espera 30 segundos antes de intentar registrarte de nuevo.';
    } else {
        CSRF::validate();

        $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
        $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        $sexo = strtoupper(trim($_POST['sexo'] ?? ''));
        if (!in_array($sexo, ['M', 'F', 'O'], true)) {
            $sexo = null;
        }
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $fechnac = trim($_POST['fechnac'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim((string)($_POST['password'] ?? ''));
        $password_confirm = trim((string)($_POST['password_confirm'] ?? ''));
        $entidad_form = (int)($_POST['entidad'] ?? $entidad_form);

        if ($cedula === '' || empty($nombre) || empty($username) || empty($password) || $entidad_form <= 0) {
            $error = 'Todos los campos marcados con * son requeridos (cédula solo números)';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres';
        } elseif ($password !== $password_confirm) {
            $error = 'Las contraseñas no coinciden';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El email no es válido';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ?");
                $stmt->execute([$cedula]);
                if ($stmt->fetch()) {
                    $error = 'Ya existe un usuario registrado con esta cédula';
                } else {
                    $userData = [
                        'username' => $username,
                        'password' => $password,
                        'email' => $email ?: null,
                        'role' => 'usuario',
                        'cedula' => $cedula,
                        'nacionalidad' => in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                        'nombre' => $nombre,
                        'sexo' => $sexo,
                        'celular' => $celular ?: null,
                        'fechnac' => $fechnac ?: null,
                        'club_id' => $club_id,
                        'entidad' => $entidad_form,
                        'status' => 0,
                        '_allow_club_for_usuario' => true,
                    ];

                    $result = Security::createUser($userData);

                    if ($result['success']) {
                        RateLimiter::recordSubmit('register_by_club');
                        session_write_close();
                        header('Location: ' . AppHelpers::url('login.php', ['registered' => '1']));
                        exit;
                    } else {
                        $error = implode(', ', $result['errors']);
                    }
                }
            } catch (Exception $e) {
                $error = 'Error al registrar: ' . $e->getMessage();
            }
        }
    }
    }
    $step = 3;
}

$form_values = [
    'nacionalidad' => 'V',
    'cedula' => '',
    'nombre' => '',
    'sexo' => '',
    'email' => '',
    'celular' => '',
    'username' => '',
    'fechnac' => '',
    'photo_path' => '',
    'cedula_image_path' => '',
];
if ($es_edicion_afiliado && is_array($afiliado_edit)) {
    $form_values = [
        'nacionalidad' => (string) ($afiliado_edit['nacionalidad'] ?? 'V'),
        'cedula' => (string) ($afiliado_edit['cedula'] ?? ''),
        'nombre' => (string) ($afiliado_edit['nombre'] ?? ''),
        'sexo' => (string) ($afiliado_edit['sexo'] ?? ''),
        'email' => (string) ($afiliado_edit['email'] ?? ''),
        'celular' => (string) ($afiliado_edit['celular'] ?? ''),
        'username' => (string) ($afiliado_edit['username'] ?? ''),
        'fechnac' => (string) ($afiliado_edit['fechnac'] ?? ''),
        'photo_path' => (string) ($afiliado_edit['photo_path'] ?? ''),
        'cedula_image_path' => (string) ($afiliado_edit['cedula_image_path'] ?? ''),
    ];
}
$afiliado_photo_url = AppHelpers::imageUrl($form_values['photo_path'] !== '' ? $form_values['photo_path'] : null);
$afiliado_cedula_image_url = AppHelpers::imageUrl($form_values['cedula_image_path'] !== '' ? $form_values['cedula_image_path'] : null);

$landing_url = AppHelpers::url('go_landing.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#1a365d">
    <title><?= htmlspecialchars(Branding::pageTitle($es_edicion_afiliado ? 'Editar afiliado' : 'Registro por Club')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #1a365d; --secondary: #2d3748; --accent: #48bb78; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); min-height: 100vh; padding: 2rem 0; }
        .main-card { background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .header { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 2rem; text-align: center; }
        .header i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.9; }
        .body-content { padding: 2rem; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; flex-wrap: wrap; }
        .step { width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0; color: #718096; display: flex; align-items: center; justify-content: center; font-weight: 600; margin: 0 0.5rem; }
        .step.active { background: var(--accent); color: white; }
        .step.completed { background: var(--primary); color: white; }
        .step-line { width: 40px; height: 3px; background: #e2e8f0; align-self: center; }
        .step-line.completed { background: var(--primary); }
        .select-card { border: 2px solid #e2e8f0; border-radius: 15px; padding: 1.5rem; transition: all 0.3s; cursor: pointer; text-decoration: none; color: inherit; display: block; margin-bottom: 1rem; }
        .select-card:hover { border-color: var(--accent); transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: inherit; }
        .select-card.inactive { opacity: 0.55; cursor: not-allowed; pointer-events: none; background: #f8f9fa; }
        .select-card .card-logo { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary); }
        .select-card .card-logo-placeholder { width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.8rem; }
        .club-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 0.75rem; transition: all 0.3s; cursor: pointer; text-decoration: none; color: inherit; display: block; }
        .club-card:hover { border-color: var(--accent); background: #f7fafc; color: inherit; }
        .info-box { background: #e6fffa; border-left: 4px solid var(--accent); padding: 1rem; border-radius: 0 8px 8px 0; margin-bottom: 1.5rem; }
        .form-control:focus, .form-select:focus { border-color: var(--accent); box-shadow: 0 0 0 0.2rem rgba(72, 187, 120, 0.25); }
        .btn-register { background: linear-gradient(135deg, var(--accent) 0%, #38a169 100%); border: none; padding: 12px; font-weight: 600; }
        .btn-register:hover { background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); }
        .search-status { font-size: 0.85rem; margin-top: 0.5rem; }
        .selected-club-badge { background: var(--accent); color: white; padding: 0.5rem 1rem; border-radius: 20px; display: inline-block; margin-bottom: 1rem; }
        .afiliado-image-box { border: 1px dashed #cbd5e0; border-radius: 12px; padding: 1rem; background: #f8fafc; height: 100%; }
        .afiliado-image-box .current-image { max-height: 140px; object-fit: cover; border-radius: 8px; }
        .afiliado-image-box .image-placeholder { width: 100%; min-height: 120px; border-radius: 8px; background: #edf2f7; display: flex; align-items: center; justify-content: center; color: #a0aec0; font-size: 2rem; margin-bottom: 0.5rem; }
        @media (max-width: 576px) { .step-line { width: 20px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-md-10">
                <div class="main-card">
                    <div class="header">
                        <i class="fas fa-user-plus"></i>
                        <h3 class="mb-1">Registro de Jugador</h3>
                        <p class="mb-0 opacity-75">Selecciona tu club y únete a la comunidad</p>
                    </div>

                    <div class="body-content">
                        <?php if (!$es_invitacion): ?>
                        <div class="step-indicator">
                            <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">1</div>
                            <div class="step-line <?= $step > 1 ? 'completed' : '' ?>"></div>
                            <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">2</div>
                            <div class="step-line <?= $step > 2 ? 'completed' : '' ?>"></div>
                            <div class="step <?= $step >= 3 ? 'active' : '' ?>">3</div>
                        </div>
                        <?php endif; ?>

                        <?php if ($step === 1 && !$es_invitacion): ?>
                        <!-- PASO 1: Todas las entidades (inactivas las que no tienen organizaciones) -->
                        <div class="mb-4">
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Inicio</a>
                        </div>
                        <h5 class="text-center mb-4"><i class="fas fa-map-marker-alt me-2"></i>Selecciona tu Entidad</h5>
                        <p class="text-center text-muted mb-4">Elige la entidad (estado/región) donde te encuentras. Las entidades sin organizaciones aparecen deshabilitadas.</p>

                        <?php if (empty($todas_entidades)): ?>
                            <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle me-2"></i>No hay entidades disponibles.</div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($todas_entidades as $ent): ?>
                                    <?php $activa = !empty($ent['tiene_organizaciones']); ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <?php if ($activa): ?>
                                        <a href="?step=2&entidad=<?= (int)$ent['id'] ?>" class="select-card" title="Seleccionar">
                                        <?php else: ?>
                                        <div class="select-card inactive" title="Sin organizaciones registradas">
                                        <?php endif; ?>
                                            <div class="d-flex align-items-center">
                                                <div class="card-logo-placeholder me-3"><i class="fas fa-map-marker-alt"></i></div>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?= htmlspecialchars($ent['nombre'] ?? '') ?></h6>
                                                    <?php if ($activa): ?>
                                                    <span class="badge bg-primary me-1"><?= (int)($ent['total_organizaciones'] ?? 0) ?> org.</span>
                                                    <span class="badge bg-success"><?= (int)($ent['total_clubes'] ?? 0) ?> clubes</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">Sin organizaciones</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($activa): ?><i class="fas fa-chevron-right text-muted"></i><?php else: ?><i class="fas fa-lock text-muted"></i><?php endif; ?>
                                            </div>
                                        <?php if ($activa): ?></a><?php else: ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php elseif ($step === 2 && $entidad_id > 0 && $entidad_info && !$es_invitacion): ?>
                        <!-- PASO 2: Organizaciones de la entidad -->
                        <div class="mb-4 d-flex flex-wrap gap-2">
                            <a href="?step=1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Cambiar Entidad</a>
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Inicio</a>
                        </div>
                        <h5 class="text-center mb-2"><i class="fas fa-building me-2"></i>Organizaciones en <?= htmlspecialchars($entidad_info['nombre'] ?? '') ?></h5>
                        <p class="text-center text-muted mb-4">Selecciona la organización a la que deseas afiliarte</p>

                        <?php if (empty($organizaciones)): ?>
                            <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle me-2"></i>No hay organizaciones disponibles.</div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($organizaciones as $org): ?>
                                    <div class="col-md-6 mb-3">
                                        <a href="?step=3&entidad=<?= $entidad_id ?>&org=<?= (int)$org['id'] ?>" class="select-card">
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($org['logo'])): ?>
                                                    <img src="<?= htmlspecialchars(AppHelpers::imageUrl($org['logo'])) ?>" alt="" class="card-logo me-3">
                                                <?php else: ?>
                                                    <div class="card-logo-placeholder me-3"><i class="fas fa-building"></i></div>
                                                <?php endif; ?>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?= htmlspecialchars($org['nombre'] ?? '') ?></h6>
                                                    <?php if (!empty($org['responsable'])): ?><small class="text-muted d-block"><?= htmlspecialchars($org['responsable']) ?></small><?php endif; ?>
                                                    <span class="badge bg-primary me-1"><?= (int)($org['total_clubes'] ?? 0) ?> clubes</span>
                                                    <span class="badge bg-success"><?= (int)($org['torneos_activos'] ?? 0) ?> torneos</span>
                                                </div>
                                                <i class="fas fa-chevron-right text-muted"></i>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php elseif ($step === 3 && (($org_id > 0 && $org_info && !$es_invitacion && !$es_edicion_afiliado) || ($club_info && ($es_invitacion || $es_edicion_afiliado)))): ?>
                        <!-- PASO 3: Clubes + Formulario (o solo formulario si es invitación) -->
                        <?php if ($es_invitacion): ?>
                        <div class="mb-4">
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver al Inicio</a>
                        </div>
                        <?php elseif ($es_edicion_afiliado && $return_url_param):
                            $fromRet = 'clubs';
                            if (preg_match('/[?&]from=([^&]+)/', $return_url_param, $mRet)) {
                                $fromRet = urldecode($mRet[1]);
                            }
                        ?>
                        <div class="mb-4">
                            <a href="<?= htmlspecialchars($return_url_param) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i><?= htmlspecialchars(ClubNavigation::returnLabelFromRequest(['from' => $fromRet])) ?></a>
                        </div>
                        <?php else: ?>
                        <div class="mb-4 d-flex flex-wrap gap-2">
                            <?php if ($club_info): ?>
                            <a href="?step=3&entidad=<?= $entidad_id ?>&org=<?= $org_id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver a clubes</a>
                            <?php endif; ?>
                            <a href="?step=2&entidad=<?= $entidad_id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Cambiar Organización</a>
                            <a href="?step=1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Cambiar Entidad</a>
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home me-1"></i>Volver al Inicio</a>
                        </div>
                        <h5 class="text-center mb-2"><i class="fas fa-users me-2"></i>Clubes de <?= htmlspecialchars($org_info['nombre'] ?? '') ?></h5>
                        <p class="text-center text-muted mb-4">Selecciona el club donde deseas registrarte</p>

                        <?php if (empty($clubes)): ?>
                            <div class="alert alert-warning text-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>No hay clubes disponibles.</div>
                        <?php else: ?>
                            <div class="row mb-4">
                                <?php foreach ($clubes as $c): ?>
                                    <?php
                                    $url_club = "?step=3&entidad={$entidad_id}&org={$org_id}&club_id=" . (int)$c['id'];
                                    $es_seleccionado = (int)$c['id'] === (int)$club_id;
                                    ?>
                                    <div class="col-md-6 mb-2">
                                        <a href="<?= htmlspecialchars($url_club) ?>" class="club-card <?= $es_seleccionado ? 'border-primary bg-light' : '' ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?= htmlspecialchars($c['nombre']) ?></h6>
                                                    <?php if (!empty($c['delegado'])): ?><small class="text-muted"><?= htmlspecialchars($c['delegado']) ?></small><?php endif; ?>
                                                </div>
                                                <span class="badge bg-success"><?= (int)($c['torneos_activos'] ?? 0) ?> torneos</span>
                                                <?php if ($es_seleccionado): ?><i class="fas fa-check text-success"></i><?php else: ?><i class="fas fa-chevron-right text-muted"></i><?php endif; ?>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($club_info): ?>
                        <div class="selected-club-badge">
                            <i class="fas fa-building me-2"></i><?= htmlspecialchars($club_info['nombre']) ?>
                            <?php if (!empty($club_info['delegado'])): ?><br><small class="opacity-90"><i class="fas fa-user me-1"></i><?= htmlspecialchars($club_info['delegado']) ?></small><?php endif; ?>
                        </div>

                        <?php if ($es_edicion_afiliado): ?>
                        <div class="info-box">
                            <i class="fas fa-user-edit text-success me-2"></i>
                            <strong>Editar afiliado:</strong> Actualice los datos del jugador en este club.
                        </div>
                        <?php elseif ($es_invitacion): ?>
                        <div class="info-box">
                            <i class="fas fa-info-circle text-success me-2"></i>
                            <strong>Invitación directa:</strong> Te registras en este club. Podrás participar en cualquier torneo de la plataforma.
                        </div>
                        <?php else: ?>
                        <div class="info-box">
                            <i class="fas fa-info-circle text-success me-2"></i>
                            Aunque te registres en este club, podrás participar en cualquier torneo de toda la plataforma sin restricciones.
                        </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                                    <a href="index.php" class="btn btn-success"><i class="fas fa-user me-1"></i>Ingresar a mi Perfil</a>
                                    <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-primary"><i class="fas fa-home me-1"></i>Retornar al Landing</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="POST" id="registerForm"<?= $es_edicion_afiliado ? ' enctype="multipart/form-data"' : '' ?>>
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                <input type="hidden" name="fechnac" id="fechnac" value="<?= htmlspecialchars($form_values['fechnac']) ?>">
                                <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">
                                <?php if ($es_edicion_afiliado && $edit_user_id > 0): ?>
                                    <input type="hidden" name="user_id" value="<?= (int) $edit_user_id ?>">
                                    <?php if ($return_url_param): ?>
                                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url_param, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                <?php endif; ?>

                                <h6 class="text-muted mb-3"><i class="fas fa-user me-2"></i>Datos Personales</h6>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Nacionalidad *</label>
                                        <select name="nacionalidad" id="nacionalidad" class="form-select" required>
                                            <option value="V" <?= $form_values['nacionalidad'] === 'V' ? 'selected' : '' ?>>V</option>
                                            <option value="E" <?= $form_values['nacionalidad'] === 'E' ? 'selected' : '' ?>>E</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Cédula *</label>
                                        <input type="text" name="cedula" id="cedula" class="form-control" value="<?= htmlspecialchars($form_values['cedula']) ?>" <?= $es_edicion_afiliado ? 'readonly' : 'onblur="debouncedBuscarPersona()"' ?> required>
                                        <?php if (!$es_edicion_afiliado): ?><div id="busqueda_resultado" class="search-status"></div><?php endif; ?>
                                    </div>
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label">Nombre Completo *</label>
                                        <input type="text" name="nombre" id="nombre" class="form-control" value="<?= htmlspecialchars($form_values['nombre']) ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="sexo" class="form-label">Sexo *</label>
                                        <select name="sexo" id="sexo" class="form-select" required>
                                            <option value="">-- Seleccionar --</option>
                                            <option value="M" <?= $form_values['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
                                            <option value="F" <?= $form_values['sexo'] === 'F' ? 'selected' : '' ?>>Femenino</option>
                                            <option value="O" <?= $form_values['sexo'] === 'O' ? 'selected' : '' ?>>Otro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($form_values['email']) ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Celular</label>
                                        <input type="text" name="celular" id="celular" class="form-control" value="<?= htmlspecialchars($form_values['celular']) ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Entidad (Ubicación)</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($entidad_nombre) ?>" readonly>
                                    <input type="hidden" name="entidad" value="<?= (int)$entidad_form ?>">
                                </div>

                                <?php if ($es_edicion_afiliado): ?>
                                <hr class="my-4">
                                <h6 class="text-muted mb-3"><i class="fas fa-images me-2"></i>Foto y documento de identidad</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6 mb-3">
                                        <div class="afiliado-image-box">
                                            <label class="form-label" for="photo-input"><i class="fas fa-camera me-1"></i>Foto del jugador</label>
                                            <?php if ($afiliado_photo_url): ?>
                                                <div class="mb-2 text-center">
                                                    <img src="<?= htmlspecialchars($afiliado_photo_url) ?>" alt="Foto actual" class="img-thumbnail current-image">
                                                    <div class="small text-muted mt-1">Imagen actual</div>
                                                </div>
                                            <?php else: ?>
                                                <div class="image-placeholder mb-2"><i class="fas fa-user"></i></div>
                                            <?php endif; ?>
                                            <input type="file" name="photo" id="photo-input" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp" data-preview-target="afiliado-photo-preview">
                                            <div id="afiliado-photo-preview"></div>
                                            <small class="text-muted d-block mt-1">JPG, PNG, GIF o WebP. Máximo 2MB.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="afiliado-image-box">
                                            <label class="form-label" for="cedula-imagen-input"><i class="fas fa-id-card me-1"></i>Imagen de cédula</label>
                                            <?php if ($afiliado_cedula_image_url): ?>
                                                <div class="mb-2 text-center">
                                                    <img src="<?= htmlspecialchars($afiliado_cedula_image_url) ?>" alt="Cédula actual" class="img-thumbnail current-image">
                                                    <div class="small text-muted mt-1">Documento actual</div>
                                                </div>
                                            <?php else: ?>
                                                <div class="image-placeholder mb-2"><i class="fas fa-id-card"></i></div>
                                            <?php endif; ?>
                                            <input type="file" name="cedula_imagen" id="cedula-imagen-input" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp" data-preview-target="afiliado-cedula-preview">
                                            <div id="afiliado-cedula-preview"></div>
                                            <small class="text-muted d-block mt-1">Foto o escaneo de la cédula. Máximo 2MB.</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <hr class="my-4">
                                <h6 class="text-muted mb-3"><i class="fas fa-key me-2"></i>Credenciales de Acceso</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nombre de Usuario *</label>
                                        <input type="text" name="username" id="username" class="form-control" value="<?= htmlspecialchars($form_values['username']) ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contraseña <?= $es_edicion_afiliado ? '' : '*' ?></label>
                                        <input type="password" name="password" id="password" class="form-control" <?= $es_edicion_afiliado ? '' : 'required' ?>>
                                        <small class="text-muted"><?= $es_edicion_afiliado ? 'Dejar vacío para mantener la actual' : 'Mínimo 6 caracteres' ?></small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Confirmar <?= $es_edicion_afiliado ? '' : '*' ?></label>
                                        <input type="password" name="password_confirm" id="password_confirm" class="form-control" <?= $es_edicion_afiliado ? '' : 'required' ?>>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-register btn-primary w-100 mt-3">
                                    <?php if ($es_edicion_afiliado): ?>
                                        <i class="fas fa-save me-2"></i>Guardar cambios del afiliado
                                    <?php else: ?>
                                        <i class="fas fa-user-plus me-2"></i>Registrarme en <?= htmlspecialchars($club_info['nombre']) ?>
                                    <?php endif; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php elseif ($step === 3 && !$club_info && !$es_invitacion): ?>
                        <p class="text-muted text-center">Debes seleccionar una entidad, organización y club para registrarte.</p>
                        <div class="text-center">
                            <a href="?step=1" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-1"></i>Empezar de nuevo</a>
                        </div>
                        <?php endif; ?>

                        <div class="text-center mt-4 pt-3 border-top">
                            <?php if (!$es_edicion_afiliado): ?>
                            <p class="text-muted mb-2">¿Ya tienes cuenta?</p>
                            <a href="login.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-sign-in-alt me-1"></i>Iniciar Sesión</a>
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars($landing_url) ?>" class="btn btn-outline-secondary btn-sm <?= $es_edicion_afiliado ? '' : 'ms-2' ?>"><i class="fas fa-home me-1"></i>Inicio</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= htmlspecialchars(rtrim($base_url ?? app_base_url(), '/') . '/assets/form-utils.js') ?>" defer></script>
    <?php if ($es_edicion_afiliado): ?>
    <script src="<?= htmlspecialchars(rtrim($base_url ?? app_base_url(), '/') . '/assets/image-preview.js') ?>" defer></script>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('registerForm');
        if (form) {
            ['cedula','nombre','email','celular','username','password','password_confirm','fechnac'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.value = el.value || '';
            });
            const nac = document.getElementById('nacionalidad');
            if (nac) nac.value = nac.value || 'V';
            const busquedaResultado = document.getElementById('busqueda_resultado');
            if (busquedaResultado) busquedaResultado.innerHTML = '';
            if (typeof preventDoubleSubmit === 'function') preventDoubleSubmit(form);
            if (typeof initCedulaValidation === 'function') initCedulaValidation('cedula');
            if (typeof initEmailValidation === 'function') initEmailValidation('email');
        }
    });
    const debouncedBuscarPersona = typeof debounce === 'function' ? debounce(buscarPersona, 400) : buscarPersona;
    function buscarPersona() {
        const cedula = document.getElementById('cedula');
        const resultadoDiv = document.getElementById('busqueda_resultado');
        if (!cedula || !cedula.value.trim()) { if (resultadoDiv) resultadoDiv.innerHTML = ''; return; }
        resultadoDiv.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Buscando...</span>';
        const nacionalidad = (document.getElementById('nacionalidad') || {}).value || 'V';
        fetch('<?= htmlspecialchars(rtrim($base_url ?? app_base_url(), '/')) ?>/public/api/search_user_persona.php?cedula=' + encodeURIComponent(cedula.value) + '&nacionalidad=' + encodeURIComponent(nacionalidad))
            .then(r => r.json())
            .then(data => {
                if (data?.success && data?.data?.encontrado) {
                    if (data.data.existe_usuario) {
                        resultadoDiv.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>' + (data.data.mensaje || '') + '</span>';
                    } else {
                        const p = data.data.persona || {};
                        if (cedula) cedula.value = String(p.cedula || cedula.value).replace(/\D/g, '');
                        const nacEl = document.getElementById('nacionalidad'); if (nacEl && p.nacionalidad) nacEl.value = ['V','E','J','P'].includes(String(p.nacionalidad).toUpperCase()) ? String(p.nacionalidad).toUpperCase() : nacEl.value;
                        const nom = document.getElementById('nombre'); if (nom) nom.value = p.nombre || '';
                        const cel = document.getElementById('celular'); if (cel) cel.value = p.celular || '';
                        const em = document.getElementById('email'); if (em) em.value = p.email || '';
                        const fech = document.getElementById('fechnac'); if (fech) fech.value = p.fechnac || '';
                        const sexo = document.getElementById('sexo'); if (sexo && p.sexo) { let sv = String(p.sexo).toUpperCase(); sexo.value = (sv === 'M' || sv === 'F' || sv === 'O') ? sv : sexo.value; }
                        const un = document.getElementById('username'); if (un && !un.value && p.nombre) { const np = p.nombre.toLowerCase().split(' '); if (np.length >= 2) un.value = np[0] + '.' + np[np.length-1]; }
                        resultadoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Datos completados</span>';
                    }
                } else {
                    resultadoDiv.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>Complete manualmente</span>';
                }
            })
            .catch(() => { if (resultadoDiv) resultadoDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Error</span>'; });
    }
    </script>
</body>
</html>
