<?php
declare(strict_types=1);

/**
 * Recuerda la última pantalla del dashboard visitada (no reporte) y la ofrece como destino de «Volver»
 * en reportes, vía sesión + atributo data-nav-origin consumido por breadcrumb-back.js.
 */
final class ReportReturnNavigation
{
    public const SESSION_KEY = 'app_nav_return_index_query';

    private const MAX_STORE_LEN = 2048;

    /** @var list<string> */
    private const REPORT_PAGES = [
        'registrants_report',
        'registrants_report_retirados',
        'estadisticas_torneos',
        'reportes_pago_usuarios',
        'auditoria',
        'statistics',
    ];

    /** @var array<string, true> */
    private const REPORT_TORNEO_GESTION_ACTIONS = [
        'reportes_inscritos' => true,
        'resultados_reportes' => true,
        'resultados_reportes_print' => true,
        'resultados_general' => true,
        'resultados_equipos_resumido' => true,
        'resultados_equipos_detallado' => true,
    ];

    /** @var array<string, true> */
    private const REPORT_TOURNAMENT_ADMIN_ACTIONS = [
        'generar_qr' => true,
        'imprimir_qr_lote' => true,
        'reporte_identificacion_jugadores' => true,
    ];

    public static function isReportView(string $page, string $action): bool
    {
        if (in_array($page, self::REPORT_PAGES, true)) {
            return true;
        }
        if ($page === 'invitations' && $action === 'reporte_pagos') {
            return true;
        }
        if ($page === 'torneo_gestion' && $action !== '' && isset(self::REPORT_TORNEO_GESTION_ACTIONS[$action])) {
            return true;
        }
        if ($page === 'tournament_admin' && $action !== '' && isset(self::REPORT_TOURNAMENT_ADMIN_ACTIONS[$action])) {
            return true;
        }

        return false;
    }

    /**
     * En cada GET «normal», guarda la URL interna actual para usarla al salir de reportes.
     */
    public static function updateSessionFromRequest(string $page, string $action): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return;
        }
        if (isset($_GET['ajax'])) {
            return;
        }
        if (self::isReportView($page, $action)) {
            return;
        }

        $built = self::buildCurrentIndexQuery();
        if ($built === '') {
            return;
        }

        $_SESSION[self::SESSION_KEY] = $built;
    }

    public static function buildCurrentIndexQuery(): string
    {
        $get = $_GET;
        if (!is_array($get)) {
            return 'index.php?page=home';
        }
        $q = http_build_query($get, '', '&', PHP_QUERY_RFC3986);
        if ($q === '') {
            return 'index.php?page=home';
        }
        $full = 'index.php?' . $q;
        if (strlen($full) > self::MAX_STORE_LEN) {
            return substr($full, 0, self::MAX_STORE_LEN);
        }

        return $full;
    }

    /**
     * Ruta relativa segura (solo index.php?...) o cadena vacía.
     */
    public static function getStoredReturnRelativeUrl(): string
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        return self::sanitizeRelativeReturn(trim($raw));
    }

    /**
     * URL absoluta hacia la pantalla recordada o inicio (para vistas sin &lt;base&gt; del layout).
     */
    public static function getReturnAbsoluteUrl(): string
    {
        require_once __DIR__ . '/app_helpers.php';
        $rel = self::getStoredReturnRelativeUrl();
        if ($rel === '' || preg_match('/^index\.php\?(.*)$/s', $rel, $m) !== 1) {
            return AppHelpers::url('index.php', ['page' => 'home']);
        }
        parse_str($m[1], $params);
        if (!is_array($params)) {
            return AppHelpers::url('index.php', ['page' => 'home']);
        }
        if (!isset($params['page']) || $params['page'] === '') {
            $params['page'] = 'home';
        }

        return AppHelpers::url('index.php', $params);
    }

    private static function sanitizeRelativeReturn(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            if (empty($_SERVER['HTTP_HOST'])) {
                return '';
            }
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === null || strcasecmp((string) $host, (string) $_SERVER['HTTP_HOST']) !== 0) {
                return '';
            }
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $query = parse_url($url, PHP_URL_QUERY);
            $baseName = basename(str_replace('\\', '/', $path));
            if ($baseName !== 'index.php' || $query === null || $query === '') {
                return '';
            }

            return self::sanitizeRelativeReturn('index.php?' . $query);
        }
        if (preg_match('#^(\./)?index\.php\?#', $url) !== 1) {
            return '';
        }

        return $url;
    }
}
