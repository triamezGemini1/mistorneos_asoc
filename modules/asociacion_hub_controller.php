<?php
declare(strict_types=1);

/**
 * Controlador del Hub de Asociación — solo orquestación (sin HTML).
 */

if (! defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/AsociacionAuth.php';
require_once __DIR__ . '/../lib/OrganizacionService.php';
require_once __DIR__ . '/../lib/TorneoService.php';
require_once __DIR__ . '/../lib/AsociacionHubNavigation.php';

/** @return array<string, array{level: int, label: string}> */
function asociacion_hub_tabs_config(): array
{
    return [
        'info' => ['level' => AsociacionAuth::PUBLICO, 'label' => 'Información'],
        'torneos' => ['level' => AsociacionAuth::AFILIADO, 'label' => 'Torneos'],
        'clubes' => ['level' => AsociacionAuth::AFILIADO, 'label' => 'Clubes'],
        'afiliados' => ['level' => AsociacionAuth::ADMIN_ASOC, 'label' => 'Afiliados'],
    ];
}

/**
 * @param array<string, array{level: int, label: string}> $tabsConfig
 * @return list<string>
 */
function asociacion_hub_tabs_visibles(int $orgId, ?AsociacionAuthUser $authUser, array $tabsConfig): array
{
    $visibles = [];
    foreach ($tabsConfig as $tabId => $meta) {
        if (AsociacionAuth::checkAccess((int) $meta['level'], $orgId, $authUser)) {
            $visibles[] = $tabId;
        }
    }

    return $visibles;
}

function asociacion_hub_redirigir_tab(int $orgId, string $tab): void
{
    if (! class_exists('AppHelpers', false)) {
        require_once __DIR__ . '/../lib/app_helpers.php';
    }
    if ($tab === 'info' || trim($tab) === '') {
        $tab = AsociacionHubNavigation::defaultOperationalTab();
    }
    if (! headers_sent()) {
        header('Location: ' . AsociacionHubNavigation::hubUrl($orgId, $tab));
        exit;
    }
}

/**
 * @param list<string> $tabsVisibles
 */
function asociacion_hub_resolve_tab(int $orgId, string $requestedTab, bool $puedeAdministrar, array $tabsVisibles): string
{
    $requestedTab = strtolower(trim($requestedTab));
    if ($requestedTab === 'info') {
        return in_array('info', $tabsVisibles, true)
            ? 'info'
            : asociacion_hub_default_tab($puedeAdministrar, $tabsVisibles);
    }
    if ($requestedTab === '') {
        return asociacion_hub_default_tab($puedeAdministrar, $tabsVisibles);
    }

    return $requestedTab;
}

/**
 * @param list<string> $tabsVisibles
 */
function asociacion_hub_default_tab(bool $puedeAdministrar, array $tabsVisibles): string
{
    if ($puedeAdministrar && in_array('clubes', $tabsVisibles, true)) {
        return 'clubes';
    }
    if (in_array('torneos', $tabsVisibles, true)) {
        return 'torneos';
    }
    foreach ($tabsVisibles as $visibleTab) {
        if ($visibleTab !== 'info') {
            return $visibleTab;
        }
    }

    return AsociacionHubNavigation::defaultOperationalTab();
}

try {
    // 1. Recepción y validación de input (org_id ya validado en index.php)
    $org_id = filter_input(INPUT_GET, 'org_id', FILTER_VALIDATE_INT);
    if (! is_int($org_id) || $org_id <= 0) {
        echo '<div class="alert alert-danger m-4">Asociación no válida.</div>';

        return;
    }

    $tabsConfig = asociacion_hub_tabs_config();

    // 2. Obtener contexto del usuario y datos
    $sessionUser = Auth::user();
    $authUser = AsociacionAuth::userFromSession($sessionUser, $org_id);
    $asociacion = OrganizacionService::getById($org_id);

    if ($asociacion === null) {
        echo '<div class="alert alert-warning m-4">Asociación no encontrada.</div>';

        return;
    }

    $tabsVisibles = asociacion_hub_tabs_visibles($org_id, $authUser, $tabsConfig);
    $puedeVerReportes = AsociacionAuth::checkAccess(AsociacionAuth::AFILIADO, $org_id, $authUser);
    $puedeAdministrar = AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $org_id, $authUser);
    $esSuperAdmin = AsociacionAuth::checkAccess(AsociacionAuth::SUPER_ADMIN, $org_id, $authUser);

    $requestedTab = array_key_exists('tab', $_GET)
        ? strtolower(trim((string) ($_GET['tab'] ?? '')))
        : '';
    $tabMissing = ! array_key_exists('tab', $_GET) || trim((string) ($_GET['tab'] ?? '')) === '';
    $tab = asociacion_hub_resolve_tab($org_id, $requestedTab, $puedeAdministrar, $tabsVisibles);

    if ($tabMissing || ($requestedTab !== '' && $requestedTab !== $tab)) {
        asociacion_hub_redirigir_tab($org_id, $tab);
    }

    // 3. Whitelist de pestañas
    if (! array_key_exists($tab, $tabsConfig)) {
        asociacion_hub_redirigir_tab($org_id, asociacion_hub_resolve_tab($org_id, '', $puedeAdministrar, $tabsVisibles));
    }

    // 4. Permisos por pestaña
    $nivelRequerido = (int) $tabsConfig[$tab]['level'];
    if (! AsociacionAuth::checkAccess($nivelRequerido, $org_id, $authUser)) {
        $_SESSION['warning'] = 'No tiene permiso para acceder a esa sección.';
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/../lib/app_helpers.php';
        }
        header('Location: ' . AppHelpers::dashboard('home'));
        exit;
    }

    $hubNav = AsociacionHubNavigation::captureEntry($org_id);

    $estadoTorneos = AsociacionHubNavigation::normalizeEstadoTorneos(
        (string) ($_GET['estado'] ?? 'en_proceso')
    );

    // 6. Preparar variables para la vista
    $viewData = [
        'org_id' => $org_id,
        'tab' => $tab,
        'tabs' => $tabsConfig,
        'tabs_visibles' => $tabsVisibles,
        'nombre_asociacion' => (string) ($asociacion->nombre ?? ''),
        'asociacion' => $asociacion,
        'auth_user' => $authUser,
        'puede_ver_detalles' => $puedeVerReportes,
        'puede_administrar' => $puedeAdministrar,
        'es_super_admin' => $esSuperAdmin,
        'hub_origin_url' => $hubNav['origin_url'],
        'hub_origin_label' => $hubNav['origin_label'],
        'estado_torneos' => $estadoTorneos,
        'torneos' => $tab === 'torneos'
            ? TorneoService::enrichForHubAdmin(TorneoService::getByOrg($org_id, $estadoTorneos))
            : [],
    ];

    // 7. Cargar vista shell + pestaña activa
    $tabFile = __DIR__ . '/views/asociacion_hub/tabs/' . $tab . '.php';
    if (! is_file($tabFile)) {
        error_log('asociacion_hub_controller: pestaña no encontrada ' . $tab);
        asociacion_hub_redirigir_tab($org_id, asociacion_hub_resolve_tab($org_id, '', $puedeAdministrar, $tabsVisibles));
    }

    $viewData['tab_file'] = $tabFile;

    include __DIR__ . '/views/asociacion_hub_view.php';
} catch (Throwable $e) {
    error_log('asociacion_hub_controller: ' . $e->getMessage());
    echo '<div class="alert alert-danger m-4">Error al cargar el hub de asociación.</div>';
}
