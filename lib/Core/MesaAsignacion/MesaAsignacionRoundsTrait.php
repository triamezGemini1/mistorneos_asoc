<?php

trait MesaAsignacionRoundsTrait
{
    public function generarAsignacionRonda($torneoId, $numRonda, $totalRondas, $estrategiaRonda2 = 'separar')
    {
        if ($numRonda === 1) {
            return $this->generarPrimeraRonda($torneoId);
        } elseif ($numRonda === 2) {
            return $this->generarSegundaRonda($torneoId);
        } elseif ($numRonda === $totalRondas) {
            return $this->generarUltimaRonda($torneoId, $numRonda);
        } else {
            return $this->generarRondaIntermedia($torneoId, $numRonda);
        }
    }

    /**
     * RONDA 1: Dispersión por Clubes
     * Objetivo: jugadores del mismo club no se enfrenten al inicio.
     * Orden: id_club, id_usuario. Vectores V1,V2,V3,V4. Mesa i: A=V1[i], C=V2[i], B=V3[i], D=V4[i]
     * BYE: los sobrantes (tras dispersión por clubes) se asignan al azar en esta ronda;
     * de la ronda 2 en adelante rige el criterio de últimos clasificados con prioridad a no repetir BYE.
     */
    private function generarPrimeraRonda($torneoId)
    {
        if ($this->repo->esTorneoIndividualRotadoInterclubes((int) $torneoId)) {
            return $this->generarRondaIndividualRotadoInterclubes((int) $torneoId, 1);
        }

        $inscritos = $this->repo->obtenerClasificacionInscritos($torneoId);
        $totalInscritos = count($inscritos);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)'
            ];
        }

        // 1. Ordenar por id_club e id_usuario
        usort($inscritos, function($a, $b) {
            $clubA = (int)($a['club_id'] ?? -1);
            $clubB = (int)($b['club_id'] ?? -1);
            if ($clubA <= 0) $clubA = -1;
            if ($clubB <= 0) $clubB = -1;
            if ($clubA !== $clubB) return $clubA <=> $clubB;
            return (int)($a['id_usuario'] ?? 0) <=> (int)($b['id_usuario'] ?? 0);
        });

        $jugadores = $inscritos;
        $total = count($jugadores);
        $mesas = (int)floor($total / self::JUGADORES_POR_MESA);
        if ($mesas < 1) {
            return ['success' => false, 'message' => 'No hay suficientes jugadores para formar al menos 1 mesa (mínimo 4)'];
        }

        // 2. Crear vectores V1, V2, V3, V4 (solo jugadores asignados; los restantes van a BYE)
        $asignar = $mesas * self::JUGADORES_POR_MESA;
        $usados = array_slice($jugadores, 0, $asignar);
        $jugadoresBye = array_slice($jugadores, $asignar);

        $v1 = $v2 = $v3 = $v4 = [];
        foreach ($usados as $index => $j) {
            if ($index < $mesas) $v1[] = $j;
            elseif ($index < $mesas * 2) $v2[] = $j;
            elseif ($index < $mesas * 3) $v3[] = $j;
            else $v4[] = $j;
        }

        // 3. Asignar mesas: A=V1[i], C=V2[i] (Pareja AC), B=V3[i], D=V4[i] (Pareja BD)
        $mesasArray = [];
        for ($i = 0; $i < $mesas; $i++) {
            $a = $v1[$i] ?? null;
            $c = $v2[$i] ?? null;
            $b = $v3[$i] ?? null;
            $d = $v4[$i] ?? null;
            if ($a && $c && $b && $d) {
                $mesasArray[] = [$a, $c, $b, $d];
            }
        }

        $this->repo->guardarAsignacionRonda($torneoId, 1, $mesasArray, $this->registradoPorUsuarioId);
        if (!empty($jugadoresBye)) {
            $this->repo->aplicarBye($torneoId, 1, $jugadoresBye, 3, $this->registradoPorUsuarioId);
        }

        return [
            'success' => true,
            'message' => 'Primera ronda generada exitosamente',
            'total_inscritos' => $totalInscritos,
            'total_mesas' => count($mesasArray),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesasArray
        ];
    }

    /**
     * RONDA 2: Separación de Líderes - Patrón 1-5-3-7
     * Pareja AC (secuencias 1,2): (N, N+4) | Pareja BD (secuencias 3,4): (N+2, N+6)
     * Mesa 1: (1,5) vs (3,7) → A=1, C=5, B=3, D=7 → orden [A,C,B,D] = [1,5,3,7]
     * Mesa 2: (2,6) vs (4,8) → A=2, C=6, B=4, D=8 → orden [A,C,B,D] = [2,6,4,8]
     * Mesa 3: (9,13) vs (11,15) → orden [9,13,11,15]
     * Mesa 4: (10,14) vs (12,16) → orden [10,14,12,16]
     * BYE: jugadores sobrantes (si total no es múltiplo de 4) se asignan automáticamente
     * con partida ganada, 100% puntos y 50% efectividad.
     */
    private function generarSegundaRonda($torneoId)
    {
        if ($this->repo->esTorneoIndividualRotadoInterclubes((int) $torneoId)) {
            return $this->generarRondaIndividualRotadoInterclubes((int) $torneoId, 2);
        }

        $ranking = $this->repo->obtenerClasificacionInscritosParaRonda2($torneoId);
        $totalInscritos = count($ranking);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)'
            ];
        }

        // Número de mesas fijo: floor(n/4). BYE: evitar más de 2 BYE por jugador (desplazar a última mesa).
        $numMesas = (int)floor($totalInscritos / self::JUGADORES_POR_MESA);
        $numBye = $totalInscritos - ($numMesas * self::JUGADORES_POR_MESA);
        $conteoBye = $this->repo->obtenerConteoByePorJugador($torneoId, 2);
        if ($numBye > 0) {
            if (!empty($conteoBye)) {
                list($jugadoresParaMesas, $jugadoresBye) = $this->reordenarParaLimitarBye($ranking, $conteoBye, $numBye, $numMesas);
            } else {
                $jugadoresParaMesas = array_slice($ranking, 0, $numMesas * self::JUGADORES_POR_MESA);
                $jugadoresBye = array_slice($ranking, $numMesas * self::JUGADORES_POR_MESA, $numBye);
            }
        } else {
            $jugadoresParaMesas = array_slice($ranking, 0, $numMesas * self::JUGADORES_POR_MESA);
            $jugadoresBye = [];
        }
        $totalParaMesas = count($jugadoresParaMesas);

        $matrizCompañeros = $this->obtenerMatrizCompañerosParaRonda($torneoId, 1);

        $mesasArray = [];
        $idx = 0;

        // Patrón 1-5-3-7 solo con jugadores asignados a mesas (no crear mesas extra)
        while ($idx + 7 < $totalParaMesas) {
            $mesasArray[] = [
                $jugadoresParaMesas[$idx],     $jugadoresParaMesas[$idx + 4],
                $jugadoresParaMesas[$idx + 2], $jugadoresParaMesas[$idx + 6]
            ];
            $mesasArray[] = [
                $jugadoresParaMesas[$idx + 1], $jugadoresParaMesas[$idx + 5],
                $jugadoresParaMesas[$idx + 3], $jugadoresParaMesas[$idx + 7]
            ];
            $idx += 8;
        }
        while ($idx + 3 < $totalParaMesas) {
            $mesasArray[] = [
                $jugadoresParaMesas[$idx],     $jugadoresParaMesas[$idx + 2],
                $jugadoresParaMesas[$idx + 1], $jugadoresParaMesas[$idx + 3]
            ];
            $idx += 4;
        }

        $mesasArray = $this->validarYRotarRonda2($mesasArray, $matrizCompañeros);

        $this->repo->guardarAsignacionRonda($torneoId, 2, $mesasArray, $this->registradoPorUsuarioId);
        if (!empty($jugadoresBye)) {
            $this->repo->aplicarBye($torneoId, 2, $jugadoresBye, 3, $this->registradoPorUsuarioId);
        }

        return [
            'success' => true,
            'message' => 'Segunda ronda generada exitosamente',
            'total_mesas' => count($mesasArray),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesasArray
        ];
    }

    /**
     * Valida y rota conflictos en Ronda 2: si AC o BD ya fueron compañeros,
     * intercambiar C con B (rotación interna) o con mesa anterior
     */
    private function validarYRotarRonda2(array $mesasArray, array $matrizCompañeros): array
    {
        if (empty($matrizCompañeros) || count($mesasArray) < 1) {
            return $mesasArray;
        }

        for ($i = count($mesasArray) - 1; $i >= 0; $i--) {
            $mesa = $mesasArray[$i];
            if (count($mesa) < 4) continue;

            $ids = array_column($mesa, 'id_usuario');
            $conflictoAC = $this->yaFueronCompañeros((int)$ids[0], (int)$ids[1], $matrizCompañeros);
            $conflictoBD = $this->yaFueronCompañeros((int)$ids[2], (int)$ids[3], $matrizCompañeros);

            if (!$conflictoAC && !$conflictoBD) continue;

            // Rotación interna: intercambiar C con B
            $mesaNueva = $mesa;
            $temp = $mesaNueva[1];
            $mesaNueva[1] = $mesaNueva[2];
            $mesaNueva[2] = $temp;
            $idsN = array_column($mesaNueva, 'id_usuario');
            if (!$this->yaFueronCompañeros((int)$idsN[0], (int)$idsN[1], $matrizCompañeros) &&
                !$this->yaFueronCompañeros((int)$idsN[2], (int)$idsN[3], $matrizCompañeros)) {
                $mesasArray[$i] = $mesaNueva;
                continue;
            }

            // Intercambio con mesa anterior (D de anterior por C de actual)
            if ($i > 0) {
                $mesaAnt = $mesasArray[$i - 1];
                if (count($mesaAnt) >= 4) {
                    $mesaActualPrueba = $mesa;
                    $mesaActualPrueba[1] = $mesaAnt[3];
                    $mesaAnteriorPrueba = $mesaAnt;
                    $mesaAnteriorPrueba[3] = $mesa[1];
                    $idsA = array_column($mesaActualPrueba, 'id_usuario');
                    $idsAnt = array_column($mesaAnteriorPrueba, 'id_usuario');
                    if (!$this->yaFueronCompañeros((int)$idsA[0], (int)$idsA[1], $matrizCompañeros) &&
                        !$this->yaFueronCompañeros((int)$idsA[2], (int)$idsA[3], $matrizCompañeros) &&
                        !$this->yaFueronCompañeros((int)$idsAnt[0], (int)$idsAnt[1], $matrizCompañeros) &&
                        !$this->yaFueronCompañeros((int)$idsAnt[2], (int)$idsAnt[3], $matrizCompañeros)) {
                        $mesasArray[$i] = $mesaActualPrueba;
                        $mesasArray[$i - 1] = $mesaAnteriorPrueba;
                    }
                }
            }
        }

        return $mesasArray;
    }

    /**
     * Consulta rápida: ¿fueron compañeros? (usa matriz en memoria)
     */
    private function yaFueronCompañeros(int $id1, int $id2, array $matrizCompañeros): bool
    {
        if ($id1 <= 0 || $id2 <= 0) return false;
        return isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1]);
    }

    /**
     * RONDAS INTERMEDIAS: Respetar clasificación, evitar compañeros
     * Para rondas 3 y subsiguientes: orden por clasificación y resolver conflictos con intercambios
     */
    private function generarRondaIntermedia($torneoId, $numRonda)
    {
        if ($this->repo->esTorneoIndividualRotadoInterclubes((int) $torneoId)) {
            return $this->generarRondaIndividualRotadoInterclubes((int) $torneoId, (int) $numRonda);
        }

        $inscritos = $this->repo->obtenerClasificacionInscritos($torneoId);
        $totalInscritos = count($inscritos);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)'
            ];
        }

        // Número de mesas fijo: floor(n/4). BYE: evitar más de 2 BYE por jugador (desplazar a última mesa).
        $numMesas = (int)floor($totalInscritos / self::JUGADORES_POR_MESA);
        $numBye = $totalInscritos - ($numMesas * self::JUGADORES_POR_MESA);
        $conteoBye = $this->repo->obtenerConteoByePorJugador($torneoId, $numRonda);
        if ($numBye > 0) {
            if (!empty($conteoBye)) {
                list($jugadoresParaMesas, $jugadoresBye) = $this->reordenarParaLimitarBye($inscritos, $conteoBye, $numBye, $numMesas);
            } else {
                // Sin historial BYE: BYE = últimos clasificados (resto de la lista)
                $jugadoresParaMesas = array_slice($inscritos, 0, $numMesas * self::JUGADORES_POR_MESA);
                $jugadoresBye = array_slice($inscritos, $numMesas * self::JUGADORES_POR_MESA, $numBye);
            }
        } else {
            $jugadoresParaMesas = array_slice($inscritos, 0, $numMesas * self::JUGADORES_POR_MESA);
            $jugadoresBye = [];
        }

        $matrizCompañeros = $this->obtenerMatrizCompañerosParaRonda($torneoId, $numRonda - 1);
        $matrizEnfrentamientos = [];
        for ($r = 1; $r < $numRonda; $r++) {
            $enfrentamientos = $this->repo->obtenerMatrizEnfrentamientos($torneoId, $r);
            foreach ($enfrentamientos as $id1 => $jugadores) {
                foreach ($jugadores as $id2 => $val) {
                    $matrizEnfrentamientos[$id1][$id2] = true;
                    $matrizEnfrentamientos[$id2][$id1] = true;
                }
            }
        }

        // Asignar solo los que juegan; no se crean mesas adicionales
        $mesas = $this->asignarMesasRondaIntermedia($jugadoresParaMesas, $matrizCompañeros, $matrizEnfrentamientos);
        $mesas = $this->limpiarMesasDuplicados($mesas);

        // Con pocas mesas puede ser imposible evitar repetir compañeros: forzar que los 16 queden en 4 mesas
        $sobrantes = $this->obtenerJugadoresSobrantes($jugadoresParaMesas, $mesas);
        if (!empty($sobrantes)) {
            $mesas = $this->completarMesasIncompletas($mesas, $sobrantes, $matrizCompañeros);
            $sobrantes = $this->obtenerJugadoresSobrantes($jugadoresParaMesas, $mesas);
        }
        // Si aún hay sobrantes, asignar a cualquier mesa con hueco (aunque repitan compañero)
        while (!empty($sobrantes)) {
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
            if (!$agregado) {
                $mesas[] = [$jugador];
            }
        }
        // Garantizar exactamente numMesas mesas de 4: si hay mesas de más, redistribuir
        $mesas = $this->ajustarMesasExactas($mesas, $numMesas, $jugadoresParaMesas);

        $this->repo->guardarAsignacionRonda($torneoId, $numRonda, $mesas, $this->registradoPorUsuarioId);
        if (!empty($jugadoresBye)) {
            $this->repo->aplicarBye($torneoId, $numRonda, $jugadoresBye, 3, $this->registradoPorUsuarioId);
        }

        return [
            'success' => true,
            'message' => "Ronda {$numRonda} generada exitosamente",
            'total_mesas' => count($mesas),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesas
        ];
    }

    /**
     * ÚLTIMA RONDA: Asignación especial
     * Mesa 1: Posición 1+3 vs Posición 2+4
     * Mesa 2: Posición 5+7 vs Posición 6+8
     * Mesa 3: Posición 9+11 vs Posición 10+12
     * Y así sucesivamente
     */
    private function generarUltimaRonda($torneoId, $numRonda)
    {
        if ($this->repo->esTorneoIndividualRotadoInterclubes((int) $torneoId)) {
            return $this->generarRondaIndividualRotadoInterclubes((int) $torneoId, (int) $numRonda);
        }

        $inscritos = $this->repo->obtenerClasificacionInscritos($torneoId);
        $totalInscritos = count($inscritos);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)'
            ];
        }

        // Número de mesas fijo: floor(n/4). BYE: evitar más de 2 BYE por jugador (desplazar a última mesa).
        $numMesas = (int)floor($totalInscritos / self::JUGADORES_POR_MESA);
        $numBye = $totalInscritos - ($numMesas * self::JUGADORES_POR_MESA);
        $conteoBye = $this->repo->obtenerConteoByePorJugador($torneoId, $numRonda);
        if ($numBye > 0) {
            if (!empty($conteoBye)) {
                list($jugadoresParaMesas, $jugadoresBye) = $this->reordenarParaLimitarBye($inscritos, $conteoBye, $numBye, $numMesas);
            } else {
                $jugadoresParaMesas = array_slice($inscritos, 0, $numMesas * self::JUGADORES_POR_MESA);
                $jugadoresBye = array_slice($inscritos, $numMesas * self::JUGADORES_POR_MESA, $numBye);
            }
        } else {
            $jugadoresParaMesas = array_slice($inscritos, 0, $numMesas * self::JUGADORES_POR_MESA);
            $jugadoresBye = [];
        }
        $totalParaMesas = count($jugadoresParaMesas);

        $mesas = [];
        $indice = 0;

        // Asignar solo num_mesas mesas (patrón 1+3 vs 2+4)
        while ($indice + 3 < $totalParaMesas) {
            $mesa = [
                $jugadoresParaMesas[$indice],
                $jugadoresParaMesas[$indice + 2],
                $jugadoresParaMesas[$indice + 1],
                $jugadoresParaMesas[$indice + 3]
            ];
            $mesas[] = $mesa;
            $indice += 4;
        }

        $this->repo->guardarAsignacionRonda($torneoId, $numRonda, $mesas, $this->registradoPorUsuarioId);
        if (!empty($jugadoresBye)) {
            $this->repo->aplicarBye($torneoId, $numRonda, $jugadoresBye, 3, $this->registradoPorUsuarioId);
        }

        return [
            'success' => true,
            'message' => "Última ronda generada exitosamente",
            'total_mesas' => count($mesas),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesas
        ];
    }

    /**
     * Modalidad individual + tipo torneo interclubes: parejas intra-club por permutación (i + ronda) mod n,
     * emparejamiento de mesas cruzando clubes en anillo (pareja de Ci vs C{i+1}).
     *
     * @return array<string, mixed>
     */
    private function generarRondaIndividualRotadoInterclubes(int $torneoId, int $numRonda): array
    {
        $inscritos = $this->repo->obtenerClasificacionInscritos($torneoId);
        $totalInscritos = count($inscritos);

        if ($totalInscritos < self::JUGADORES_POR_MESA) {
            return [
                'success' => false,
                'message' => 'No hay suficientes jugadores inscritos (mínimo 4)',
            ];
        }

        $r = $numRonda > 0 ? $numRonda : 1;
        $porClub = $this->rotadoInterclubesAgruparPorCodigoEquipo($inscritos);

        if (count($porClub) < 2) {
            error_log("Individual Rotado Interclubes (torneo {$torneoId}): hacen falta al menos 2 codigo_equipo distintos con inscritos confirmados.");

            return [
                'success' => false,
                'message' => 'Individual Rotado Interclubes: asigne codigo_equipo en al menos dos clubes con jugadores confirmados.',
            ];
        }

        $parejasPorClub = [];
        $jugadoresBye = [];

        foreach ($porClub as $codigo => $jugadoresClub) {
            $resClub = $this->rotadoInterclubesParejasEnClub($jugadoresClub, $r);
            foreach ($resClub['parejas'] as $par) {
                if (!isset($parejasPorClub[$codigo])) {
                    $parejasPorClub[$codigo] = [];
                }
                $parejasPorClub[$codigo][] = $par;
            }
            foreach ($resClub['bye'] as $bj) {
                $jugadoresBye[] = $bj;
            }
        }

        $cruce = $this->rotadoInterclubesCruzarMesas($parejasPorClub);
        $mesasArray = $cruce['mesas'];

        foreach ($cruce['parejasSinUsar'] as $parSueltas) {
            $jugadoresBye[] = $parSueltas[0];
            $jugadoresBye[] = $parSueltas[1];
        }

        if ($mesasArray === []) {
            error_log("Individual Rotado Interclubes (torneo {$torneoId}): no se formó ninguna mesa con el cruce circular actual.");

            return [
                'success' => false,
                'message' => 'No se pudo formar ninguna mesa interclubes; revise codigo_equipo y el reparto por club.',
            ];
        }

        $this->repo->guardarAsignacionRonda($torneoId, $numRonda, $mesasArray, $this->registradoPorUsuarioId);

        if ($jugadoresBye !== []) {
            $nBye = count($jugadoresBye);
            $this->repo->aplicarBye($torneoId, $numRonda, $jugadoresBye, max(3, $nBye), $this->registradoPorUsuarioId);
        }

        return [
            'success' => true,
            'message' => "Ronda {$numRonda} (Individual Rotado Interclubes) generada",
            'total_inscritos' => $totalInscritos,
            'total_mesas' => count($mesasArray),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesasArray,
        ];
    }

    /**
     * @param list<array<string, mixed>> $inscritos
     * @return array<string, list<array<string, mixed>>>
     */
    private function rotadoInterclubesAgruparPorCodigoEquipo(array $inscritos): array
    {
        $gr = [];
        foreach ($inscritos as $row) {
            $cod = trim((string) ($row['codigo_equipo'] ?? ''));
            if ($cod === '') {
                $cod = '_SIN_CODIGO_';
                error_log('Individual Rotado Interclubes: inscrito sin codigo_equipo; use un código de equipo para agrupar correctamente.');
            }
            if (!isset($gr[$cod])) {
                $gr[$cod] = [];
            }
            $gr[$cod][] = $row;
        }
        foreach ($gr as $cod => $lista) {
            usort($lista, static function ($a, $b) {
                return (int) ($a['id_usuario'] ?? 0) <=> (int) ($b['id_usuario'] ?? 0);
            });
            $gr[$cod] = $lista;
        }
        ksort($gr, SORT_STRING);

        return $gr;
    }

    /**
     * @param list<array<string, mixed>> $jugadoresOrdenados
     * @return array{parejas: list<array{0: array<string, mixed>, 1: array<string, mixed>}>, bye: list<array<string, mixed>>}
     */
    private function rotadoInterclubesParejasEnClub(array $jugadoresOrdenados, int $numRonda): array
    {
        $parejas = [];
        $bye = [];
        $n = count($jugadoresOrdenados);
        if ($n < 2) {
            if ($n === 1) {
                error_log('Individual Rotado Interclubes: club con un solo jugador; queda en BYE.');
                $bye[] = $jugadoresOrdenados[0];
            }

            return ['parejas' => $parejas, 'bye' => $bye];
        }

        $r = $numRonda > 0 ? $numRonda : 1;
        $visitado = array_fill(0, $n, false);

        for ($inicio = 0; $inicio < $n; $inicio++) {
            if ($visitado[$inicio]) {
                continue;
            }
            $ciclo = [];
            $i = $inicio;
            do {
                $visitado[$i] = true;
                $ciclo[] = $i;
                $i = ($i + $r) % $n;
            } while ($i !== $inicio);

            $L = count($ciclo);
            if ($L % 2 === 1) {
                error_log("Individual Rotado Interclubes: ciclo impar (n={$n}, r={$r}, L={$L}); un jugador sin pareja pasa a BYE.");
                for ($t = 0; $t + 1 < $L; $t += 2) {
                    $parejas[] = [$jugadoresOrdenados[$ciclo[$t]], $jugadoresOrdenados[$ciclo[$t + 1]]];
                }
                $bye[] = $jugadoresOrdenados[$ciclo[$L - 1]];
            } else {
                for ($t = 0; $t < $L; $t += 2) {
                    $parejas[] = [$jugadoresOrdenados[$ciclo[$t]], $jugadoresOrdenados[$ciclo[$t + 1]]];
                }
            }
        }

        return ['parejas' => $parejas, 'bye' => $bye];
    }

    /**
     * @param array<string, list<array{0: array<string, mixed>, 1: array<string, mixed>}>> $parejasPorClub
     * @return array{mesas: list<list<array<string, mixed>>>, parejasSinUsar: list<array{0: array<string, mixed>, 1: array<string, mixed>}>}
     */
    private function rotadoInterclubesCruzarMesas(array $parejasPorClub): array
    {
        $mesas = [];
        $sinUsar = [];

        $codes = [];
        foreach ($parejasPorClub as $cod => $lista) {
            if ($lista !== []) {
                $codes[] = (string) $cod;
            }
        }
        $K = count($codes);
        if ($K < 2) {
            foreach ($parejasPorClub as $lista) {
                foreach ($lista as $par) {
                    $sinUsar[] = $par;
                }
            }

            return ['mesas' => $mesas, 'parejasSinUsar' => $sinUsar];
        }

        $slot = [];
        foreach ($codes as $c) {
            $slot[$c] = 0;
        }

        $formed = true;
        while ($formed) {
            $formed = false;
            for ($m0 = 0; $m0 < $K; $m0++) {
                $codeA = $codes[$m0];
                $codeB = $codes[($m0 + 1) % $K];
                if ($slot[$codeA] >= count($parejasPorClub[$codeA])) {
                    continue;
                }
                if ($slot[$codeB] >= count($parejasPorClub[$codeB])) {
                    continue;
                }
                $pa = $parejasPorClub[$codeA][$slot[$codeA]];
                $pb = $parejasPorClub[$codeB][$slot[$codeB]];
                $slot[$codeA]++;
                $slot[$codeB]++;
                // Pareja AC (club A) vs BD (club B): mismo orden que el resto del servicio [A,C,B,D]
                $mesas[] = [$pa[0], $pa[1], $pb[0], $pb[1]];
                $formed = true;
            }
        }

        foreach ($codes as $c) {
            while ($slot[$c] < count($parejasPorClub[$c])) {
                $sinUsar[] = $parejasPorClub[$c][$slot[$c]];
                $slot[$c]++;
            }
        }

        return ['mesas' => $mesas, 'parejasSinUsar' => $sinUsar];
    }
}

