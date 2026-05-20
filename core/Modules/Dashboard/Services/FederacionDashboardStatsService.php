<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Services;

use DB;
use PDO;
use Throwable;

/**
 * Métricas globales de federación (Q1–Q4) y rankings opcionales.
 */
final class FederacionDashboardStatsService
{
    /**
     * @return array{metrics: list<array{label: string, value: string, hint: string}>, rankings: list<array{pos: int, nombre: string, puntos: int|string}>}
     */
    public function fetch(): array
    {
        $pdo = DB::pdo();

        return [
            'metrics' => $this->buildMetrics($pdo),
            'rankings' => $this->fetchRankings($pdo),
        ];
    }

    /**
     * @return list<array{label: string, value: string, hint: string}>
     */
    private function buildMetrics(PDO $pdo): array
    {
        $entidades = $this->countEntidades($pdo);
        $organizaciones = $this->countOrganizacionesActivas($pdo);
        $asociaciones = $this->countAsociaciones($pdo);
        $afiliados = $this->countAfiliados($pdo);

        $asocHint = DashboardSchemaHelper::hasOrganizacionesColumn('tipo_org')
            ? 'tipo_org = 0 (asociaciones territoriales)'
            : 'Sin columna tipo_org en esquema';

        $afiliadosHint = 'Usuarios activos (role usuario)';
        if (DashboardSchemaHelper::hasTable('atletas')) {
            $atletas = $this->countAtletasActivos($pdo);
            if ($atletas > 0) {
                $afiliadosHint .= ' · ' . number_format($atletas, 0, ',', '.') . ' en tabla atletas';
            }
        }

        return [
            [
                'label' => 'Entidades',
                'value' => $this->formatNumber($entidades),
                'hint' => 'Territorios con organización',
            ],
            [
                'label' => 'Organizaciones',
                'value' => $this->formatNumber($organizaciones),
                'hint' => 'Estatus activo',
            ],
            [
                'label' => 'Asociaciones',
                'value' => $this->formatNumber($asociaciones),
                'hint' => $asocHint,
            ],
            [
                'label' => 'Afiliados',
                'value' => $this->formatNumber($afiliados),
                'hint' => $afiliadosHint,
            ],
        ];
    }

    private function countEntidades(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query(
                'SELECT COUNT(DISTINCT entidad) AS total
                 FROM organizaciones
                 WHERE entidad IS NOT NULL AND entidad <> 0'
            );

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function countOrganizacionesActivas(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query(
                'SELECT COUNT(*) AS total FROM organizaciones WHERE estatus = 1'
            );

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function countAsociaciones(PDO $pdo): int
    {
        try {
            if (DashboardSchemaHelper::hasOrganizacionesColumn('tipo_org')) {
                $stmt = $pdo->query(
                    'SELECT COUNT(*) AS total
                     FROM organizaciones
                     WHERE estatus = 1 AND COALESCE(tipo_org, 0) = 0'
                );
            } else {
                $stmt = $pdo->query(
                    'SELECT COUNT(*) AS total FROM organizaciones WHERE estatus = 1'
                );
            }

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function countAfiliados(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) AS total FROM usuarios WHERE role = 'usuario' AND status = 0"
            );

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function countAtletasActivos(PDO $pdo): int
    {
        try {
            if (DashboardSchemaHelper::hasColumn('atletas', 'estatus')) {
                $stmt = $pdo->query(
                    'SELECT COUNT(*) AS total FROM atletas WHERE COALESCE(estatus, 1) = 1'
                );
            } else {
                $stmt = $pdo->query('SELECT COUNT(*) AS total FROM atletas');
            }

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return list<array{pos: int, nombre: string, puntos: int|string}>
     */
    private function fetchRankings(PDO $pdo): array
    {
        if (!DashboardSchemaHelper::hasInscritosColumn('ganados')) {
            return [];
        }

        try {
            $stmt = $pdo->query(
                "SELECT u.nombre, COALESCE(SUM(i.ganados), 0) AS puntos
                 FROM usuarios u
                 INNER JOIN inscritos i ON i.id_usuario = u.id
                 WHERE u.role = 'usuario' AND u.status = 0
                 GROUP BY u.id, u.nombre
                 ORDER BY puntos DESC, u.nombre ASC
                 LIMIT 10"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $rankings = [];
        $pos = 1;
        foreach ($rows as $row) {
            $rankings[] = [
                'pos' => $pos++,
                'nombre' => (string) ($row['nombre'] ?? ''),
                'puntos' => (int) ($row['puntos'] ?? 0),
            ];
        }

        return $rankings;
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
