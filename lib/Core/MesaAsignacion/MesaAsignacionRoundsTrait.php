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
}

