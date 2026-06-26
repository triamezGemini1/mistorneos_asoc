<?php
declare(strict_types=1);

/**
 * Inicialización de marca para páginas públicas (standalone o módulos).
 * Tras incluirlo: $brand_name, $brand_tagline, $brand_email disponibles.
 */
if (! class_exists('Branding', false)) {
    if (! defined('APP_BOOTSTRAPPED')) {
        require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
    } else {
        require_once dirname(__DIR__, 2) . '/lib/Branding.php';
    }
}

$brand_name = Branding::siteName();
$brand_tagline = Branding::tagline();
$brand_email = Branding::contactEmail();
$brand_logo_url = Branding::logoUrl();
$brand_theme_color = Branding::themeColor();
