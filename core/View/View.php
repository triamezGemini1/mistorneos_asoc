<?php

declare(strict_types=1);

namespace Core\View;

use InvalidArgumentException;
use RuntimeException;

/**
 * Motor de vistas aislado: las plantillas solo reciben variables del array $data.
 */
final class View
{
    private static string $basePath = '';

    private static string $defaultLayout = 'layouts/main';

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function getBasePath(): string
    {
        return self::$basePath;
    }

    public static function setDefaultLayout(string $layout): void
    {
        self::$defaultLayout = ltrim(str_replace('\\', '/', $layout), '/');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $layoutData Variables adicionales para el layout (title, assetBase, menu, etc.)
     */
    public static function render(
        string $name,
        array $data = [],
        ?string $layout = null,
        array $layoutData = []
    ): string {
        $content = self::partial($name, $data);

        $layoutName = $layout ?? self::$defaultLayout;
        if ($layoutName === '' || $layoutName === 'none') {
            return $content;
        }

        $layoutFile = self::resolvePath($layoutName);
        $merged = array_merge($layoutData, ['content' => $content]);

        return self::renderFile($layoutFile, $merged);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $layoutData
     */
    public static function display(
        string $name,
        array $data = [],
        ?string $layout = null,
        array $layoutData = []
    ): void {
        echo self::render($name, $data, $layout, $layoutData);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function partial(string $name, array $data = []): string
    {
        return self::renderFile(self::resolvePath($name), $data);
    }

    private static function resolvePath(string $name): string
    {
        if (self::$basePath === '') {
            throw new RuntimeException('View::setBasePath() debe llamarse antes de renderizar.');
        }

        $relative = ltrim(str_replace('\\', '/', $name), '/');
        if (!str_ends_with($relative, '.php')) {
            $relative .= '.php';
        }

        if (str_contains($relative, '..')) {
            throw new InvalidArgumentException('Ruta de vista no válida.');
        }

        $file = self::$basePath . '/' . $relative;

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Vista no encontrada: %s', $relative));
        }

        return $file;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderFile(string $file, array $data): string
    {
        $renderer = static function (array $__viewData) use ($file): string {
            extract($__viewData, EXTR_SKIP);
            ob_start();
            require $file;

            return (string) ob_get_clean();
        };

        return $renderer($data);
    }
}
