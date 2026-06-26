<?php
declare(strict_types=1);

/**
 * Configuración del segmento de producto (MisTorneos ASOC, Clubes, etc.).
 * Una instalación = APP_SEGMENT en .env + perfil en config/segments/.
 */
final class SegmentConfig
{
    /** @var array<string, mixed>|null */
    private static $config = null;

    private static $segmentKey = '';

    public static function boot(): void
    {
        self::load();
    }

    public static function segmentKey(): string
    {
        if (self::$segmentKey !== '') {
            return self::$segmentKey;
        }

        $fromEnv = '';
        if (class_exists('Env', false)) {
            $fromEnv = trim((string) Env::get('APP_SEGMENT', ''));
        }
        if ($fromEnv === '') {
            $fromEnv = 'asoc';
        }

        self::$segmentKey = preg_replace('/[^a-z0-9_]/', '', strtolower($fromEnv)) ?: 'asoc';

        return self::$segmentKey;
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $defaultsFile = __DIR__ . '/../config/segments/_defaults.php';
        $defaults = is_file($defaultsFile) ? require $defaultsFile : [];

        $segmentFile = __DIR__ . '/../config/segments/' . self::segmentKey() . '.php';
        $segment = is_file($segmentFile) ? require $segmentFile : [];

        if (! is_array($defaults)) {
            $defaults = [];
        }
        if (! is_array($segment)) {
            $segment = [];
        }

        self::$config = self::mergeRecursive($defaults, $segment);

        return self::$config;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function mergeRecursive(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Acceso con notación de puntos: product.name, features.menu_inicio
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public static function get(string $path, $default = null)
    {
        $config = self::load();
        $parts = explode('.', $path);
        $cursor = $config;

        foreach ($parts as $part) {
            if (! is_array($cursor) || ! array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    public static function feature(string $name): bool
    {
        return (bool) self::get('features.' . $name, false);
    }

    public static function productName(): string
    {
        return (string) self::get('product.name', 'MisTorneos');
    }

    public static function productShortName(): string
    {
        return (string) self::get('product.short_name', self::productName());
    }

    public static function label(string $key, string $fallback = ''): string
    {
        $value = self::get('labels.' . $key, null);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $fallback !== '' ? $fallback : $key;
    }

    public static function organizacionRaizId(): int
    {
        $fromConfig = (int) self::get('instance.organizacion_raiz_id', 0);
        if ($fromConfig > 0) {
            return $fromConfig;
        }

        if (class_exists('Env', false)) {
            return (int) Env::get('ORG_RAIZ_ID', 0);
        }

        return 0;
    }

    public static function hierarchyMode(): string
    {
        return (string) self::get('hierarchy.mode', 'sectorial');
    }
}
