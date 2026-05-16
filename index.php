<?php
/**
 * Punto de entrada raíz.
 * Redirige a public/index.php (app principal).
 * Ej.: http://localhost/mistorneos_fvd/ → .../mistorneos_fvd/public/index.php
 */
$reqUri = rtrim($_SERVER['REQUEST_URI'] ?? '/', '/');
if ($reqUri === '') {
    $reqUri = '/';
}
if (preg_match('#^/(mistorneos_fvd|pruebas|mistorneos_beta|public)(/|$)#', $reqUri, $m)) {
    $base = '/' . $m[1];
} elseif (!empty($_SERVER['SCRIPT_NAME'])) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($base === '.' || $base === '') {
        $base = '';
    }
} else {
    $base = '';
}
$target = ($base !== '' ? $base . '/' : '') . 'public/index.php';
if (!headers_sent()) {
    header('Location: ' . $target, true, 302);
}
exit;
