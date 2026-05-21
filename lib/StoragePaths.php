<?php

declare(strict_types=1);

/**
 * Asegura directorios de storage necesarios en runtime (logs, sesiones, caché).
 */
final class StoragePaths
{
    /** @return list<string> Rutas creadas o ya existentes */
    public static function ensureWritableDirs(?string $root = null): array
    {
        $root = $root ?? (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__));
        $dirs = [
            $root . '/storage',
            $root . '/storage/logs',
            $root . '/storage/cache',
            $root . '/storage/sessions',
        ];

        $created = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (@mkdir($dir, 0755, true)) {
                    $created[] = $dir;
                }
            } elseif (!is_writable($dir)) {
                @chmod($dir, 0755);
            }
        }

        return $created;
    }
}
