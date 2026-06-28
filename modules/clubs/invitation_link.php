<?php
declare(strict_types=1);

/**
 * P?gina obsoleta: redirige al origen correspondiente (detalle de club o listado).
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ClubNavigation.php';

Auth::requireRole(['admin_club', 'admin_general']);

ClubNavigation::redirectLegacyInvitationLink($_GET);
