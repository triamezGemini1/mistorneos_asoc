<?php
declare(strict_types=1);

/**
 * Carga Branding sin bootstrap completo (páginas de error / mantenimiento).
 */
if (! class_exists('Branding', false)) {
    require_once dirname(__DIR__, 2) . '/lib/Env.php';
    Env::load(dirname(__DIR__, 2) . '/.env');
    require_once dirname(__DIR__, 2) . '/lib/SegmentConfig.php';
    require_once dirname(__DIR__, 2) . '/lib/Branding.php';
    SegmentConfig::boot();
}

$brand_name = Branding::siteName();
$brand_tagline = Branding::tagline();
$brand_theme_color = Branding::themeColor();
