<?php

declare(strict_types=1);

/**
 * Norma global de mesas: como máximo 2 jugadores del mismo club por mesa (parejas AC / BD).
 * Los rezagados no entran en partiresul (sin mesa 0): se marcan retirados vía MesaRepository.
 */
trait MesaAsignacionLimiteClubMesaTrait
{
    /** @return array<int, int> club_id (0 = sin club) => cantidad en la mesa */
    private function conteosPorClubEnMesa(array $mesa4): array
    {
        $c = [];
        foreach ($mesa4 as $j) {
            $cid = $this->clubIdEfectivoJugador($j);
            if ($cid < 0) {
                $cid = 0;
            }
            $c[$cid] = ($c[$cid] ?? 0) + 1;
        }

        return $c;
    }

    private function mesaCumpleMaxDosMismoClub(array $mesa4): bool
    {
        if (count($mesa4) !== self::JUGADORES_POR_MESA) {
            return false;
        }
        foreach ($this->conteosPorClubEnMesa($mesa4) as $n) {
            if ($n > 2) {
                return false;
            }
        }

        return true;
    }

    private function todasLasMesasCumplenLimiteClub(array $mesas): bool
    {
        foreach ($mesas as $mesa) {
            if (count($mesa) !== self::JUGADORES_POR_MESA) {
                return false;
            }
            if (! $this->mesaCumpleMaxDosMismoClub($mesa)) {
                return false;
            }
        }

        return true;
    }

    private function metricaViolacionClubPorMesa(array $mesa4): int
    {
        $t = 0;
        foreach ($this->conteosPorClubEnMesa($mesa4) as $n) {
            if ($n > 2) {
                $t += ($n - 2);
            }
        }

        return $t;
    }

    private function metricaViolacionClubTotal(array $mesas): int
    {
        $t = 0;
        foreach ($mesas as $mesa) {
            $t += $this->metricaViolacionClubPorMesa($mesa);
        }

        return $t;
    }

    /**
     * Parejas (0-1) y (2-3) no deben haber sido compañeros en rondas anteriores si hay matriz.
     *
     * @param array<int, array<int, true>>|null $matrizCompañeros
     */
    private function mesaRespetaHistorialParejas(array $mesa4, ?array $matrizCompañeros): bool
    {
        if ($matrizCompañeros === null || $matrizCompañeros === []) {
            return true;
        }
        if (count($mesa4) !== 4) {
            return false;
        }
        $ids = array_map(static fn ($j) => (int) ($j['id_usuario'] ?? 0), $mesa4);

        return ! $this->yaFueronCompañeros($ids[0], $ids[1], $matrizCompañeros)
            && ! $this->yaFueronCompañeros($ids[2], $ids[3], $matrizCompañeros);
    }

    /**
     * Intenta reducir violaciones del límite de club intercambiando jugadores entre mesas y con la bolsa de rezagados.
     *
     * @param list<list<array<string, mixed>>> $mesas
     * @param list<array<string, mixed>> $byePool
     * @param array<int, array<int, true>>|null $matrizCompañeros null = no validar historial de parejas
     */
    private function ajustarMesasMaxDosMismoClub(array &$mesas, array &$byePool, ?array $matrizCompañeros = null): void
    {
        $fases = [
            ['solo_club' => false],
            ['solo_club' => true],
        ];
        foreach ($fases as $fase) {
            $soloClub = (bool) $fase['solo_club'];
            for ($g = 0; $g < 2500; $g++) {
                $score = $this->metricaViolacionClubTotal($mesas);
                if ($score === 0) {
                    break 2;
                }
                $mejorDelta = 0;
                $mejorAccion = null;

                $nM = count($mesas);
                for ($m1 = 0; $m1 < $nM; $m1++) {
                    for ($p1 = 0; $p1 < 4; $p1++) {
                        for ($m2 = $m1 + 1; $m2 < $nM; $m2++) {
                            for ($p2 = 0; $p2 < 4; $p2++) {
                                $nm1 = $mesas[$m1];
                                $nm2 = $mesas[$m2];
                                $t = $nm1[$p1];
                                $nm1[$p1] = $nm2[$p2];
                                $nm2[$p2] = $t;
                                if (! $this->mesaCumpleMaxDosMismoClub($nm1) || ! $this->mesaCumpleMaxDosMismoClub($nm2)) {
                                    continue;
                                }
                                if (! $soloClub && (! $this->mesaRespetaHistorialParejas($nm1, $matrizCompañeros) || ! $this->mesaRespetaHistorialParejas($nm2, $matrizCompañeros))) {
                                    continue;
                                }
                                $nuevo = $this->metricaViolacionClubPorMesa($nm1) + $this->metricaViolacionClubPorMesa($nm2);
                                $viejo = $this->metricaViolacionClubPorMesa($mesas[$m1]) + $this->metricaViolacionClubPorMesa($mesas[$m2]);
                                $delta = $viejo - $nuevo;
                                if ($delta > $mejorDelta) {
                                    $mejorDelta = $delta;
                                    $mejorAccion = ['t' => 'mm', 'm1' => $m1, 'p1' => $p1, 'm2' => $m2, 'p2' => $p2];
                                }
                            }
                        }

                        foreach ($byePool as $bi => $bj) {
                            $nm1 = $mesas[$m1];
                            $nm1[$p1] = $bj;
                            if (! $this->mesaCumpleMaxDosMismoClub($nm1)) {
                                continue;
                            }
                            if (! $soloClub && ! $this->mesaRespetaHistorialParejas($nm1, $matrizCompañeros)) {
                                continue;
                            }
                            $viejo = $this->metricaViolacionClubPorMesa($mesas[$m1]);
                            $nuevo = $this->metricaViolacionClubPorMesa($nm1);
                            $delta = $viejo - $nuevo;
                            if ($delta > $mejorDelta) {
                                $mejorDelta = $delta;
                                $mejorAccion = ['t' => 'mb', 'm1' => $m1, 'p1' => $p1, 'bi' => $bi, 'sale' => $mesas[$m1][$p1]];
                            }
                        }
                    }
                }

                if ($mejorAccion === null || $mejorDelta <= 0) {
                    break;
                }
                if ($mejorAccion['t'] === 'mm') {
                    $m1 = $mejorAccion['m1'];
                    $p1 = $mejorAccion['p1'];
                    $m2 = $mejorAccion['m2'];
                    $p2 = $mejorAccion['p2'];
                    $tmp = $mesas[$m1][$p1];
                    $mesas[$m1][$p1] = $mesas[$m2][$p2];
                    $mesas[$m2][$p2] = $tmp;
                } else {
                    $m1 = $mejorAccion['m1'];
                    $p1 = $mejorAccion['p1'];
                    $bi = $mejorAccion['bi'];
                    $sale = $mejorAccion['sale'];
                    $mesas[$m1][$p1] = $byePool[$bi];
                    $byePool[$bi] = $sale;
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $jugadoresBye
     */
    private function marcarRezagadosSinMesaComoRetirados(int $torneoId, array $jugadoresBye): int
    {
        $ids = [];
        foreach ($jugadoresBye as $j) {
            $id = (int) ($j['id_usuario'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }

        return $this->repo->marcarInscritosRetiradoSobrantesInterclub($torneoId, $ids);
    }
}
