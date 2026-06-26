<?php

declare(strict_types=1);

/**
 * Identidad institucional única: Federación Venezolana de Dominó (FVD).
 * Punto de anclaje global; no usar valores de GET/POST para organizacion_id.
 */
final class FvdConfig
{
    public const ORGANIZACION_ID = 1;
    public const ORGANIZACION_NOMBRE = 'FEDERACION VENEZOLANA DE DOMINO';
    public const ORGANIZACION_SIGLAS = 'FVD';

    /**
     * Fila territorial en `entidad` para la FVD: alcance etiquetado como "Nacional".
     * No se usa como filtro estricto de torneos (véase {@see entidadTerritorioEfectivaOrganizacion()}).
     */
    public const ENTIDAD_AMBITO_NACIONAL_ID = 999;

    /**
     * ID simbólica en `inscritos.inscrito_por` para inscripciones hechas en línea
     * desde el landing público (sin operador humano en sesión).
     */
    public const INSCRITO_POR_LANDING_PUBLICO = 9999;

    /** Carpeta del proyecto bajo el document root (WAMP: /mistorneos_fvd). */
    public const APP_FOLDER = 'mistorneos_fvd';

    public const BASE_PATH = '/mistorneos_fvd/public/';

    public static function localAppUrl(): string
    {
        return 'http://localhost/' . self::APP_FOLDER;
    }

    public static function localPublicUrl(): string
    {
        return self::localAppUrl() . '/public';
    }

    /** URL base de la app (respeta APP_URL / app_base_url / localhost FVD). */
    public static function resolveAppUrl(): string
    {
        $env = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        if (function_exists('app_base_url')) {
            return app_base_url();
        }
        if (class_exists('AppHelpers', false)) {
            return AppHelpers::getBaseUrl();
        }
        return self::localAppUrl();
    }

    /** URL de public/ (assets, APIs, view_file, etc.). */
    public static function resolvePublicUrl(): string
    {
        if (class_exists('AppHelpers', false)) {
            return AppHelpers::getPublicUrl();
        }
        if (defined('URL_BASE') && is_string(URL_BASE) && URL_BASE !== '' && URL_BASE !== '/') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $scheme . '://' . $host . rtrim(URL_BASE, '/');
        }
        return rtrim(self::resolveAppUrl(), '/') . '/public';
    }

    /** @var array<string, mixed>|null */
    private static ?array $maestraCache = null;

    private static bool $maestraWarmAttempted = false;

    public static function organizacionId(): int
    {
        if (class_exists('SegmentConfig', false)) {
            $fromSegment = SegmentConfig::organizacionRaizId();
            if ($fromSegment > 0) {
                return $fromSegment;
            }
        }

        return self::ORGANIZACION_ID;
    }

    /** Organización raíz del segmento (federación / ámbito nacional). */
    public static function organizacionEsAmbitoNacional(?array $organizacion): bool
    {
        return is_array($organizacion)
            && (int) ($organizacion['id'] ?? 0) === self::organizacionId();
    }

    /**
     * Para SQL de alcance territorial (torneos/usuarios): la FVD se trata como 0 (nacional).
     */
    public static function entidadTerritorioEfectivaOrganizacion(?array $organizacion): int
    {
        if ($organizacion === null) {
            return 0;
        }
        if (self::organizacionEsAmbitoNacional($organizacion)) {
            return 0;
        }

        return (int) ($organizacion['entidad'] ?? 0);
    }

    /**
     * Ignora cualquier entrada externa (formulario, URL, API).
     */
    public static function resolveOrganizacionId(mixed $ignored = null): int
    {
        return self::organizacionId();
    }

    public static function clubResponsableTorneo(mixed $ignored = null): int
    {
        return self::organizacionId();
    }

    /**
     * Fija la organización en sesión tras login o en cada petición autenticada.
     */
    public static function anchorSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $rootId = self::organizacionId();
        $_SESSION['organizacion_id'] = $rootId;
        $_SESSION['organizacion_nombre'] = self::getOrganizacionNombre();
        $_SESSION['organizacion_siglas'] = self::organizacionSiglas();
        $_SESSION['fvd_anchor'] = true;

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['organizacion_id'] = $rootId;
        }
    }

    /**
     * Re-aplica el anclaje si hay usuario en sesión (evita drift por GET/POST legacy).
     */
    public static function ensureSessionAnchorIfAuthenticated(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || empty($user)) {
            return;
        }
        self::anchorSession();
    }

    /**
     * La FVD (id 1) siempre se considera activa y al día; sin validación SaaS.
     */
    public static function isOrganizacionOperativa(int $organizacionId): bool
    {
        return $organizacionId === self::organizacionId();
    }

    public static function organizacionSiglas(): string
    {
        $row = self::getOrganizacionMaestra();
        $siglas = trim((string) ($row['siglas'] ?? ''));
        if ($siglas !== '') {
            return $siglas;
        }
        if (class_exists('SegmentConfig', false)) {
            $fromSegment = trim((string) SegmentConfig::get('product.short_name', ''));
            if ($fromSegment !== '') {
                return $fromSegment;
            }
        }

        return self::ORGANIZACION_SIGLAS;
    }

    /** @alias isOrganizacionOperativa */
    public static function organizacionEstaActiva(int $organizacionId): bool
    {
        return self::isOrganizacionOperativa($organizacionId);
    }

    public static function organizacionAlDiaConPago(int $organizacionId): bool
    {
        return self::isOrganizacionOperativa($organizacionId);
    }

    /**
     * Carga en memoria la fila de organizaciones id = 1 (una vez por petición).
     */
    public static function warmOrganizacionMaestra(): void
    {
        if (self::$maestraWarmAttempted) {
            return;
        }
        self::$maestraWarmAttempted = true;

        if (self::$maestraCache !== null) {
            return;
        }

        $rootId = self::organizacionId();
        self::$maestraCache = [
            'id' => $rootId,
            'nombre' => self::ORGANIZACION_NOMBRE,
            'siglas' => self::ORGANIZACION_SIGLAS,
            'estatus' => 1,
        ];

        if (!class_exists('DB', false)) {
            return;
        }

        try {
            $pdo = DB::pdo();
            $cols = $pdo->query('SHOW COLUMNS FROM organizaciones')->fetchAll(PDO::FETCH_COLUMN);
            $colSet = array_map('strtolower', $cols);
            $select = ['id', 'nombre', 'estatus'];
            if (in_array('siglas', $colSet, true)) {
                $select[] = 'siglas';
            }
            if (in_array('logo', $colSet, true)) {
                $select[] = 'logo';
            }
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM organizaciones WHERE id = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$rootId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                self::$maestraCache = array_merge(self::$maestraCache, $row);
                self::$maestraCache['id'] = $rootId;
                if (empty(self::$maestraCache['nombre'])) {
                    self::$maestraCache['nombre'] = self::ORGANIZACION_NOMBRE;
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('FvdConfig::warmOrganizacionMaestra: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getOrganizacionMaestra(): ?array
    {
        self::warmOrganizacionMaestra();
        return self::$maestraCache;
    }

    public static function getOrganizacionNombre(): string
    {
        $row = self::getOrganizacionMaestra();
        $nombre = trim((string)($row['nombre'] ?? ''));
        return $nombre !== '' ? $nombre : self::ORGANIZACION_NOMBRE;
    }
}
