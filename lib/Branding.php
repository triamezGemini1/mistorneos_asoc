<?php
declare(strict_types=1);

/**
 * Identidad visual y textos de marca por segmento (MisTorneos ASOC, etc.).
 */
final class Branding
{
    private const LEGACY_NAME = 'La Estación del Dominó';
    private const LEGACY_TAGLINE = 'Sistema integral para la gestión de torneos de dominó';
    private const LEGACY_EMAIL = 'info@laestaciondeldomino.com';

    private static function bootSegment(): void
    {
        if (! class_exists('SegmentConfig', false)) {
            $path = __DIR__ . '/SegmentConfig.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (class_exists('SegmentConfig', false)) {
            SegmentConfig::boot();
        }
    }

    public static function siteName(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $name = trim(SegmentConfig::productName());
            if ($name !== '') {
                return $name;
            }
        }

        return self::LEGACY_NAME;
    }

    public static function siteShortName(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $short = trim(SegmentConfig::productShortName());
            if ($short !== '') {
                return $short;
            }
        }

        return self::siteName();
    }

    public static function tagline(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('product.tagline', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::LEGACY_TAGLINE;
    }

    public static function contactEmail(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.contact_email', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::LEGACY_EMAIL;
    }

    public static function metaDescription(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.meta_description', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Plataforma integral para la gestión de torneos de dominó. Participa en eventos, consulta resultados e inscríbete en torneos.';
    }

    public static function metaKeywords(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.meta_keywords', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'dominó, torneos dominó, torneos, campeonatos, clubes dominó, resultados dominó, inscripciones torneos';
    }

    public static function landingMetaTitle(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.landing_meta_title', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::siteName() . ' - ' . self::tagline();
    }

    public static function ogTitle(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.og_title', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::siteName() . ' - Sistema de Gestión de Torneos';
    }

    public static function ogDescription(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.og_description', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::metaDescription();
    }

    public static function themeColor(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.theme_color', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '#1a365d';
    }

    public static function primaryColor(): string
    {
        self::bootSegment();
        if (class_exists('SegmentConfig', false)) {
            $value = trim((string) SegmentConfig::get('branding.primary_color', ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '#1a5276';
    }

    public static function mailFromName(): string
    {
        return self::siteName();
    }

    /**
     * @param string $pageTitle Título de la página (ej. «Iniciar Sesión»)
     */
    public static function pageTitle(string $pageTitle = ''): string
    {
        $pageTitle = trim($pageTitle);
        if ($pageTitle === '') {
            return self::siteName();
        }

        return $pageTitle . ' - ' . self::siteName();
    }

    public static function copyrightNotice(): string
    {
        return '© ' . date('Y') . ' ' . self::siteName() . '. Todos los derechos reservados.';
    }

    /**
     * Nombre de organización con fallback a la marca del sitio.
     */
    public static function orgNameOrSite(?string $organizacionNombre): string
    {
        $nombre = trim((string) $organizacionNombre);

        return $nombre !== '' ? $nombre : self::siteName();
    }

    public static function logoUrl(): string
    {
        self::bootSegment();

        if (class_exists('SegmentConfig', false)) {
            $configured = trim((string) SegmentConfig::get('branding.logo', ''));
            if ($configured !== '') {
                return self::resolveImagePath($configured);
            }
        }

        $segmentKey = class_exists('SegmentConfig', false) ? SegmentConfig::segmentKey() : 'asoc';
        $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);

        $segmentLogo = $root . '/public/assets/segments/' . $segmentKey . '/logo.png';
        if (is_file($segmentLogo)) {
            return self::publicAssetUrl('assets/segments/' . $segmentKey . '/logo.png');
        }

        $defaultLogo = $root . '/public/assets/logo.png';
        if (is_file($defaultLogo)) {
            return self::publicAssetUrl('assets/logo.png');
        }

        return self::publicAssetUrl('view_image.php?path=' . rawurlencode('lib/Assets/mislogos/logo4.png'));
    }

    public static function faviconUrl(): string
    {
        self::bootSegment();

        $segmentKey = class_exists('SegmentConfig', false) ? SegmentConfig::segmentKey() : 'asoc';
        $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);

        $segmentFavicon = $root . '/public/assets/segments/' . $segmentKey . '/favicon.png';
        if (is_file($segmentFavicon)) {
            return self::publicAssetUrl('assets/segments/' . $segmentKey . '/favicon.png');
        }

        if (class_exists('SegmentConfig', false)) {
            $configured = trim((string) SegmentConfig::get('branding.favicon', ''));
            if ($configured !== '') {
                return self::resolveImagePath($configured);
            }
        }

        return self::publicAssetUrl('favicon.png');
    }

    /**
     * @param string $class
     * @param string|null $alt null = nombre del sitio
     */
    public static function logoHtml(string $class = '', $alt = null, int $height = 40, bool $priority = false): string
    {
        $altText = $alt !== null && $alt !== '' ? $alt : self::siteName();
        $logoUrl = self::logoUrl();
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
        $priorityAttr = $priority ? ' fetchpriority="high"' : '';

        return '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="'
            . htmlspecialchars($altText, ENT_QUOTES, 'UTF-8') . '" height="' . $height . '"' . $classAttr . $priorityAttr . '>';
    }

    /**
     * Variables CSS :root para tema del segmento.
     *
     * @return array<string, string>
     */
    public static function cssVariables(): array
    {
        return [
            '--brand-primary' => self::primaryColor(),
            '--brand-theme-color' => self::themeColor(),
            '--brand-name' => '"' . self::siteName() . '"',
        ];
    }

    private static function publicAssetUrl(string $path): string
    {
        if (class_exists('AppHelpers', false)) {
            $base = rtrim(AppHelpers::getPublicUrl(), '/');
            if ($base !== '') {
                return $base . '/' . ltrim($path, '/');
            }
        }

        if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
            return rtrim(URL_BASE, '/') . '/' . ltrim($path, '/');
        }

        return '/' . ltrim($path, '/');
    }

    private static function resolveImagePath(string $path): string
    {
        if (strpos($path, 'http') === 0) {
            return $path;
        }
        if (class_exists('AppHelpers', false)) {
            $url = AppHelpers::imageUrl($path);
            if ($url !== '') {
                return $url;
            }
        }

        return self::publicAssetUrl(ltrim($path, '/'));
    }
}
