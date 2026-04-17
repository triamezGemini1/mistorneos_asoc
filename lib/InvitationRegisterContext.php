<?php
/**
 * Lógica de negocio para el formulario de inscripción por invitación.
 * Solo datos: validación de token/invitación, carga de torneo/club/organizador,
 * permisos y listado de inscritos. Sin HTML.
 *
 * No modifica validaciones de token ni de usuario existentes.
 */
declare(strict_types=1);

class InvitationRegisterContext
{
    /**
     * Carga el contexto completo para la vista de invitación (GET).
     * Usa $_GET para token, torneo, club.
     *
     * @return array{error_message: string, success_message: string, error_acceso: bool, invitation_data: ?array, tournament_data: ?array, club_data: ?array, organizer_club_data: ?array, inscripciones_abiertas: bool, is_admin_general: bool, is_admin_torneo: bool, is_admin_club: bool, stand_by: bool, form_enabled: bool, existing_registrations: array, base: string, url_retorno: string, texto_retorno: string, torneo_id: string|int, club_id: string|int, token: string}
     */
    public static function load(): array
    {
        $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
        $torneo_id = $_GET['torneo'] ?? $_POST['torneo_id'] ?? $_POST['torneo'] ?? '';
        $club_id = $_GET['club'] ?? $_POST['club_id'] ?? $_POST['club'] ?? '';

        $error_message = '';
        $success_message = '';
        $error_acceso = false;
        $invitation_data = null;
        $tournament_data = null;
        $club_data = null;
        $organizer_club_data = null;
        $inscripciones_abiertas = false;
        $is_admin_general = false;
        $is_admin_torneo = false;
        $club_data_from_directorio = null;

        $tb_inv = defined('TABLE_INVITATIONS') ? TABLE_INVITATIONS : 'invitaciones';
        if (!empty($token) && strlen($token) >= 32 && (empty($torneo_id) || empty($club_id))) {
            try {
                $stmt = DB::pdo()->prepare("SELECT torneo_id, club_id FROM {$tb_inv} WHERE token = ? AND (estado = 0 OR estado = 1 OR estado = 'activa' OR estado = 'vinculado') LIMIT 1");
                $stmt->execute([$token]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $torneo_id = $row['torneo_id'];
                    $club_id = $row['club_id'];
                }
            } catch (Throwable $e) {
                $torneo_id = '';
                $club_id = '';
            }
        }

        // Si el token no devolvió torneo/club pero el usuario es admin, permitir acceso buscando por token sin filtrar por estado (invitación expirada/inactiva)
        if ((empty($torneo_id) || empty($club_id)) && !empty($token) && strlen($token) >= 32) {
            $current_user = Auth::user();
            $is_admin = $current_user && (Auth::isAdminGeneral() || in_array($current_user['role'], ['admin_torneo', 'admin_club'], true));
            if ($is_admin) {
                try {
                    $stmt = DB::pdo()->prepare("SELECT torneo_id, club_id FROM {$tb_inv} WHERE token = ? LIMIT 1");
                    $stmt->execute([$token]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $torneo_id = $row['torneo_id'];
                        $club_id = $row['club_id'];
                    }
                } catch (Throwable $e) {
                    // ignorar
                }
            }
        }

        $sin_token_ni_ids = (empty($token) && empty($torneo_id) && empty($club_id));
        $token_invalido = (!empty($token) && (strlen($token) < 32 || (empty($torneo_id) && empty($club_id))));

        if (empty($torneo_id) || empty($club_id)) {
            if ($sin_token_ni_ids) {
                $error_message = "Debes acceder mediante el enlace de tu invitación (correo o mensaje del organizador del torneo).";
            } elseif ($token_invalido) {
                $error_message = "El enlace de invitación no es válido o ha expirado. Solicita uno nuevo al organizador.";
            } else {
                $error_message = "Parámetros de acceso inválidos. Usa el enlace completo de tu invitación.";
            }
            $error_acceso = true;
            return self::buildReturn(
                $error_message,
                $success_message,
                $error_acceso,
                $invitation_data,
                $tournament_data,
                $club_data,
                $organizer_club_data,
                $inscripciones_abiertas,
                $is_admin_general,
                $is_admin_torneo,
                false,
                false,
                false,
                [],
                $torneo_id,
                $club_id,
                $token,
                '',
                '',
                'Volver al inicio',
                null
            );
        }

        try {
            $stmt = DB::pdo()->prepare("
                SELECT i.*, t.nombre as tournament_name, t.fechator, t.clase, t.modalidad, t.club_responsable,
                       COALESCE(t.es_evento_masivo, 0) AS es_evento_masivo,
                       t.cuenta_id,
                       c.nombre as club_name, c.direccion, c.delegado, c.telefono, c.email, c.logo as club_logo,
                       COALESCE(c.delegado_user_id, 0) AS club_delegado_user_id
                FROM {$tb_inv} i
                LEFT JOIN tournaments t ON i.torneo_id = t.id
                LEFT JOIN clubes c ON i.club_id = c.id
                WHERE i.torneo_id = ? AND i.club_id = ? AND (i.estado = 0 OR i.estado = 1 OR i.estado = 'activa' OR i.estado = 'vinculado')
            ");
            $stmt->execute([$torneo_id, $club_id]);
            $invitation_data = $stmt->fetch();

            if (!$invitation_data) {
                $tid_int = (int) $torneo_id;
                $current_user = Auth::user();
                $is_admin = $current_user && (Auth::isAdminGeneral() || in_array($current_user['role'], ['admin_torneo', 'admin_club'], true));
                $can_view_as_admin = $tid_int > 0 && $is_admin && Auth::canAccessTournament($tid_int);
                if ($can_view_as_admin) {
                    $pdo = DB::pdo();
                    $stmt_t = $pdo->prepare("SELECT id, nombre, fechator, clase, modalidad, club_responsable, COALESCE(es_evento_masivo, 0) AS es_evento_masivo, cuenta_id FROM tournaments WHERE id = ? LIMIT 1");
                    $stmt_t->execute([$tid_int]);
                    $tournament_row = $stmt_t->fetch(PDO::FETCH_ASSOC);
                    $stmt_c = $pdo->prepare("SELECT id, nombre, direccion, delegado, telefono, email, logo FROM clubes WHERE id = ? LIMIT 1");
                    $stmt_c->execute([(int) $club_id]);
                    $club_row = $stmt_c->fetch(PDO::FETCH_ASSOC);
                    if ($tournament_row && $club_row) {
                        $organizer_id = (int) ($tournament_row['club_responsable'] ?? 0);
                        $organizer_club_data = null;
                        if ($organizer_id > 0) {
                            $st_o = $pdo->prepare("SELECT nombre, logo, direccion, delegado, telefono, email FROM clubes WHERE id = ? LIMIT 1");
                            $st_o->execute([$organizer_id]);
                            $organizer_club_data = $st_o->fetch(PDO::FETCH_ASSOC);
                        }
                        $tournament_data = [
                            'id' => $tournament_row['id'],
                            'nombre' => $tournament_row['nombre'],
                            'fechator' => $tournament_row['fechator'],
                            'clase' => $tournament_row['clase'] ?? '',
                            'modalidad' => $tournament_row['modalidad'] ?? '',
                            'es_evento_masivo' => (int)($tournament_row['es_evento_masivo'] ?? 0),
                            'cuenta_id' => (int)($tournament_row['cuenta_id'] ?? 0)
                        ];
                        $club_data = [
                            'id' => $club_row['id'],
                            'nombre' => $club_row['nombre'],
                            'direccion' => $club_row['direccion'] ?? '',
                            'delegado' => $club_row['delegado'] ?? '',
                            'telefono' => $club_row['telefono'] ?? '',
                            'email' => $club_row['email'] ?? '',
                            'logo' => $club_row['logo'] ?? null
                        ];
                        $existing_registrations = [];
                        try {
                            $st_ins = $pdo->prepare("
                                SELECT i.id, i.id_usuario, i.fecha_inscripcion as created_at,
                                       u.cedula, u.nombre, u.sexo, u.username,
                                       COALESCE(u.celular, '') as celular,
                                       u.email
                                FROM inscritos i
                                JOIN usuarios u ON i.id_usuario = u.id
                                WHERE i.torneo_id = ? AND i.id_club = ?
                                ORDER BY i.fecha_inscripcion DESC
                            ");
                            $st_ins->execute([$tid_int, (int) $club_id]);
                            $existing_registrations = $st_ins->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Throwable $e) {
                            // ignorar
                        }
                        $base = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : ($GLOBALS['APP_CONFIG']['app']['base_url'] ?? ''), '/');
                        $url_retorno = ($base !== '') ? $base . '/index.php?page=invitations' : 'index.php?page=invitations';
                        $is_admin_general = $current_user && Auth::isAdminGeneral();
                        $is_admin_torneo = $current_user && $current_user['role'] === 'admin_torneo';
                        $is_admin_club = $current_user && $current_user['role'] === 'admin_club';
                        unset($_SESSION['invitation_token'], $_SESSION['invitation_club_name']);
                        if (isset($_SESSION['url_retorno']) && strpos((string)$_SESSION['url_retorno'], 'invitation/register') !== false) {
                            $_SESSION['url_retorno'] = $url_retorno;
                        }
                        return self::buildReturn(
                            '',
                            $success_message,
                            false,
                            null,
                            $tournament_data,
                            $club_data,
                            $organizer_club_data ?: null,
                            true,
                            $is_admin_general,
                            $is_admin_torneo,
                            $is_admin_club,
                            false,
                            true,
                            $existing_registrations,
                            $torneo_id,
                            $club_id,
                            $token,
                            $base,
                            $url_retorno,
                            'Volver a Invitaciones',
                            $current_user
                        );
                    }
                }
                $error_message = "Invitación no válida";
                $error_acceso = true;
                return self::buildReturn(
                    $error_message,
                    $success_message,
                    $error_acceso,
                    null,
                    null,
                    null,
                    null,
                    false,
                    false,
                    false,
                    false,
                    false,
                    [],
                    $torneo_id,
                    $club_id,
                    $token
                );
            }

            $id_directorio_club_inv = isset($invitation_data['id_directorio_club']) ? (int)$invitation_data['id_directorio_club'] : 0;
            $club_tiene_usuario = (int)($invitation_data['club_delegado_user_id'] ?? 0) > 0;
            try {
                $pdo = DB::pdo();
                $has_id_usuario = (bool) @$pdo->query("SHOW COLUMNS FROM directorio_clubes LIKE 'id_usuario'")->fetch();
                $sel_dc = $has_id_usuario ? "id, nombre, direccion, delegado, telefono, email, logo, id_usuario" : "id, nombre, direccion, delegado, telefono, email, logo";
                if ($id_directorio_club_inv > 0) {
                    $st_dc = $pdo->prepare("SELECT {$sel_dc} FROM directorio_clubes WHERE id = ? LIMIT 1");
                    $st_dc->execute([$id_directorio_club_inv]);
                    $club_data_from_directorio = $st_dc->fetch(PDO::FETCH_ASSOC);
                }
                if (!$club_data_from_directorio && ($invitation_data['club_name'] ?? '') !== '') {
                    $st_dc = $pdo->prepare("SELECT {$sel_dc} FROM directorio_clubes WHERE TRIM(nombre) = TRIM(?) LIMIT 1");
                    $st_dc->execute([$invitation_data['club_name']]);
                    $club_data_from_directorio = $st_dc->fetch(PDO::FETCH_ASSOC);
                }
                if ($club_data_from_directorio && $has_id_usuario && !empty($club_data_from_directorio['id_usuario'])) {
                    $club_tiene_usuario = (int)$club_data_from_directorio['id_usuario'] > 0;
                }
            } catch (Throwable $e) {
                // ignorar
            }

            $id_vinculado = isset($invitation_data['id_usuario_vinculado']) ? (int)$invitation_data['id_usuario_vinculado'] : 0;
            $current_user = Auth::user();
            $is_admin_general = $current_user && Auth::isAdminGeneral();
            $is_admin_torneo = $current_user && $current_user['role'] === 'admin_torneo';
            $is_admin_club = $current_user && $current_user['role'] === 'admin_club';
            $es_usuario_vinculado = $current_user && $id_vinculado > 0 && (int)$current_user['id'] === $id_vinculado;
            $stand_by = false;

            if ($is_admin_general || $is_admin_torneo || $is_admin_club) {
                unset($_SESSION['invitation_token'], $_SESSION['invitation_club_name']);
                $base_admin = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : ($GLOBALS['APP_CONFIG']['app']['base_url'] ?? ''), '/');
                $url_invitations_admin = ($base_admin !== '') ? $base_admin . '/index.php?page=invitations' : 'index.php?page=invitations';
                if (isset($_SESSION['url_retorno']) && strpos((string)$_SESSION['url_retorno'], 'invitation/register') !== false) {
                    $_SESSION['url_retorno'] = $url_invitations_admin;
                }
            }

            if (!$current_user) {
                $base = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : (rtrim(($GLOBALS['APP_CONFIG']['app']['base_url'] ?? ''), '/') ?: '');
                if ($base !== '' && !empty($token)) {
                    $url_retorno_full = $base . '/invitation/register?' . http_build_query(['token' => $token, 'torneo' => $torneo_id, 'club' => $club_id]);
                    $_SESSION['url_retorno'] = $url_retorno_full;
                    $_SESSION['invitation_token'] = $token;
                    $_SESSION['invitation_club_name'] = $invitation_data['club_name'] ?? 'Club';
                    if (!headers_sent()) {
                        setcookie('invitation_token', $token, time() + (7 * 86400), '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', true);
                    }
                    if (!$club_tiene_usuario) {
                        header('Location: ' . $base . '/join?token=' . urlencode($token));
                        exit;
                    }
                }
                $stand_by = true;
                $now = new DateTime();
                $start_date = new DateTime($invitation_data['acceso1']);
                $end_date = new DateTime($invitation_data['acceso2']);
                $stmt_organizer = DB::pdo()->prepare("SELECT nombre, logo, direccion, delegado, telefono, email FROM clubes WHERE id = ?");
                $stmt_organizer->execute([$invitation_data['club_responsable']]);
                $organizer_club_data = $stmt_organizer->fetch();
                $tournament_data = [
                    'id' => $invitation_data['torneo_id'],
                    'nombre' => $invitation_data['tournament_name'],
                    'fechator' => $invitation_data['fechator'],
                    'clase' => $invitation_data['clase'],
                    'modalidad' => $invitation_data['modalidad'],
                    'es_evento_masivo' => (int)($invitation_data['es_evento_masivo'] ?? 0),
                    'cuenta_id' => (int)($invitation_data['cuenta_id'] ?? 0)
                ];
                $club_data = [
                    'id' => $invitation_data['club_id'],
                    'nombre' => $club_data_from_directorio['nombre'] ?? $invitation_data['club_name'] ?? 'Club invitado',
                    'direccion' => $club_data_from_directorio['direccion'] ?? $invitation_data['direccion'] ?? '',
                    'delegado' => $club_data_from_directorio['delegado'] ?? $invitation_data['invitado_delegado'] ?? $invitation_data['club_delegado'] ?? $invitation_data['delegado'] ?? '',
                    'telefono' => $club_data_from_directorio['telefono'] ?? $invitation_data['club_telefono'] ?? $invitation_data['telefono'] ?? '',
                    'email' => $club_data_from_directorio['email'] ?? $invitation_data['invitado_email'] ?? $invitation_data['club_email'] ?? $invitation_data['email'] ?? '',
                    'logo' => $club_data_from_directorio['logo'] ?? $invitation_data['club_logo'] ?? null
                ];
                $inscripciones_abiertas = ($now >= $start_date && $now <= $end_date);
            } elseif (!$is_admin_general && !$is_admin_torneo && !$is_admin_club && $id_vinculado > 0 && (int)($current_user['id']) !== $id_vinculado) {
                $error_message = "Esta invitación ya está siendo gestionada por otro delegado.";
                $error_acceso = true;
                $invitation_data = null;
                $base = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : ($GLOBALS['APP_CONFIG']['app']['base_url'] ?? ''), '/');
                $url_retorno = ($base !== '') ? $base . '/index.php?page=invitations' : 'index.php?page=invitations';
                return self::buildReturn(
                    $error_message,
                    $success_message,
                    $error_acceso,
                    null,
                    null,
                    null,
                    null,
                    false,
                    $is_admin_general,
                    $is_admin_torneo,
                    $is_admin_club,
                    false,
                    false,
                    [],
                    $torneo_id,
                    $club_id,
                    $token,
                    $base,
                    $url_retorno,
                    'Volver a Invitaciones',
                    $current_user
                );
            } else {
                $stand_by = false;
                $now = new DateTime();
                $start_date = new DateTime($invitation_data['acceso1']);
                $end_date = new DateTime($invitation_data['acceso2']);
                if ($now < $start_date) {
                    $error_message = "El período de inscripción aún no ha comenzado";
                    $error_acceso = true;
                } elseif ($now > $end_date) {
                    $error_message = "El período de inscripción ha expirado";
                    $error_acceso = true;
                }
                $stmt_organizer = DB::pdo()->prepare("SELECT nombre, logo, direccion, delegado, telefono, email FROM clubes WHERE id = ?");
                $stmt_organizer->execute([$invitation_data['club_responsable']]);
                $organizer_club_data = $stmt_organizer->fetch();
                $tournament_data = [
                    'id' => $invitation_data['torneo_id'],
                    'nombre' => $invitation_data['tournament_name'],
                    'fechator' => $invitation_data['fechator'],
                    'clase' => $invitation_data['clase'],
                    'modalidad' => $invitation_data['modalidad'],
                    'es_evento_masivo' => (int)($invitation_data['es_evento_masivo'] ?? 0),
                    'cuenta_id' => (int)($invitation_data['cuenta_id'] ?? 0)
                ];
                $club_data = [
                    'id' => $invitation_data['club_id'],
                    'nombre' => $club_data_from_directorio['nombre'] ?? $invitation_data['club_name'] ?? 'Club invitado',
                    'direccion' => $club_data_from_directorio['direccion'] ?? $invitation_data['direccion'] ?? '',
                    'delegado' => $club_data_from_directorio['delegado'] ?? $invitation_data['invitado_delegado'] ?? $invitation_data['club_delegado'] ?? $invitation_data['delegado'] ?? '',
                    'telefono' => $club_data_from_directorio['telefono'] ?? $invitation_data['club_telefono'] ?? $invitation_data['telefono'] ?? '',
                    'email' => $club_data_from_directorio['email'] ?? $invitation_data['invitado_email'] ?? $invitation_data['club_email'] ?? $invitation_data['email'] ?? '',
                    'logo' => $club_data_from_directorio['logo'] ?? $invitation_data['club_logo'] ?? null
                ];
                $inscripciones_abiertas = ($now >= $start_date && $now <= $end_date);
                if (!$inscripciones_abiertas) {
                    $error_message = '';
                }
            }

            // Vinculación delegado – directorio_clubes.id_usuario
            if ($current_user && $invitation_data && !$es_usuario_vinculado) {
                $id_directorio_club = (int)($invitation_data['id_directorio_club'] ?? 0);
                if ($id_directorio_club <= 0 && $club_data_from_directorio && !empty($club_data_from_directorio['id'])) {
                    $id_directorio_club = (int)$club_data_from_directorio['id'];
                }
                if ($id_directorio_club > 0) {
                    try {
                        $cols = @$pdo->query("SHOW COLUMNS FROM directorio_clubes LIKE 'id_usuario'")->fetch();
                        if (!empty($cols)) {
                            $st = $pdo->prepare("SELECT id_usuario FROM directorio_clubes WHERE id = ? LIMIT 1");
                            $st->execute([$id_directorio_club]);
                            $row = $st->fetch(PDO::FETCH_ASSOC);
                            if ($row && isset($row['id_usuario']) && (int)$row['id_usuario'] === (int)$current_user['id']) {
                                $es_usuario_vinculado = true;
                                if ($id_vinculado <= 0) {
                                    $has_col = @$pdo->query("SHOW COLUMNS FROM {$tb_inv} LIKE 'id_usuario_vinculado'")->fetch();
                                    if ($has_col) {
                                        $up_inv = $pdo->prepare("UPDATE {$tb_inv} SET id_usuario_vinculado = ? WHERE torneo_id = ? AND club_id = ?");
                                        $up_inv->execute([$current_user['id'], $torneo_id, $club_id]);
                                        $invitation_data['id_usuario_vinculado'] = $current_user['id'];
                                    }
                                    $pdo->prepare("UPDATE directorio_clubes SET id_usuario = ? WHERE id = ?")->execute([$current_user['id'], $id_directorio_club]);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("invitation_register: vinculación directorio " . $e->getMessage());
                    }
                }
            }

            $form_enabled = $is_admin_general || $is_admin_torneo || $is_admin_club || $es_usuario_vinculado;

            $existing_registrations = [];
            if ($invitation_data && !$error_acceso) {
                try {
                    $stmt = DB::pdo()->prepare("
                        SELECT i.id, i.id_usuario, i.fecha_inscripcion as created_at,
                               u.cedula, u.nombre, u.sexo, u.username,
                               COALESCE(u.celular, '') as celular,
                               u.email
                        FROM inscritos i
                        JOIN usuarios u ON i.id_usuario = u.id
                        WHERE i.torneo_id = ? AND i.id_club = ?
                        ORDER BY i.fecha_inscripcion DESC
                    ");
                    $stmt->execute([$torneo_id, $club_id]);
                    $existing_registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    // ignorar
                }
            }

            $reportes_pago_invitacion = [];
            $reportes_pago_subtotal = 0;
            if (!$error_acceso && (int)($tournament_data['es_evento_masivo'] ?? 0) && (int)($tournament_data['cuenta_id'] ?? 0)) {
                try {
                    $stmt = DB::pdo()->prepare("
                        SELECT rpu.id, rpu.fecha, rpu.hora, rpu.tipo_pago, rpu.banco, rpu.referencia, rpu.cantidad_inscritos, rpu.monto, rpu.estatus,
                               u.nombre as usuario_nombre, u.cedula as usuario_cedula
                        FROM reportes_pago_usuarios rpu
                        INNER JOIN inscritos i ON i.id = rpu.inscrito_id AND i.torneo_id = rpu.torneo_id
                        INNER JOIN usuarios u ON u.id = rpu.id_usuario
                        WHERE rpu.torneo_id = ? AND i.id_club = ?
                        ORDER BY rpu.fecha DESC, rpu.id DESC
                    ");
                    $stmt->execute([$torneo_id, $club_id]);
                    $reportes_pago_invitacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $reportes_pago_subtotal = array_sum(array_column($reportes_pago_invitacion, 'monto'));
                } catch (Throwable $e) {
                    // ignorar
                }
            }

            $base = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : ($GLOBALS['APP_CONFIG']['app']['base_url'] ?? ''), '/');
            $url_invitations = ($base !== '') ? $base . '/index.php?page=invitations' : 'index.php?page=invitations';
            $url_landing = ($base !== '') ? $base . '/' : '/';
            $es_admin_para_retorno = $is_admin_general || $is_admin_torneo;
            $url_retorno = $es_admin_para_retorno ? $url_invitations : $url_landing;
            $texto_retorno = $es_admin_para_retorno ? 'Volver a Invitaciones' : 'Volver al inicio';

            return self::buildReturn(
                $error_message,
                $success_message,
                $error_acceso,
                $invitation_data,
                $tournament_data,
                $club_data,
                $organizer_club_data,
                $inscripciones_abiertas,
                $is_admin_general,
                $is_admin_torneo,
                $is_admin_club,
                $stand_by,
                $form_enabled,
                $existing_registrations,
                $torneo_id,
                $club_id,
                $token,
                $base,
                $url_retorno,
                $texto_retorno,
                $current_user,
                $reportes_pago_invitacion,
                $reportes_pago_subtotal
            );
        } catch (Throwable $e) {
            $error_message = "Error al validar invitación: " . $e->getMessage();
            $error_acceso = true;
            return self::buildReturn(
                $error_message,
                $success_message,
                $error_acceso,
                null,
                null,
                null,
                null,
                false,
                false,
                false,
                false,
                false,
                [],
                $torneo_id,
                $club_id,
                $token
            );
        }
    }

    private static function buildReturn(
        string $error_message,
        string $success_message,
        bool $error_acceso,
        $invitation_data,
        $tournament_data,
        $club_data,
        $organizer_club_data,
        bool $inscripciones_abiertas,
        bool $is_admin_general,
        bool $is_admin_torneo,
        bool $is_admin_club,
        bool $stand_by,
        bool $form_enabled,
        array $existing_registrations,
        $torneo_id,
        $club_id,
        string $token,
        string $base = '',
        string $url_retorno = '',
        string $texto_retorno = 'Volver al inicio',
        $current_user = null,
        array $reportes_pago_invitacion = [],
        float $reportes_pago_subtotal = 0.0
    ): array {
        return [
            'error_message' => $error_message,
            'success_message' => $success_message,
            'error_acceso' => $error_acceso,
            'invitation_data' => $invitation_data,
            'tournament_data' => $tournament_data,
            'club_data' => $club_data,
            'organizer_club_data' => $organizer_club_data,
            'inscripciones_abiertas' => $inscripciones_abiertas,
            'is_admin_general' => $is_admin_general,
            'is_admin_torneo' => $is_admin_torneo,
            'is_admin_club' => $is_admin_club,
            'stand_by' => $stand_by,
            'form_enabled' => $form_enabled,
            'existing_registrations' => $existing_registrations,
            'base' => $base,
            'url_retorno' => $url_retorno,
            'texto_retorno' => $texto_retorno,
            'torneo_id' => $torneo_id,
            'club_id' => $club_id,
            'token' => $token,
            'current_user' => $current_user,
            'reportes_pago_invitacion' => $reportes_pago_invitacion,
            'reportes_pago_subtotal' => $reportes_pago_subtotal,
        ];
    }
}
