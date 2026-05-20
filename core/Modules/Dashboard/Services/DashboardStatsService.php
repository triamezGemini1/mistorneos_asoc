<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Services;

/**
 * Fachada única para estadísticas de dashboard (sin Auth ni sesión).
 */
final class DashboardStatsService
{
    private FederacionDashboardStatsService $federacion;

    private AsociacionDashboardStatsService $asociacion;

    private ClubDashboardStatsService $club;

    public function __construct(
        ?FederacionDashboardStatsService $federacion = null,
        ?AsociacionDashboardStatsService $asociacion = null,
        ?ClubDashboardStatsService $club = null
    ) {
        $this->federacion = $federacion ?? new FederacionDashboardStatsService();
        $this->asociacion = $asociacion ?? new AsociacionDashboardStatsService();
        $this->club = $club ?? new ClubDashboardStatsService();
    }

    /**
     * @return array{metrics: list<array{label: string, value: string, hint: string}>, rankings: list<array{pos: int, nombre: string, puntos: int|string}>}
     */
    public function getFederacionStats(): array
    {
        return $this->federacion->fetch();
    }

    /**
     * @param array<string, mixed> $organizacion
     *
     * @return array{
     *   orgNombre: string,
     *   clubesCount: int,
     *   atletasCount: int,
     *   torneosActivos: int,
     *   clubes: list<array{id: int, nombre: string, delegado: string, estatus: string}>
     * }
     */
    public function getAsociacionStats(array $organizacion): array
    {
        return $this->asociacion->fetch($organizacion);
    }

    /**
     * @param array<string, mixed>|null $organizacion
     *
     * @return array{
     *   clubNombre: string,
     *   orgNombre: string,
     *   torneosActivos: list<array{id: int, nombre: string, fechator: string|null}>,
     *   mesasPendientes: int,
     *   mesas: list<array{mesa: int, ronda: int, estado: string, torneo_nombre?: string}>,
     *   quickActions: list<array{label: string, href: string, disabled: bool}>
     * }
     */
    public function getClubStats(int $clubId, ?array $organizacion = null): array
    {
        return $this->club->fetch($clubId, $organizacion);
    }
}
