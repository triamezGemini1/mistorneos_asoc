<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Controllers;

use Core\Modules\Dashboard\Services\DashboardStatsService;

final class FederacionDashboardController extends AbstractDashboardController
{
    public function index(): void
    {
        $payload = (new DashboardStatsService())->getFederacionStats();

        $this->display('modules/Dashboard/federacion', [
            'metrics' => $payload['metrics'],
            'rankings' => $payload['rankings'],
        ], 'Inicio — Federación');
    }
}
