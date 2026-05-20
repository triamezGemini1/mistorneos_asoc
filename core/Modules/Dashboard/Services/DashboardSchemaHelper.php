<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Services;

use DB;
use PDO;
use Throwable;

/**
 * Introspección de esquema con caché estática por petición (SHOW COLUMNS).
 */
final class DashboardSchemaHelper
{
    /** @var array<string, bool> clave "tabla.columna" => existe */
    private static array $columnCache = [];

    /** @var array<string, bool> clave nombre tabla => existe */
    private static array $tableCache = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $table = self::sanitizeIdentifier($table);
        $column = self::sanitizeIdentifier($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        try {
            $quoted = self::pdo()->quote($column);
            $exists = (bool) self::pdo()
                ->query("SHOW COLUMNS FROM `{$table}` LIKE {$quoted}")
                ->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $exists = false;
        }

        return self::$columnCache[$key] = $exists;
    }

    public static function hasTable(string $table): bool
    {
        $table = self::sanitizeIdentifier($table);
        if ($table === '') {
            return false;
        }

        if (array_key_exists($table, self::$tableCache)) {
            return self::$tableCache[$table];
        }

        try {
            $quoted = self::pdo()->quote($table);
            $exists = (bool) self::pdo()
                ->query("SHOW TABLES LIKE {$quoted}")
                ->fetch(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            $exists = false;
        }

        return self::$tableCache[$table] = $exists;
    }

    public static function hasOrganizacionesColumn(string $column): bool
    {
        return self::hasColumn('organizaciones', $column);
    }

    public static function hasTournamentsColumn(string $column): bool
    {
        return self::hasColumn('tournaments', $column);
    }

    public static function hasInscritosColumn(string $column): bool
    {
        return self::hasColumn('inscritos', $column);
    }

    /** Limpia la caché (útil en tests). */
    public static function resetCache(): void
    {
        self::$columnCache = [];
        self::$tableCache = [];
    }

    private static function pdo(): PDO
    {
        return DB::pdo();
    }

    private static function sanitizeIdentifier(string $value): string
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value) ? $value : '';
    }
}
