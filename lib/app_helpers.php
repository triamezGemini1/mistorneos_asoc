<?php

/**
 * Helper centralizado para la aplicaci�n
 * Detecta autom�ticamente el entorno y simplifica la generaci�n de URLs
 */
class AppHelpers {
    public static ?bool $is_production = null;
    public static ?string $base_url = null;
    
    /**
     * Detecta si estamos en producci�n
     */
    public static function isProduction(): bool {
        if (self::$is_production === null) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $server_name = $_SERVER['SERVER_NAME'] ?? '';
            
            // Indicadores de producci�n
            self::$is_production = (
                strpos($host, 'laestacion') !== false ||
                strpos($host, 'laestaciondeldomino.com') !== false ||
                strpos($host, 'laestaciondeldominohoy.com') !== false ||
                strpos($host, 'mistorneos.com') !== false ||
                strpos($server_name, 'laestacion') !== false ||
                (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' && !strpos($host, 'localhost'))
            );
        }
        
        return self::$is_production;
    }
    
    /**
     * Obtiene la URL base de la aplicación (raíz del proyecto, sin /public).
     * Detecta automáticamente localhost vs producción: en localhost usa /mistorneos_fvd
     * si APP_URL no está definida; en producción se recomienda definir APP_URL en .env.
     */
    public static function getProjectFolder(): string
    {
        return class_exists('FvdConfig', false) ? FvdConfig::APP_FOLDER : 'mistorneos_fvd';
    }

    /**
     * Path del proyecto bajo el host (ej. /mistorneos_fvd).
     */
    public static function getProjectPath(): string
    {
        $folder = self::getProjectFolder();
        if (isset($_SERVER['REQUEST_URI'])) {
            $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($uriPath && preg_match('#/' . preg_quote($folder, '#') . '(/|$)#', $uriPath)) {
                return '/' . $folder;
            }
        }
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            if (preg_match('#/' . preg_quote($folder, '#') . '(/|$)#', $scriptDir)) {
                return '/' . $folder;
            }
            if (str_ends_with($scriptDir, '/public') || strpos($scriptDir, '/public/') !== false) {
                $derived = $scriptDir === '/public' ? '' : rtrim(preg_replace('#/public/?$#', '', $scriptDir), '/');
                if ($derived !== '' && str_contains($derived, $folder)) {
                    return $derived[0] === '/' ? $derived : '/' . $derived;
                }
            }
        }
        return '/' . $folder;
    }

    public static function getBaseUrl(): string {
        if (self::$base_url === null) {
            $fromEnv = class_exists('Env') ? Env::get('APP_URL') : null;
            $fromConfig = $GLOBALS['APP_CONFIG']['app']['base_url'] ?? null;

            if (!empty($fromEnv)) {
                self::$base_url = rtrim($fromEnv, '/');
            } elseif (!empty($fromConfig) && $fromConfig !== '/') {
                $cfg = $fromConfig;
                if (!preg_match('#^https?://#', $cfg)) {
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    self::$base_url = $protocol . '://' . $host . $cfg;
                } else {
                    self::$base_url = rtrim($cfg, '/');
                }
            }
            if (self::$base_url === null) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $hostLower = strtolower($host);
                $isLocalhost = ($hostLower === 'localhost' || $hostLower === '127.0.0.1'
                    || strpos($hostLower, 'localhost:') === 0 || strpos($hostLower, '127.0.0.1:') === 0);
                $path = $isLocalhost ? self::getProjectPath() : self::getProjectPath();
                if ($path === '/' && !empty($_SERVER['SCRIPT_NAME'])) {
                    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                    if ($scriptDir !== '.' && $scriptDir !== '' && $scriptDir !== '/') {
                        if (str_ends_with($scriptDir, '/public') || strpos($scriptDir, '/public/') !== false) {
                            $path = $scriptDir === '/public' ? '' : rtrim(preg_replace('#/public/?$#', '', $scriptDir), '/');
                            if ($path !== '' && $path[0] !== '/') {
                                $path = '/' . $path;
                            }
                        }
                    }
                }
                self::$base_url = $protocol . '://' . $host . $path;
            }
            if (str_ends_with(self::$base_url, '/public')) {
                self::$base_url = rtrim(substr(self::$base_url, 0, -7), '/');
            }
        }
        return self::$base_url;
    }

    /**
     * URL de la carpeta public/ (assets, index.php, etc.)
     * Si está definida URL_BASE (ej. /pruebas/public/), se usa para anclar a la subcarpeta.
     */
    public static function getPublicUrl(): string {
        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path = rtrim(URL_BASE, '/');
            // Si la petición es a public/api/..., la base debe ser public/, no public/api/
            if (preg_match('#^(.*/public)/api(/.*)?$#', $path)) {
                $path = preg_replace('#^(.*/public)/api(/.*)?$#', '$1', $path);
            } elseif (preg_match('#^(.*)/api$#', $path)) {
                $path = preg_replace('#^(.*)/api$#', '$1', $path);
            }
            return $scheme . '://' . $host . $path;
        }
        return rtrim(self::getBaseUrl(), '/') . '/public';
    }

    /**
     * Base URL del entry point actual (SCRIPT_NAME), para que redirects no se vayan a la raíz del dominio.
     * Uso: header('Location: ' . AppHelpers::getRequestEntryUrl() . '/index.php');
     * En /pruebas/public/ o /mistorneos_beta/public/ devuelve la URL de esa carpeta.
     */
    public static function getRequestEntryUrl(): string {
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = dirname($_SERVER['SCRIPT_NAME']);
            if ($dir !== '.' && $dir !== '' && $dir !== '/') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                return rtrim($scheme . '://' . $host . str_replace('\\', '/', $dir), '/');
            }
        }
        return rtrim(self::getPublicUrl(), '/');
    }
    
    /**
     * Genera URL para cualquier archivo de la aplicaci�n
     * SIMPLIFICADO: Siempre usar /public/ para archivos PHP (como en desarrollo)
     */
    public static function url(string $path = '', array $params = []): string {
        $base = self::getPublicUrl();
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'public/')) {
            $path = substr($path, 7);
        }
        $url = $base . ($path !== '' ? '/' . $path : '');
        
        // Agregar par�metros si existen
        if (!empty($params)) {
            $query_string = http_build_query($params);
            $url .= '?' . $query_string;
        }
        
        return $url;
    }
    
    /**
     * Genera URL para el dashboard
     */
    public static function dashboard(string $page = 'home', array $params = []): string {
        $params['page'] = $page;
        return self::url('index.php', $params);
    }

    /** URL segura a torneo_gestion (siempre vía public/index.php; evita enlaces rotos a modules/). */
    public static function torneoGestionUrl(string $action, int $torneoId, array $extra = []): string {
        return self::url('index.php', array_merge([
            'page' => 'torneo_gestion',
            'action' => $action,
            'torneo_id' => $torneoId,
        ], $extra));
    }

    /**
     * URL para "Volver al panel" según rol (operativo asociación → asociacion_panel).
     */
    public static function urlPanelTorneoReturn(int $torneoId = 0, array $extra = []): string
    {
        if (class_exists('Auth') && Auth::isOperativoSoloAsociacion()) {
            $params = $extra;
            if ($torneoId > 0) {
                $params['torneo_id'] = $torneoId;
            }

            return self::dashboard('asociacion_panel', $params);
        }

        return self::torneoGestionUrl('panel', $torneoId, $extra);
    }
    
    /**
     * Genera URL para archivos espec�ficos
     */
    public static function file(string $filename, array $params = []): string {
        return self::url($filename, $params);
    }
    
    /**
     * Genera URL para logout
     */
    public static function logout(): string {
        return self::url('logout.php');
    }
    
    /**
     * Genera URL para login
     */
    public static function login(): string {
        return self::url('login.php');
    }
    
    /**
     * Genera URL para invitaciones simples
     */
    public static function simpleInvitation(int $torneoId, int $clubId): string {
        return self::url('simple_invitation_login.php', [
            'torneo' => $torneoId,
            'club' => $clubId
        ]);
    }
    
    /**
     * Genera URL para archivos de torneo
     */
    public static function tournamentFile(string $filePath): string {
        return self::url('view_tournament_files.php', ['file' => $filePath]);
    }
    
    /**
     * Genera URL para endpoints de API
     */
    public static function api(string $endpoint, array $params = []): string {
        return self::url('api/' . ltrim($endpoint, '/'), $params);
    }
    
    /**
     * Obtiene el path relativo correcto para archivos públicos
     * (usado en JavaScript para AJAX calls)
     */
    public static function getPublicPath(): string {
        $base = self::getBaseUrl();
        $parsed = parse_url($base);
        $path = $parsed['path'] ?? self::getProjectPath();
        return rtrim($path, '/') . '/public/';
    }
    
    /**
     * Redirige a una URL
     */
    public static function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Redirige al dashboard
     */
    public static function redirectToDashboard(string $page = 'home', array $params = []): void {
        self::redirect(self::dashboard($page, $params));
    }

    /**
     * Redirige al origen (política: siempre regresar al origen salvo navegación expedita).
     * Usa return_to o from (POST/GET); si no hay, usa referrer mismo-origen; si no, fallback.
     */
    public static function redirectToOrigin(string $fallbackPage = 'home', array $fallbackParams = []): void {
        $origin = $_POST['return_to'] ?? $_GET['return_to'] ?? $_GET['from'] ?? '';
        if ($origin !== '') {
            $decoded = rawurldecode($origin);
            $safe = (strpos($decoded, 'http') !== 0);
            if (!$safe && isset($_SERVER['HTTP_HOST'])) {
                $host = parse_url($decoded, PHP_URL_HOST);
                $safe = ($host === null || $host === $_SERVER['HTTP_HOST']);
            }
            if ($safe) {
                self::redirect($decoded);
                return;
            }
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref !== '' && strpos($ref, 'http') === 0) {
            $refHost = parse_url($ref, PHP_URL_HOST);
            $curHost = $_SERVER['HTTP_HOST'] ?? '';
            if ($refHost === $curHost) {
                self::redirect($ref);
                return;
            }
        }
        self::redirectToDashboard($fallbackPage, $fallbackParams);
    }
    
    /**
     * Obtiene informaci�n del entorno para debugging
     */
    public static function getEnvironmentInfo(): array {
        return [
            'is_production' => self::isProduction(),
            'base_url' => self::getBaseUrl(),
            'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'https' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'server_name' => $_SERVER['SERVER_NAME'] ?? '',
        ];
    }
    
    /**
     * Obtiene la URL del logo principal.
     * Prioridad: public/assets/logo.png (estático) si existe; si no, view_image.php con lib/Assets/mislogos/logo4.png.
     */
    public static function getAppLogo(): string {
        $publicLogo = __DIR__ . '/../public/assets/logo.png';
        if (is_file($publicLogo)) {
            return rtrim(self::getPublicUrl(), '/') . '/assets/logo.png';
        }
        return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png');
    }
    
    /**
     * Genera el HTML para mostrar el logo de la aplicación
     * @param string $class Clases CSS adicionales
     * @param string $alt Texto alternativo
     * @param int $height Altura en píxeles (por defecto 40)
     * @param bool $priority Si true, añade fetchpriority="high" para LCP (logo principal del dashboard)
     */
    public static function appLogo(string $class = '', string $alt = 'La Estación del Dominó', int $height = 40, bool $priority = false): string {
        $logo_url = self::getAppLogo();
        $class_attr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
        $priority_attr = $priority ? ' fetchpriority="high"' : '';
        return '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($alt) . '" height="' . $height . '"' . $class_attr . $priority_attr . '>';
    }

    /**
     * URL absoluta para cualquier imagen (logos, fotos, etc.) en todas las pantallas.
     * Usa view_image.php; la URL es absoluta para que funcione con cualquier subpath (/pruebas/public/, /mistorneos_beta/public/, etc.).
     * @param string|null $path Ruta relativa al proyecto, ej: upload/logos/logo_1.jpg o lib/Assets/mislogos/logo4.png
     * @return string URL completa para src="..." o string vacío si no hay path
     */
    public static function imageUrl(?string $path): string {
        if ($path === null || $path === '') {
            return '';
        }
        if (strpos($path, 'http') === 0) {
            return $path;
        }
        $path = ltrim($path, '/\\');
        return rtrim(self::getPublicUrl(), '/') . '/view_image.php?path=' . rawurlencode($path);
    }
}

?>
