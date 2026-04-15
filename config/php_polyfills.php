<?php
/**
 * Compatibilidad con PHP anterior a 8.0: str_contains, str_starts_with, str_ends_with.
 * En PHP 8+ las funciones nativas tienen prioridad (no se redeclaran).
 */
if (!function_exists('str_contains')) {
    /**
     * @param string $haystack
     * @param string $needle
     */
    function str_contains($haystack, $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strpos((string) $haystack, (string) $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * @param string $haystack
     * @param string $needle
     */
    function str_ends_with($haystack, $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        $len = strlen($needle);

        return $len <= strlen($haystack) && substr($haystack, -$len) === $needle;
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * @param string $haystack
     * @param string $needle
     */
    function str_starts_with($haystack, $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
    }
}
