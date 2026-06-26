<?php

declare(strict_types=1);

/**
 * Persistencia y lecturas para asignación de mesas (partiresul, historial_parejas, inscritos).
 *
 * Copia espejo de app/Core/MesaRepository.php para despliegues sin carpeta app/.
 * Si existe app/Core/MesaRepository.php, mn_require_mesa_repository() carga ese primero.
 */

require_once dirname(__DIR__, 2) . '/lib/InscritosHelper.php';
require_once dirname(__DIR__, 2) . '/lib/PartiresulEstatusSql.php';
require_once dirname(__DIR__) . '/TorneosEstructuraService.php';
require_once __DIR__ . '/MesaAsignacionMatriz.php';
require_once __DIR__ . '/MesaRepositoryPersistTrait.php';

final class MesaRepository
{
    use MesaRepositoryPersistTrait;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{sql:string,bind:array} */
    private function whereEntidad(string $alias = ''): array
    {
        return ['sql' => '', 'bind' => []];
    }

    public function fechaPartidaAhora(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function sqlInsertIgnoreInto(string $tableAndRest): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $kw = $driver === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

        return $kw . ' ' . $tableAndRest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function obtenerClasificacionInscritos(int $torneoId): array
    {
        $ent = $this->whereEntidad('i');
        $og = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $oe = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $clubIdExpr = 'COALESCE(NULLIF(i.id_club, 0), NULLIF(u.club_id, 0))';
        $sql = 'SELECT i.*, u.nombre, u.sexo, c.nombre as club_nombre, ' . $clubIdExpr . ' as club_id
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                LEFT JOIN clubes c ON c.id = ' . $clubIdExpr . '
                WHERE i.torneo_id = ? AND ' . InscritosHelper::sqlWhereElegibleParaMesaConAlias('i') . $ent['sql'] . '
                ORDER BY i.posicion ASC, ' . $og . ' DESC, ' . $oe . ' DESC, ' . $op . ' DESC, i.id_usuario ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$torneoId], $ent['bind']));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<int>
     */
    public function obtenerIdsByeRonda1(int $torneoId): array
    {
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT id_usuario FROM partiresul
            WHERE id_torneo = ? AND partida = 1 AND mesa = 0 AND ' . $regOk
        );
        $stmt->execute([$torneoId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, int> id_usuario => cantidad BYE
     */
    public function obtenerConteoByePorJugador(int $torneoId, int $antesDeRonda): array
    {
        if ($antesDeRonda <= 1) {
            return [];
        }
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario, COUNT(*) AS cnt
            FROM partiresul
            WHERE id_torneo = ? AND partida < ? AND partida >= 1 AND mesa = 0 AND ' . $regOk . '
            GROUP BY id_usuario'
        );
        $stmt->execute([$torneoId, $antesDeRonda]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['id_usuario']] = (int) $row['cnt'];
        }

        return $out;
    }

    /** Valor «Jugadores por club» del torneo (tournaments.pareclub); 0 = sin cupo fijo en BD. */
    public function obtenerPareclubTorneo(int $torneoId): int
    {
        if ($torneoId <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT pareclub FROM tournaments WHERE id = ? LIMIT 1');
        $stmt->execute([$torneoId]);
        $v = $stmt->fetchColumn();

        return max(0, (int) $v);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function obtenerClasificacionInscritosParaRonda2(int $torneoId): array
    {
        $entI = $this->whereEntidad('i');
        $regPr1 = PartiresulEstatusSql::whereRegistradoUno('pr1');
        $ganoR1 = InscritosHelper::sqlExprPartiresulResultado1MayorQueResultado2('pr1');
        $ganadorR1Expr = "(CASE WHEN pr1.id IS NOT NULL AND ({$regPr1}) AND {$ganoR1} THEN 1 ELSE 0 END)";
        $byeR1Expr = "(CASE WHEN pr1.id IS NOT NULL AND ({$regPr1}) AND {$ganoR1} AND pr1.mesa = 0 THEN 1 ELSE 0 END)";
        $oe = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $clubIdExprR2 = 'COALESCE(NULLIF(i.id_club, 0), NULLIF(u.club_id, 0))';
        $sql = 'SELECT i.*, u.nombre, u.sexo, c.nombre as club_nombre, ' . $clubIdExprR2 . ' as club_id,
                ' . $ganadorR1Expr . ' AS ganador_r1,
                ' . $byeR1Expr . ' AS bye_r1
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                LEFT JOIN clubes c ON c.id = ' . $clubIdExprR2 . '
                LEFT JOIN partiresul pr1 ON pr1.id_torneo = i.torneo_id AND pr1.id_usuario = i.id_usuario AND pr1.partida = 1
                WHERE i.torneo_id = ? AND ' . InscritosHelper::sqlWhereElegibleParaMesaConAlias('i') . $entI['sql'] . '
                ORDER BY
                    ' . $ganadorR1Expr . ' DESC,
                    ' . $byeR1Expr . ' ASC,
                    ' . $oe . ' DESC, ' . $op . ' DESC, i.id_usuario ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$torneoId], $entI['bind']));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<int, true>>
     */
    public function obtenerMatrizCompañerosDesdeHistorial(int $torneoId, int $hastaRonda): array
    {
        try {
            $ent = $this->whereEntidad();
            $sql = 'SELECT jugador_1_id, jugador_2_id FROM historial_parejas WHERE torneo_id = ? AND ronda_id <= ?' . $ent['sql'];
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$torneoId, $hastaRonda], $ent['bind']));
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return MesaAsignacionMatriz::crearMatrizCompañeros(
                array_map(static fn ($r) => [(int) $r['jugador_1_id'], (int) $r['jugador_2_id']], $filas)
            );
        } catch (Exception $e) {
            return [];
        }
    }

    public function yaJugaronJuntos(int $torneoId, int $id1, int $id2, int $hastaRonda): bool
    {
        $idMenor = min($id1, $id2);
        $idMayor = max($id1, $id2);
        $llave = $idMenor . '-' . $idMayor;
        $ent = $this->whereEntidad();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM historial_parejas WHERE torneo_id = ? AND llave = ? AND ronda_id <= ?' . $ent['sql'] . ' LIMIT 1'
            );
            $stmt->execute(array_merge([$torneoId, $llave, $hastaRonda], $ent['bind']));

            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM historial_parejas WHERE torneo_id = ? AND jugador_1_id = ? AND jugador_2_id = ? AND ronda_id <= ?' . $ent['sql'] . ' LIMIT 1'
                );
                $stmt->execute(array_merge([$torneoId, $idMenor, $idMayor, $hastaRonda], $ent['bind']));

                return (bool) $stmt->fetch();
            } catch (Exception $e2) {
                return false;
            }
        }
    }

    /**
     * @return list<array{0:mixed,1:mixed}>
     */
    public function obtenerParejasRonda(int $torneoId, int $ronda): array
    {
        $sql = 'SELECT partida, mesa, id_usuario, secuencia
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                ORDER BY mesa, secuencia';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parejas = [];
        $mesaActual = null;
        $jugadoresMesa = [];

        foreach ($resultados as $r) {
            if ($mesaActual !== $r['mesa']) {
                if (count($jugadoresMesa) >= 4) {
                    $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
                    $parejas[] = [$jugadoresMesa[2], $jugadoresMesa[3]];
                } elseif (count($jugadoresMesa) >= 2) {
                    $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
                }
                $mesaActual = $r['mesa'];
                $jugadoresMesa = [];
            }
            $jugadoresMesa[] = $r['id_usuario'];
        }

        if (count($jugadoresMesa) >= 4) {
            $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
            $parejas[] = [$jugadoresMesa[2], $jugadoresMesa[3]];
        } elseif (count($jugadoresMesa) >= 2) {
            $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
        }

        return $parejas;
    }

    /**
     * @return list<array{0:mixed,1:mixed}>
     */
    public function obtenerParejasRondasAnteriores(int $torneoId, int $hastaRonda): array
    {
        $todasParejas = [];
        for ($r = 1; $r <= $hastaRonda; $r++) {
            $parejas = $this->obtenerParejasRonda($torneoId, $r);
            $todasParejas = array_merge($todasParejas, $parejas);
        }

        return $todasParejas;
    }

    /**
     * @return array<int, array<int, true>>
     */
    public function obtenerMatrizEnfrentamientos(int $torneoId, int $ronda): array
    {
        $sql = 'SELECT DISTINCT pr1.id_usuario as id1, pr2.id_usuario as id2
                FROM partiresul pr1
                INNER JOIN partiresul pr2 ON pr1.id_torneo = pr2.id_torneo
                    AND pr1.partida = pr2.partida
                    AND pr1.mesa = pr2.mesa
                    AND pr1.id_usuario < pr2.id_usuario
                WHERE pr1.id_torneo = ? AND pr1.partida = ? AND pr1.mesa > 0';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matriz = [];
        foreach ($resultados as $r) {
            $matriz[$r['id1']][$r['id2']] = true;
            $matriz[$r['id2']][$r['id1']] = true;
        }

        return $matriz;
    }

    /**
     * Sin tope de «máximo 2 del mismo club por mesa»: organización particular o un solo club en inscritos.
     */
    public function torneoOmiteLimiteClubPorMesa(int $torneoId): bool
    {
        if ($torneoId <= 0) {
            return false;
        }

        return $this->torneoEsOrganizacionParticular($torneoId)
            || $this->torneoInscritosUnSoloClub($torneoId);
    }

    /**
     * Torneo responsable de una organización particular (tipo_org = 1).
     */
    public function torneoEsOrganizacionParticular(int $torneoId): bool
    {
        if ($torneoId <= 0 || ! TorneosEstructuraService::hasTipoOrg($this->pdo)) {
            return false;
        }

        $joinParts = ['o.id = t.club_responsable'];
        if (TorneosEstructuraService::hasTournamentCodOrg($this->pdo)) {
            $joinParts[] = '(COALESCE(t.cod_org, 0) > 0 AND o.cod_org = t.cod_org)';
            $joinParts[] = '(COALESCE(t.cod_org, 0) > 0 AND o.id = t.cod_org)';
        }
        $joinOn = implode(' OR ', $joinParts);
        $sql = "SELECT 1 FROM tournaments t
                INNER JOIN organizaciones o ON ({$joinOn})
                WHERE t.id = ? AND COALESCE(o.tipo_org, 0) = 1
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Entre inscritos confirmados hay como máximo un club distinto (id > 0).
     */
    public function torneoInscritosUnSoloClub(int $torneoId): bool
    {
        if ($torneoId <= 0) {
            return false;
        }

        $ent = $this->whereEntidad('i');
        $clubIdExpr = 'COALESCE(NULLIF(i.id_club, 0), NULLIF(u.club_id, 0))';
        $sql = "SELECT COUNT(DISTINCT {$clubIdExpr}) AS n_clubs
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                WHERE i.torneo_id = ? AND {$clubIdExpr} > 0 AND "
            . InscritosHelper::sqlWhereElegibleParaMesaConAlias('i') . $ent['sql'];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$torneoId], $ent['bind']));
        $n = (int) $stmt->fetchColumn();

        return $n <= 1;
    }
}
