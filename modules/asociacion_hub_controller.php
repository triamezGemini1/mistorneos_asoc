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
    if (! headers_sent()) {
        header('Location: ' . AppHelpers::dashboard('asociacion_hub', [
            'org_id' => $orgId,
            'tab' => $tab,
        ]));
        exit;
    }
}

try {
    // 1. Recepción y validación de input (org_id ya validado en index.php)
    $org_id = filter_input(INPUT_GET, 'org_id', FILTER_VALIDATE_INT);
    if (! is_int($org_id) || $org_id <= 0) {
        echo '<div class="alert alert-danger m-4">Asociación no válida.</div>';

        return;
    }

    $tabsConfig = asociacion_hub_tabs_config();
    $tab = strtolower(trim((string) ($_GET['tab'] ?? 'info')));
    if ($tab === '') {
        $tab = 'info';
    }

    // 2. Obtener contexto del usuario y datos
    $sessionUser = Auth::user();
    $authUser = AsociacionAuth::userFromSession($sessionUser, $org_id);
    $asociacion = OrganizacionService::getById($org_id);

    if ($asociacion === null) {
        echo '<div class="alert alert-warning m-4">Asociación no encontrada.</div>';

        return;
    }

    // 3. Whitelist de pestañas
    if (! array_key_exists($tab, $tabsConfig)) {
        asociacion_hub_redirigir_tab($org_id, 'info');
        $tab = 'info';
    }

    // 4. Permisos por pestaña
    $nivelRequerido = (int) $tabsConfig[$tab]['level'];
    if (! AsociacionAuth::checkAccess($nivelRequerido, $org_id, $authUser)) {
        $_SESSION['warning'] = 'No tiene permiso para acceder a esa sección.';
        asociacion_hub_redirigir_tab($org_id, 'info');
        $tab = 'info';
    }

    $tabsVisibles = asociacion_hub_tabs_visibles($org_id, $authUser, $tabsConfig);

    // 5. Verificación de acceso global (flags reutilizables en vistas)
    $puedeVerReportes = AsociacionAuth::checkAccess(AsociacionAuth::AFILIADO, $org_id, $authUser);
    $puedeAdministrar = AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $org_id, $authUser);
    $esSuperAdmin = AsociacionAuth::checkAccess(AsociacionAuth::SUPER_ADMIN, $org_id, $authUser);

    $hubNav = AsociacionHubNavigation::captureEntry($org_id);

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
        'torneos' => TorneoService::getByOrg($org_id),
    ];

    // 7. Cargar vista shell + pestaña activa
    $tabFile = __DIR__ . '/views/asociacion_hub/tabs/' . $tab . '.php';
    if (! is_file($tabFile)) {
        error_log('asociacion_hub_controller: pestaña no encontrada ' . $tab);
        $tab = 'info';
        $viewData['tab'] = 'info';
        $tabFile = __DIR__ . '/views/asociacion_hub/tabs/info.php';
    }

    $viewData['tab_file'] = $tabFile;

    include __DIR__ . '/views/asociacion_hub_view.php';
} catch (Throwable $e) {
    error_log('asociacion_hub_controller: ' . $e->getMessage());
    echo '<div class="alert alert-danger m-4">Error al cargar el hub de asociación.</div>';
}
