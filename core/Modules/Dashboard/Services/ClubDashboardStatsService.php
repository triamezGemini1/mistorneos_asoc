<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Services;

use DB;
use PDO;
use Throwable;

/**
 * Dashboard operativo de club: torneos activos (Q7), mesas (Q8) y pendientes (Q9).
 */
final class ClubDashboardStatsService
{
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
    public function fetch(int $clubId, ?array $organizacion = null): array
    {
        if ($clubId <= 0) {
            return $this->emptyPayload('Club', '');
        }

        $pdo = DB::pdo();
        $clubNombre = $this->resolveClubNombre($pdo, $clubId);
        $orgNombre = is_array($organizacion) ? (string) ($organizacion['nombre'] ?? '') : '';

        $torneos = $this->fetchTorneosActivos($pdo, $clubId, $organizacion);
        $torneoIds = array_column($torneos, 'id');

        if (DashboardSchemaHelper::hasTable('partiresul')) {
            $mesas = $this->fetchMesasFromPartiresul($pdo, $clubId, $organizacion);
            $mesasPendientes = $this->countMesasPendientesPartiresul($pdo, $clubId, $organizacion);
        } elseif (DashboardSchemaHelper::hasTable('mesas_asignacion') && $torneoIds !== []) {
            $mesas = $this->fetchMesasFromAsignacion($pdo, $torneoIds);
            $mesasPendientes = count(array_filter(
                $mesas,
                static fn (array $m): bool => ($m['estado'] ?? '') !== 'Registrado'
            ));
        } else {
            $mesas = [];
            $mesasPendientes = 0;
        }

        return [
            'clubNombre' => $clubNombre,
            'orgNombre' => $orgNombre,
            'torneosActivos' => $torneos,
            'mesasPendientes' => $mesasPendientes,
            'mesas' => $mesas,
            'quickActions' => $this->buildQuickActions($torneos),
        ];
    }

    private function resolveClubNombre(PDO $pdo, int $clubId): string
    {
        try {
            $stmt = $pdo->prepare('SELECT nombre FROM clubes WHERE id = ? LIMIT 1');
            $stmt->execute([$clubId]);
            $name = $stmt->fetchColumn();

            return is_string($name) && $name !== '' ? $name : 'Club';
        } catch (Throwable $e) {
            return 'Club';
        }
    }

    /**
     * Q7 — Torneos activos del club.
     *
     * @param array<string, mixed>|null $organizacion
     *
     * @return list<array{id: int, nombre: string, fechator: string|null}>
     */
    private function fetchTorneosActivos(PDO $pdo, int $clubId, ?array $organizacion): array
    {
        [$clubFilter, $params] = $this->tournamentClubFilter($clubId, $organizacion);
        $finalizadoSql = DashboardSchemaHelper::hasTournamentsColumn('finalizado')
            ? ' AND COALESCE(t.finalizado, 0) = 0'
            : '';

        try {
            $sql = "SELECT t.id, t.nombre, t.fechator
                    FROM tournaments t
                    WHERE t.estatus = 1
                      {$finalizadoSql}
                      AND ({$clubFilter})
                      AND (t.fechator >= CURDATE() OR t.fechator IS NULL)
                    ORDER BY t.fechator ASC, t.id DESC
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $torneos = [];
        foreach ($rows as $row) {
            $torneos[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => (string) ($row['nombre'] ?? ''),
                'fechator' => isset($row['fechator']) ? (string) $row['fechator'] : null,
            ];
        }

        return $torneos;
    }

    /**
     * Q8 — Mesas desde partiresul.
     *
     * @param array<string, mixed>|null $organizacion
     *
     * @return list<array{mesa: int, ronda: int, estado: string, torneo_nombre: string}>
     */
    private function fetchMesasFromPartiresul(PDO $pdo, int $clubId, ?array $organizacion): array
    {
        [$clubFilter, $params] = $this->tournamentClubFilter($clubId, $organizacion, 't');
        $finalizadoSql = DashboardSchemaHelper::hasTournamentsColumn('finalizado')
            ? ' AND COALESCE(t.finalizado, 0) = 0'
            : '';
        $hasRegistrado = DashboardSchemaHelper::hasColumn('partiresul', 'registrado');

        $estadoExpr = $hasRegistrado
            ? "CASE
                    WHEN SUM(CASE WHEN COALESCE(pr.registrado, 0) = 1 THEN 1 ELSE 0 END) >= COUNT(*)
                    THEN 'Registrado'
                    ELSE 'Pendiente'
               END"
            : "'Asignada'";

        try {
            $sql = "SELECT
                        t.nombre AS torneo_nombre,
                        pr.partida AS ronda,
                        pr.mesa,
                        {$estadoExpr} AS estado
                    FROM partiresul pr
                    INNER JOIN tournaments t ON t.id = pr.id_torneo
                    WHERE pr.mesa > 0
                      AND t.estatus = 1
                      {$finalizadoSql}
                      AND ({$clubFilter})
                      AND (
                            DATE(t.fechator) = CURDATE()
                            OR pr.partida = (
                                SELECT MAX(pr2.partida)
                                FROM partiresul pr2
                                WHERE pr2.id_torneo = t.id AND pr2.mesa > 0
                            )
                          )
                    GROUP BY t.id, t.nombre, pr.partida, pr.mesa
                    ORDER BY t.fechator ASC, pr.partida ASC, pr.mesa ASC
                    LIMIT 30";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return $this->mapMesasRows($rows);
    }

    /**
     * Fallback Q8 — mesas_asignacion cuando no existe partiresul.
     *
     * @param list<int> $torneoIds
     *
     * @return list<array{mesa: int, ronda: int, estado: string, torneo_nombre: string}>
     */
    private function fetchMesasFromAsignacion(PDO $pdo, array $torneoIds): array
    {
        $torneoIds = array_values(array_filter(array_map('intval', $torneoIds), static fn (int $id): bool => $id > 0));
        if ($torneoIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($torneoIds), '?'));

        try {
            $sql = "SELECT ma.tournament_id AS torneo_id, t.nombre AS torneo_nombre,
                           ma.ronda, ma.mesa, 'Asignada' AS estado
                    FROM mesas_asignacion ma
                    INNER JOIN tournaments t ON t.id = ma.tournament_id
                    WHERE ma.tournament_id IN ({$placeholders})
                      AND ma.ronda = (
                          SELECT MAX(ma2.ronda)
                          FROM mesas_asignacion ma2
                          WHERE ma2.tournament_id = ma.tournament_id
                      )
                    ORDER BY ma.ronda ASC, ma.mesa ASC
                    LIMIT 30";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($torneoIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return $this->mapMesasRows($rows);
    }

    /**
     * Q9 — Contador de mesas pendientes (partiresul).
     *
     * @param array<string, mixed>|null $organizacion
     */
    private function countMesasPendientesPartiresul(PDO $pdo, int $clubId, ?array $organizacion): int
    {
        if (!DashboardSchemaHelper::hasColumn('partiresul', 'registrado')) {
            return 0;
        }

        [$clubFilter, $params] = $this->tournamentClubFilter($clubId, $organizacion, 't');
        $finalizadoSql = DashboardSchemaHelper::hasTournamentsColumn('finalizado')
            ? ' AND COALESCE(t.finalizado, 0) = 0'
            : '';

        try {
            $sql = "SELECT COUNT(*) FROM (
                        SELECT pr.id_torneo, pr.partida, pr.mesa
                        FROM partiresul pr
                        INNER JOIN tournaments t ON t.id = pr.id_torneo
                        WHERE pr.mesa > 0
                          AND COALESCE(pr.registrado, 0) = 0
                          AND t.estatus = 1
                          {$finalizadoSql}
                          AND ({$clubFilter})
                        GROUP BY pr.id_torneo, pr.partida, pr.mesa
                    ) AS pendientes";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{mesa: int, ronda: int, estado: string, torneo_nombre: string}>
     */
    private function mapMesasRows(array $rows): array
    {
        $mesas = [];
        foreach ($rows as $row) {
            $mesas[] = [
                'mesa' => (int) ($row['mesa'] ?? 0),
                'ronda' => (int) ($row['ronda'] ?? 0),
                'estado' => (string) ($row['estado'] ?? ''),
                'torneo_nombre' => (string) ($row['torneo_nombre'] ?? ''),
            ];
        }

        return $mesas;
    }

    /**
     * Filtro OR sobre club_responsable / cod_org (IDs explícitos, sin Auth).
     *
     * @param array<string, mixed>|null $organizacion
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function tournamentClubFilter(int $clubId, ?array $organizacion, string $alias = 't'): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 't';
        }

        $parts = ["{$alias}.club_responsable = ?"];
        $params = [$clubId];

        if (is_array($organizacion)) {
            $orgPk = (int) ($organizacion['id'] ?? 0);
            if ($orgPk > 0 && $orgPk !== $clubId) {
                $parts[] = "{$alias}.club_responsable = ?";
                $params[] = $orgPk;
            }

            if (DashboardSchemaHelper::hasTournamentsColumn('cod_org')) {
                $orgCod = (int) ($organizacion['cod_org'] ?? 0);
                if ($orgCod <= 0) {
                    $orgCod = (int) ($organizacion['entidad'] ?? 0);
                }
                if ($orgCod > 0) {
                    $parts[] = "({$alias}.cod_org IS NOT NULL AND {$alias}.cod_org = ?)";
                    $params[] = $orgCod;
                }
            }
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * @param list<array{id: int, nombre: string, fechator: string|null}> $torneos
     *
     * @return list<array{label: string, href: string, disabled: bool}>
     */
    private function buildQuickActions(array $torneos): array
    {
        $base = defined('URL_BASE') ? URL_BASE : '/';
        $torneoId = isset($torneos[0]['id']) ? (int) $torneos[0]['id'] : 0;
        $hasTorneo = $torneoId > 0;

        $q = static fn (string $action) => $base . 'index.php?page=torneo_gestion&action='
            . $action . '&torneo_id=' . $torneoId;

        return [
            [
                'label' => 'Inscripción rápida',
                'href' => $hasTorneo ? $q('inscribir_sitio') : '#',
                'disabled' => !$hasTorneo,
            ],
            [
                'label' => 'Asignar mesas',
                'href' => $hasTorneo ? $q('asignar_mesas_operador') : '#',
                'disabled' => !$hasTorneo,
            ],
            [
                'label' => 'Registrar resultados',
                'href' => $hasTorneo ? $q('registrar_resultados_v2') : '#',
                'disabled' => !$hasTorneo,
            ],
        ];
    }

    /**
     * @return array{
     *   clubNombre: string,
     *   orgNombre: string,
     *   torneosActivos: list<array{id: int, nombre: string, fechator: string|null}>,
     *   mesasPendientes: int,
     *   mesas: list<array{mesa: int, ronda: int, estado: string}>,
     *   quickActions: list<array{label: string, href: string, disabled: bool}>
     * }
     */
    private function emptyPayload(string $clubNombre, string $orgNombre): array
    {
        return [
            'clubNombre' => $clubNombre,
            'orgNombre' => $orgNombre,
            'torneosActivos' => [],
            'mesasPendientes' => 0,
            'mesas' => [],
            'quickActions' => $this->buildQuickActions([]),
        ];
    }
}
