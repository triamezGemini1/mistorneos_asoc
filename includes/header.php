<?php
/**
 * Cabecera común: estructura HTML superior, metadatos y carga de assets (mistorneos).
 * Favicon y rutas base dinámicos según entorno (/pruebas/public, /mistorneos_beta/public, etc.).
 * Uso: definir $header_title opcional; luego include_once __DIR__ . '/../includes/header.php';
 * No cierra </head> para que la página pueda añadir estilos o meta adicionales.
 */
if (! class_exists('Branding', false)) {
    $brandingPath = __DIR__ . '/../lib/Branding.php';
    if (is_file($brandingPath)) {
        require_once $brandingPath;
    }
}

$header_title = $header_title ?? (class_exists('Branding', false) ? Branding::siteName() : 'La Estación del Dominó');
$header_theme_color = class_exists('Branding', false) ? Branding::themeColor() : '#1a365d';
$header_meta_description = class_exists('Branding', false)
    ? Branding::metaDescription()
    : 'mistorneos - La Estación del Dominó. Gestión de torneos, inscripciones y resultados.';
// Favicon: EXCLUSIVAMENTE PNG (favicon.png ~88ms). No usar favicon.ico (363KB). Innegociable para rendimiento.
$header_asset_base = '';
if (defined('URL_BASE') && URL_BASE !== '' && URL_BASE !== '/') {
    $header_asset_base = rtrim(URL_BASE, '/');
} elseif (class_exists('AppHelpers')) {
    $pu = AppHelpers::getPublicUrl();
    $header_asset_base = (strpos($pu, 'http') === 0) ? parse_url($pu, PHP_URL_PATH) : $pu;
}
if ($header_asset_base === null || $header_asset_base === '') {
    $header_asset_base = '/mistorneos_beta/public';
}
$header_asset_base = rtrim($header_asset_base, '/');
$header_favicon_url = class_exists('Branding', false)
    ? Branding::faviconUrl()
    : ($header_asset_base . '/favicon.png');
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="<?= htmlspecialchars($header_theme_color) ?>">
  <!-- Favicon: solo PNG (favicon.png). Nunca .ico. Ejecutar make_favicon.php para generar. -->
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($header_favicon_url) ?>">
  <title><?= htmlspecialchars($header_title) ?></title>
  <meta name="description" content="<?= htmlspecialchars($header_meta_description) ?>">
<?php
$brandThemePartial = __DIR__ . '/../public/includes/partials/brand_theme.php';
if (is_file($brandThemePartial)) {
    include $brandThemePartial;
}
?>
