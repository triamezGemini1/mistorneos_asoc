<?php

declare(strict_types=1);

/**
 * Individual por club: round-robin de compañeros dentro del club + mesas con parejas de otros clubes.
 */
trait MesaAsignacionClubInterclubTrait
{
    private function esEmparejamientoClubInterclubRR(string $estrategia): bool
    {
        return trim($estrategia) === 'club_interclub_rr';
    }

    private function clubIdEfectivoJugador(array $j): int
    {
        $c = (int) ($j['club_id'] ?? 0);

        return $c > 0 ? $c : 0;
    }

    /** Comparación estable por clasificación dentro del torneo. */
    private function rankingValorOrdenJugador(array $j): array
    {
        return [
            (int) ($j['posicion'] ?? PHP_INT_MAX),
            -((float) ($j['ganados'] ?? 0)),
            -((float) ($j['efectividad'] ?? 0)),
            -((float) ($j['puntos'] ?? 0)),
            (int) ($j['id_usuario'] ?? 0),
        ];
    }

    /**
     * Intercambia jugadores mesa ⇄ bye para que cada club (>0) tenga conteo par en mesaPool.
     *
     * @param list<array<string, mixed>> $mesaPool
     * @param list<array<string, mixed>> $byePool
     */
    private function equilibrarParidadClubesMesaVersusBye(array &$mesaPool, array &$byePool): void
    {
        if ($byePool === []) {
            return;
        }
        for ($iter = 0; $iter < 640; $iter++) {
            $porClub = [];
            foreach ($mesaPool as $jug) {
                $cid = $this->clubIdEfectivoJugador($jug);
                $porClub[$cid][] = $jug;
            }
            $clubImpar = null;
            foreach ($porClub as $cid => $lista) {
                if ((count($lista) % 2) === 1) {
                    $clubImpar = (int) $cid;
                    break;
                }
            }
            if ($clubImpar === null) {
                break;
            }

            /** @var list<array<string, mixed>> $listaClub */
            $listaClub = $porClub[$clubImpar];
            usort($listaClub, fn ($a, $b) => $this->rankingValorOrdenJugador($a) <=> $this->rankingValorOrdenJugador($b));
            $empeora = array_pop($listaClub);
            if ($empeora === null) {
                break;
            }
            $uidSal = (int) ($empeora['id_usuario'] ?? 0);
            if ($uidSal <= 0) {
                break;
            }

            usort($byePool, fn ($a, $b) => $this->rankingValorOrdenJugador($a) <=> $this->rankingValorOrdenJugador($b));
            $entrada = null;
            foreach ($byePool as $cand) {
                if ($this->clubIdEfectivoJugador($cand) === $clubImpar
                    && ($this->rankingValorOrdenJugador($cand) <=> $this->rankingValorOrdenJugador($empeora)) <= 0) {
                    $entrada = $cand;
                    break;
                }
            }
            if ($entrada === null) {
                foreach ($byePool as $cand) {
                    if ($this->clubIdEfectivoJugador($cand) === $clubImpar) {
                        $entrada = $cand;
                        break;
                    }
                }
            }
            if ($entrada === null) {
                break;
            }

            $mpIdx = -1;
            foreach ($mesaPool as $idx => $row) {
                if ((int) ($row['id_usuario'] ?? 0) === $uidSal) {
                    $mpIdx = (int) $idx;
                    break;
                }
            }
            $uidEnt = (int) ($entrada['id_usuario'] ?? 0);
            $bpIdx = -1;
            foreach ($byePool as $idx => $row) {
                if ((int) ($row['id_usuario'] ?? 0) === $uidEnt) {
                    $bpIdx = (int) $idx;
                    break;
                }
            }
            if ($mpIdx < 0 || $bpIdx < 0) {
                break;
            }

            $tmp = $mesaPool[$mpIdx];
            $mesaPool[$mpIdx] = $byePool[$bpIdx];
            $byePool[$bpIdx] = $tmp;
        }
    }

    /**
     * Por club (clave 0 = sin club), orden por ranking: si hay cantidad impar, el último del vector
     * queda sin pareja de club y se excluye del pool de asignación RR; se devuelve al final en $rezagados.
     *
     * @param list<array<string, mixed>> $jugadores
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [pool solo jugadores con pareja de club, rezagados al final]
     */
    private function excluirImparesPorClubAlFinalDelVector(array $jugadores): array
    {
        $porClub = [];
        foreach ($jugadores as $j) {
            $cid = $this->clubIdEfectivoJugador($j);
            $k = $cid > 0 ? $cid : 0;
            $porClub[$k][] = $j;
        }
        ksort($porClub, SORT_NUMERIC);

        $rezagados = [];
        $pool = [];
        foreach ($porClub as $lista) {
            if ($lista === []) {
                continue;
            }
            usort($lista, fn ($a, $b) => $this->rankingValorOrdenJugador($a) <=> $this->rankingValorOrdenJugador($b));
            if ((count($lista) % 2) === 1) {
                $sinPareja = array_pop($lista);
                if ($sinPareja !== null) {
                    $rezagados[] = $sinPareja;
                }
            }
            foreach ($lista as $jx) {
                $pool[] = $jx;
            }
        }

        return [$pool, $rezagados];
    }

    /**
     * @param list<array<string, mixed>> $jugadoresClubOrdenados
     * @return list<array{0: array<string,mixed>, 1: array<string,mixed>}>
     */
    private function apareamientosCirculoCompanerosClub(array $jugadoresClubOrdenados, int $roundSeed): array
    {
        $n = count($jugadoresClubOrdenados);
        if ($n < 2 || ($n % 2) !== 0) {
            return [];
        }
        if ($n === 2) {
            return [[$jugadoresClubOrdenados[0], $jugadoresClubOrdenados[1]]];
        }

        $circle = [];
        $circle[] = $jugadoresClubOrdenados[0];
        $rest = array_slice($jugadoresClubOrdenados, 1);
        $rlen = count($rest);
        $shift = (($roundSeed % $rlen) + $rlen) % $rlen;
        for ($i = 0; $i < $rlen; $i++) {
            $circle[] = $rest[($i + $shift) % $rlen];
        }

        $pares = [];
        $half = (int) ($n / 2);
        for ($i = 0; $i < $half; $i++) {
            $pares[] = [$circle[$i], $circle[$n - 1 - $i]];
        }

        return $pares;
    }

    /**
     * @param array<int, array<int, true>> $matrix
     */
    private function crucesHistoricosParVsPar(array $parAc, array $parBd, array $matrix): int
    {
        $idsAc = [(int) ($parAc[0]['id_usuario'] ?? 0), (int) ($parAc[1]['id_usuario'] ?? 0)];
        $idsBd = [(int) ($parBd[0]['id_usuario'] ?? 0), (int) ($parBd[1]['id_usuario'] ?? 0)];
        $c = 0;
        foreach ($idsAc as $i1) {
            if ($i1 <= 0) {
                continue;
            }
            foreach ($idsBd as $i2) {
                if ($i2 <= 0 || $i1 === $i2) {
                    continue;
                }
                $a = min($i1, $i2);
                $b = max($i1, $i2);
                if (isset($matrix[$a][$b])) {
                    ++$c;
                }
            }
        }

        return $c;
    }

    /**
     * @param array<int, array<int, true>> $matrizEnfrentamiento
     * @param list<array{p0: array<string,mixed>, p1: array<string,mixed>, club:int}> $listaPares
     * @return list<list<array<string,mixed>>>
     */
    private function formarMesasGreedyPorParesClub(array $listaPares, int $mesasObjetivo, array $matrizEnfrentamiento): array
    {
        $mesasObjetivo = max(0, $mesasObjetivo);
        $disponibles = array_values($listaPares);
        usort($disponibles, static function ($a, $b) {
            return ((int) ($a['club'] ?? 0)) <=> ((int) ($b['club'] ?? 0));
        });

        /** @var list<bool> $usado */
        $usado = array_fill(0, count($disponibles), false);
        $mesasArr = [];

        for ($mesaIdx = 0; $mesaIdx < $mesasObjetivo; $mesaIdx++) {
            $iP = null;
            for ($p = 0; $p < count($disponibles); $p++) {
                if (! $usado[$p]) {
                    $iP = $p;
                    break;
                }
            }
            if ($iP === null) {
                break;
            }
            $prim = $disponibles[$iP];
            $usado[$iP] = true;

            $mejorQ = null;
            $mejorScore = PHP_INT_MAX;
            for ($q = 0; $q < count($disponibles); $q++) {
                if ($usado[$q]) {
                    continue;
                }
                $seg = $disponibles[$q];
                if ((int) ($seg['club'] ?? 0) === (int) ($prim['club'] ?? 0)) {
                    continue;
                }
                $sc = $this->crucesHistoricosParVsPar(
                    [$prim['p0'], $prim['p1']],
                    [$seg['p0'], $seg['p1']],
                    $matrizEnfrentamiento
                );
                if ($sc < $mejorScore) {
                    $mejorScore = $sc;
                    $mejorQ = $q;
                }
            }
            if ($mejorQ === null) {
                for ($q = 0; $q < count($disponibles); $q++) {
                    if ($usado[$q]) {
                        continue;
                    }
                    $seg = $disponibles[$q];
                    if ((int) ($seg['club'] ?? 0) !== (int) ($prim['club'] ?? 0)) {
                        $mejorQ = $q;
                        break;
                    }
                }
            }
            if ($mejorQ === null) {
                $usado[$iP] = false;
                break;
            }
            $seg = $disponibles[$mejorQ];
            $usado[$mejorQ] = true;
            /** Orden pareja AC vs BD estándar: A,C vs B,D */
            $mesasArr[] = [
                $prim['p0'],
                $prim['p1'],
                $seg['p0'],
                $seg['p1'],
            ];
        }

        return $mesasArr;
    }

    private function obtenerMatrizEnfrentamientosAcumulada(int $torneoId, int $antesDeRonda): array
    {
        $matriz = [];
        for ($r = 1; $r < $antesDeRonda; $r++) {
            $bloque = $this->repo->obtenerMatrizEnfrentamientos($torneoId, $r);
            foreach ($bloque as $id1 => $jugadores) {
                foreach ($jugadores as $id2 => $val) {
                    $matriz[$id1][$id2] = true;
                    $matriz[$id2][$id1] = true;
                }
            }
        }

        return $matriz;
    }

    /**
     * Rondas intermedias tipo interclub RR (estrategia club_interclub_rr).
     *
     * @return array<string, mixed>
     */
    private function generarRondaClubInterclubRR(int $torneoId, int $numRonda): array
    {
        $inscritos = $this->repo->obtenerClasificacionInscritos($torneoId);
        $totalInscritos = count($inscritos);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)',
            ];
        }

        $numMesasPre = (int) floor($totalInscritos / self::JUGADORES_POR_MESA);
        $numBye = $totalInscritos - ($numMesasPre * self::JUGADORES_POR_MESA);
        $conteoBye = $this->repo->obtenerConteoByePorJugador($torneoId, $numRonda);
        if ($numBye > 0) {
            if ($conteoBye !== []) {
                [$jugadoresParaMesas, $jugadoresBye] = $this->reordenarParaLimitarBye($inscritos, $conteoBye, $numBye, $numMesasPre);
            } else {
                $jugadoresParaMesas = array_slice($inscritos, 0, $numMesasPre * self::JUGADORES_POR_MESA);
                $jugadoresBye = array_slice($inscritos, $numMesasPre * self::JUGADORES_POR_MESA, $numBye);
            }
        } else {
            $jugadoresParaMesas = $inscritos;
            $jugadoresBye = [];
        }

        $this->equilibrarParidadClubesMesaVersusBye($jugadoresParaMesas, $jugadoresBye);

        [$jugadoresParaMesas, $sinParejaPorClub] = $this->excluirImparesPorClubAlFinalDelVector($jugadoresParaMesas);
        $jugadoresBye = array_values(array_merge($jugadoresBye, $sinParejaPorClub));

        $nPool = count($jugadoresParaMesas);
        if ($nPool < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'Club interclub RR: tras excluir jugadores sin pareja de club no quedan al menos 4 participantes para armar mesas.',
            ];
        }
        $numMesas = (int) floor($nPool / self::JUGADORES_POR_MESA);

        $matrizCompañeros = $this->obtenerMatrizCompañerosParaRonda($torneoId, $numRonda - 1);
        $matrizEnfrent = $this->obtenerMatrizEnfrentamientosAcumulada($torneoId, $numRonda);

        $clubsIdPositivos = [];
        $sinClub = 0;
        foreach ($jugadoresParaMesas as $jx) {
            $cx = $this->clubIdEfectivoJugador($jx);
            if ($cx > 0) {
                $clubsIdPositivos[$cx] = true;
            } else {
                ++$sinClub;
            }
        }
        $nclubs = count($clubsIdPositivos);
        if ($nclubs === 1 && $sinClub === 0) {
            return [
                'success' => false,
                'message' => 'Club interclub RR: todos los jugadores en mesa pertenecen al mismo club. '
                    . 'Inscriba al menos otro club o use la estrategia clásica (Separar líderes / Suizo).',
            ];
        }
        if ($nclubs === 0 && $sinClub > 0) {
            return [
                'success' => false,
                'message' => 'Club interclub RR: ningún jugador tiene club asignado (inscripción o usuario). '
                    . 'Asigne club o use la estrategia clásica.',
            ];
        }

        $porClub = [];
        foreach ($jugadoresParaMesas as $j) {
            $cid = $this->clubIdEfectivoJugador($j);
            $k = $cid > 0 ? $cid : 0;
            $porClub[$k][] = $j;
        }
        ksort($porClub);

        $roundSeed = (($numRonda - 2) * 7937 + $torneoId * 17) % 1000003;

        /** @var list<array{p0: array<string,mixed>, p1: array<string,mixed>, club:int}> $listaParObj */
        $listaParObj = [];
        foreach ($porClub as $cid => $jugList) {
            if ($jugList === []) {
                continue;
            }
            usort($jugList, fn ($a, $b) => $this->rankingValorOrdenJugador($a) <=> $this->rankingValorOrdenJugador($b));
            $seedClub = (($roundSeed + (int) $cid * 17) % 10000007);
            foreach ($this->apareamientosCirculoCompanerosClub($jugList, $seedClub) as $dup) {
                $listaParObj[] = [
                    'p0' => $dup[0],
                    'p1' => $dup[1],
                    'club' => (int) $cid,
                ];
            }
        }

        $mesas = $this->formarMesasGreedyPorParesClub($listaParObj, $numMesas, $matrizEnfrent);
        if (count($mesas) !== $numMesas) {
            $mesas = $this->generarRondaIntermediaConstruirMesasSuizoFallback(
                $jugadoresParaMesas,
                $matrizCompañeros,
                $matrizEnfrent,
                $numMesas
            );
            if (count($mesas) !== $numMesas) {
                return [
                    'success' => false,
                    'message' => 'No fue posible completar todas las mesas con emparejamiento interclub RR; '
                        . 'verifique distribución por club y número de jugadores.',
                ];
            }
        }

        $this->ajustarMesasMaxDosMismoClub($mesas, $jugadoresBye, $matrizCompañeros);
        if (! $this->todasLasMesasCumplenLimiteClub($mesas)) {
            return [
                'success' => false,
                'message' => "No se pudo generar la ronda {$numRonda} (interclub RR): máximo 2 jugadores del mismo club por mesa.",
            ];
        }

        $this->repo->guardarAsignacionRonda($torneoId, $numRonda, $mesas, $this->registradoPorUsuarioId);
        if ($jugadoresBye !== []) {
            $this->marcarRezagadosSinMesaComoRetirados((int) $torneoId, $jugadoresBye);
        }

        $msg = "Ronda {$numRonda} generada (interclub + RR interno por club)";
        if ($sinParejaPorClub !== []) {
            $msg .= ' Excluido(s) del vector de parejas (sin compañero de club; al final de no asignados): ' . count($sinParejaPorClub) . '.';
        }

        return [
            'success' => true,
            'message' => $msg,
            'total_mesas' => count($mesas),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesas,
        ];
    }

    /**
     * Refactorización mínima: reutiliza lógica de generarRondaIntermedia cuando el greedy RR no llena mesas.
     *
     * @param list<array<string, mixed>> $jugadoresParaMesas
     * @param array<int, array<int, true>> $matrizCompañeros
     * @param array<int, array<int, true>> $matrizEnfrentamiento
     * @return list<list<array<string,mixed>>>
     */
    private function generarRondaIntermediaConstruirMesasSuizoFallback(
        array $jugadoresParaMesas,
        array $matrizCompañeros,
        array $matrizEnfrentamiento,
        int $numMesas
    ): array {
        $mesas = $this->asignarMesasRondaIntermedia($jugadoresParaMesas, $matrizCompañeros, $matrizEnfrentamiento);
        $mesas = $this->limpiarMesasDuplicados($mesas);
        $sobrantes = $this->obtenerJugadoresSobrantes($jugadoresParaMesas, $mesas);
        if ($sobrantes !== []) {
            $mesas = $this->completarMesasIncompletas($mesas, $sobrantes, $matrizCompañeros);
            $sobrantes = $this->obtenerJugadoresSobrantes($jugadoresParaMesas, $mesas);
        }
        while ($sobrantes !== []) {
            $jugador = array_shift($sobrantes);
            $agregado = false;
            foreach ($mesas as &$mesa) {
                if (count($mesa) < self::JUGADORES_POR_MESA) {
                    $mesa[] = $jugador;
                    $agregado = true;
                    break;
                }
            }
            unset($mesa);
            if (! $agregado) {
                $mesas[] = [$jugador];
            }
        }
        $mesas = $this->ajustarMesasExactas($mesas, $numMesas, $jugadoresParaMesas);

        return $mesas;
    }
}
