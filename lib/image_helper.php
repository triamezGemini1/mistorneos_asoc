<?php


class ImageHelper {
    private static $base_url = null;
    
    public static function getImageUrl(string $image_path): string {
        if (empty($image_path)) {
            return '';
        }
        
        // Si ya es una URL completa, devolverla tal como est�
        if (strpos($image_path, 'http') === 0) {
            return $image_path;
        }
        if (class_exists('AppHelpers')) {
            return AppHelpers::imageUrl($image_path);
        }
        $clean_path = ltrim($image_path, '/');
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $script_file = $_SERVER['SCRIPT_FILENAME'] ?? '';

        // Cuando la página se sirve desde public/, usar URL relativa para que el logo cargue siempre
        if (strpos($script_name, '/public/') !== false ||
            strpos($script_file, DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR) !== false) {
            return '../' . $clean_path;
        }

        // Fallback: URL absoluta según base del proyecto
        if (self::$base_url === null) {
            self::$base_url = self::getBaseUrl();
        }
        return self::$base_url . '/' . $clean_path;
    }
    
    private static function getBaseUrl(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Determinar el directorio base del proyecto
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        $base_path = '';
        
        if (function_exists('app_base_url')) {
            $full = app_base_url();
            $parsed = parse_url($full);
            $base_path = $parsed['path'] ?? (class_exists('AppHelpers', false) ? AppHelpers::getProjectPath() : '/mistorneos_fvd');
        } elseif (strpos($script_name, '/public/') !== false) {
            $base_path = substr($script_name, 0, strpos($script_name, '/public/'));
        } else {
            $base_path = class_exists('AppHelpers', false) ? AppHelpers::getProjectPath() : '/mistorneos_fvd';
        }
        
        return $protocol . '://' . $host . $base_path;
    }
    
    public static function getRelativeImageUrl(string $image_path): string {
        if (empty($image_path)) {
            return '';
        }
        
        // Para rutas relativas, simplemente limpiar
        $clean_path = ltrim($image_path, '/');
        
        // Determinar si necesitamos el prefijo del proyecto
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
        
        if (strpos($script_name, '/public/') !== false) {
            // Estamos en /public/, necesitamos subir un nivel
            return '../' . $clean_path;
        } else {
            // Estamos en la ra�z
            return $clean_path;
        }
    }
    
    public static function imageExists(string $image_path): bool {
        if (empty($image_path)) {
            return false;
        }
        
        $physical_path = __DIR__ . '/../' . ltrim($image_path, '/');
        return file_exists($physical_path);
    }
    
    public static function getImageInfo(string $image_path): array {
        $info = [
            'exists' => false,
            'size' => 0,
            'type' => '',
            'url' => ''
        ];
        
        if (empty($image_path)) {
            return $info;
        }
        
        $physical_path = __DIR__ . '/../' . ltrim($image_path, '/');
        
        if (file_exists($physical_path)) {
            $info['exists'] = true;
            $info['size'] = filesize($physical_path);
            $info['type'] = mime_content_type($physical_path);
            $info['url'] = self::getImageUrl($image_path);
        }
        
        return $info;
    }
}







