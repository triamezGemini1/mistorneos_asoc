<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard;

use Core\Http\Context;
use Core\Modules\Dashboard\Contracts\DashboardControllerInterface;
use Core\Modules\Dashboard\Controllers\AsociacionDashboardController;
use Core\Modules\Dashboard\Controllers\ClubDashboardController;
use Core\Modules\Dashboard\Controllers\FederacionDashboardController;

final class DashboardControllerFactory
{
    /** @var array<string, class-string<DashboardControllerInterface>> */
    private static array $map = [
        Context::FEDERACION => FederacionDashboardController::class,
        Context::ASOCIACION => AsociacionDashboardController::class,
        Context::CLUB => ClubDashboardController::class,
    ];

    public static function make(string $context): ?DashboardControllerInterface
    {
        if (!isset(self::$map[$context])) {
            return null;
        }

        $class = self::$map[$context];

        return new $class();
    }
}
