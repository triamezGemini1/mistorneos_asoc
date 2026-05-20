<?php

declare(strict_types=1);

namespace Core\Bootstrap;

/**
 * Autoloader PSR-4 nativo (fallback cuando no existe vendor/autoload.php).
 * Los prefijos se inyectan en register(); no están hardcoded en la clase.
 */
final class Autoloader
{
    /** @var array<string, string> namespace prefix => directorio relativo a la raíz del proyecto */
    private static array $prefixes = [];

    private static string $projectRoot = '';

    private static bool $registered = false;

    /**
     * @param array<string, string> $mapping Ej. ['Core\\' => 'core/', 'Lib\\' => 'lib/']
     */
    public static function register(string $root, array $mapping): void
    {
        self::$projectRoot = rtrim(str_replace('\\', '/', $root), '/');
        self::$prefixes = $mapping;

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load'], true, true);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            $baseDir = str_replace('\\', '/', $baseDir);
            $file = self::$projectRoot . '/' . trim($baseDir, '/') . '/' . $relative;

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
