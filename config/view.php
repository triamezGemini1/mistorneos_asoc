<?php

declare(strict_types=1);

/**
 * Configuración del motor de vistas (Fase 0).
 * Requiere autoload activo (Composer o Core\Bootstrap\Autoloader).
 */

use Core\View\View;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

View::setBasePath(APP_ROOT . '/views');
View::setDefaultLayout('layouts/main');
