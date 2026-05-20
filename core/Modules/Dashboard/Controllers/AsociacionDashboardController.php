<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Controllers;

use Core\Http\Context;
use Core\Modules\Dashboard\Services\DashboardStatsService;

final class AsociacionDashboardController extends AbstractDashboardController
{
    public function index(): void
    {
        $meta = Context::resolveWithMeta();
        $org = $meta['org'] ?? null;

        if (!is_array($org) || empty($org['id'])) {
            $this->display('modules/Dashboard/asociacion', [
                'orgNombre' => 'Asociación',
                'clubesCount' => 0,
                'atletasCount' => 0,
                'torneosActivos' => 0,
                'clubes' => [],
            ], 'Inicio — Asociación');

            return;
        }

        $payload = (new DashboardStatsService())->getAsociacionStats($org);

        $this->display(
            'modules/Dashboard/asociacion',
            $payload,
            'Inicio — ' . $payload['orgNombre']
        );
    }
}
