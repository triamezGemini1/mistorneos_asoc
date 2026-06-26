<?php
if (! class_exists('Branding', false)) {
    require_once __DIR__ . '/../lib/Env.php';
    Env::load(__DIR__ . '/../.env');
    require_once __DIR__ . '/../lib/SegmentConfig.php';
    require_once __DIR__ . '/../lib/Branding.php';
    SegmentConfig::boot();
}
$inv_brand = Branding::siteName();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#1a365d">
    <title><?= htmlspecialchars(class_exists('Branding', false) ? Branding::pageTitle('Inscripción por invitación') : 'Inscripción por invitación - La Estación del Dominó') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 1.5rem 0;
            color: #1f2937;
        }
        .invitation-page-header {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 16px 16px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .invitation-page-header .header-logo-app img {
            height: 80px;
            width: auto;
        }
        .invitation-page-header .header-logo-club img {
            max-height: 80px !important;
            max-width: 140px !important;
            width: auto;
            object-fit: contain;
        }
        .invitation-page-header .header-tournament-data {
            flex: 1;
            min-width: 200px;
            text-align: center;
        }
        .invitation-page-header .header-text { text-align: center; }
        .invitation-page-header .header-logo img {
            height: 112px;
            width: auto;
        }
        .invitation-page-header h4 { font-weight: 600; margin-bottom: 0.25rem; }
        .invitation-page-header .sub { opacity: 0.9; font-size: 0.95rem; }
        .main-card-wrap {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            overflow: hidden;
            max-width: 1200px;
            margin: 0 auto;
        }
        .main-card-wrap .card { border: none; border-radius: 0; }
        .main-card-wrap .card-header { font-weight: 600; }
        .btn-mistorneos {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            border: none;
            color: #fff;
            font-weight: 500;
        }
        .btn-mistorneos:hover {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
            color: #fff;
        }
        .form-control:focus, .form-select:focus {
            border-color: #48bb78;
            box-shadow: 0 0 0 0.2rem rgba(72, 187, 120, 0.25);
        }
        .badge-sm { font-size: 0.7em; }
        .table-sm th, .table-sm td { padding: 0.35rem 0.5rem; }
        .form-control:readonly { background-color: #f8f9fa; opacity: 0.8; }
        .form-select:disabled { background-color: #f8f9fa; opacity: 0.8; }
        .form-compact-invitation .inv-field { display: inline-flex; flex-direction: column; margin-bottom: 0; }
        .form-compact-invitation .inv-label { font-size: 0.7rem; margin-bottom: 0.15rem; white-space: nowrap; }
        .form-compact-invitation .form-control-sm, .form-compact-invitation .form-select-sm { font-size: 0.8rem; }
        .form-compact-invitation .inv-input { box-sizing: border-box; }
        .form-compact-invitation select.inv-input { width: auto; min-width: 3.5rem; }
        .form-compact-invitation .inv-input-cedula { width: 5.5rem; min-width: 5.5rem; }
        .form-compact-invitation .inv-field-nombre { flex: 1; min-width: 6rem; }
        .form-compact-invitation .inv-field-nombre .inv-input { width: 100%; min-width: 6rem; }
        .form-compact-invitation .inv-input-date { width: 8rem; min-width: 8rem; }
        .form-compact-invitation .inv-input-tel { width: 8rem; min-width: 8rem; }
        .form-compact-invitation .inv-field-email { flex: 1; min-width: 8rem; }
        .form-compact-invitation .inv-field-email .inv-input { width: 100%; min-width: 8rem; }
        /* Formulario invitación: compacto, borde azul jugadores, línea roja separadora */
        .inv-form-card .card { margin-bottom: 0.75rem; }
        .inv-form-card .card-body { padding: 0.6rem 0.85rem; }
        .inv-parejas-recuadro { border: 3px solid #0d6efd; border-radius: 8px; padding: 0.5rem 0.75rem; margin: 0.4rem 0 0.6rem; background: #f8fafc; }
        .inv-parejas-separador { border: none; border-top: 3px solid #dc3545; margin: 0.45rem 0; }
        .inv-jugador-block { margin: 0; min-height: 30px; display: flex; align-items: center; background: #fff; border-radius: 6px; padding: 0.35rem 0.5rem; }
        .inv-parejas-recuadro .inv-label-inline { font-size: 0.78rem; margin: 0; white-space: nowrap; font-weight: 600; color: #495057; }
        .inv-parejas-recuadro .inv-label-inline.jugador-titulo { color: #0d6efd; margin-right: 0.25rem; }
        .inv-parejas-recuadro .form-control-sm, .inv-parejas-recuadro .form-select-sm { font-size: 0.78rem; padding: 0.2rem 0.35rem; height: 28px; line-height: 1.3; max-width: 100px; min-width: 52px; }
        .inv-parejas-recuadro .inv-input-cedula { max-width: 82px; }
        .inv-parejas-recuadro .inv-input-tel { max-width: 95px; }
        .inv-parejas-linea { display: flex; flex-wrap: nowrap; align-items: center; gap: 0.35rem 0.6rem; }
        .inv-form-row { gap: 0.4rem 0.75rem !important; }
        .inv-form-row .inv-field .inv-input { font-size: 0.8rem; padding: 0.25rem 0.4rem; height: 28px; max-width: 100px; }
        .inv-form-row .inv-field-nombre .inv-input { max-width: 140px; }
        .inv-form-row .inv-input-cedula { max-width: 85px; }
        .inv-form-row .inv-input-tel { max-width: 100px; }
        .inv-form-row .inv-input-date { max-width: 115px; }
        .inv-form-row .inv-field-email .inv-input { max-width: 160px; }
        .invitation-club-logo-wrap img { max-width: 240px !important; max-height: 240px !important; width: auto; height: auto; object-fit: contain; }
        .invitation-club-logo-wrap .img-thumbnail { padding: 0.25rem; background: #f8f9fa; border-radius: 8px; }
        .invitation-inner-logos .logo-box img { max-width: 120px; max-height: 120px; object-fit: contain; }
        #invitation-toast-container {
            position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.5rem;
            pointer-events: none; max-width: 90vw;
        }
        .invitation-toast {
            padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.9rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: invitation-toast-in 0.3s ease;
        }
        .invitation-toast--out { opacity: 0; transform: translateX(1rem); transition: opacity 0.3s, transform 0.3s; }
        .invitation-toast--info { background: #0dcaf0; color: #000; }
        .invitation-toast--success { background: #198754; color: #fff; }
        .invitation-toast--warning { background: #ffc107; color: #000; }
        .invitation-toast--danger { background: #dc3545; color: #fff; }
        @keyframes invitation-toast-in {
            from { opacity: 0; transform: translateX(1rem); }
            to { opacity: 1; transform: translateX(0); }
        }
        .invitation-loading { margin-bottom: 0.5rem; }
        .inv-inscritos-card .table-responsive { font-size: 0.85rem; }
        .inv-inscritos-card .table td, .inv-inscritos-card .table th { padding: 0.35rem 0.5rem; }
        .inv-inscritos-card .card-body { padding: 0.5rem; }
        @media (max-width: 768px) {
            .col-md-6 { margin-bottom: 1rem; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="main-card-wrap">
        <div class="invitation-page-header">
            <?php if (!empty($organizer_club_data) && !empty($tournament_data)): ?>
                <!-- Izquierda: logo del club responsable -->
                <div class="header-logo-club d-flex align-items-center">
                    <div class="invitation-inner-logos logo-box" style="height: 80px; min-width: 100px; background: rgba(255,255,255,0.1); border-radius: 10px; padding: 0.25rem; display: flex; align-items: center; justify-content: center;">
                        <?= displayClubLogoInvitation($organizer_club_data, 'organizador') ?>
                    </div>
                </div>
                <!-- Centro: nombre del club responsable (primera línea) y datos del torneo -->
                <div class="header-tournament-data">
                    <h4 class="mb-1 text-white"><?= htmlspecialchars($organizer_club_data['nombre'] ?? '') ?></h4>
                    <p class="sub mb-0 opacity-90">
                        <strong><?= htmlspecialchars($tournament_data['nombre']) ?></strong>
                        <span class="opacity-90 small ms-1"><i class="fas fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($tournament_data['fechator'])) ?></span>
                    </p>
                    <?php if (!empty($tournament_data['clase']) || !empty($tournament_data['modalidad'])): ?>
                        <p class="mb-0 opacity-75 small"><?= htmlspecialchars(trim(($tournament_data['clase'] ?? '') . ' ' . ($tournament_data['modalidad'] ?? ''))) ?></p>
                    <?php endif; ?>
                </div>
                <!-- Derecha: logo de la aplicación - La Estación del Dominó -->
                <div class="header-logo-app">
                    <?php $logo_url = AppHelpers::getAppLogo(); ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($inv_brand) ?>">
                </div>
            <?php else: ?>
                <div class="header-logo">
                    <?php $logo_url = AppHelpers::getAppLogo(); ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($inv_brand) ?>">
                </div>
                <div class="header-text">
                    <h4 class="mb-0"><?= htmlspecialchars($inv_brand) ?></h4>
                    <p class="sub mb-0">Inscripción por invitación</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
<div class="fade-in">
    <?php if ($error_acceso && $error_message): ?>
        <!-- Error de acceso: pantalla bloqueante -->
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Acceso Denegado
                </h5>
            </div>
            <div class="card-body text-center py-5">
                <i class="fas fa-lock text-danger fs-1 mb-3"></i>
                <h5 class="text-danger"><?= htmlspecialchars($error_message) ?></h5>
                <p class="text-muted">Por favor, verifica que tienes acceso válido a esta página.</p>
                <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i><?= htmlspecialchars($texto_retorno) ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Card: logo club invitado + información del torneo + nombre del club + bloque Bienvenido (solo si club autenticado) -->
        <div class="card mb-3">
            <div class="card-body text-center py-3">
                <div class="row align-items-center">
                    <!-- Logo del club invitado (izquierda) -->
                    <div class="col-md-3">
                        <div class="invitation-inner-logos logo-box d-flex align-items-center justify-content-center" style="height: 120px; background: #f8f9fa; border-radius: 10px; border: 2px dashed #dee2e6;">
                           <?= displayClubLogoInvitation($club_data, 'invitado') ?>
                        </div>
                    </div>
                    <!-- Información del torneo (entre logo y nombre de la organización) -->
                    <div class="col-md-<?= ($is_admin_club) ? '3' : '4' ?> text-center text-md-start">
                        <?php if (!empty($tournament_data)): ?>
                        <div class="border rounded p-2 bg-light">
                            <strong class="d-block text-primary small mb-1">Torneo</strong>
                            <span class="fw-semibold"><?= htmlspecialchars($tournament_data['nombre'] ?? '') ?></span>
                            <?php if (!empty($tournament_data['fechator'])): ?>
                            <br><span class="small text-muted"><i class="fas fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($tournament_data['fechator'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($tournament_data['clase']) || !empty($tournament_data['modalidad'])): ?>
                            <br><span class="small text-muted"><?= htmlspecialchars(trim(($tournament_data['clase'] ?? '') . ' ' . ($tournament_data['modalidad'] ?? ''))) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </div>
                    <!-- Nombre del club / organización -->
                    <div class="col-md-<?= ($is_admin_club) ? '3' : '5' ?> text-center text-md-start">
                        <h1 class="display-6 text-success mb-0">
                            <?= htmlspecialchars($club_data['nombre']) ?>
                        </h1>
                    </div>
                    <?php if ($is_admin_club): ?>
                    <!-- Bloque Bienvenido a la derecha del nombre -->
                    <div class="col-md-3 text-start">
                        <div class="small text-muted mb-1">
                            <strong class="text-dark">Bienvenido:</strong> <?= htmlspecialchars($club_data['nombre']) ?><br>
                            <strong>Usuario:</strong> <?= htmlspecialchars($current_user['username']) ?><br>
                            <strong>Delegado:</strong> <?= htmlspecialchars($club_data['delegado']) ?><br>
                            <strong>Teléfono:</strong> <?= htmlspecialchars($club_data['telefono']) ?>
                        </div>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="user_logout">
                            <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sign-out-alt me-1"></i>Cerrar sesión</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Panel administrador: una línea (solo admin general / admin torneo) -->
        <?php if ($is_admin_general || $is_admin_torneo): ?>
        <div class="alert alert-primary py-1 px-2 mb-2 small">
            <strong>Usuario:</strong> <?= htmlspecialchars($current_user['username']) ?> &nbsp; <strong>Rol:</strong> <?= htmlspecialchars($current_user['role']) ?> &nbsp; Puede inscribir jugadores directamente sin autenticación del club.
        </div>
        <?php endif; ?>

        <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i><strong>Inscripción exitosa.</strong> <?= htmlspecialchars($success_message ?? '') ?>
            <?php if (!empty($stand_by)): ?>
            <p class="mb-0 mt-2 small">Para inscribir más jugadores, <a href="<?= htmlspecialchars(class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '') ?>/auth/login?<?= http_build_query(['return_url' => 'invitation/register?token=' . urlencode($token) . '&torneo=' . $torneo_id . '&club=' . $club_id]) ?>">inicie sesión</a> o <a href="<?= htmlspecialchars(class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '') ?>/join?token=<?= urlencode($token) ?>">regístrese</a>.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        $mostrar_stand_by = !empty($stand_by) && empty($_GET['success']);
        if ($mostrar_stand_by): ?>
        <!-- Invitación en Stand-by: banner y formulario bloqueado -->
        <div class="alert alert-warning shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-start">
                <i class="fas fa-hourglass-half fa-3x me-4 text-warning"></i>
                <div class="flex-grow-1">
                    <h4 class="alert-heading">Invitación en espera</h4>
                    <p class="mb-4">Para inscribir a sus atletas y confirmar su participación, debe <strong>Iniciar Sesión</strong> o <strong>Registrarse</strong>.</p>
                    <?php
                    $base_inv = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
                    if ($base_inv !== ''):
                    ?>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= htmlspecialchars($base_inv) ?>/auth/login?<?= http_build_query(['return_url' => 'invitation/register?' . http_build_query(['token' => $token, 'torneo' => $torneo_id, 'club' => $club_id])]) ?>" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
                        </a>
                        <a href="<?= htmlspecialchars($base_inv) ?>/join?<?= http_build_query(['token' => $token, 'torneo' => $torneo_id, 'club' => $club_id]) ?>" class="btn btn-success">
                            <i class="fas fa-user-plus me-2"></i>Registrarse
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Layout: formulario arriba (dos líneas), listado inscritos abajo (una columna) -->
        <?php if (!$inscripciones_abiertas): ?>
        <div class="alert alert-warning text-center py-4 mb-4">
            <i class="fas fa-lock fa-3x mb-3"></i>
            <h4 class="alert-heading">Inscripciones Cerradas</h4>
            <p class="mb-0">El período de inscripción ha finalizado o aún no ha comenzado. Consulte el listado a continuación.</p>
        </div>
        <?php endif; ?>
        <?php $mostrar_reportes_lateral = !empty($tournament_data) && !empty($tournament_data['es_evento_masivo']) && !empty($tournament_data['cuenta_id']); ?>
        <div class="row">
            <?php if ($mostrar_reportes_lateral): ?>
            <!-- Parte lateral izquierda: Reportar pago + Reporte de pagos del club -->
            <div class="col-md-4 mb-3">
                <div class="card">
                    <div class="card-header bg-warning text-dark py-2">
                        <strong><i class="fas fa-money-bill-wave me-1"></i>Pagos</strong>
                    </div>
                    <div class="card-body py-2">
                        <?php $url_reportar_pago_izq = (isset($base) && $base !== '' ? rtrim($base, '/') . '/' : '') . 'reportar_pago_evento_masivo.php?torneo_id=' . (int)$torneo_id; ?>
                        <a href="<?= htmlspecialchars($url_reportar_pago_izq) ?>" class="btn btn-success btn-sm w-100 mb-2" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-money-bill-wave me-1"></i>Reportar pago
                        </a>
                        <h6 class="small text-muted mb-1">Club invitado: <?= htmlspecialchars($club_data['nombre'] ?? '') ?></h6>
                        <?php if (empty($reportes_pago_invitacion ?? [])): ?>
                            <p class="small text-muted mb-0">Sin reportes de pago aún.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Jugador</th>
                                            <th>Banco</th>
                                            <th>Comp.</th>
                                            <th>Fecha</th>
                                            <th>Cat.</th>
                                            <th>Total</th>
                                            <th>✓</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reportes_pago_invitacion as $rp): ?>
                                            <tr>
                                                <td title="<?= htmlspecialchars($rp['usuario_nombre'] ?? '') ?>"><?= htmlspecialchars(mb_substr($rp['usuario_nombre'] ?? '-', 0, 10)) ?></td>
                                                <td><?= htmlspecialchars(mb_substr($rp['banco'] ?? '-', 0, 6)) ?></td>
                                                <td title="<?= htmlspecialchars($rp['referencia'] ?? '') ?>"><?= htmlspecialchars(mb_substr($rp['referencia'] ?? '-', 0, 6)) ?></td>
                                                <td><?= isset($rp['fecha']) ? date('d/m', strtotime($rp['fecha'])) : '-' ?></td>
                                                <td><?= (int)($rp['cantidad_inscritos'] ?? 1) ?></td>
                                                <td>$<?= number_format((float)($rp['monto'] ?? 0), 0) ?></td>
                                                <td><?php $est = $rp['estatus'] ?? ''; echo $est === 'confirmado' ? '✓' : ($est === 'rechazado' ? '✗' : '…'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="5" class="text-end small">Subtotal club:</th>
                                            <th class="small">$<?= number_format($reportes_pago_subtotal ?? 0, 2) ?></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-<?= $mostrar_reportes_lateral ? 'md-8' : '12' ?>">
            <?php
            $mostrar_formulario = $inscripciones_abiertas && (empty($stand_by) || !empty($_GET['success']));
            if ($mostrar_formulario): ?>
            <?php
            $modalidad_val = isset($tournament_data['modalidad']) ? (int)$tournament_data['modalidad'] : 0;
            if ($modalidad_val === 0 && !empty($torneo_id)) {
                try {
                    $st = DB::pdo()->prepare("SELECT modalidad FROM tournaments WHERE id = ? LIMIT 1");
                    $st->execute([(int)$torneo_id]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row !== false) {
                        $modalidad_val = (int)$row['modalidad'];
                    }
                } catch (Throwable $e) { /* ignorar */ }
            }
            $es_parejas = $modalidad_val === 4;
            ?>
            <!-- Formulario: parejas (2 jug + nombre opc) o un jugador según modalidad -->
            <div class="col-12 inv-form-card">
                <div class="card mb-2">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <span class="text-muted small">Inscripción</span>
                            <a href="<?= htmlspecialchars($url_retorno) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i><?= htmlspecialchars($texto_retorno) ?></a>
                        </div>
                        <?php if (!$form_enabled): ?>
                            <div class="alert alert-info py-2 mb-2 small">Para inscribir debe autenticarse primero.</div>
                        <?php endif; ?>

                        <?php if ($es_parejas): ?>
                        <h6 class="text-primary mb-1 small"><i class="fas fa-handshake me-1"></i>Parejas — 2 jugadores, nombre opcional</h6>
                        <form method="POST" id="registrationFormPareja" class="form-compact-invitation" <?= !$form_enabled ? 'onsubmit="return false;"' : '' ?>>
                            <input type="hidden" name="action" value="register_pair">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">
                            <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id ?>">
                            <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">
                            <input type="hidden" name="id_usuario_1" id="id_usuario_1" value="">
                            <input type="hidden" name="id_usuario_2" id="id_usuario_2" value="">
                            <div class="mb-1 d-flex align-items-center gap-2">
                                <label class="form-label small mb-0">Nombre pareja (opc.):</label>
                                <input type="text" name="nombre_equipo" class="form-control form-control-sm" maxlength="100" placeholder="Ej: Los Duendes" style="max-width: 200px;">
                            </div>
                            <div class="inv-parejas-recuadro">
                                <div class="inv-jugador-block inv-parejas-linea">
                                    <span class="inv-label-inline text-muted">Jugador 1</span>
                                    <span class="inv-label-inline">Nacionalidad</span>
                                    <select class="form-select form-select-sm inv-input" name="nacionalidad_1" id="nacionalidad_1" <?= !$form_enabled ? 'disabled' : '' ?>><option value="V">V</option><option value="E">E</option><option value="J">J</option><option value="P">P</option></select>
                                    <span class="inv-label-inline">Cédula</span>
                                    <input type="text" class="form-control form-control-sm inv-input inv-input-cedula" name="cedula_1" id="cedula_1" placeholder="Cédula" maxlength="10" <?= !$form_enabled ? 'readonly' : '' ?> onblur="if(typeof searchPersonaForRow==='function')searchPersonaForRow(1);">
                                    <span class="inv-label-inline">Nombre</span>
                                    <input type="text" class="form-control form-control-sm inv-input" name="nombre_1" id="nombre_1" <?= !$form_enabled ? 'readonly' : '' ?> required>
                                    <span class="inv-label-inline">Tel.</span>
                                    <input type="tel" class="form-control form-control-sm inv-input inv-input-tel" name="telefono_1" id="telefono_1" <?= !$form_enabled ? 'readonly' : '' ?> required>
                                </div>
                                <hr class="inv-parejas-separador">
                                <div class="inv-jugador-block inv-parejas-linea">
                                    <span class="inv-label-inline text-muted">Jugador 2</span>
                                    <span class="inv-label-inline">Nacionalidad</span>
                                    <select class="form-select form-select-sm inv-input" name="nacionalidad_2" id="nacionalidad_2" <?= !$form_enabled ? 'disabled' : '' ?>><option value="V">V</option><option value="E">E</option><option value="J">J</option><option value="P">P</option></select>
                                    <span class="inv-label-inline">Cédula</span>
                                    <input type="text" class="form-control form-control-sm inv-input inv-input-cedula" name="cedula_2" id="cedula_2" placeholder="Cédula" maxlength="10" <?= !$form_enabled ? 'readonly' : '' ?> onblur="if(typeof searchPersonaForRow==='function')searchPersonaForRow(2);">
                                    <span class="inv-label-inline">Nombre</span>
                                    <input type="text" class="form-control form-control-sm inv-input" name="nombre_2" id="nombre_2" <?= !$form_enabled ? 'readonly' : '' ?> required>
                                    <span class="inv-label-inline">Tel.</span>
                                    <input type="tel" class="form-control form-control-sm inv-input inv-input-tel" name="telefono_2" id="telefono_2" <?= !$form_enabled ? 'readonly' : '' ?> required>
                                </div>
                            </div>
                            <div class="mt-1 d-flex align-items-center gap-1 flex-wrap">
                                <?php if ($form_enabled): ?>
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-handshake me-1"></i>Inscribir pareja</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('registrationFormPareja').reset(); document.getElementById('id_usuario_1').value=''; document.getElementById('id_usuario_2').value='';"><i class="fas fa-eraser me-1"></i>Limpiar</button>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars(isset($base) && $base !== '' ? $base . '/' : '/') ?>auth/login?<?= http_build_query(['return_url' => 'invitation/register?token=' . urlencode($token)]) ?>" class="btn btn-primary btn-sm"><i class="fas fa-sign-in-alt me-1"></i>Iniciar sesión</a>
                                <?php endif; ?>
                                <span class="text-muted small ms-1">Busque por cédula en cada jugador.</span>
                            </div>
                        </form>
                        <?php else: ?>
                        <h6 class="text-primary mb-1 small"><i class="fas fa-user me-1"></i>Un jugador</h6>
                        <form method="POST" id="registrationForm" class="form-compact-invitation" <?= !$form_enabled ? 'onsubmit="return false;"' : '' ?>>
                            <input type="hidden" name="action" value="register_player">
                            <input type="hidden" id="torneo_id" name="torneo_id" value="<?= htmlspecialchars($torneo_id) ?>">
                            <input type="hidden" name="club_id" value="<?= htmlspecialchars($club_id) ?>">
                            <input type="hidden" id="id_usuario" name="id_usuario" value="">
                            <div class="d-flex flex-wrap align-items-end gap-2 gx-2 inv-form-row">
                                <div class="inv-field">
                                    <label for="nacionalidad" class="form-label inv-label">Nac. <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm inv-input" id="nacionalidad" name="nacionalidad" <?= !$form_enabled ? 'disabled' : '' ?> required title="Nacionalidad">
                                        <option value="">...</option>
                                        <option value="V" <?= ($_POST['nacionalidad'] ?? '') == 'V' ? 'selected' : '' ?>>V</option>
                                        <option value="E" <?= ($_POST['nacionalidad'] ?? '') == 'E' ? 'selected' : '' ?>>E</option>
                                        <option value="J" <?= ($_POST['nacionalidad'] ?? '') == 'J' ? 'selected' : '' ?>>J</option>
                                        <option value="P" <?= ($_POST['nacionalidad'] ?? '') == 'P' ? 'selected' : '' ?>>P</option>
                                    </select>
                                </div>
                                <div class="inv-field">
                                    <label for="cedula" class="form-label inv-label">Cédula <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm inv-input inv-input-cedula" id="cedula" name="cedula" value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>" placeholder="12345678" maxlength="8" <?= !$form_enabled ? 'readonly' : '' ?> onblur="if(typeof searchPersona==='function')searchPersona();" required title="Al salir se buscan datos automáticamente (inscritos, usuarios, base externa)">
                                </div>
                                <div class="inv-field inv-field-nombre">
                                    <label for="nombre" class="form-label inv-label">Nombre <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm inv-input" id="nombre" name="nombre" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" <?= !$form_enabled ? 'readonly' : '' ?> required>
                                </div>
                                <div class="inv-field">
                                    <label for="sexo" class="form-label inv-label">Sexo <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm inv-input" id="sexo" name="sexo" <?= !$form_enabled ? 'disabled' : '' ?> required>
                                        <option value="">...</option>
                                        <option value="M" <?= ($_POST['sexo'] ?? '') == 'M' ? 'selected' : '' ?>>M</option>
                                        <option value="F" <?= ($_POST['sexo'] ?? '') == 'F' ? 'selected' : '' ?>>F</option>
                                    </select>
                                </div>
                                <div class="inv-field">
                                    <label for="fechnac" class="form-label inv-label">F. Nac.</label>
                                    <input type="date" class="form-control form-control-sm inv-input inv-input-date" id="fechnac" name="fechnac" value="<?= htmlspecialchars($_POST['fechnac'] ?? '') ?>" <?= !$form_enabled ? 'readonly' : '' ?>>
                                </div>
                                <div class="inv-field">
                                    <label for="telefono" class="form-label inv-label">Tel. <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control form-control-sm inv-input inv-input-tel" id="telefono" name="telefono" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>" placeholder="0424-1234567" <?= !$form_enabled ? 'readonly' : '' ?> required>
                                </div>
                                <div class="inv-field inv-field-email">
                                    <label for="email" class="form-label inv-label">Email</label>
                                    <input type="email" class="form-control form-control-sm inv-input" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="usuario@gmail.com" <?= !$form_enabled ? 'readonly' : '' ?>>
                                </div>
                                <div class="inv-field ms-1">
                                    <?php if ($form_enabled): ?>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Inscribir</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearForm()"><i class="fas fa-eraser me-1"></i>Limpiar</button>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars(isset($base) && $base !== '' ? $base . '/' : '/') ?>auth/login?<?= http_build_query(['return_url' => 'invitation/register?token=' . urlencode($token)]) ?>" class="btn btn-primary btn-sm"><i class="fas fa-sign-in-alt me-1"></i>Iniciar sesión</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Una columna: listado de inscritos -->
            <div class="col-12">
                <div class="card mb-2 inv-inscritos-card">
                    <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0">
                            <i class="fas fa-check-circle me-2"></i>
                            Jugadores Inscritos
                            <span class="badge bg-light text-dark ms-2"><?= count($existing_registrations) ?></span>
                        </h6>
                        <?php if (!$mostrar_reportes_lateral && !empty($tournament_data['es_evento_masivo']) && !empty($tournament_data['cuenta_id'])): ?>
                            <?php $url_reportar_pago_card = (isset($base) && $base !== '' ? rtrim($base, '/') . '/' : '') . 'reportar_pago_evento_masivo.php?torneo_id=' . (int)$torneo_id; ?>
                            <a href="<?= htmlspecialchars($url_reportar_pago_card) ?>" class="btn btn-light btn-sm" target="_blank" rel="noopener noreferrer" title="Reportar pago de inscripción">
                                <i class="fas fa-money-bill-wave me-1"></i>Reportar pago
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($existing_registrations)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-users text-muted fs-1 mb-3"></i>
                                <h6 class="text-muted">No hay jugadores inscritos aún</h6>
                                <p class="text-muted">Los jugadores inscritos aparecerán aquí</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Id usuario</th>
                                            <th>Cédula</th>
                                            <th>Nombre</th>
                                            <th>Sexo</th>
                                            <th>Teléfono</th>
                                            <th>Email</th>
                                            <?php if ($inscripciones_abiertas): ?><th>Acciones</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($existing_registrations as $registration):
                                            $email_display = !empty(trim($registration['email'] ?? '')) ? $registration['email'] : ((string)($registration['username'] ?? '') . '@gmail.com');
                                        ?>
                                            <tr>
                                                <td><small><?= (int)$registration['id_usuario'] ?></small></td>
                                                <td><small><?= htmlspecialchars($registration['cedula']) ?></small></td>
                                                <td><small><?= htmlspecialchars($registration['nombre']) ?></small></td>
                                                <td><span class="badge bg-info badge-sm"><?= htmlspecialchars($registration['sexo']) ?></span></td>
                                                <td><small><?= htmlspecialchars($registration['celular'] ?: '-') ?></small></td>
                                                <td><small><?= htmlspecialchars($email_display) ?></small></td>
                                                <?php if ($inscripciones_abiertas): ?>
                                                <td>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('¿Retirar a este jugador del torneo?');">
                                                        <input type="hidden" name="action" value="retirar">
                                                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                                        <input type="hidden" name="id_inscripcion" value="<?= (int)$registration['id'] ?>">
                                                        <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id ?>">
                                                        <input type="hidden" name="club_id" value="<?= (int)$club_id ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-user-minus me-1"></i>Retirar</button>
                                                    </form>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>
        </div>
    <?php endif; ?>
</div>
        </div>
    </div>
</div>

<style>
    /* Estilos adicionales solo si se necesitan (badge-sm, table-sm ya en head) */
</style>

<script>
    window.INVITATION_REGISTER_CONFIG = {
        apiBase: <?= json_encode((isset($base) && $base !== '') ? rtrim($base, '/') . '/api' : '') ?>,
        torneoId: <?= (int)($torneo_id ?? 0) ?>
    };
</script>
<script src="<?= htmlspecialchars(isset($base) && $base !== '' ? rtrim($base, '/') . '/js/invitation-register.js' : 'js/invitation-register.js') ?>"></script>
<script>
    function togglePasswordVisibility() {
        var passwordInput = document.getElementById('password');
        var toggleIcon = document.getElementById('passwordToggleIcon');
        if (passwordInput && toggleIcon) {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
    }
    function showAuthRequiredMessage() {
        if (typeof showToastInvitation !== 'undefined') showToastInvitation('Debe autenticarse primero para poder inscribir jugadores.', 'warning');
        else alert('Debe autenticarse primero para poder inscribir jugadores.');
    }
    <?php if (!empty($success_message)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof showToastInvitation === 'function') showToastInvitation(<?= json_encode($success_message) ?>, 'success');
        var nac = document.getElementById('nacionalidad');
        if (nac) { nac.focus(); }
    });
    <?php endif; ?>
    <?php if (!empty($error_message) && !$error_acceso): ?>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof showToastInvitation === 'function') showToastInvitation(<?= json_encode($error_message) ?>, 'danger');
    });
    <?php endif; ?>
</script>
</body>
</html>
