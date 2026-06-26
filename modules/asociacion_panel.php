<?php
/**
 * Panel operativo — Administración de asociación (delegado / admin club).
 * Vista ancho completo. Solo solicitudes, inscripciones y consulta (sin crear entidades ni gestionar torneo).
 */
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/AsociacionAdminHelper.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/FvdConfig.php';

if (!Auth::user()) {
    header('Location: ' . AppHelpers::url('login.php'));
    exit;
}

$pdo = DB::pdo();
$uid = Auth::id();
$user = Auth::user();
$role = (string) ($user['role'] ?? '');

$club = AsociacionAdminHelper::clubOperativo($pdo, $uid, $role);
$esOperativoAcotado = Auth::isOperativoSoloAsociacion();

// Organización particular: panel FVD (solicitudes a federación) no aplica — usar inicio / gestión de torneos.
if (AsociacionAdminHelper::usuarioAdministraOrganizacionParticular($pdo, $uid)) {
    $_SESSION['info'] = 'El panel de asociación FVD no aplica a organizaciones particulares. Use Inicio y Gestión de torneos.';
    header('Location: ' . AppHelpers::dashboard('home'));
    exit;
}

if ($club === null) {
    echo '<div class="alert alert-warning m-4"><i class="fas fa-info-circle me-2"></i>'
        . 'Esta pantalla es para el administrador de asociación: debe ser <strong>delegado</strong> del club en la ficha del club, '
        . 'o usuario <strong>admin club</strong> con club asignado.'
        . '</div>';
    return;
}

$entidadNombre = trim((string) ($club['entidad_nombre'] ?? ''));
$clubNombre = trim((string) ($club['nombre'] ?? 'Asociación'));
$cid = (int) ($club['id'] ?? 0);
$entidadId = (int) ($club['entidad'] ?? 0);
$orgClub = (int) ($club['organizacion_id'] ?? 0);
$orgFvd = class_exists('FvdConfig') ? (int) FvdConfig::organizacionId() : 1;
$delegadoNombre = trim((string) ($user['nombre'] ?? $user['username'] ?? 'Usuario'));

$truncLabel = static function (string $s, int $max = 42): string {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $max, '…', 'UTF-8');
    }
    return strlen($s) <= $max ? $s : (substr($s, 0, $max - 1) . '…');
};
$upperLabel = static function (string $s): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
};

$torneoHighlight = (int) ($_GET['torneo_nuevo'] ?? 0);
$tabActiva = (int) ($_GET['tab'] ?? 1);
if ($tabActiva < 1 || $tabActiva > 3) {
    $tabActiva = 1;
}

$urlPanel = AppHelpers::dashboard('asociacion_panel');
$urlPerfil = AppHelpers::url('index.php', ['page' => 'users/profile']);
$urlLogout = AppHelpers::logout();
$urlFinanzasBase = AppHelpers::dashboard('finanzas/resumen_asociacion');
$urlNotif = AppHelpers::dashboard('user_notificaciones');

$fvdLogo = AppHelpers::getAppLogo();
$clubLogo = !empty($club['logo']) ? AppHelpers::imageUrl((string) $club['logo']) : '';

$torneosSede = AsociacionAdminHelper::listarTorneosFvdParaClub($pdo, $club, $orgFvd, 30);
$torneosMasivos = AsociacionAdminHelper::listarTorneosFvdMasivos($pdo, $orgFvd, 30);
$torneosLista = array_merge($torneosMasivos, $torneosSede);

$torneoPanelId = (int) ($_GET['torneo_id'] ?? 0);
if ($torneoHighlight > 0) {
    $torneoPanelId = $torneoHighlight;
}
if ($torneoPanelId <= 0) {
    if ($tabActiva === 1 && $torneosSede !== []) {
        $torneoPanelId = (int) $torneosSede[0]['id'];
    } elseif ($tabActiva === 2 && $torneosMasivos !== []) {
        $torneoPanelId = (int) $torneosMasivos[0]['id'];
    } elseif ($torneosLista !== []) {
        $torneoPanelId = (int) $torneosLista[0]['id'];
    }
}

$torneoCtx = null;
$modalidadTorneo = 1;
foreach ($torneosLista as $tx) {
    if ((int) $tx['id'] === $torneoPanelId) {
        $torneoCtx = $tx;
        $modalidadTorneo = (int) ($tx['modalidad'] ?? 1);
        break;
    }
}

$urlTorneo = static function (string $action, array $extra = []) use ($torneoPanelId): string {
    if ($torneoPanelId <= 0) {
        return '#';
    }
    return AppHelpers::dashboard('torneo_gestion', ['action' => $action, 'torneo_id' => $torneoPanelId] + $extra);
};

$urlVerTorneo = $torneoPanelId > 0
    ? AppHelpers::dashboard('asociacion/torneo_ver', ['torneo_id' => $torneoPanelId])
    : '#';
$urlInscripciones = $urlTorneo('inscripciones');
$urlInscribirSitio = $urlTorneo('inscribir_sitio');
$urlInscribirEquipo = $urlTorneo('inscribir_equipo_sitio');
$urlCargaParejas = $urlTorneo('carga_masiva_parejas_sitio');
$urlCargaEquipos = $urlTorneo('carga_masiva_equipos_sitio');
$urlCarnetQr = $torneoPanelId > 0
    ? AppHelpers::dashboard('tournament_admin', ['torneo_id' => $torneoPanelId, 'action' => 'generar_qr'])
    : '#';

$sinTorneo = $torneoPanelId <= 0 || !Auth::canAccessTournament($torneoPanelId);
$esEventoMasivo = AsociacionAdminHelper::esEventoMasivo($torneoCtx);
$urlFinanzas = $esEventoMasivo && $torneoPanelId > 0
    ? AppHelpers::dashboard('finanzas/resumen_asociacion', ['torneo_id' => $torneoPanelId, 'evento_masivo' => 1])
    : $urlFinanzasBase;
$panelError = trim((string) ($_GET['error'] ?? ''));
$urlSolicitud = static function (string $tipo) use ($torneoPanelId): string {
    $p = ['tipo' => $tipo];
    if ($torneoPanelId > 0) {
        $p['torneo_id'] = $torneoPanelId;
    }
    return AppHelpers::dashboard('asociacion/solicitud', $p);
};

$panelQs = static function (array $extra = []) use ($torneoPanelId, $torneoHighlight): array {
    $q = $extra;
    if ($torneoPanelId > 0) {
        $q['torneo_id'] = $torneoPanelId;
    }
    if ($torneoHighlight > 0) {
        $q['torneo_nuevo'] = $torneoHighlight;
    }
    return $q;
};
$tab1Href = AppHelpers::dashboard('asociacion_panel', $panelQs(['tab' => 1]));
$tab2Href = AppHelpers::dashboard('asociacion_panel', $panelQs(['tab' => 2]));
$tab3Href = AppHelpers::dashboard('asociacion_panel', $panelQs(['tab' => 3]));
?>
<div class="asoc-fvd-wrap text-dark">
    <header class="asoc-fvd-topbar">
        <div class="container-fluid d-flex flex-wrap align-items-center justify-content-between py-2 px-3 gap-2">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= htmlspecialchars($fvdLogo) ?>" alt="FVD" class="asoc-fvd-logo-fvd" height="40">
                <span class="asoc-fvd-topbar-title d-none d-sm-inline">Federación Venezolana de Dominó</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= htmlspecialchars($urlPanel) ?>" class="btn btn-sm btn-outline-light"><i class="fas fa-home me-1"></i>Panel</a>
                <a href="<?= htmlspecialchars($urlPerfil) ?>" class="btn btn-sm btn-outline-light"><i class="fas fa-user me-1"></i>Mi perfil</a>
                <a href="<?= htmlspecialchars($urlLogout) ?>" class="btn btn-sm btn-warning text-dark"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
            </div>
        </div>
    </header>

    <div class="container-fluid px-3 px-lg-4 py-4">
        <?php if ($torneoHighlight > 0): ?>
        <div class="alert alert-primary border-0 shadow-sm d-flex align-items-center flex-wrap gap-2 mb-4">
            <i class="fas fa-bullhorn fa-lg"></i>
            <div class="flex-grow-1">
                <strong>Nuevo torneo publicado.</strong> Revise notificaciones y use las pestañas de contexto para abrir inscripciones o carnets.
            </div>
            <a href="<?= htmlspecialchars($urlNotif) ?>" class="btn btn-sm btn-light">Notificaciones</a>
        </div>
        <?php endif; ?>

        <div class="mb-2">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2 small">
                    <li class="breadcrumb-item active">Panel de asociación</li>
                </ol>
            </nav>
            <h1 class="asoc-fvd-h1 mb-1">Administración de asociación</h1>
            <p class="text-muted mb-0">
                <?php if ($esEventoMasivo && $torneoCtx): ?>
                    Evento masivo FVD: inscripciones y estado de cuenta de su asociación (sin solicitudes administrativas a la federación).
                <?php else: ?>
                    Ámbito provincial: solicitudes, inscripciones al torneo y consultas. No puede crear torneos ni modificar su configuración.
                <?php endif; ?>
            </p>
            <?php if ($panelError !== ''): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2"><?= htmlspecialchars($panelError) ?></div>
            <?php endif; ?>
        </div>

        <ul class="nav nav-tabs nav-tabs-asoc flex-nowrap overflow-auto mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tabActiva === 1 ? 'active' : '' ?>" href="<?= htmlspecialchars($tab1Href) ?>">Torneo (movimientos / inscripciones)</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tabActiva === 2 ? 'active' : '' ?>" href="<?= htmlspecialchars($tab2Href) ?>">Eventos nacionales / masivos</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tabActiva === 3 ? 'active' : '' ?>" href="<?= htmlspecialchars($tab3Href) ?>">Todos los torneos</a>
            </li>
        </ul>

        <div class="card asoc-fvd-identity shadow-sm border-0 mb-4">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3 py-3">
                <div class="d-flex align-items-center gap-3">
                    <?php if ($clubLogo !== ''): ?>
                        <img src="<?= htmlspecialchars($clubLogo) ?>" alt="" class="rounded border bg-white asoc-club-logo">
                    <?php else: ?>
                        <div class="asoc-club-logo-placeholder rounded d-flex align-items-center justify-content-center bg-primary text-white fw-bold fs-4">
                            <?= htmlspecialchars($upperLabel(function_exists('mb_substr') ? mb_substr($clubNombre, 0, 1, 'UTF-8') : substr($clubNombre, 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="asoc-entity-name text-uppercase fw-bold text-primary"><?= htmlspecialchars($upperLabel($clubNombre)) ?></div>
                        <div class="small text-muted">
                            Delegado: <strong><?= htmlspecialchars($delegadoNombre) ?></strong>
                            <?php if ($entidadNombre !== ''): ?>
                                · <?= htmlspecialchars($entidadNombre) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="d-none d-md-block text-end">
                    <img src="<?= htmlspecialchars($fvdLogo) ?>" alt="FVD" height="36" class="opacity-90">
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label for="asocQuickSearch" class="visually-hidden">Acción rápida</label>
            <div class="input-group input-group-lg asoc-quick-search shadow-sm">
                <span class="input-group-text bg-dark text-white border-0"><i class="fas fa-search"></i></span>
                <input type="search" id="asocQuickSearch" class="form-control border-0" placeholder="Acción rápida (Ctrl+Q)" autocomplete="off" aria-describedby="asocQuickHint">
            </div>
            <div id="asocQuickHint" class="form-text">Filtra las tarjetas de las tres columnas. Atajo de teclado: Ctrl+Q.</div>
        </div>

        <?php
        $listaContexto = $tabActiva === 1 ? $torneosSede : ($tabActiva === 2 ? $torneosMasivos : $torneosLista);
        ?>
        <?php if ($listaContexto !== []): ?>
        <div class="small text-muted mb-3">
            <strong>Torneo activo:</strong>
            <?php foreach (array_slice($listaContexto, 0, 8) as $tx): ?>
                <?php $tid = (int) $tx['id']; ?>
                <a class="me-2 asoc-torneo-chip<?= $tid === $torneoPanelId ? ' fw-bold text-primary' : '' ?>" href="<?= htmlspecialchars(AppHelpers::dashboard('asociacion_panel', $panelQs(['torneo_id' => $tid, 'tab' => $tabActiva]))) ?>">
                    <?= htmlspecialchars($truncLabel((string) $tx['nombre'])) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php elseif ($sinTorneo): ?>
        <div class="alert alert-secondary">No hay torneos FVD visibles para su entidad en este momento.</div>
        <?php endif; ?>

        <?php $colAncho = $esEventoMasivo ? 'col-lg-6' : 'col-lg-4'; ?>
        <div class="row g-4 asoc-columns">
            <?php if (!$esEventoMasivo): ?>
            <div class="col-lg-4">
                <div class="asoc-col h-100">
                    <div class="asoc-col-header">Solicitudes a la FVD</div>
                    <div class="list-group list-group-flush asoc-proc-list">
                        <a href="<?= htmlspecialchars($urlSolicitud('afiliacion')) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3">
                            <span class="asoc-ico text-success"><i class="fas fa-user-plus"></i></span>
                            <span><span class="fw-semibold d-block">Nueva afiliación</span><span class="small text-muted">Solicitud de afiliado</span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlSolicitud('anualidad')) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3">
                            <span class="asoc-ico text-primary"><i class="fas fa-calendar-check"></i></span>
                            <span><span class="fw-semibold d-block">Anualidad</span><span class="small text-muted">Renovación anual</span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlSolicitud('traspaso')) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3">
                            <span class="asoc-ico text-info"><i class="fas fa-exchange-alt"></i></span>
                            <span><span class="fw-semibold d-block">Traspaso</span><span class="small text-muted">Cambio de asociación</span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlSolicitud('carnet')) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3">
                            <span class="asoc-ico text-warning"><i class="fas fa-id-card"></i></span>
                            <span><span class="fw-semibold d-block">Carnet</span><span class="small text-muted">Credencial FVD</span></span>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="<?= $colAncho ?>">
                <div class="asoc-col h-100">
                    <div class="asoc-col-header">Inscripciones al torneo</div>
                    <div class="list-group list-group-flush asoc-proc-list">
                        <a href="<?= htmlspecialchars($urlVerTorneo) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-secondary"><i class="fas fa-eye"></i></span>
                            <span><span class="fw-semibold d-block">Ver configuración</span><span class="small text-muted">Solo lectura</span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlInscripciones) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-primary"><i class="fas fa-clipboard-list"></i></span>
                            <span><span class="fw-semibold d-block">Listado de inscritos</span><span class="small text-muted">Pago, recordatorio y retirar</span></span>
                        </a>
                        <?php if ($modalidadTorneo === 3): ?>
                        <a href="<?= htmlspecialchars($urlInscribirEquipo) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-success"><i class="fas fa-users"></i></span>
                            <span><span class="fw-semibold d-block">Inscribir equipo</span><span class="small text-muted">En sitio</span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlCargaEquipos) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-dark"><i class="fas fa-file-upload"></i></span>
                            <span><span class="fw-semibold d-block">Carga masiva equipos</span><span class="small text-muted">Lote / plantilla</span></span>
                        </a>
                        <?php elseif ($modalidadTorneo === 2): ?>
                        <a href="<?= htmlspecialchars($urlInscribirSitio) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-success"><i class="fas fa-user-friends"></i></span>
                            <span><span class="fw-semibold d-block">Inscribir en sitio</span><span class="small text-muted">Parejas</span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlCargaParejas) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-dark"><i class="fas fa-file-upload"></i></span>
                            <span><span class="fw-semibold d-block">Carga masiva parejas</span><span class="small text-muted">Lote / plantilla</span></span>
                        </a>
                        <?php else: ?>
                        <a href="<?= htmlspecialchars($urlInscribirSitio) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-success"><i class="fas fa-user-check"></i></span>
                            <span><span class="fw-semibold d-block">Inscribir en sitio</span><span class="small text-muted">Individual</span></span>
                        </a>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($urlCarnetQr) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3<?= $sinTorneo ? ' disabled opacity-50' : '' ?>">
                            <span class="asoc-ico text-warning"><i class="fas fa-qrcode"></i></span>
                            <span><span class="fw-semibold d-block">Carnets del torneo</span><span class="small text-muted">QR e impresión</span></span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="<?= $colAncho ?>">
                <div class="asoc-col h-100">
                    <div class="asoc-col-header">Consulta y finanzas</div>
                    <div class="list-group list-group-flush asoc-proc-list">
                        <a href="<?= htmlspecialchars($urlFinanzas) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3">
                            <span class="asoc-ico text-success"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span><span class="fw-semibold d-block">Estado de cuentas</span><span class="small text-muted"><?= $esEventoMasivo ? 'Detalle completo del evento' : 'Movimientos de su asociación' ?></span></span>
                        </a>
                        <a href="<?= htmlspecialchars($urlNotif) ?>" class="list-group-item list-group-item-action asoc-proc-link d-flex align-items-center gap-3 py-3">
                            <span class="asoc-ico text-primary"><i class="fas fa-bell"></i></span>
                            <span><span class="fw-semibold d-block">Notificaciones</span><span class="small text-muted">Avisos de la FVD</span></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <footer class="text-center text-muted small py-4 border-top mt-4">
            © <?= (int) date('Y') ?> Federación Venezolana de Dominó
        </footer>
    </div>
    <button type="button" class="btn btn-warning rounded-circle shadow-lg asoc-fab" title="Acción rápida" aria-label="Acción rápida" onclick="var e=document.getElementById('asocQuickSearch'); if(e){e.focus();e.select();}">
        <i class="fas fa-plus"></i>
    </button>
</div>

<style>
.asoc-fvd-wrap { background: #f4f6f9; min-height: 60vh; }
.asoc-fvd-topbar {
    background: linear-gradient(90deg, #0a1628 0%, #132a4a 50%, #0a1628 100%);
    color: #fff;
    border-bottom: 3px solid #c9a227;
}
.asoc-fvd-logo-fvd { object-fit: contain; max-height: 40px; }
.asoc-fvd-topbar-title { font-size: 0.9rem; letter-spacing: .04em; opacity: .95; }
.asoc-fvd-h1 {
    font-family: 'Montserrat', system-ui, sans-serif;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-size: clamp(1.15rem, 2.5vw, 1.65rem);
    color: #0a1628;
}
.nav-tabs-asoc .nav-link {
    color: #495057;
    border: none;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
    font-weight: 600;
    font-size: 0.85rem;
}
.nav-tabs-asoc .nav-link:hover { border-color: #dee2e6; color: #0a1628; }
.nav-tabs-asoc .nav-link.active {
    color: #0a1628;
    background: transparent;
    border-color: #c9a227 #c9a227 #f4f6f9;
}
.asoc-fvd-identity { background: #fff; border-left: 4px solid #c9a227 !important; }
.asoc-club-logo { width: 64px; height: 64px; object-fit: contain; }
.asoc-club-logo-placeholder { width: 64px; height: 64px; }
.asoc-entity-name { font-size: 1.25rem; letter-spacing: .04em; }
.asoc-quick-search { border-radius: .5rem; overflow: hidden; }
.asoc-col-header {
    background: #0a1628;
    color: #fff;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .12em;
    font-size: 0.75rem;
    padding: .65rem 1rem;
    border-radius: .35rem .35rem 0 0;
}
.asoc-col {
    background: #fff;
    border-radius: .35rem;
    box-shadow: 0 4px 14px rgba(10,22,40,.08);
    overflow: hidden;
}
.asoc-proc-list .list-group-item { border-color: #eef1f5; }
.asoc-proc-link:hover { background: #f8fafc; }
.asoc-ico {
    width: 2.5rem;
    height: 2.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: .5rem;
    background: #eef2f7;
    flex-shrink: 0;
}
.asoc-torneo-chip { text-decoration: none; }
.asoc-torneo-chip:hover { text-decoration: underline; }
.asoc-fab {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    width: 3.25rem;
    height: 3.25rem;
    z-index: 1040;
}
</style>
<script>
(function () {
    var inp = document.getElementById('asocQuickSearch');
    if (!inp) return;
    function filter() {
        var q = (inp.value || '').toLowerCase().trim();
        document.querySelectorAll('.asoc-proc-link').forEach(function (a) {
            var t = (a.textContent || '').toLowerCase();
            a.style.display = (!q || t.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    inp.addEventListener('input', filter);
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && (e.key === 'q' || e.key === 'Q')) {
            e.preventDefault();
            inp.focus();
            inp.select();
        }
    });
})();
</script>

