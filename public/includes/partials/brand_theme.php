<?php
declare(strict_types=1);

/**
 * Variables CSS de marca por segmento (inline, sin archivo por instalación).
 */
if (! class_exists('Branding', false)) {
    require_once __DIR__ . '/../../../lib/Branding.php';
}

$brandCssVars = Branding::cssVariables();
$cssLines = [];
foreach ($brandCssVars as $var => $value) {
    $cssLines[] = $var . ': ' . $value . ';';
}
?>
<style id="segment-brand-theme">:root { <?= implode(' ', $cssLines) ?> }</style>
