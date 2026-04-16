<?php
declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';

/**
 * Ranking público de atletas por sexo: acumula rendimiento en torneos finalizados
 * visibles (publicar_landing), alineado con datos de inscritos (ptosrnk, efectividad, posición).
 */
final class RankingAtletasPublicoService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function hasColumnPublicarLanding(): bool
    {
        try {
            $c = $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'publicar_landing'")->fetchAll();

            return ! empty($c);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasColumnCodOrg(): bool
    {
        try {
            $c = $this->pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetchAll();
            return !empty($c);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Algunas BD no tienen tournaments.cod_org; no usar COALESCE(t.cod_org, …) en ese caso. */
    private function hasColumnTournamentsCodOrg(): bool
    {
        try {
            $this->pdo->query('SELECT `cod_org` FROM `tournaments` LIMIT 0');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Filas de participación + torneo para un sexo (M|F).
     *
     * @return list<array<string, mixed>>
     */
    public function filasParticipacionPorSexo(string $sexo, int $organizacionId = 0): array
    {
        $sexo = strtoupper($sexo) === 'F' ? 'F' : 'M';
        $organizacionId = max(0, $organizacionId);
        $pub = $this->hasColumnPublicarLanding()
            ? ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)'
            : '';

        $tOrgRef = $this->hasColumnTournamentsCodOrg()
            ? 'COALESCE(t.cod_org, t.club_responsable, 0)'
            : 'COALESCE(t.club_responsable, 0)';

        $whereOrg = '';
        /** @var list<int|float|string> $orgParams */
        $orgParams = [];
        if ($organizacionId > 0) {
            // Solo placeholders posicionales (?): evita HY093 con PDO nativo y nombres duplicados.
            if ($this->hasColumnCodOrg()) {
                $whereOrg = " AND EXISTS (
                        SELECT 1
                        FROM organizaciones ox
                        WHERE (ox.id = ? OR ox.cod_org = ?)
                          AND (ox.id = {$tOrgRef} OR ox.cod_org = {$tOrgRef})
                    )";
                $orgParams = [$organizacionId, $organizacionId];
            } else {
                $whereOrg = " AND {$tOrgRef} = ?";
                $orgParams = [$organizacionId];
            }
        }
        $wEst = InscritosHelper::sqlWhereActivoConAlias('i');
        $ig = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $ie = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $ip = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $ipt = InscritosHelper::sqlExprColumnaNumerica('i.ptosrnk');
        $sql = "
            SELECT
                u.id AS id_usuario,
                COALESCE(NULLIF(TRIM(u.nombre), ''), u.username) AS nombre_atleta,
                u.cedula,
                u.sexo,
                i.torneo_id,
                t.nombre AS torneo_nombre,
                t.fechator,
                t.modalidad,
                COALESCE(i.posicion, 0) AS posicion,
                $ig AS ganados,
                COALESCE(CAST(i.perdidos AS SIGNED), 0) AS perdidos,
                $ie AS efectividad,
                $ip AS puntos,
                $ipt AS ptosrnk
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            INNER JOIN tournaments t ON i.torneo_id = t.id
            WHERE u.sexo = ?
            AND $wEst
            AND t.estatus = 1
            AND COALESCE(t.ranking, 0) = 1
            AND DATE(t.fechator) < CURDATE()
            {$pub}
            {$whereOrg}
            ORDER BY u.id ASC, t.fechator DESC
        ";
        $st = $this->pdo->prepare($sql);
        $params = array_merge([$sexo], $orgParams);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{
     *   criterio_orden: string,
     *   atletas: list<array{
     *     rank: int,
     *     id_usuario: int,
     *     nombre: string,
     *     cedula: string,
     *     sexo: string,
     *     torneos_count: int,
     *     total_ptosrnk: int,
     *     total_efectividad: int,
     *     total_ganados: int,
     *     total_puntos: int,
     *     detalle_torneos: list<array<string, mixed>>
     *   }>
     * }
     */
    public function construirRanking(string $sexo, int $organizacionId = 0): array
    {
        $filas = $this->filasParticipacionPorSexo($sexo, $organizacionId);
        /** @var array<int, array{id_usuario: int, nombre: string, cedula: string, sexo: string, torneos: list<array<string, mixed>>, sum_pt: int, sum_ef: int, sum_g: int, sum_pu: int}> $porUsuario */
        $porUsuario = [];
        foreach ($filas as $row) {
            $uid = (int) ($row['id_usuario'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if (! isset($porUsuario[$uid])) {
                $porUsuario[$uid] = [
                    'id_usuario' => $uid,
                    'nombre' => (string) ($row['nombre_atleta'] ?? ''),
                    'cedula' => (string) ($row['cedula'] ?? ''),
                    'sexo' => (string) ($row['sexo'] ?? ''),
                    'torneos' => [],
                    'sum_pt' => 0,
                    'sum_ef' => 0,
                    'sum_g' => 0,
                    'sum_pu' => 0,
                ];
            }
            $pt = (int) ($row['ptosrnk'] ?? 0);
            $ef = (int) ($row['efectividad'] ?? 0);
            $g = (int) ($row['ganados'] ?? 0);
            $pu = (int) ($row['puntos'] ?? 0);
            $porUsuario[$uid]['sum_pt'] += $pt;
            $porUsuario[$uid]['sum_ef'] += $ef;
            $porUsuario[$uid]['sum_g'] += $g;
            $porUsuario[$uid]['sum_pu'] += $pu;
            $porUsuario[$uid]['torneos'][] = [
                'torneo_id' => (int) ($row['torneo_id'] ?? 0),
                'nombre' => (string) ($row['torneo_nombre'] ?? ''),
                'fechator' => (string) ($row['fechator'] ?? ''),
                'modalidad' => (int) ($row['modalidad'] ?? 0),
                'posicion' => (int) ($row['posicion'] ?? 0),
                'ganados' => $g,
                'perdidos' => (int) ($row['perdidos'] ?? 0),
                'efectividad' => $ef,
                'puntos' => $pu,
                'ptosrnk' => $pt,
            ];
        }
        $lista = array_values($porUsuario);
        usort($lista, static function (array $a, array $b): int {
            if ($a['sum_pt'] !== $b['sum_pt']) {
                return $b['sum_pt'] <=> $a['sum_pt'];
            }
            if ($a['sum_ef'] !== $b['sum_ef']) {
                return $b['sum_ef'] <=> $a['sum_ef'];
            }
            if ($a['sum_g'] !== $b['sum_g']) {
                return $b['sum_g'] <=> $a['sum_g'];
            }

            return strcasecmp($a['nombre'], $b['nombre']);
        });
        $out = [];
        $rank = 0;
        foreach ($lista as $item) {
            $rank++;
            $out[] = [
                'rank' => $rank,
                'id_usuario' => $item['id_usuario'],
                'nombre' => $item['nombre'],
                'cedula' => $item['cedula'],
                'sexo' => $item['sexo'],
                'torneos_count' => count($item['torneos']),
                'total_ptosrnk' => $item['sum_pt'],
                'total_efectividad' => $item['sum_ef'],
                'total_ganados' => $item['sum_g'],
                'total_puntos' => $item['sum_pu'],
                'detalle_torneos' => $item['torneos'],
            ];
        }

        return [
            'criterio_orden' => 'Suma de puntos de ranking (ptosrnk) por torneo; desempate: efectividad total, partidas ganadas.',
            'atletas' => $out,
        ];
    }
}
