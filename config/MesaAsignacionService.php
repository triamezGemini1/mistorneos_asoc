<?php
/**
 * Servicio de Asignación de Mesas para Torneos de Dominó
 *
 * NORMA POR RONDA:
 * - Base del torneo: inscritos activos (confirmados). Esa lista se usa en cada ronda.
 * - Número de mesas = floor(inscritos/4). Con 17 inscritos solo hay 4 mesas (nunca mesa 5).
 * - Primero se asignan jugadores a mesas según el criterio de la ronda (sin repetir parejas cuando aplica);
 *   los restantes (últimos de la clasificación) son BYE y se fuerza que así sea.
 * - Si hubo BYE en la ronda 1 y no se ha retirado nadie, los mismos jugadores mantienen BYE hasta el final.
 * - Ronda 1: asignación al azar (dispersión por clubes); los sobrantes son BYE.
 * - De la ronda 2 en adelante: el BYE se elige del grupo de los últimos clasificados, con prioridad
 *   a no haber sido asignado BYE antes (si es posible no asignar dos veces BYE a un jugador; con pocos
 *   jugadores puede ser inevitable). Quien ya tiene 2 BYE va a mesa (última mesa).
 * - BYE se registra en partiresul con mesa=0 (partida ganada, 100% puntos, 50% efectividad).
 *
 * Arquitectura según directrices de equidad:
 * - Mesa: 4 jugadores. Pareja AC (A,C) vs Pareja BD (B,D). Secuencias: A=1, C=2, B=3, D=4
 * - Ronda 1: Dispersión por Clubes - vectores V1,V2,V3,V4
 * - Ronda 2: Separación de Líderes - patrón 1-5-3-7
 * - Rondas 3 a N-1: Suizo con restricción - evitar compañeros repetidos
 * - Ronda Final: Intercalado estricto (1,3) vs (2,4), etc.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';

class MesaAsignacionService
{
    private $pdo;
    private const JUGADORES_POR_MESA = 4;
    /** Máximo de jugadores en condición BYE por ronda (resto de activos/4: 0, 1, 2 o 3) */
    private const MAX_JUGADORES_BYE = 3;
    /** Máximo de BYE que puede tener un jugador en todo el torneo; con 2 debe jugar (última mesa). */
    private const MAX_BYE_POR_JUGADOR = 2;
    private const ID_COMODIN = 0;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    /**
     * Genera la asignación de mesas para una ronda específica
     */
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
        $inscritos = $this->obtenerClasificacionInscritos($torneoId);
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

        $this->guardarAsignacionRonda($torneoId, 1, $mesasArray);
        if (!empty($jugadoresBye)) {
            $this->aplicarBye($torneoId, 1, $jugadoresBye);
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
        $ranking = $this->obtenerClasificacionInscritosParaRonda2($torneoId);
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
        $conteoBye = $this->obtenerConteoByePorJugador($torneoId, 2);
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

        $this->guardarAsignacionRonda($torneoId, 2, $mesasArray);
        if (!empty($jugadoresBye)) {
            $this->aplicarBye($torneoId, 2, $jugadoresBye);
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
        $inscritos = $this->obtenerClasificacionInscritos($torneoId);
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
        $conteoBye = $this->obtenerConteoByePorJugador($torneoId, $numRonda);
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
            $enfrentamientos = $this->obtenerMatrizEnfrentamientos($torneoId, $r);
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

        $this->guardarAsignacionRonda($torneoId, $numRonda, $mesas);
        if (!empty($jugadoresBye)) {
            $this->aplicarBye($torneoId, $numRonda, $jugadoresBye);
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
        $inscritos = $this->obtenerClasificacionInscritos($torneoId);
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
        $conteoBye = $this->obtenerConteoByePorJugador($torneoId, $numRonda);
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

        $this->guardarAsignacionRonda($torneoId, $numRonda, $mesas);
        if (!empty($jugadoresBye)) {
            $this->aplicarBye($torneoId, $numRonda, $jugadoresBye);
        }

        return [
            'success' => true,
            'message' => "Última ronda generada exitosamente",
            'total_mesas' => count($mesas),
            'jugadores_bye' => count($jugadoresBye),
            'mesas' => $mesas
        ];
    }

    // ========================================================================
    // FUNCIONES AUXILIARES
    // ========================================================================

    /**
     * Base del torneo en cada ronda: inscritos activos (estatus 1 = confirmado).
     * Esta lista se usa para asignar mesas; los no asignados (máx. 3) quedan en BYE (mesa=0).
     * Orden: clasificación oficial (posicion, ganados, efectividad, puntos).
     */
    private function obtenerClasificacionInscritos($torneoId)
    {
        $og = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $oe = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $sql = "SELECT i.*, u.nombre, u.sexo, c.nombre as club_nombre, c.id as club_id
                FROM inscritos i
                INNER JOIN usuarios u ON (
                    u.id = i.id_usuario
                    OR (
                        u.numfvd = i.id_usuario
                        AND EXISTS (
                            SELECT 1 FROM tournaments tx
                            WHERE tx.id = i.torneo_id AND tx.club_responsable = 7
                        )
                    )
                )
                LEFT JOIN clubes c ON i.id_club = c.id
                WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereElegibleParaMesaConAlias('i') . "
                ORDER BY i.posicion ASC, {$og} DESC, {$oe} DESC, {$op} DESC, i.id_usuario ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $resultado;
    }

    /**
     * Devuelve los id_usuario que tuvieron BYE en la ronda 1 (mesa=0, partida=1).
     * Se usa para mantener el mismo BYE en rondas siguientes si no hay retirados.
     */
    private function obtenerIdsByeRonda1($torneoId): array
    {
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT id_usuario FROM partiresul
            WHERE id_torneo = ? AND partida = 1 AND mesa = 0 AND {$regOk}
        ");
        $stmt->execute([$torneoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Reordena la lista de jugadores: primero los que NO tuvieron BYE en ronda 1 (manteniendo orden),
     * al final los que sí tuvieron BYE en ronda 1. Así los BYE de r1 quedan como "resto" y se les asigna BYE.
     */
    private function reordenarConByeR1AlFinal(array $ranking, array $idsByeR1): array
    {
        $sinBye = [];
        $conBye = [];
        foreach ($ranking as $j) {
            $id = (int)($j['id_usuario'] ?? 0);
            if (in_array($id, $idsByeR1, true)) {
                $conBye[] = $j;
            } else {
                $sinBye[] = $j;
            }
        }
        return array_merge($sinBye, $conBye);
    }

    /**
     * Conteo de BYE por jugador en rondas anteriores (partida < antesDeRonda, mesa=0).
     * Se usa para no dar más de MAX_BYE_POR_JUGADOR a nadie y para colocar a quien tiene 2 BYE en la última mesa.
     */
    private function obtenerConteoByePorJugador(int $torneoId, int $antesDeRonda): array
    {
        if ($antesDeRonda <= 1) {
            return [];
        }
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $this->pdo->prepare("
            SELECT id_usuario, COUNT(*) AS cnt
            FROM partiresul
            WHERE id_torneo = ? AND partida < ? AND partida >= 1 AND mesa = 0 AND {$regOk}
            GROUP BY id_usuario
        ");
        $stmt->execute([$torneoId, $antesDeRonda]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['id_usuario']] = (int)$row['cnt'];
        }
        return $out;
    }

    /**
     * BYE = del grupo de últimos clasificados; prioridad = no haber sido BYE antes (0, luego 1; evitar 2).
     * Orden para elegir quién recibe BYE: bye_count ASC (preferir 0), position DESC (últimos clasificados).
     * Los primeros numBye reciben BYE; el resto va a mesas en orden position ASC (mejores primero).
     * @return array [jugadoresParaMesas, jugadoresBye]
     */
    private function reordenarParaLimitarBye(array $ranking, array $conteoBye, int $numBye, int $numMesas): array
    {
        $posicionKey = 0;
        $conClasif = [];
        foreach ($ranking as $j) {
            $id = (int)($j['id_usuario'] ?? 0);
            $byeCount = $conteoBye[$id] ?? 0;
            $pos = (int)($j['posicion'] ?? $posicionKey);
            $conClasif[] = ['bye_count' => $byeCount, 'posicion' => $pos, 'id_usuario' => $id, 'jugador' => $j];
            $posicionKey++;
        }
        // Quién recibe BYE: preferir 0 BYE, luego 1; dentro de cada grupo, últimos clasificados (position DESC)
        usort($conClasif, function ($a, $b) {
            if ($a['bye_count'] !== $b['bye_count']) {
                return $a['bye_count'] - $b['bye_count'];
            }
            return $b['posicion'] - $a['posicion']; // peor posición primero (últimos clasificados)
        });
        $ordenados = array_column($conClasif, 'jugador');
        $totalParaMesas = $numMesas * self::JUGADORES_POR_MESA;
        $jugadoresBye = array_slice($ordenados, 0, $numBye);
        $resto = array_slice($ordenados, $numBye, $totalParaMesas);
        // Mesas en orden de clasificación (mejores primero): ordenar resto por position ASC
        usort($resto, function ($a, $b) {
            $pa = (int)($a['posicion'] ?? 0);
            $pb = (int)($b['posicion'] ?? 0);
            return $pa - $pb;
        });
        return [$resto, $jugadoresBye];
    }

    /**
     * Clasificación para la SEGUNDA ronda: los jugadores que tuvieron BYE en la ronda 1
     * se consideran "peores ganadores" (posición inmediatamente después de los ganadores de ronda 1).
     * bye_r1: 1 = tuvo BYE en ronda 1, 0 = resto. Solo en ronda 2 se usa este criterio.
     * Orden: ganadores r1 (sin BYE) primero, luego ganadores r1 con BYE, luego perdedores r1.
     */
    private function obtenerClasificacionInscritosParaRonda2($torneoId)
    {
        $regPr1 = PartiresulEstatusSql::whereRegistradoUno('pr1');
        $ganoR1 = InscritosHelper::sqlExprPartiresulResultado1MayorQueResultado2('pr1');
        $ganadorR1Expr = "(CASE WHEN pr1.id IS NOT NULL AND ({$regPr1}) AND {$ganoR1} THEN 1 ELSE 0 END)";
        $byeR1Expr = "(CASE WHEN pr1.id IS NOT NULL AND ({$regPr1}) AND {$ganoR1} AND pr1.mesa = 0 THEN 1 ELSE 0 END)";
        $oe = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $sql = "SELECT i.*, u.nombre, u.sexo, c.nombre as club_nombre, c.id as club_id,
                {$ganadorR1Expr} AS ganador_r1,
                {$byeR1Expr} AS bye_r1
                FROM inscritos i
                INNER JOIN usuarios u ON (
                    u.id = i.id_usuario
                    OR (
                        u.numfvd = i.id_usuario
                        AND EXISTS (
                            SELECT 1 FROM tournaments tx
                            WHERE tx.id = i.torneo_id AND tx.club_responsable = 7
                        )
                    )
                )
                LEFT JOIN clubes c ON i.id_club = c.id
                LEFT JOIN partiresul pr1 ON pr1.id_torneo = i.torneo_id AND pr1.id_usuario = i.id_usuario AND pr1.partida = 1
                WHERE i.torneo_id = ? AND " . InscritosHelper::sqlWhereElegibleParaMesaConAlias('i') . "
                ORDER BY
                    {$ganadorR1Expr} DESC,
                    {$byeR1Expr} ASC,
                    {$oe} DESC, {$op} DESC, i.id_usuario ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $resultado;
    }

    private function mezclarJugadores($jugadores)
    {
        // Mezclar manteniendo cierto orden de clasificación
        shuffle($jugadores);
        return $jugadores;
    }

    private function asignarMesasAlternandoClubes($jugadores)
    {
        $mesas = [];
        $mesaActual = [];
        $clubUsados = [];

        foreach ($jugadores as $jugador) {
            $clubId = $jugador['club_id'] ?? 0;

            // Si la mesa está llena, iniciar nueva mesa
            if (count($mesaActual) >= self::JUGADORES_POR_MESA) {
                $mesas[] = $mesaActual;
                $mesaActual = [];
                $clubUsados = [];
            }

            // Verificar si el club ya está en la mesa
            if (in_array($clubId, $clubUsados) && count($mesaActual) > 0) {
                // Si el club ya está, buscar siguiente jugador o agregarlo igual
                $mesaActual[] = $jugador;
                $clubUsados[] = $clubId;
            } else {
                $mesaActual[] = $jugador;
                if ($clubId > 0) {
                    $clubUsados[] = $clubId;
                }
            }
        }

        // Agregar última mesa si tiene jugadores
        if (!empty($mesaActual)) {
            $mesas[] = $mesaActual;
        }

        return $mesas;
    }

    private function asignarMesasRonda2($jugadores, $matrizCompañeros, $matrizEnfrentamientos)
    {
        $mesas = [];
        $asignados = [];

        foreach ($jugadores as $jugador) {
            if (in_array($jugador['id_usuario'], $asignados)) {
                continue;
            }

            // Buscar una mesa donde pueda agregarse
            $agregado = false;
            
            // Primero intentar agregar a una mesa existente que no esté completa
            foreach ($mesas as &$mesa) {
                if (count($mesa) >= self::JUGADORES_POR_MESA) {
                    continue; // Mesa completa, saltar
                }

                // Verificar que no esté con compañero anterior en esta mesa
                $puedeAgregar = true;
                foreach ($mesa as $m) {
                    $idM = $m['id_usuario'];
                    $idJ = $jugador['id_usuario'];
                    if (isset($matrizCompañeros[$idM][$idJ]) || isset($matrizCompañeros[$idJ][$idM])) {
                        $puedeAgregar = false;
                        break;
                    }
                }

                if ($puedeAgregar) {
                    $mesa[] = $jugador;
                    $asignados[] = $jugador['id_usuario'];
                    $agregado = true;
                    break;
                }
            }
            unset($mesa); // Liberar referencia

            // Si no se pudo agregar a ninguna mesa existente, crear una nueva
            if (!$agregado) {
                $mesas[] = [$jugador];
                $asignados[] = $jugador['id_usuario'];
            }
        }

        // Intentar completar mesas incompletas con jugadores no asignados
        $jugadoresNoAsignados = array_filter($jugadores, function($j) use ($asignados) {
            return !in_array($j['id_usuario'], $asignados);
        });

        foreach ($jugadoresNoAsignados as $jugador) {
            // Buscar una mesa incompleta donde pueda agregarse
            $agregado = false;
            
            foreach ($mesas as &$mesa) {
                if (count($mesa) >= self::JUGADORES_POR_MESA) {
                    continue; // Mesa completa
                }

                // Verificar que no esté con compañero anterior
                $puedeAgregar = true;
                foreach ($mesa as $m) {
                    $idM = $m['id_usuario'];
                    $idJ = $jugador['id_usuario'];
                    if (isset($matrizCompañeros[$idM][$idJ]) || isset($matrizCompañeros[$idJ][$idM])) {
                        $puedeAgregar = false;
                        break;
                    }
                }

                if ($puedeAgregar) {
                    $mesa[] = $jugador;
                    $asignados[] = $jugador['id_usuario'];
                    $agregado = true;
                    break;
                }
            }
            unset($mesa);

            // Si aún no se pudo agregar, crear nueva mesa
            if (!$agregado && !in_array($jugador['id_usuario'], $asignados)) {
                $mesas[] = [$jugador];
                $asignados[] = $jugador['id_usuario'];
            }
        }

        return $mesas;
    }

    /**
     * Asigna mesas para rondas intermedias (3+) siguiendo el algoritmo:
     * 1. Jugador posición 1 escoge compañero (revisa 2, 3, 4... hasta encontrar uno con el que no haya jugado)
     * 2. Jugador posición 2 escoge compañero siguiendo el mismo procedimiento
     * 3. Completar mesa con 4 jugadores
     * 4. Repetir para siguientes mesas
     * 5. Si hay conflictos en últimas mesas, resolver intercambiando
     */
    private function asignarMesasRondaIntermedia($jugadores, $matrizCompañeros, $matrizEnfrentamientos)
    {
        $mesas = [];
        $asignados = [];
        $disponibles = array_values($jugadores); // Mantener orden de clasificación
        $indiceActual = 0;

        // Proceso de asignación mesa por mesa
        while ($indiceActual < count($disponibles)) {
            $mesaActual = [];
            
            // Obtener el primer jugador disponible (mejor clasificado no asignado)
            $jugador1 = null;
            while ($indiceActual < count($disponibles)) {
                if (!in_array($disponibles[$indiceActual]['id_usuario'], $asignados)) {
                    $jugador1 = $disponibles[$indiceActual];
                    break;
                }
                $indiceActual++;
            }
            
            if (!$jugador1) {
                break; // No hay más jugadores disponibles
            }
            
            $mesaActual[] = $jugador1;
            $asignados[] = $jugador1['id_usuario'];

            // Jugador 1 escoge su compañero (Pareja A - posición 2 de la mesa)
            $compañero1 = $this->buscarCompañero($jugador1, $disponibles, $asignados, $matrizCompañeros, $indiceActual + 1);
            
            if ($compañero1) {
                $mesaActual[] = $compañero1;
                $asignados[] = $compañero1['id_usuario'];
            } else {
                // Si no encuentra compañero, tomar el siguiente disponible
                $compañero1 = $this->obtenerSiguienteDisponible($disponibles, $asignados, $indiceActual + 1);
                if ($compañero1) {
                    $mesaActual[] = $compañero1;
                    $asignados[] = $compañero1['id_usuario'];
                }
            }

            // Obtener el siguiente jugador disponible para formar la Pareja B
            $jugador2 = $this->obtenerSiguienteDisponible($disponibles, $asignados, $indiceActual + 1);
            
            if (!$jugador2) {
                // Si no hay más jugadores, guardar lo que hay y terminar
                if (count($mesaActual) >= 2) {
                    $mesas[] = $mesaActual;
                }
                break;
            }

            $mesaActual[] = $jugador2;
            $asignados[] = $jugador2['id_usuario'];

            // Jugador 2 escoge su compañero (Pareja B - posición 4 de la mesa)
            $compañero2 = $this->buscarCompañero($jugador2, $disponibles, $asignados, $matrizCompañeros, $indiceActual + 1);
            
            if ($compañero2) {
                $mesaActual[] = $compañero2;
                $asignados[] = $compañero2['id_usuario'];
            } else {
                // Si no encuentra compañero, tomar el siguiente disponible
                $compañero2 = $this->obtenerSiguienteDisponible($disponibles, $asignados, $indiceActual + 1);
                if ($compañero2) {
                    $mesaActual[] = $compañero2;
                    $asignados[] = $compañero2['id_usuario'];
                }
            }

            // Si la mesa está completa (4 jugadores), guardarla
            if (count($mesaActual) >= self::JUGADORES_POR_MESA) {
                $mesas[] = $mesaActual;
            } elseif (count($mesaActual) >= 2) {
                // Si tiene al menos 2 jugadores pero no 4, guardarla igual
                $mesas[] = $mesaActual;
            }

            // Avanzar al siguiente jugador no asignado
            while ($indiceActual < count($disponibles) && 
                   in_array($disponibles[$indiceActual]['id_usuario'], $asignados)) {
                $indiceActual++;
            }
        }
        
        // Si quedan jugadores sin asignar, completar mesas incompletas
        // incluso si significa repetir compañeros (priorizar que todos jueguen)
        $jugadoresNoAsignados = array_filter($disponibles, function($j) use ($asignados) {
            return !in_array($j['id_usuario'], $asignados);
        });
        
        if (!empty($jugadoresNoAsignados)) {
            foreach ($jugadoresNoAsignados as $jugador) {
                $agregado = false;
                
                // Primero intentar agregar a una mesa incompleta sin repetir compañeros
                foreach ($mesas as &$mesa) {
                    if (count($mesa) >= self::JUGADORES_POR_MESA) {
                        continue;
                    }
                    
                    $puedeAgregar = true;
                    foreach ($mesa as $m) {
                        $idM = $m['id_usuario'];
                        $idJ = $jugador['id_usuario'];
                        if (isset($matrizCompañeros[$idM][$idJ]) || isset($matrizCompañeros[$idJ][$idM])) {
                            $puedeAgregar = false;
                            break;
                        }
                    }
                    
                    if ($puedeAgregar) {
                        $mesa[] = $jugador;
                        $asignados[] = $jugador['id_usuario'];
                        $agregado = true;
                        break;
                    }
                }
                unset($mesa);
                
                // Si no se pudo agregar sin repetir, agregar de todas formas a una mesa incompleta
                if (!$agregado) {
                    foreach ($mesas as &$mesa) {
                        if (count($mesa) < self::JUGADORES_POR_MESA) {
                            $mesa[] = $jugador;
                            $asignados[] = $jugador['id_usuario'];
                            $agregado = true;
                            break;
                        }
                    }
                    unset($mesa);
                }
                
                // Si aún no se pudo agregar, crear una nueva mesa
                if (!$agregado) {
                    $mesas[] = [$jugador];
                    $asignados[] = $jugador['id_usuario'];
                }
            }
        }
        
        // Limpiar duplicados antes de resolver conflictos
        $mesas = $this->limpiarMesasDuplicados($mesas);

        // Resolver conflictos en las últimas mesas
        return $this->resolverConflictosUltimasMesas($mesas, $jugadores, $matrizCompañeros);
    }

    /**
     * Busca un compañero para un jugador que no haya sido su compañero antes
     */
    private function buscarCompañero($jugador, $disponibles, $asignados, $matrizCompañeros, $inicioDesde = 0)
    {
        $idJugador = $jugador['id_usuario'];
        
        // Buscar desde la siguiente posición en clasificación
        for ($i = $inicioDesde; $i < count($disponibles); $i++) {
            $candidato = $disponibles[$i];
            
            // Saltar si ya está asignado
            if (in_array($candidato['id_usuario'], $asignados)) {
                continue;
            }
            
            // Verificar que no hayan sido compañeros antes
            if (!isset($matrizCompañeros[$idJugador][$candidato['id_usuario']]) &&
                !isset($matrizCompañeros[$candidato['id_usuario']][$idJugador])) {
                return $candidato;
            }
        }
        
        return null;
    }

    /**
     * Obtiene el siguiente jugador disponible no asignado
     */
    private function obtenerSiguienteDisponible($disponibles, $asignados, $inicioDesde = 0)
    {
        for ($i = $inicioDesde; $i < count($disponibles); $i++) {
            if (!in_array($disponibles[$i]['id_usuario'], $asignados)) {
                return $disponibles[$i];
            }
        }
        return null;
    }
    
    /**
     * Limpia mesas eliminando duplicados y redistribuyendo jugadores de mesas inválidas
     */
    private function limpiarMesasDuplicados($mesas)
    {
        $mesasLimpias = [];
        $idsAsignados = [];
        $jugadoresMesasInvalidas = [];
        
        foreach ($mesas as $mesa) {
            $mesaLimpia = [];
            $idsEnMesa = [];
            
            foreach ($mesa as $jugador) {
                $idUsuario = $jugador['id_usuario'];
                
                // Si el jugador ya está en otra mesa, no agregarlo a esta
                if (in_array($idUsuario, $idsAsignados)) {
                    continue;
                }
                
                // Si el jugador ya está en esta mesa, no duplicarlo
                if (in_array($idUsuario, $idsEnMesa)) {
                    continue;
                }
                
                $mesaLimpia[] = $jugador;
                $idsEnMesa[] = $idUsuario;
                $idsAsignados[] = $idUsuario;
            }
            
            // Solo agregar mesas con al menos 2 jugadores
            if (count($mesaLimpia) >= 2) {
                $mesasLimpias[] = $mesaLimpia;
            } else {
                // Guardar jugadores de mesas inválidas para redistribuir
                $jugadoresMesasInvalidas = array_merge($jugadoresMesasInvalidas, $mesaLimpia);
            }
        }
        
        // Redistribuir jugadores de mesas inválidas
        foreach ($jugadoresMesasInvalidas as $jugador) {
            $agregado = false;
            foreach ($mesasLimpias as &$mesa) {
                if (count($mesa) < self::JUGADORES_POR_MESA) {
                    // Verificar que no esté duplicado
                    $yaEnMesa = false;
                    foreach ($mesa as $m) {
                        if ($m['id_usuario'] == $jugador['id_usuario']) {
                            $yaEnMesa = true;
                            break;
                        }
                    }
                    if (!$yaEnMesa) {
                        $mesa[] = $jugador;
                        $agregado = true;
                        break;
                    }
                }
            }
            unset($mesa);
        }
        
        return $mesasLimpias;
    }
    
    /**
     * Completa mesas incompletas con jugadores sobrantes
     * Prioriza evitar repeticiones, pero si es necesario, permite repeticiones mínimas
     */
    private function completarMesasIncompletas($mesas, $jugadoresSobrantes, $matrizCompañeros)
    {
        $asignados = [];
        foreach ($mesas as $mesa) {
            foreach ($mesa as $jugador) {
                $asignados[] = $jugador['id_usuario'];
            }
        }
        
        foreach ($jugadoresSobrantes as $jugador) {
            if (in_array($jugador['id_usuario'], $asignados)) {
                continue;
            }
            
            $agregado = false;
            
            // Primero intentar agregar a una mesa incompleta sin repetir compañeros
            foreach ($mesas as &$mesa) {
                if (count($mesa) >= self::JUGADORES_POR_MESA) {
                    continue;
                }
                
                $puedeAgregar = true;
                foreach ($mesa as $m) {
                    $idM = $m['id_usuario'];
                    $idJ = $jugador['id_usuario'];
                    if (isset($matrizCompañeros[$idM][$idJ]) || isset($matrizCompañeros[$idJ][$idM])) {
                        $puedeAgregar = false;
                        break;
                    }
                }
                
                if ($puedeAgregar) {
                    $mesa[] = $jugador;
                    $asignados[] = $jugador['id_usuario'];
                    $agregado = true;
                    break;
                }
            }
            unset($mesa);
            
            // Si no se pudo agregar sin repetir, agregar de todas formas a una mesa incompleta
            if (!$agregado) {
                foreach ($mesas as &$mesa) {
                    if (count($mesa) < self::JUGADORES_POR_MESA) {
                        $mesa[] = $jugador;
                        $asignados[] = $jugador['id_usuario'];
                        $agregado = true;
                        break;
                    }
                }
                unset($mesa);
            }
        }
        
        return $mesas;
    }

    /**
     * Resuelve conflictos en las últimas mesas
     * Si un jugador repite compañero en las últimas mesas:
     * 1. Intentar cambiarlo en la misma mesa
     * 2. Si ya jugó con todos en esa mesa, moverlo a la mesa anterior y hacer intercambios
     */
    private function resolverConflictosUltimasMesas($mesas, $jugadoresOrdenados, $matrizCompañeros)
    {
        if (count($mesas) < 2) {
            return $mesas;
        }

        // Procesar de atrás hacia adelante (últimas mesas primero)
        for ($i = count($mesas) - 1; $i >= 0; $i--) {
            $mesa = $mesas[$i];
            
            if (count($mesa) < 4) {
                continue; // Mesa incompleta, saltar
            }

            $idsMesa = array_column($mesa, 'id_usuario');
            $conflictos = $this->detectarConflictosMesa($mesa, $matrizCompañeros);
            
            if (empty($conflictos)) {
                continue; // No hay conflictos en esta mesa
            }

            // Intentar resolver conflictos en la misma mesa primero
            $mesaResuelta = $this->resolverConflictosEnMismaMesa($mesa, $matrizCompañeros);
            
            // Si aún hay conflictos y no es la primera mesa, mover a mesa anterior
            if ($i > 0 && $this->detectarConflictosMesa($mesaResuelta, $matrizCompañeros)) {
                $mesaResuelta = $this->moverAMesaAnterior($mesaResuelta, $mesas, $i, $matrizCompañeros);
            }
            
            $mesas[$i] = $mesaResuelta;
        }

        return $mesas;
    }

    /**
     * Resuelve conflictos en últimas mesas de Ronda 2
     * Formato mesa: [a,b,c,d] donde Pareja A = indices 0,2 (a,c), Pareja B = indices 1,3 (b,d)
     * Mismo principio que rondas 3 a N-1: en misma mesa o con mesa anterior
     */
    private function resolverConflictosUltimasMesasRonda2($mesas, $jugadoresOrdenados, $matrizCompañeros)
    {
        if (count($mesas) < 2) {
            return $mesas;
        }

        for ($i = count($mesas) - 1; $i >= 0; $i--) {
            $mesa = $mesas[$i];
            if (count($mesa) < 4) {
                continue;
            }

            if (empty($this->detectarConflictosMesaRonda2($mesa, $matrizCompañeros))) {
                continue;
            }

            $mesaResuelta = $this->resolverConflictosEnMismaMesaRonda2($mesa, $matrizCompañeros);
            if ($i > 0 && !empty($this->detectarConflictosMesaRonda2($mesaResuelta, $matrizCompañeros))) {
                list($mesaResuelta, $mesaAnteriorResuelta) = $this->moverAMesaAnteriorRonda2($mesaResuelta, $mesas[$i - 1], $matrizCompañeros);
                if ($mesaAnteriorResuelta !== null) {
                    $mesas[$i - 1] = $mesaAnteriorResuelta;
                }
            }
            $mesas[$i] = $mesaResuelta;
        }

        return $mesas;
    }

    /**
     * Detecta conflictos en mesa formato Ronda 2: orden [a,c,b,d] → Pareja 1 = 0,1; Pareja 2 = 2,3
     */
    private function detectarConflictosMesaRonda2($mesa, $matrizCompañeros)
    {
        $conflictos = [];
        if (count($mesa) < 4) return $conflictos;

        $ids = array_column($mesa, 'id_usuario');
        if (isset($matrizCompañeros[$ids[0]][$ids[1]]) || isset($matrizCompañeros[$ids[1]][$ids[0]])) {
            $conflictos[] = ['pareja' => 'A', 'idx1' => 0, 'idx2' => 1];
        }
        if (isset($matrizCompañeros[$ids[2]][$ids[3]]) || isset($matrizCompañeros[$ids[3]][$ids[2]])) {
            $conflictos[] = ['pareja' => 'B', 'idx1' => 2, 'idx2' => 3];
        }
        return $conflictos;
    }

    /**
     * Resuelve conflictos intercambiando dentro de la misma mesa (formato Ronda 2)
     */
    private function resolverConflictosEnMismaMesaRonda2($mesa, $matrizCompañeros)
    {
        $conflictos = $this->detectarConflictosMesaRonda2($mesa, $matrizCompañeros);
        if (empty($conflictos)) return $mesa;

        foreach ($conflictos as $c) {
            $idx1 = $c['idx1'];
            $idx2 = $c['idx2'];
            $otros = array_values(array_diff([0,1,2,3], [$idx1, $idx2]));
            foreach ([$idx1, $idx2] as $idxConflicto) {
                foreach ($otros as $o) {
                    $mesaPrueba = $mesa;
                    $temp = $mesaPrueba[$idxConflicto];
                    $mesaPrueba[$idxConflicto] = $mesaPrueba[$o];
                    $mesaPrueba[$o] = $temp;
                    if (empty($this->detectarConflictosMesaRonda2($mesaPrueba, $matrizCompañeros))) {
                        return $mesaPrueba;
                    }
                }
            }
        }
        return $mesa;
    }

    /**
     * Intercambia con mesa anterior para resolver conflictos (formato Ronda 2)
     * Retorna [mesaActualResuelta, mesaAnteriorResuelta] o [mesaActual, null] si no se resolvió
     */
    private function moverAMesaAnteriorRonda2($mesaActual, $mesaAnterior, $matrizCompañeros)
    {
        if (count($mesaActual) < 4 || count($mesaAnterior) < 4) {
            return [$mesaActual, null];
        }

        $conflictos = $this->detectarConflictosMesaRonda2($mesaActual, $matrizCompañeros);
        if (empty($conflictos)) return [$mesaActual, null];

        foreach ($conflictos as $c) {
            foreach ([$c['idx1'], $c['idx2']] as $idxConflicto) {
                for ($o = 0; $o < 4; $o++) {
                    $mesaActualPrueba = $mesaActual;
                    $mesaAnteriorPrueba = $mesaAnterior;
                    $temp = $mesaActualPrueba[$idxConflicto];
                    $mesaActualPrueba[$idxConflicto] = $mesaAnteriorPrueba[$o];
                    $mesaAnteriorPrueba[$o] = $temp;
                    if (empty($this->detectarConflictosMesaRonda2($mesaActualPrueba, $matrizCompañeros)) &&
                        empty($this->detectarConflictosMesaRonda2($mesaAnteriorPrueba, $matrizCompañeros))) {
                        return [$mesaActualPrueba, $mesaAnteriorPrueba];
                    }
                }
            }
        }
        return [$mesaActual, null];
    }

    /**
     * Detecta conflictos de compañeros repetidos en una mesa
     */
    private function detectarConflictosMesa($mesa, $matrizCompañeros)
    {
        $conflictos = [];
        
        if (count($mesa) < 4) {
            return $conflictos;
        }

        $idsMesa = array_column($mesa, 'id_usuario');
        
        // Verificar Pareja A (posiciones 0-1)
        $id1 = $idsMesa[0];
        $id2 = $idsMesa[1];
        if (isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1])) {
            $conflictos[] = ['pareja' => 'A', 'jugador1' => $id1, 'jugador2' => $id2];
        }

        // Verificar Pareja B (posiciones 2-3)
        $id3 = $idsMesa[2];
        $id4 = $idsMesa[3];
        if (isset($matrizCompañeros[$id3][$id4]) || isset($matrizCompañeros[$id4][$id3])) {
            $conflictos[] = ['pareja' => 'B', 'jugador1' => $id3, 'jugador2' => $id4];
        }

        return $conflictos;
    }

    /**
     * Resuelve conflictos intercambiando dentro de la misma mesa
     */
    private function resolverConflictosEnMismaMesa($mesa, $matrizCompañeros)
    {
        $conflictos = $this->detectarConflictosMesa($mesa, $matrizCompañeros);
        
        if (empty($conflictos)) {
            return $mesa;
        }

        $idsMesa = array_column($mesa, 'id_usuario');
        
        // Si hay conflicto en Pareja A, intercambiar con Pareja B
        foreach ($conflictos as $conflicto) {
            if ($conflicto['pareja'] === 'A') {
                // Intercambiar jugador de Pareja A (posición 1) con jugador de Pareja B (posición 2 o 3)
                // Probar intercambio con posición 2
                $mesaPrueba = $mesa;
                $temp = $mesaPrueba[1];
                $mesaPrueba[1] = $mesaPrueba[2];
                $mesaPrueba[2] = $temp;
                
                if (empty($this->detectarConflictosMesa($mesaPrueba, $matrizCompañeros))) {
                    return $mesaPrueba;
                }
                
                // Si no funcionó, probar con posición 3
                $mesaPrueba = $mesa;
                $temp = $mesaPrueba[1];
                $mesaPrueba[1] = $mesaPrueba[3];
                $mesaPrueba[3] = $temp;
                
                if (empty($this->detectarConflictosMesa($mesaPrueba, $matrizCompañeros))) {
                    return $mesaPrueba;
                }
            } elseif ($conflicto['pareja'] === 'B') {
                // Intercambiar jugador de Pareja B (posición 3) con jugador de Pareja A (posición 0 o 1)
                $mesaPrueba = $mesa;
                $temp = $mesaPrueba[3];
                $mesaPrueba[3] = $mesaPrueba[0];
                $mesaPrueba[0] = $temp;
                
                if (empty($this->detectarConflictosMesa($mesaPrueba, $matrizCompañeros))) {
                    return $mesaPrueba;
                }
            }
        }

        return $mesa; // No se pudo resolver en la misma mesa
    }

    /**
     * Mueve un jugador con conflicto a la mesa anterior y hace intercambios
     */
    private function moverAMesaAnterior($mesaActual, $todasMesas, $indiceMesaActual, $matrizCompañeros)
    {
        if ($indiceMesaActual <= 0) {
            return $mesaActual; // No hay mesa anterior
        }

        $mesaAnterior = $todasMesas[$indiceMesaActual - 1];
        
        if (count($mesaAnterior) < 4) {
            return $mesaActual; // Mesa anterior incompleta
        }

        $conflictos = $this->detectarConflictosMesa($mesaActual, $matrizCompañeros);
        
        if (empty($conflictos)) {
            return $mesaActual; // Ya no hay conflictos
        }

        // Para cada conflicto, intentar intercambiar con la mesa anterior
        foreach ($conflictos as $conflicto) {
            $jugadorConflictivo = $conflicto['jugador1'];
            $idxJugador = null;
            
            // Encontrar índice del jugador conflictivo en la mesa actual
            foreach ($mesaActual as $idx => $jugador) {
                if ($jugador['id_usuario'] == $jugadorConflictivo) {
                    $idxJugador = $idx;
                    break;
                }
            }
            
            if ($idxJugador === null) {
                continue;
            }

            // Intentar intercambiar con cada jugador de la mesa anterior
            foreach ($mesaAnterior as $idxAnterior => $jugadorAnterior) {
                $mesaActualPrueba = $mesaActual;
                $mesaAnteriorPrueba = $mesaAnterior;
                
                // Intercambiar
                $temp = $mesaActualPrueba[$idxJugador];
                $mesaActualPrueba[$idxJugador] = $mesaAnteriorPrueba[$idxAnterior];
                $mesaAnteriorPrueba[$idxAnterior] = $temp;
                
                // Verificar que ambas mesas queden sin conflictos
                if (empty($this->detectarConflictosMesa($mesaActualPrueba, $matrizCompañeros)) &&
                    empty($this->detectarConflictosMesa($mesaAnteriorPrueba, $matrizCompañeros))) {
                    // Actualizar ambas mesas
                    $todasMesas[$indiceMesaActual] = $mesaActualPrueba;
                    $todasMesas[$indiceMesaActual - 1] = $mesaAnteriorPrueba;
                    return $mesaActualPrueba;
                }
            }
        }

        return $mesaActual; // No se pudo resolver
    }

    /**
     * Resuelve conflictos en una mesa intentando intercambiar jugadores
     */
    private function resolverConflictosMesa($mesaActual, $disponibles, $asignados, $matrizCompañeros, $jugadoresOrdenados)
    {
        $necesarios = self::JUGADORES_POR_MESA - count($mesaActual);
        $idsEnMesa = array_column($mesaActual, 'id_usuario');
        $candidatos = [];

        // Buscar jugadores disponibles que no sean compañeros de los ya en la mesa
        foreach ($disponibles as $candidato) {
            if (in_array($candidato['id_usuario'], $asignados)) {
                continue;
            }

            $puedeAgregar = true;
            foreach ($idsEnMesa as $idMesa) {
                if (isset($matrizCompañeros[$candidato['id_usuario']][$idMesa]) || 
                    isset($matrizCompañeros[$idMesa][$candidato['id_usuario']])) {
                    $puedeAgregar = false;
                    break;
                }
            }

            if ($puedeAgregar) {
                $candidatos[] = $candidato;
            }
        }

        // Agregar candidatos válidos
        for ($i = 0; $i < min($necesarios, count($candidatos)); $i++) {
            $mesaActual[] = $candidatos[$i];
        }

        return $mesaActual;
    }

    /**
     * Optimiza las últimas mesas intercambiando jugadores para evitar compañeros repetidos
     */
    private function optimizarUltimasMesas($mesas, $jugadoresOrdenados, $matrizCompañeros)
    {
        if (count($mesas) < 2) {
            return $mesas;
        }

        // Procesar de atrás hacia adelante (últimas mesas)
        for ($i = count($mesas) - 1; $i >= max(0, count($mesas) - 3); $i--) {
            $mesa = $mesas[$i];
            
            if (count($mesa) < self::JUGADORES_POR_MESA) {
                continue;
            }

            // Verificar si hay compañeros repetidos en la mesa
            $idsMesa = array_column($mesa, 'id_usuario');
            $tieneConflicto = false;
            
            for ($j = 0; $j < count($idsMesa); $j++) {
                for ($k = $j + 1; $k < count($idsMesa); $k++) {
                    $id1 = $idsMesa[$j];
                    $id2 = $idsMesa[$k];
                    
                    // Verificar si fueron pareja (secuencia 1-2 o 3-4)
                    $fueronPareja = false;
                    if (($j < 2 && $k < 2) || ($j >= 2 && $k >= 2)) {
                        $fueronPareja = isset($matrizCompañeros[$id1][$id2]) || 
                                       isset($matrizCompañeros[$id2][$id1]);
                    }
                    
                    if ($fueronPareja) {
                        $tieneConflicto = true;
                        break 2;
                    }
                }
            }

            if ($tieneConflicto) {
                // Intentar intercambiar dentro de la misma mesa primero
                $mesa = $this->intercambiarEnMismaMesa($mesa, $matrizCompañeros);
                
                // Si aún hay conflicto, intercambiar con otras mesas
                if ($this->tieneConflictoParejas($mesa, $matrizCompañeros)) {
                    $mesa = $this->intercambiarConOtrasMesas($mesa, $mesas, $i, $jugadoresOrdenados, $matrizCompañeros);
                }
                
                $mesas[$i] = $mesa;
            }
        }

        return $mesas;
    }

    /**
     * Intercambia jugadores dentro de la misma mesa para evitar compañeros repetidos
     */
    private function intercambiarEnMismaMesa($mesa, $matrizCompañeros)
    {
        $idsMesa = array_column($mesa, 'id_usuario');
        
        // Intercambiar entre pareja A (posiciones 0-1) y pareja B (posiciones 2-3)
        // Si hay conflicto en pareja A, intercambiar con pareja B
        for ($p = 0; $p < 2; $p++) {
            $id1 = $idsMesa[$p];
            $id2 = $idsMesa[1 - $p];
            
            if (isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1])) {
                // Intercambiar con pareja B
                $temp = $mesa[$p];
                $mesa[$p] = $mesa[2];
                $mesa[2] = $temp;
                
                // Revalidar
                $idsMesa = array_column($mesa, 'id_usuario');
                if (!isset($matrizCompañeros[$idsMesa[0]][$idsMesa[1]]) && 
                    !isset($matrizCompañeros[$idsMesa[1]][$idsMesa[0]]) &&
                    !isset($matrizCompañeros[$idsMesa[2]][$idsMesa[3]]) && 
                    !isset($matrizCompañeros[$idsMesa[3]][$idsMesa[2]])) {
                    return $mesa;
                }
            }
        }

        // Si hay conflicto en pareja B, intercambiar con pareja A
        for ($p = 2; $p < 4; $p++) {
            $id1 = $idsMesa[$p];
            $id2 = $idsMesa[5 - $p];
            
            if (isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1])) {
                $temp = $mesa[$p];
                $mesa[$p] = $mesa[0];
                $mesa[0] = $temp;
            }
        }

        return $mesa;
    }

    /**
     * Intercambia jugadores con otras mesas según clasificación
     */
    private function intercambiarConOtrasMesas($mesa, $todasMesas, $indiceMesaActual, $jugadoresOrdenados, $matrizCompañeros)
    {
        $idsMesa = array_column($mesa, 'id_usuario');
        $posicionesJugadores = [];
        
        // Crear mapa de posiciones de clasificación
        foreach ($jugadoresOrdenados as $idx => $j) {
            $posicionesJugadores[$j['id_usuario']] = $idx;
        }

        // Buscar intercambio en mesas anteriores (mejor clasificación) o posteriores (peor clasificación)
        for ($i = 0; $i < count($todasMesas); $i++) {
            if ($i === $indiceMesaActual || count($todasMesas[$i]) < self::JUGADORES_POR_MESA) {
                continue;
            }

            $otraMesa = $todasMesas[$i];
            $idsOtraMesa = array_column($otraMesa, 'id_usuario');

            // Intentar intercambiar cada jugador de la mesa actual
            foreach ($mesa as $idxJugador => $jugador) {
                $idJugador = $jugador['id_usuario'];
                $posicionJugador = $posicionesJugadores[$idJugador] ?? 9999;

                // Buscar jugador en otra mesa para intercambiar
                foreach ($otraMesa as $idxOtro => $otroJugador) {
                    $idOtro = $otroJugador['id_usuario'];
                    $posicionOtro = $posicionesJugadores[$idOtro] ?? 9999;

                    // Intercambiar solo si mejora la situación y respeta la clasificación
                    if (($i < $indiceMesaActual && $posicionOtro < $posicionJugador) ||
                        ($i > $indiceMesaActual && $posicionOtro > $posicionJugador)) {
                        
                        // Verificar si el intercambio resuelve conflictos
                        $mesaPrueba = $mesa;
                        $mesaPrueba[$idxJugador] = $otroJugador;
                        
                        $otraMesaPrueba = $otraMesa;
                        $otraMesaPrueba[$idxOtro] = $jugador;

                        if (!$this->tieneConflictoParejas($mesaPrueba, $matrizCompañeros) &&
                            !$this->tieneConflictoParejas($otraMesaPrueba, $matrizCompañeros)) {
                            $mesa = $mesaPrueba;
                            $todasMesas[$i] = $otraMesaPrueba;
                            return $mesa;
                        }
                    }
                }
            }
        }

        return $mesa;
    }

    /**
     * Verifica si una mesa tiene conflictos de parejas repetidas
     */
    private function tieneConflictoParejas($mesa, $matrizCompañeros)
    {
        if (count($mesa) < 2) {
            return false;
        }

        $idsMesa = array_column($mesa, 'id_usuario');
        
        // Verificar pareja A (posiciones 0-1)
        if (count($idsMesa) > 1) {
            $id1 = $idsMesa[0];
            $id2 = $idsMesa[1];
            if (isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1])) {
                return true;
            }
        }

        // Verificar pareja B (posiciones 2-3)
        if (count($idsMesa) > 3) {
            $id3 = $idsMesa[2];
            $id4 = $idsMesa[3];
            if (isset($matrizCompañeros[$id3][$id4]) || isset($matrizCompañeros[$id4][$id3])) {
                return true;
            }
        }

        return false;
    }

    private function obtenerJugadoresSobrantes($todosJugadores, $mesas)
    {
        $asignados = [];
        foreach ($mesas as $mesa) {
            foreach ($mesa as $jugador) {
                $asignados[] = $jugador['id_usuario'];
            }
        }

        return array_values(array_filter($todosJugadores, function($j) use ($asignados) {
            return !in_array($j['id_usuario'], $asignados);
        }));
    }

    /**
     * Con pocas mesas (ej. 17 jugadores = 4 mesas + 1 BYE) hay que garantizar exactamente numMesas mesas de 4.
     * Solo redistribuye si hay mesas de más o alguna mesa con distinto de 4 jugadores.
     */
    private function ajustarMesasExactas(array $mesas, int $numMesas, array $jugadoresParaMesas): array
    {
        $correcto = count($mesas) === $numMesas;
        if ($correcto) {
            foreach ($mesas as $mesa) {
                if (count($mesa) !== self::JUGADORES_POR_MESA) {
                    $correcto = false;
                    break;
                }
            }
        }
        if ($correcto) {
            return $mesas;
        }

        $todos = [];
        foreach ($mesas as $mesa) {
            foreach ($mesa as $j) {
                $todos[$j['id_usuario']] = $j;
            }
        }
        $ordenados = [];
        foreach ($jugadoresParaMesas as $j) {
            $id = (int)($j['id_usuario'] ?? 0);
            if (isset($todos[$id])) {
                $ordenados[] = $j;
            }
        }
        if (count($ordenados) < $numMesas * self::JUGADORES_POR_MESA) {
            return $mesas;
        }
        $resultado = [];
        for ($m = 0; $m < $numMesas; $m++) {
            $resultado[] = array_slice($ordenados, $m * self::JUGADORES_POR_MESA, self::JUGADORES_POR_MESA);
        }
        return $resultado;
    }

    /**
     * Obtiene la matriz de compañeros desde historial_parejas (si existe).
     * Datos guardados como id_menor-id_mayor (jugador_1_id <= jugador_2_id, llave).
     */
    private function obtenerMatrizCompañerosDesdeHistorial(int $torneoId, int $hastaRonda): array
    {
        try {
            $sql = "SELECT jugador_1_id, jugador_2_id FROM historial_parejas WHERE torneo_id = ? AND ronda_id <= ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId, $hastaRonda]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->crearMatrizCompañeros(
                array_map(fn($r) => [(int)$r['jugador_1_id'], (int)$r['jugador_2_id']], $filas)
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Verifica si dos jugadores ya jugaron juntos (una sola consulta: torneo_id + llave).
     */
    public function yaJugaronJuntos(int $torneoId, int $id1, int $id2, int $hastaRonda): bool
    {
        $idMenor = min($id1, $id2);
        $idMayor = max($id1, $id2);
        $llave = $idMenor . '-' . $idMayor;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM historial_parejas WHERE torneo_id = ? AND llave = ? AND ronda_id <= ? LIMIT 1"
            );
            $stmt->execute([$torneoId, $llave, $hastaRonda]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM historial_parejas WHERE torneo_id = ? AND jugador_1_id = ? AND jugador_2_id = ? AND ronda_id <= ? LIMIT 1"
                );
                $stmt->execute([$torneoId, $idMenor, $idMayor, $hastaRonda]);
                return (bool)$stmt->fetch();
            } catch (Exception $e2) {
                return false;
            }
        }
    }

    /**
     * Obtiene matriz de compañeros: usa historial_parejas si hay datos, sino partiresul.
     */
    private function obtenerMatrizCompañerosParaRonda(int $torneoId, int $hastaRonda): array
    {
        $matriz = $this->obtenerMatrizCompañerosDesdeHistorial($torneoId, $hastaRonda);
        if (!empty($matriz)) {
            return $matriz;
        }
        $parejas = $this->obtenerParejasRondasAnteriores($torneoId, $hastaRonda);
        return $this->crearMatrizCompañeros($parejas);
    }

    private function obtenerParejasRonda($torneoId, $ronda)
    {
        $sql = "SELECT partida, mesa, id_usuario, secuencia
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                ORDER BY mesa, secuencia";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parejas = [];
        $mesaActual = null;
        $jugadoresMesa = [];

        foreach ($resultados as $r) {
            if ($mesaActual !== $r['mesa']) {
                // Guardar parejas de la mesa anterior
                if (count($jugadoresMesa) >= 4) {
                    // Pareja A: secuencias 1-2
                    $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
                    // Pareja B: secuencias 3-4
                    $parejas[] = [$jugadoresMesa[2], $jugadoresMesa[3]];
                } elseif (count($jugadoresMesa) >= 2) {
                    // Si solo hay 2 jugadores, forman una pareja
                    $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
                }
                $mesaActual = $r['mesa'];
                $jugadoresMesa = [];
            }
            $jugadoresMesa[] = $r['id_usuario'];
        }

        // Guardar parejas de la última mesa
        if (count($jugadoresMesa) >= 4) {
            $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
            $parejas[] = [$jugadoresMesa[2], $jugadoresMesa[3]];
        } elseif (count($jugadoresMesa) >= 2) {
            $parejas[] = [$jugadoresMesa[0], $jugadoresMesa[1]];
        }

        return $parejas;
    }

    private function obtenerParejasRondasAnteriores($torneoId, $hastaRonda)
    {
        $todasParejas = [];
        for ($r = 1; $r <= $hastaRonda; $r++) {
            $parejas = $this->obtenerParejasRonda($torneoId, $r);
            $todasParejas = array_merge($todasParejas, $parejas);
        }
        return $todasParejas;
    }

    private function crearMatrizCompañeros($parejas)
    {
        $matriz = [];
        foreach ($parejas as $pareja) {
            if (count($pareja) >= 2) {
                $id1 = $pareja[0];
                $id2 = $pareja[1];
                $matriz[$id1][$id2] = true;
                $matriz[$id2][$id1] = true;
            }
        }
        return $matriz;
    }

    private function obtenerMatrizEnfrentamientos($torneoId, $ronda)
    {
        $sql = "SELECT DISTINCT pr1.id_usuario as id1, pr2.id_usuario as id2
                FROM partiresul pr1
                INNER JOIN partiresul pr2 ON pr1.id_torneo = pr2.id_torneo 
                    AND pr1.partida = pr2.partida 
                    AND pr1.mesa = pr2.mesa
                    AND pr1.id_usuario < pr2.id_usuario
                WHERE pr1.id_torneo = ? AND pr1.partida = ? AND pr1.mesa > 0";
        
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

    private function guardarAsignacionRonda($torneoId, $ronda, $mesas)
    {
        $this->pdo->beginTransaction();

        try {
            $numeroMesa = 1;
            foreach ($mesas as $mesa) {
                $secuencia = 1;
                foreach ($mesa as $jugador) {
                    $idUsuario = (int)($jugador['id_usuario'] ?? 0);
                    if ($idUsuario <= 0) continue; // Saltar comodín/bye
                    $registrado_por = (class_exists('Auth') && method_exists('Auth', 'id')) ? ((int)Auth::id() ?: 1) : 1;
                    $sql = "INSERT INTO partiresul 
                            (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por)
                            VALUES (?, ?, ?, ?, ?, NOW(), 0, ?)
                            ON DUPLICATE KEY UPDATE
                            mesa = VALUES(mesa),
                            secuencia = VALUES(secuencia)";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$torneoId, $idUsuario, $ronda, $numeroMesa, $secuencia, $registrado_por]);
                    $secuencia++;
                }
                $numeroMesa++;
            }

            $this->guardarHistorialParejas($mesas, $torneoId, $ronda);
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Guarda parejas AC y BD en historial_parejas (si la tabla existe).
     * Regla: siempre id_menor-id_mayor. jugador_1_id < jugador_2_id, llave = 'id_menor-id_mayor'.
     * Permite una sola consulta: WHERE torneo_id = ? AND llave = '123-456'
     */
    private function guardarHistorialParejas(array $mesasAsignadas, int $torneoId, int $rondaId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id, llave) VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($mesasAsignadas as $mesa) {
                if (count($mesa) < 4) continue;
                $ids = array_column($mesa, 'id_usuario');
                $a = (int)($ids[0] ?? 0);
                $c = (int)($ids[1] ?? 0);
                $b = (int)($ids[2] ?? 0);
                $d = (int)($ids[3] ?? 0);
                if ($a > 0 && $c > 0) {
                    $idMenor = min($a, $c);
                    $idMayor = max($a, $c);
                    $llave = $idMenor . '-' . $idMayor;
                    $stmt->execute([$torneoId, $rondaId, $idMenor, $idMayor, $llave]);
                }
                if ($b > 0 && $d > 0) {
                    $idMenor = min($b, $d);
                    $idMayor = max($b, $d);
                    $llave = $idMenor . '-' . $idMayor;
                    $stmt->execute([$torneoId, $rondaId, $idMenor, $idMayor, $llave]);
                }
            }
        } catch (Exception $e) {
            // Si la tabla no existe o no tiene llave, intentar sin llave (compatibilidad)
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT IGNORE INTO historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id) VALUES (?, ?, ?, ?)"
                );
                foreach ($mesasAsignadas as $mesa) {
                    if (count($mesa) < 4) continue;
                    $ids = array_column($mesa, 'id_usuario');
                    $a = (int)($ids[0] ?? 0);
                    $c = (int)($ids[1] ?? 0);
                    $b = (int)($ids[2] ?? 0);
                    $d = (int)($ids[3] ?? 0);
                    if ($a > 0 && $c > 0) {
                        $stmt->execute([$torneoId, $rondaId, min($a, $c), max($a, $c)]);
                    }
                    if ($b > 0 && $d > 0) {
                        $stmt->execute([$torneoId, $rondaId, min($b, $d), max($b, $d)]);
                    }
                }
            } catch (Exception $e2) {
                // Tabla puede no existir
            }
        }
    }

    /**
     * Aplica BYE: registra en partiresul con mesa=0 a los jugadores no asignados a mesa (máx. 3 por ronda)
     * y aplica la regla BYE a todos los registros mesa=0 de la ronda (partida ganada, 100% puntos, 50% efectividad).
     * Un solo registro por jugador por ronda; no se duplican registros.
     */
    private function aplicarBye($torneoId, $ronda, $jugadoresBye)
    {
        $jugadoresBye = array_slice($jugadoresBye, 0, self::MAX_JUGADORES_BYE); // norma: máximo 3 BYE por ronda
        if (empty($jugadoresBye)) {
            return;
        }
        $puntosTorneo = 200;
        try {
            $stmt = $this->pdo->prepare("SELECT COALESCE(NULLIF(puntos, 0), 200) AS puntos FROM tournaments WHERE id = ?");
            $stmt->execute([$torneoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['puntos'])) {
                $puntosTorneo = (int)$row['puntos'];
            }
            if ($puntosTorneo <= 0) {
                $puntosTorneo = 200;
            }
        } catch (Exception $e) {
            // mantener default 200
        }
        $efectividadBye = (int)round($puntosTorneo * 0.5); // 50%

        // 1) Crear filas mesa=0 para esta ronda (borrar previos y insertar jugadores BYE)
        $this->pdo->prepare("DELETE FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = 0")
            ->execute([$torneoId, $ronda]);
        $registrado_por = (class_exists('Auth') && method_exists('Auth', 'id')) ? ((int)Auth::id() ?: 1) : 1;
        $stmt = $this->pdo->prepare("
            INSERT INTO partiresul (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por)
            VALUES (?, ?, ?, 0, 1, NOW(), 0, ?)
        ");
        foreach ($jugadoresBye as $jugador) {
            $idUsuario = (int)($jugador['id_usuario'] ?? 0);
            if ($idUsuario <= 0) continue;
            $stmt->execute([$torneoId, $idUsuario, $ronda, $registrado_por]);
        }

        // 2) Aplicar la regla BYE a todos los registros de la ronda con mesa=0
        $this->pdo->prepare("
            UPDATE partiresul
            SET resultado1 = ?, resultado2 = 0, efectividad = ?, registrado = 1
            WHERE id_torneo = ? AND partida = ? AND mesa = 0
        ")->execute([$puntosTorneo, $efectividadBye, $torneoId, $ronda]);

        // Las estadísticas de inscritos se actualizan desde partiresul al generar la siguiente ronda
        // o al guardar resultados (actualizarEstadisticasInscritos). No se hace N actualizaciones aquí (evita N+1).
    }

    /**
     * Obtiene la última ronda generada
     */
    public function obtenerUltimaRonda($torneoId)
    {
        $sql = "SELECT MAX(partida) as ultima_ronda
                FROM partiresul
                WHERE id_torneo = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['ultima_ronda'] ?? 0);
    }

    /**
     * Obtiene la próxima ronda a generar
     */
    public function obtenerProximaRonda($torneoId)
    {
        return $this->obtenerUltimaRonda($torneoId) + 1;
    }

    /**
     * Verifica si todas las mesas de una ronda están completas
     */
    public function todasLasMesasCompletas($torneoId, $ronda)
    {
        $noReg = PartiresulEstatusSql::whereRegistradoNoCompleto();
        $sql = "SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND {$noReg}";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['mesas_incompletas'] ?? 0) === 0;
    }

    /**
     * Cuenta mesas incompletas
     */
    public function contarMesasIncompletas($torneoId, $ronda)
    {
        $noReg = PartiresulEstatusSql::whereRegistradoNoCompleto();
        $sql = "SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND {$noReg}";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['mesas_incompletas'] ?? 0);
    }

    /**
     * Indica si la ronda tiene resultados de MESAS registrados (mesa > 0, registrado=1).
     * La información estructural (asignaciones, BYE) no cuenta; solo cuando ya se guardaron
     * resultados de partidas en mesas.
     */
    public function rondaTieneResultadosEnMesas($torneoId, $ronda): bool
    {
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0 AND {$regOk}"
        );
        $stmt->execute([$torneoId, $ronda]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Elimina una ronda: borra partiresul y también el historial de parejas de esa ronda.
     * El controlador debe comprobar condiciones de seguridad si la ronda tiene resultados en mesas.
     */
    public function eliminarRonda($torneoId, $ronda)
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Eliminar historial de parejas de esta ronda (compañeros que jugaron juntos en esta ronda)
            try {
                $stmtH = $this->pdo->prepare("DELETE FROM historial_parejas WHERE torneo_id = ? AND ronda_id = ?");
                $stmtH->execute([$torneoId, $ronda]);
            } catch (Exception $e) {
                // Tabla historial_parejas puede no existir en instalaciones antiguas
                error_log("eliminarRonda historial_parejas: " . $e->getMessage());
            }

            // 2. Eliminar todos los registros de la ronda en partiresul (asignaciones y resultados)
            $stmt = $this->pdo->prepare("DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?");
            $stmt->execute([$torneoId, $ronda]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("eliminarRonda: " . $e->getMessage());
            return false;
        }
    }
}

