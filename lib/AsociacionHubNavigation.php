<?php
declare(strict_types=1);

/**
 * Navegación del hub de asociación: origen de entrada y retorno al paso anterior.
 */
final class AsociacionHubNavigation
{
    private const SESSION_PREFIX = 'asociacion_hub_nav_';

    /**
     * @return array{origin_url: string, origin_label: string}
     */
    public static function captureEntry(int $orgId): array
    {
        if ($orgId <= 0) {
            return self::defaultOrigin();
        }

        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        $key = self::SESSION_PREFIX . $orgId;
        $returnUrl = trim((string) ($_GET['return_url'] ?? ''));
        if ($returnUrl !== '' && self::isAllowedOriginUrl($returnUrl)) {
            $origin = [
                'origin_url' => self::normalizeInternalUrl($returnUrl),
                'origin_label' => self::labelFromUrl($returnUrl),
            ];
            $_SESSION[$key] = $origin;

            return $origin;
        }

        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '' && ! self::isHubUrl($referer) && self::isAllowedOriginUrl($referer)) {
            $origin = [
                'origin_url' => self::normalizeInternalUrl($referer),
                'origin_label' => self::labelFromUrl($referer),
            ];
            $_SESSION[$key] = $origin;

            return $origin;
        }

        if (! empty($_SESSION[$key]['origin_url'])) {
            $stored = (string) $_SESSION[$key]['origin_url'];
            if (self::isAllowedOriginUrl($stored)) {
                return [
                    'origin_url' => $stored,
                    'origin_label' => (string) ($_SESSION[$key]['origin_label'] ?? 'Origen'),
                ];
            }
            unset($_SESSION[$key]);
        }

        $origin = self::defaultOrigin();
        $_SESSION[$key] = $origin;

        return $origin;
    }

    /**
     * @return array{origin_url: string, origin_label: string}
     */
    public static function getOrigin(int $orgId): array
    {
        if ($orgId <= 0) {
            return self::defaultOrigin();
        }
        $key = self::SESSION_PREFIX . $orgId;
        if (! empty($_SESSION[$key]['origin_url'])) {
            $stored = (string) $_SESSION[$key]['origin_url'];
            if (self::isAllowedOriginUrl($stored)) {
                return [
                    'origin_url' => $stored,
                    'origin_label' => (string) ($_SESSION[$key]['origin_label'] ?? 'Origen'),
                ];
            }
            unset($_SESSION[$key]);
        }

        return self::captureEntry($orgId);
    }

    public static function hubUrl(int $orgId, string $tab = 'info', array $extra = []): string
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        return AppHelpers::dashboard('asociacion_hub', array_merge([
            'org_id' => $orgId,
            'tab' => $tab !== '' ? $tab : 'info',
        ], $extra));
    }

    public static function normalizeEstadoTorneos(?string $estado): string
    {
        $estado = strtolower(trim((string) $estado));
        if ($estado === 'por_realizar') {
            return 'pendientes';
        }
        if (in_array($estado, ['realizados', 'en_proceso', 'pendientes'], true)) {
            return $estado;
        }

        return 'en_proceso';
    }

    public static function torneosListUrl(int $orgId, string $estado = 'en_proceso'): string
    {
        return self::hubUrl($orgId, 'torneos', [
            'estado' => self::normalizeEstadoTorneos($estado),
        ]);
    }

    /**
     * URL de retorno al listado de torneos del hub tras crear/editar torneo.
     */
    public static function redirectAfterTournamentForm(?array $request = null): ?string
    {
        $request = $request ?? array_merge($_GET, $_POST);
        if (! self::isHubContext($request)) {
            return null;
        }
        $orgId = (int) ($request['hub_org_id'] ?? 0);
        if ($orgId <= 0) {
            return null;
        }
        $estado = (string) ($request['hub_estado'] ?? 'en_proceso');

        return self::torneosListUrl($orgId, $estado);
    }

    /**
     * @return array{from: string, hub_org_id: int, hub_tab: string, hub_estado?: string}
     */
    public static function outboundParams(int $orgId, string $tab, ?string $estadoTorneos = null): array
    {
        $out = [
            'from' => 'asociacion_hub',
            'hub_org_id' => $orgId,
            'hub_tab' => $tab !== '' ? $tab : 'info',
        ];
        if ($tab === 'torneos') {
            $out['hub_estado'] = self::normalizeEstadoTorneos($estadoTorneos ?? 'en_proceso');
        }

        return $out;
    }

    public static function isHubContext(?array $request = null): bool
    {
        $request = $request ?? $_GET;

        return ($request['from'] ?? '') === 'asociacion_hub'
            && (int) ($request['hub_org_id'] ?? 0) > 0;
    }

    /**
     * URL de retorno desde una pantalla hija (torneo, ficha club, etc.).
     */
    public static function returnUrlFromRequest(?array $request = null): ?string
    {
        $request = $request ?? $_GET;
        if (! self::isHubContext($request)) {
            return null;
        }
        $orgId = (int) ($request['hub_org_id'] ?? 0);
        $tab = trim((string) ($request['hub_tab'] ?? 'info'));
        if ($tab === '') {
            $tab = 'info';
        }
        $extra = [];
        if ($tab === 'torneos' && isset($request['hub_estado'])) {
            $extra['estado'] = self::normalizeEstadoTorneos((string) $request['hub_estado']);
        }

        return self::hubUrl($orgId, $tab, $extra);
    }

    public static function returnLabelFromRequest(?array $request = null): string
    {
        $request = $request ?? $_GET;
        if (! self::isHubContext($request)) {
            return 'Volver';
        }
        $tab = (string) ($request['hub_tab'] ?? 'info');
        $labels = [
            'info' => 'Información',
            'torneos' => 'Torneos',
            'clubes' => 'Clubes',
            'afiliados' => 'Afiliados',
        ];

        return 'Volver al hub — ' . ($labels[$tab] ?? 'Asociación');
    }

    /**
     * Conserva contexto hub en URLs de torneo_gestion.
     *
     * @param array<string, scalar|null> $extra
     * @return array<string, scalar|null>
     */
    public static function mergeTorneoGestionParams(array $extra = [], ?array $request = null): array
    {
        $request = $request ?? $_GET;
        if (! self::isHubContext($request)) {
            return $extra;
        }

        return array_merge(self::outboundParams(
            (int) $request['hub_org_id'],
            (string) ($request['hub_tab'] ?? 'info')
        ), $extra);
    }

    /**
     * @return array{origin_url: string, origin_label: string}
     */
    private static function defaultOrigin(): array
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        if (class_exists('Auth', false) && Auth::isAdminGeneral()) {
            return [
                'origin_url' => AppHelpers::dashboard('listado_asociaciones'),
                'origin_label' => 'Asociaciones Afiliadas',
            ];
        }

        return [
            'origin_url' => AppHelpers::dashboard('home'),
            'origin_label' => 'Inicio',
        ];
    }

    private static function isHubUrl(string $url): bool
    {
        return str_contains($url, 'page=asociacion_hub')
            || str_contains($url, 'asociacion_hub');
    }

    /**
     * Orígenes válidos para "Volver" desde el hub (excluye listado de gestión de torneos).
     */
    private static function isAllowedOriginUrl(string $url): bool
    {
        if (! self::isSafeInternalUrl($url)) {
            return false;
        }
        if (self::isTorneoGestionIndexUrl($url)) {
            return false;
        }

        return true;
    }

    /** Listado general page=torneo_gestion&action=index (sin torneo concreto). */
    private static function isTorneoGestionIndexUrl(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return false;
        }
        parse_str($query, $params);
        $page = (string) ($params['page'] ?? '');
        if ($page !== 'torneo_gestion') {
            return false;
        }
        $action = strtolower(trim((string) ($params['action'] ?? 'index')));

        return $action === '' || $action === 'index';
    }

    private static function isSafeInternalUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            $current = $_SERVER['HTTP_HOST'] ?? '';
            if ($host === null || $current === '' || strcasecmp((string) $host, (string) $current) !== 0) {
                return false;
            }
        }
        if (str_contains($url, 'logout.php') || str_contains($url, 'login.php')) {
            return false;
        }

        return str_contains($url, 'index.php')
            || str_contains($url, 'page=')
            || ! preg_match('#^https?://#i', $url);
    }

    private static function normalizeInternalUrl(string $url): string
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        if (preg_match('#^https?://#i', $url)) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $query = parse_url($url, PHP_URL_QUERY);
            $relative = basename($path);
            if ($relative === '' || $relative === 'index.php') {
                return AppHelpers::url('index.php') . ($query ? '?' . $query : '');
            }

            return $path . ($query ? '?' . $query : '');
        }

        return $url;
    }

    private static function labelFromUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $page = (string) ($params['page'] ?? '');
            $map = [
                'listado_asociaciones' => 'Asociaciones Afiliadas',
                'home' => 'Inicio',
                'entidades' => 'Asociaciones',
                'organizaciones' => 'Organizaciones',
                'torneos_estructura' => 'Torneos',
            ];
            if (isset($map[$page])) {
                return $map[$page];
            }
        }

        return 'Origen';
    }
}
