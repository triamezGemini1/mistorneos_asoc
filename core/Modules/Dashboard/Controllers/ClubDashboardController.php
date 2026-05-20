<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Controllers;

use Core\Http\Context;
use Core\Modules\Dashboard\Services\DashboardStatsService;

final class ClubDashboardController extends AbstractDashboardController
{
    public function index(): void
    {
        $meta = Context::resolveWithMeta();
        $club = $meta['club'] ?? null;
        $clubId = is_array($club) ? (int) ($club['id'] ?? 0) : 0;

        if ($clubId <= 0) {
            $this->display('modules/Dashboard/club', [
                'clubNombre' => 'Club',
                'orgNombre' => is_array($meta['org'] ?? null) ? (string) ($meta['org']['nombre'] ?? '') : '',
                'torneosActivos' => [],
                'mesasPendientes' => 0,
                'mesas' => [],
                'quickActions' => [
                    ['label' => 'Inscripción rápida', 'href' => '#', 'disabled' => true],
                    ['label' => 'Asignar mesas', 'href' => '#', 'disabled' => true],
                    ['label' => 'Registrar resultados', 'href' => '#', 'disabled' => true],
                ],
            ], 'Inicio — Club');

            return;
        }

        $org = is_array($meta['org'] ?? null) ? $meta['org'] : null;
        $payload = (new DashboardStatsService())->getClubStats($clubId, $org);

        $this->display(
            'modules/Dashboard/club',
            $payload,
            'Inicio — ' . $payload['clubNombre']
        );
    }
}
