<?php
/**
 * Configuración de la Landing Page - Variables centralizadas
 * Incluir antes de los componentes para tenerlas disponibles
 */
if (! defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
if (! class_exists('Branding', false)) {
    require_once __DIR__ . '/../lib/Branding.php';
}

$SITE_NAME = Branding::siteName();
$SITE_TAGLINE = Branding::tagline();
$META_TITLE = Branding::landingMetaTitle();
$META_DESCRIPTION = Branding::metaDescription();
$META_KEYWORDS = Branding::metaKeywords();
$META_AUTHOR = Branding::siteName();
$META_OG_TITLE = Branding::ogTitle();
$META_OG_DESCRIPTION = Branding::ogDescription();
$SITE_EMAIL = Branding::contactEmail();
$SITE_URL = rtrim(app_base_url(), '/') . '/public/landing-spa.php';
$OG_IMAGE = Branding::logoUrl();
