<?php
/**
 * Servicio de Asignación de Mesas para Torneos de Equipos de Dominó — V3
 *
 * Mesa siempre 4 jugadores. Datos del torneo indicado (id_torneo).
 *
 * Ronda 1 — Lista global ordenada por id de equipo (equipos.id) y id de usuario; 4 segmentos de
 * tamaño N = Total/4; mesa i = jugador i de cada segmento.
 *
 * Rondas 2+ — Equipos ordenados por G/E/P (ganados, efectividad, puntos). Lotes de 4 equipos;
 * residuo &lt; 4 se suma al último lote. Por lote: si hay 4 equipos, mesas por homólogo (1 vs 1…).
 * Si hay &gt;4 equipos, mesas de 4 integrantes mediante cruce por posición (asignación latina por
 * mesa × posición).
 *
 * V3.1 — Inconsistencias de género: no se excluye por sexo; se marca {@see alerta_genero} en filas
 * de jugador cuando el sexo del usuario no coincide con el género inferido del nombre del torneo.
 *
 * V3.2 — Homólogos rondas 2+: equipos por G/E/P (ganados, efectividad, puntos; menos perdidos);
 * jugadores 1–4 por rendimiento individual. Lotes 4–7 equipos. k=4: mesas puras homólogo (rangos
 * 1–4). k≥5: asignación latina en el lote (cruce de rangos) para mesas de 4 con equipos distintos
 * y sin dejar jugadores fuera. Restricción: mismo id_equipo no se repite en la misma mesa.
 *
 * V3.3 — Tras formar cada mesa (4 jugadores de equipos distintos), se ordenan asientos 1–4
 * (secuencia) por rendimiento individual (mismo criterio: ganados, efectividad, puntos, perdidos);
 * el de peor rendimiento queda en la última secuencia, sin mezclar equipos en la mesa.
 *
 * Sanciones / partiresul: los valores numéricos se normalizan en la app con {@see TorneoCampoNumerico}
 * y {@see SancionesHelper} (mismo motor que Individual). No usar el texto 'pendiente' como valor
 * de columna DOUBLE; el listado de estados de inscripción es independiente (InscritosHelper).
 */

if (!class_exists('DB', false)) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';

class MesaAsignacionEquiposService
{
    private $pdo;
    private const JUGADORES_POR_EQUIPO = 4;
    private const JUGADORES_POR_MESA = 4;

    /** @var array<int, string> género esperado M/F/'' por torneo_id (cache por request) */
    private $generoTorneoInferidoCache = [];

    /** @var bool */
    private static $partiresulColIdEquipoCacheListo = false;

    /** @var string|null id_equipo, equipo_codigo o null si no hay columna */
    private static $partiresulColIdEquipo = null;

    /**
     * Columna en partiresul donde guardar el id numérico de equipos.id, si existe en el esquema.
     */
    private function partiresulColumnaIdEquipo(): ?string
    {
        if (self::$partiresulColIdEquipoCacheListo) {
            return self::$partiresulColIdEquipo;
        }
        self::$partiresulColIdEquipoCacheListo = true;
        self::$partiresulColIdEquipo = null;
        try {
            $st = $this->pdo->query("SHOW COLUMNS FROM partiresul LIKE 'id_equipo'");
            if ($st && $st->rowCount() > 0) {
                self::$partiresulColIdEquipo = 'id_equipo';

                return 'id_equipo';
            }
            $st = $this->pdo->query("SHOW COLUMNS FROM partiresul LIKE 'equipo_codigo'");
            if ($st && $st->rowCount() > 0) {
                self::$partiresulColIdEquipo = 'equipo_codigo';

                return 'equipo_codigo';
            }
        } catch (Exception $e) {
            // sin columna
        }

        return null;
    }

    private function detectarGeneroTorneoPorNombreLocal(string $nombre): string
    {
        $txt = mb_strtolower($nombre, 'UTF-8');
        if (preg_match('/\b(femenino|fem|damas)\b/ui', $txt)) {
            return 'F';
        }
        if (preg_match('/\b(masculino|masc|caballeros)\b/ui', $txt)) {
            return 'M';
        }

        return '';
    }

    private function obtenerGeneroTorneoInferido(int $torneoId): string
    {
        if (isset($this->generoTorneoInferidoCache[$torneoId])) {
            return $this->generoTorneoInferidoCache[$torneoId];
        }
        $st = $this->pdo->prepare('SELECT nombre FROM tournaments WHERE id = ? LIMIT 1');
        $st->execute([$torneoId]);
        $n = (string) ($st->fetchColumn() ?: '');
        $g = $this->detectarGeneroTorneoPorNombreLocal($n);
        $this->generoTorneoInferidoCache[$torneoId] = $g;

        return $g;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadores
     * @return array<int, array<string, mixed>>
     */
    private function marcarAlertaGeneroEnJugadores(int $torneoId, array $jugadores): array
    {
        $gTorneo = $this->obtenerGeneroTorneoInferido($torneoId);
        if ($gTorneo !== 'M' && $gTorneo !== 'F') {
            foreach ($jugadores as &$j) {
                $j['alerta_genero'] = false;
            }
            unset($j);

            return $jugadores;
        }
        foreach ($jugadores as &$j) {
            $x = is_string($j['sexo'] ?? null) ? strtoupper(trim((string) $j['sexo'])) : (is_numeric($j['sexo'] ?? null) ? (string) $j['sexo'] : '');
            if ($x === '1' || $x === 'M') {
                $u = 'M';
            } elseif ($x === '2' || $x === 'F') {
                $u = 'F';
            } else {
                $j['alerta_genero'] = false;
                continue;
            }
            $j['alerta_genero'] = ($u !== $gTorneo);
        }
        unset($j);

        return $jugadores;
    }

    /**
     * Fragmento JOIN usuarios: solo filas coherentes con el equipo (torneo + club).
     *
     * Requiere alias eq para equipos e i para inscritos.
     */
    private function sqlJoinUsuarioInscritoEquipo(): string
    {
        return 'INNER JOIN usuarios u ON (
                u.id = i.id_usuario
                OR (
                    u.numfvd = i.id_usuario
                    AND EXISTS (
                        SELECT 1 FROM tournaments t
                        WHERE t.id = i.torneo_id AND t.club_responsable = 7
                    )
                    AND u.club_id = eq.id_club
                )
            )';
    }

    /**
     * El JOIN usuarios con OR (id vs numfvd) puede duplicar filas por inscrito;
     * LIMIT 4 entonces deja menos de 4 inscritos distintos y la cíclica repite equipo en mesa.
     * Se prioriza la fila donde u.id = i.id_usuario.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function deduplicarJugadoresPorInscrito(array $rows): array
    {
        usort($rows, static function ($a, $b) {
            $ca = ((int)($a['usuario_id'] ?? 0) === (int)($a['id_usuario'] ?? 0)) ? 0 : 1;
            $cb = ((int)($b['usuario_id'] ?? 0) === (int)($b['id_usuario'] ?? 0)) ? 0 : 1;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }

            return ((int)($a['id_inscrito'] ?? 0)) <=> ((int)($b['id_inscrito'] ?? 0));
        });
        $porInscrito = [];
        foreach ($rows as $r) {
            $iid = (int)($r['id_inscrito'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            if (!isset($porInscrito[$iid])) {
                $porInscrito[$iid] = $r;
            }
        }

        return array_values($porInscrito);
    }

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    /**
     * Genera la asignación de mesas para una ronda específica de torneo de equipos
     */
public function generarAsignacionRonda($torneo_id, $proxima_ronda, $total_rondas, $estrategia) 
{
    $pdo = DB::pdo();
    $numeroMesaActual = 1;

    // 1. Obtener todos los equipos ordenados por su clasificación oficial
    $queryEquipos = "SELECT codigo_equipo FROM equipos WHERE id_torneo = ? AND estatus = 0 ORDER BY posicion ASC";
    $stmtE = $pdo->prepare($queryEquipos);
    $stmtE->execute([$torneo_id]);
    $listaEquipos = $stmtE->fetchAll(PDO::FETCH_COLUMN);

    if (empty($listaEquipos)) return ['success' => false, 'message' => 'No hay equipos para asignar'];

    // 2. Agrupación en bloques de 4 con ajuste de remanentes
    $bloques = [];
    $tempBloque = [];
    foreach ($listaEquipos as $cod) {
        $tempBloque[] = $cod;
        if (count($tempBloque) === 4) {
            $bloques[] = $tempBloque;
            $tempBloque = [];
        }
    }

    // REGLA ESPECIAL: Si sobran equipos (1, 2 o 3), se fusionan con el último bloque de 4
    if (!empty($tempBloque)) {
        if (empty($bloques)) {
            $bloques[] = $tempBloque; // Caso: menos de 4 equipos en total
        } else {
            $ultimoIndice = count($bloques) - 1;
            $bloques[$ultimoIndice] = array_merge($bloques[$ultimoIndice], $tempBloque);
        }
    }

    // 3. Procesar cada bloque (normalmente de 4, o más si es el último)
    foreach ($bloques as $bloqueEquipos) {
        // En cada bloque, procesamos los 4 rangos de jugadores
        for ($rango = 1; $rango <= 4; $rango++) {
            
            // Obtenemos a los jugadores de los equipos del bloque para este rango específico
            // Los ordenamos por su desempeño individual para determinar quién es el 1, 2, 3 y 4 del grupo
            $placeholders = implode(',', array_fill(0, count($bloqueEquipos), '?'));
            $queryJugadores = "SELECT id_usuario FROM inscritos 
                              WHERE torneo_id = ? AND numero = ? AND codigo_equipo IN ($placeholders)
                              ORDER BY CAST(ganados AS SIGNED) DESC, CAST(efectividad AS SIGNED) DESC, CAST(puntos AS SIGNED) DESC";
            
            $stmtJ = $pdo->prepare($queryJugadores);
            $stmtJ->execute(array_merge([$torneo_id, $rango], $bloqueEquipos));
            $jugadoresGrupo = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

            // 4. Asignar mesas dentro del bloque de jugadores del mismo rango
            for ($i = 0; $i < count($jugadoresGrupo); $i += 4) {
                $j1 = $jugadoresGrupo[$i] ?? null;
                $j2 = $jugadoresGrupo[$i + 1] ?? null;
                $j3 = $jugadoresGrupo[$i + 2] ?? null;
                $j4 = $jugadoresGrupo[$i + 3] ?? null;

                if ($j1 && $j2 && $j3 && $j4) {
                    $this->registrarMesaCuadruple(
                        $torneo_id, $numeroMesaActual, $proxima_ronda, 
                        $j1['id_usuario'], $j2['id_usuario'], 
                        $j3['id_usuario'], $j4['id_usuario']
                    );
                    $numeroMesaActual++;
                } else if ($j1) {
                    // Si el grupo no es múltiplo de 4, manejamos los que sobran
                    $this->registrarMesaIncompleta($torneo_id, $numeroMesaActual, $proxima_ronda, array_filter([$j1, $j2, $j3, $j4]));
                }
            }
        }
    }

    return ['success' => true, 'message' => 'Ronda generada por Bloques de Equipos y Rangos'];
} 


private function registrarMesaCuadruple($torneo_id, $mesa, $ronda, $u1, $u2, $u3, $u4) 
{
    $pdo = DB::pdo();
    $sql = "INSERT INTO partiresul (id_torneo, id_usuario, partida, mesa, secuencia, registrado, fecha_partida) VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)";
    $stmt = $pdo->prepare($sql);
    
    // Pareja A (Compañeros: secuencia 1 y 2)
    $stmt->execute([$torneo_id, $u1, $ronda, $mesa, 1]);
    $stmt->execute([$torneo_id, $u2, $ronda, $mesa, 2]);
    
    // Pareja B (Compañeros: secuencia 3 y 4)
    $stmt->execute([$torneo_id, $u3, $ronda, $mesa, 3]);
    $stmt->execute([$torneo_id, $u4, $ronda, $mesa, 4]);

}

private function registrarMesaIncompleta($torneo_id, $mesa, $ronda, $jugadores) 
{
    $pdo = DB::pdo();
    
    // Si no hay 4 jugadores, los enviamos a la mesa 0 (BYE)
    // para que el sistema les asigne sus puntos de descanso automáticamente.
    foreach ($jugadores as $jugador) {
        $sql = "INSERT INTO partiresul (id_torneo, id_usuario, partida, mesa, secuencia, registrado, fecha_partida) 
                VALUES (?, ?, ?, 0, 1, 0, CURRENT_TIMESTAMP)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneo_id, $jugador['id_usuario'], $ronda]);
    }
}
    /**
     * Ronda 1: 4 segmentos de N jugadores; mesa i = [seg0[i], seg1[i], seg2[i], seg3[i]].
     *
     * @param array<int, array<string, mixed>> $jugadoresOrdenados Exactamente 4*N filas
     * @return array<int, array<int, array<string, mixed>>>|null
     */
    private function distribuirCuatroSegmentos(array $jugadoresOrdenados, int $N): ?array
    {
        $total = count($jugadoresOrdenados);
        if ($N <= 0 || $total !== 4 * $N) {
            return null;
        }
        $mesas = [];
        for ($i = 0; $i < $N; $i++) {
            $mesas[] = [
                $jugadoresOrdenados[$i],
                $jugadoresOrdenados[$N + $i],
                $jugadoresOrdenados[2 * $N + $i],
                $jugadoresOrdenados[3 * $N + $i],
            ];
        }

        return $mesas;
    }


    /**
     * Parte la lista de equipos ya ordenada en lotes de 4; el residuo &lt; 4 se fusiona con el último lote
     * (5, 6 o 7 equipos en el último lote cuando aplica).
     *
     * @param array<int, array<string, mixed>> $equiposOrdenados
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function partirEquiposEnLotes(array $equiposOrdenados): array
    {
        $equiposOrdenados = array_values($equiposOrdenados);
        $n = count($equiposOrdenados);
        if ($n === 0) {
            return [];
        }
        $lotes = [];
        $i = 0;
        while ($i < $n) {
            $remaining = $n - $i;
            if ($remaining <= 4) {
                $lotes[] = array_slice($equiposOrdenados, $i);
                break;
            }
            $remAfter = $remaining - 4;
            if ($remAfter > 0 && $remAfter < 4) {
                $lotes[] = array_slice($equiposOrdenados, $i);
                break;
            }
            $lotes[] = array_slice($equiposOrdenados, $i, 4);
            $i += 4;
        }

        return $lotes;
    }

    /**
     * Ordena los 4 jugadores de una mesa para asignación de secuencias: mejor rendimiento → secuencia 1,
     * peor → secuencia 4 (ganados, efectividad, puntos; menos perdidos).
     *
     * @param array<int, array<string, mixed>> $mesa
     * @return array<int, array<string, mixed>>
     */
    private function ordenarMesaPorRendimientoIndividual(array $mesa): array
    {
        $mesa = array_values($mesa);
        if (count($mesa) !== self::JUGADORES_POR_MESA) {
            return $mesa;
        }
        usort($mesa, function (array $a, array $b): int {
            return $this->compararRendimientoJugadorIndividual($a, $b);
        });

        return $mesa;
    }

    /**
     * Comparación para ordenar jugadores en la mesa: mejor (mayor mérito) primero.
     * Criterio alineado a equipos: ganados, efectividad, puntos DESC; perdidos ASC.
     */
    private function compararRendimientoJugadorIndividual(array $a, array $b): int
    {
        $ga = (int) ($a['ganados'] ?? 0);
        $gb = (int) ($b['ganados'] ?? 0);
        if ($ga !== $gb) {
            return $gb <=> $ga;
        }
        $ea = (float) ($a['efectividad'] ?? 0);
        $eb = (float) ($b['efectividad'] ?? 0);
        if ($ea != $eb) {
            return $eb <=> $ea;
        }
        $pa = (int) ($a['puntos'] ?? 0);
        $pb = (int) ($b['puntos'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        $pra = (int) ($a['perdidos'] ?? 0);
        $prb = (int) ($b['perdidos'] ?? 0);
        if ($pra !== $prb) {
            return $pra <=> $prb;
        }

        return ((int) ($a['id_usuario'] ?? 0)) <=> ((int) ($b['id_usuario'] ?? 0));
    }

    /**
     * V3.2 / V3.3 — Por lote: k=4 → 4 mesas (rangos homólogos: 1 vs 1, 2 vs 2…).
     * k≥5 → k mesas; en cada mesa m hay un jugador de rango p del equipo índice ((m-(p-1)) mod k),
     * cubriendo todos los jugadores del lote sin saltos (redistribución dentro del lote).
     * V3.3: en cada mesa, los 4 asientos se ordenan por rendimiento individual (no por orden del equipo en el lote).
     *
     * @param array<int, array<int, array<string, mixed>>> $lotes
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function construirMesasDesdeLotesHomologos(array $lotes): array
    {
        $mesas = [];
        foreach ($lotes as $lote) {
            $lote = array_values($lote);
            $k = count($lote);
            if ($k === 0) {
                continue;
            }
            if ($k === 4) {
                for ($pos = 1; $pos <= 4; $pos++) {
                    $fila = [];
                    foreach ($lote as $equipo) {
                        $j = $this->buscarJugadorPorPosicion($equipo, $pos);
                        if ($j) {
                            $fila[] = $j;
                        }
                    }
                    if (count($fila) === self::JUGADORES_POR_MESA) {
                        $mesas[] = $this->ordenarMesaPorRendimientoIndividual($fila);
                    }
                }
                continue;
            }
            if ($k >= 5) {
                for ($m = 0; $m < $k; $m++) {
                    $fila = [];
                    for ($p = 1; $p <= 4; $p++) {
                        $teamIndex = (($m - ($p - 1)) % $k + $k) % $k;
                        $equipo = $lote[$teamIndex];
                        $j = $this->buscarJugadorPorPosicion($equipo, $p);
                        if ($j) {
                            $fila[] = $j;
                        }
                    }
                    if (count($fila) === self::JUGADORES_POR_MESA) {
                        $mesas[] = $this->ordenarMesaPorRendimientoIndividual($fila);
                    }
                }
            }
        }

        return $mesas;
    }

    /**
     * Rondas 2+: lotes (residuo fusionado al último) y mesas por homólogo.
     */
    private function construirMesasRonda2DesdeEquiposOrdenados(array $equipos): array
    {
        $equipos = array_values($equipos);
        if ($equipos === []) {
            return [];
        }
        $lotes = $this->partirEquiposEnLotes($equipos);

        return $this->construirMesasDesdeLotesHomologos($lotes);
    }

    /**
     * V3.2: en cada mesa, 4 jugadores; codigo_equipo distintos; id_equipo distinto si está informado.
     */
    private function mesasTienenEquiposTodosDistintos(array $mesas): bool
    {
        foreach ($mesas as $mesa) {
            $n = count($mesa);
            if ($n !== self::JUGADORES_POR_MESA) {
                return false;
            }
            $codes = [];
            $idsEq = [];
            foreach ($mesa as $j) {
                $c = trim((string)($j['codigo_equipo'] ?? ''));
                if ($c === '') {
                    return false;
                }
                if (isset($codes[$c])) {
                    return false;
                }
                $codes[$c] = true;
                $idEq = (int) ($j['id_equipo'] ?? 0);
                if ($idEq > 0) {
                    if (isset($idsEq[$idEq])) {
                        return false;
                    }
                    $idsEq[$idEq] = true;
                }
            }
            if (count($codes) !== $n) {
                return false;
            }
        }

        return true;
    }

    /**
     * Todos los jugadores de los equipos clasificados deben aparecer en alguna mesa (sin saltos).
     *
     * @param array<int, array<string, mixed>> $equipos
     * @param array<int, array<int, array<string, mixed>>> $mesas
     */
    private function coberturaTotalJugadoresRonda2(array $equipos, array $mesas): bool
    {
        $esperados = 0;
        foreach ($equipos as $eq) {
            $esperados += count($eq['jugadores'] ?? []);
        }
        $asignados = 0;
        foreach ($mesas as $mesa) {
            $asignados += count($mesa);
        }

        return $esperados > 0 && $esperados === $asignados;
    }

    /**
     * RONDA 1: matriz de segmentos (torneo_id, club, código de equipo, integrantes; 4 segmentos × N → mesas).
     */
    private function generarRonda1($torneoId, $estrategia)
    {
        $equipos = $this->obtenerEquiposConJugadores($torneoId);

        if (empty($equipos)) {
            return [
                'success' => false,
                'message' => 'No hay equipos inscritos en el torneo',
            ];
        }

        foreach ($equipos as $equipo) {
            if (count($equipo['jugadores']) !== self::JUGADORES_POR_EQUIPO) {
                return [
                    'success' => false,
                    'message' => "El equipo '{$equipo['nombre_equipo']}' no tiene " . self::JUGADORES_POR_EQUIPO . ' jugadores completos',
                ];
            }
        }

        $totalEquipos = count($equipos);
        $N = $totalEquipos;
        $listaGlobal = $this->obtenerListaJugadoresRonda1Ordenada($torneoId);
        if ($listaGlobal === null) {
            return [
                'success' => false,
                'message' => 'No se pudo formar la lista global (torneo activo): revise que cada equipo tenga exactamente 4 inscritos y que usuario/club/equipo coincidan.',
            ];
        }
        $totalJugadores = count($listaGlobal);
        if ($totalJugadores !== $N * self::JUGADORES_POR_EQUIPO) {
            return [
                'success' => false,
                'message' => "Inconsistencia de plantilla: hay {$totalJugadores} jugadores activos; se esperaban " . ($N * self::JUGADORES_POR_EQUIPO) . " ({$N} equipos de 4).",
            ];
        }

        $mesasArray = $this->distribuirCuatroSegmentos($listaGlobal, $N);
        if ($mesasArray === null) {
            return [
                'success' => false,
                'message' => 'Error al formar la matriz de segmentos (4×N). Revise inscripciones duplicadas o equipos incompletos.',
            ];
        }

        // Verificar que cada mesa tenga exactamente 4 jugadores
        foreach ($mesasArray as $idx => $mesa) {
            if (count($mesa) !== self::JUGADORES_POR_MESA) {
                return [
                    'success' => false,
                    'message' => 'Error en asignación: La mesa ' . ($idx + 1) . ' tiene ' . count($mesa) . ' jugadores en lugar de ' . self::JUGADORES_POR_MESA . '.',
                ];
            }
        }

        if (!$this->mesasTienenEquiposTodosDistintos($mesasArray)) {
            return [
                'success' => false,
                'message' => 'La matriz secuencial dejó jugadores del mismo equipo en la misma mesa o datos inconsistentes. Revise inscripciones, codigo_equipo y duplicados.',
            ];
        }

        // Debug: listar mesas y jugadores asignados (ronda 1)
        error_log("ASIGNACION RONDA1: Total mesas=" . count($mesasArray));
        foreach ($mesasArray as $idx => $mesa) {
            $jugStr = [];
            foreach ($mesa as $jug) {
                $jugStr[] = sprintf(
                    "[u:%s eq:%s num:%s posEq:%s g:%s e:%s p:%s]",
                    $jug['id_usuario'] ?? '?',
                    $jug['codigo_equipo'] ?? '?',
                    $jug['numero'] ?? $jug['posicion_equipo'] ?? '?',
                    $jug['posicion_equipo'] ?? '?',
                    $jug['ganados'] ?? 0,
                    $jug['efectividad'] ?? 0,
                    $jug['puntos'] ?? 0
                );
            }
            error_log("  Mesa " . ($idx + 1) . ": " . implode(", ", $jugStr));
        }

        // Guardar asignación (validación + borrado ronda + insert)
        try {
            $this->guardarAsignacionRonda($torneoId, 1, $mesasArray);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => 'Primera ronda generada exitosamente (segmentos por id de equipo y usuario; mesas de 4).',
            'total_equipos' => $totalEquipos,
            'total_mesas' => count($mesasArray),
            'jugadores_bye' => 0,
            'mesas' => $mesasArray
        ];
    }

    /**
     * RONDAS 2+: equipos ordenados por resultados; jugadores por rendimiento dentro del equipo;
     * bloques de 4 equipos con homólogos (1…4); segmentos clasificación 1–4 vs 5+; fallback cíclico.
     */
    private function generarRonda2Plus($torneoId, $numRonda, $estrategia)
    {
        // Obtener equipos con clasificación y jugadores
        $equipos = $this->obtenerEquiposConJugadoresYClasificacion($torneoId, $numRonda - 1);
        
        if (empty($equipos)) {
            return [
                'success' => false,
                'message' => 'No hay equipos inscritos en el torneo'
            ];
        }

        // Log de control: contar jugadores por equipo (sin frenar la asignación)
        foreach ($equipos as $equipo) {
            $cnt = count($equipo['jugadores']);
            if ($cnt !== self::JUGADORES_POR_EQUIPO) {
                error_log("ADVERTENCIA: Equipo '{$equipo['nombre_equipo']}' tiene $cnt jugadores (esperado 4). Se continuará usando las consultas directas.");
            }
        }

        $mesas = $this->construirMesasRonda2DesdeEquiposOrdenados($equipos);

        if ($mesas === [] || !$this->coberturaTotalJugadoresRonda2($equipos, $mesas)) {
            return [
                'success' => false,
                'message' => 'No se pudo asignar todos los jugadores de los equipos clasificados a mesas (V3.2). Revise equipos e inscripciones.',
            ];
        }

        if (!$this->mesasTienenEquiposTodosDistintos($mesas)) {
            return [
                'success' => false,
                'message' => 'No se pudo generar mesas: en alguna mesa se repite el mismo equipo (id/código). Revise la asignación homóloga.',
            ];
        }

        try {
            $this->guardarAsignacionRonda($torneoId, $numRonda, $mesas);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => "Ronda {$numRonda} generada exitosamente (estrategia: {$estrategia})",
            'total_equipos' => count($equipos),
            'total_mesas' => count($mesas),
            'jugadores_bye' => 0,
            'grupos' => 0,
            'mesas' => $mesas
        ];
    }

    /**
     * Asignación secuencial estándar (Rondas 2+)
     * Agrupa jugadores de la misma posición (1,2,3,4) de 4 equipos en una mesa
     */
    private function asignarMesasSecuencial($grupos, $matrizCompañeros)
    {
        $mesas = [];
        $numeroMesa = 1;

        // Esta función ya no se usa en la lógica de rondas 2+ (se reemplazó por consultas directas).
        return [];
    }

    /**
     * Asignación intercalada 1,3 - 2,4
     * Mesa 1: Posición 1 y 3 de los equipos
     * Mesa 2: Posición 2 y 4 de los equipos
     * Y así alternando
     */
    private function asignarMesasIntercaladas13_24($grupos, $matrizCompañeros)
    {
        // No usada en la lógica actual; se mantiene firma por compatibilidad
        return [];
    }

    /**
     * Asignación intercalada 1,4 - 2,3
     * Mesa 1: Posición 1 y 4 de los equipos
     * Mesa 2: Posición 2 y 3 de los equipos
     */
    private function asignarMesasIntercaladas14_23($grupos, $matrizCompañeros)
    {
        // No usada en la lógica actual; se mantiene firma por compatibilidad
        return [];
    }

    /**
     * Asignación por rendimiento/clasificación
     * Clasifica jugadores primero dentro del equipo, luego en general
     * Distribuye según rendimiento pero respetando "1 jugador por equipo por mesa"
     */
    private function asignarMesasPorRendimiento($grupos, $matrizCompañeros)
    {
        // No usada en la lógica actual; se mantiene firma por compatibilidad
        return [];
    }

    /**
     * Construye mesas directamente desde inscritos según la condición en clasiequi
     * ($condicionClasiequi: e.g. '< 5' o '> 4'), ordenando por numero ASC, clasiequi ASC,
     * luego rendimiento individual.
     */
    private function crearMesasDesdeConsulta($torneoId, $condicionClasiequi) {
        $pdo = $this->pdo;
        $og = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $oe = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $iClas = InscritosHelper::sqlExprColumnaNumerica('i.clasiequi');
        $iNum = InscritosHelper::sqlExprColumnaNumerica('i.numero');
        $sql = "
            SELECT 
                i.id_usuario,
                i.codigo_equipo,
                i.numero,
                i.clasiequi,
                i.ganados,
                i.efectividad,
                i.puntos,
                i.id as id_inscrito
            FROM inscritos i
            WHERE i.torneo_id = ? 
              AND i.estatus != 4
              AND ({$iClas}) {$condicionClasiequi}
            ORDER BY 
              {$iNum} ASC,
              i.posicion ASC,
              {$iClas} ASC,
              $og DESC,
              $oe DESC,
              $op DESC,
              i.id_usuario ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mesas = [];
        if (!empty($jugadores)) {
            $chunks = array_chunk($jugadores, self::JUGADORES_POR_MESA);
            foreach ($chunks as $mesa) {
                if (!empty($mesa)) {
                    $mesas[] = $mesa;
                }
            }
        }

        // Debug
        error_log("ASIGNACION DIRECTA ($condicionClasiequi): mesas=" . count($mesas));
        foreach ($mesas as $idx => $mesa) {
            $jugStr = [];
            foreach ($mesa as $jug) {
                $jugStr[] = sprintf(
                    "[u:%s eq:%s clasiequi:%s num:%s g:%s e:%s p:%s]",
                    $jug['id_usuario'] ?? '?',
                    $jug['codigo_equipo'] ?? '?',
                    $jug['clasiequi'] ?? '?',
                    $jug['numero'] ?? '?',
                    $jug['ganados'] ?? 0,
                    $jug['efectividad'] ?? 0,
                    $jug['puntos'] ?? 0
                );
            }
            error_log("  Mesa " . ($idx + 1) . ": " . implode(", ", $jugStr));
        }

        return $mesas;
    }

    // ========================================================================
    // FUNCIONES AUXILIARES
    // ========================================================================

    /**
     * Ronda 1: todos los inscritos del torneo, orden estricto club_id, numfvd, id inscrito.
     * Devuelve null si no hay exactamente 4 filas por codigo_equipo tras deduplicar.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function obtenerListaJugadoresRonda1Ordenada(int $torneoId): ?array
    {
        $sql = "SELECT i.id as id_inscrito, i.torneo_id, i.id_usuario, i.codigo_equipo,
                       u.cedula, u.nombre, u.id as usuario_id, u.numfvd, u.sexo,
                       eq.id AS id_equipo, eq.id_club,
                       i.puntos, i.ganados, i.perdidos, i.efectividad, i.posicion, i.numero,
                       " . PartiresulEstatusSql::sqlSubqueryCountGffPorUsuarioTorneo() . " AS gff, i.sancion, i.tarjeta
                FROM inscritos i
                INNER JOIN equipos eq ON eq.id_torneo = i.torneo_id AND eq.codigo_equipo = i.codigo_equipo AND eq.estatus = 0
                " . $this->sqlJoinUsuarioInscritoEquipo() . "
                WHERE i.torneo_id = ? AND i.estatus != 4
                  AND i.codigo_equipo IS NOT NULL AND TRIM(i.codigo_equipo) != ''
                ORDER BY eq.id ASC, u.id ASC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId]);
            $jugadores = $this->deduplicarJugadoresPorInscrito($stmt->fetchAll(PDO::FETCH_ASSOC));
            $porEq = [];
            foreach ($jugadores as $row) {
                $c = trim((string)($row['codigo_equipo'] ?? ''));
                if ($c === '') {
                    return null;
                }
                $porEq[$c] = ($porEq[$c] ?? 0) + 1;
            }
            foreach ($porEq as $cnt) {
                if ($cnt !== self::JUGADORES_POR_EQUIPO) {
                    return null;
                }
            }

            return $this->marcarAlertaGeneroEnJugadores($torneoId, array_values($jugadores));
        } catch (Exception $e) {
            error_log('obtenerListaJugadoresRonda1Ordenada: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Todos los jugadores del torneo (equipos activos), orden global: club ASC, nombre ASC.
     *
     * @return array<int, array<string, mixed>>
     */
    private function obtenerTodosJugadoresOrdenClubNombre(int $torneoId): array
    {
        $sql = "SELECT i.id AS id_inscrito, i.id_usuario, i.codigo_equipo,
                       u.cedula, u.nombre, u.id AS usuario_id,
                       COALESCE(e.id_club, i.id_club, 0) AS id_club_ord,
                       i.puntos, i.ganados, i.perdidos, i.efectividad, i.posicion, i.numero
                FROM inscritos i
                INNER JOIN equipos e ON e.id_torneo = i.torneo_id AND e.codigo_equipo = i.codigo_equipo AND e.estatus = 0
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
                WHERE i.torneo_id = ? AND i.estatus != 4
                  AND i.codigo_equipo IS NOT NULL AND TRIM(i.codigo_equipo) != ''
                ORDER BY COALESCE(e.id_club, 0) ASC, u.nombre ASC, i.id ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId]);
            $jugadores = $this->deduplicarJugadoresPorInscrito($stmt->fetchAll(PDO::FETCH_ASSOC));
            usort($jugadores, static function ($a, $b) {
                $ca = (int)($a['id_club_ord'] ?? 0);
                $cb = (int)($b['id_club_ord'] ?? 0);
                if ($ca !== $cb) {
                    return $ca <=> $cb;
                }
                $na = mb_strtolower(trim((string)($a['nombre'] ?? '')), 'UTF-8');
                $nb = mb_strtolower(trim((string)($b['nombre'] ?? '')), 'UTF-8');
                if ($na !== $nb) {
                    return $na <=> $nb;
                }

                return ((int)($a['id_inscrito'] ?? 0)) <=> ((int)($b['id_inscrito'] ?? 0));
            });

            return array_values($jugadores);
        } catch (Exception $e) {
            error_log('obtenerTodosJugadoresOrdenClubNombre: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Equipos con jugadores para ronda 1: torneo_id, club (id_club), código de equipo, nombre de equipo.
     */
    private function obtenerEquiposConJugadores($torneoId)
    {
        $sql = "SELECT e.id, e.id_torneo, e.codigo_equipo, e.nombre_equipo, e.id_club, c.nombre AS nombre_club
                FROM equipos e
                LEFT JOIN clubes c ON e.id_club = c.id
                WHERE e.id_torneo = ? AND e.estatus = 0
                ORDER BY e.id_torneo ASC, COALESCE(e.id_club, 0) ASC, e.codigo_equipo ASC, e.nombre_equipo ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($equipos as &$equipo) {
            $equipo['jugadores'] = $this->obtenerJugadoresEquipo($torneoId, $equipo['codigo_equipo']);
        }
        unset($equipo);

        return $equipos;
    }

    /**
     * Equipos y clasificación para rondas 2+.
     * Orden G/E/P: ganados, efectividad, puntos; menos perdidos (P); desempate código.
     */
    private function obtenerEquiposConJugadoresYClasificacion($torneoId, $hastaRonda)
    {
        $eg = InscritosHelper::sqlExprColumnaNumerica('e.ganados');
        $ee = InscritosHelper::sqlExprColumnaNumerica('e.efectividad');
        $ep = InscritosHelper::sqlExprColumnaNumerica('e.puntos');
        $epe = InscritosHelper::sqlExprColumnaNumerica('e.perdidos');
        $sql = "SELECT e.id, e.codigo_equipo, e.nombre_equipo, e.id_club, c.nombre as nombre_club,
                       {$ep} AS puntos_equipo,
                       {$eg} AS ganados_equipo,
                       {$epe} AS perdidos_equipo,
                       {$ee} AS efectividad_equipo,
                       ({$eg} - {$epe}) AS diferencia_equipo
                FROM equipos e
                LEFT JOIN clubes c ON e.id_club = c.id
                WHERE e.id_torneo = ? AND e.estatus = 0
                ORDER BY
                    {$eg} DESC,
                    {$ee} DESC,
                    {$ep} DESC,
                    {$epe} ASC,
                    e.codigo_equipo ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rank = 1;
        foreach ($equipos as &$equipo) {
            $equipo['clasificacion_equipo'] = $rank;
            $rank++;
            $jugadores = $this->obtenerJugadoresEquipoConClasificacion($torneoId, $equipo['codigo_equipo']);
            $equipo['jugadores'] = $jugadores;
        }
        unset($equipo);

        return $equipos;
    }

    /**
     * Jugadores de un equipo para ronda 1: no se usa rendimiento (no hay resultados).
     * Orden fijo: número de tablero si está definido; si no, orden de inscripción (id inscrito).
     */
    private function obtenerJugadoresEquipo($torneoId, $codigoEquipo)
    {
        if (empty($codigoEquipo)) {
            return [];
        }

        $iNum = InscritosHelper::sqlExprColumnaNumerica('i.numero');
        $sql = "SELECT i.id as id_inscrito, i.torneo_id, i.id_usuario, i.codigo_equipo,
                       eq.id AS id_equipo,
                       u.cedula, u.nombre, u.id as usuario_id, u.sexo,
                       i.puntos, i.ganados, i.perdidos, i.efectividad, i.posicion, i.numero,
                       " . PartiresulEstatusSql::sqlSubqueryCountGffPorUsuarioTorneo() . " AS gff, i.sancion, i.tarjeta
                FROM inscritos i
                INNER JOIN equipos eq ON eq.id_torneo = i.torneo_id AND eq.codigo_equipo = i.codigo_equipo AND eq.estatus = 0
                " . $this->sqlJoinUsuarioInscritoEquipo() . "
                WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 4
                ORDER BY 
                    i.torneo_id ASC,
                    CASE WHEN u.id = i.id_usuario THEN 0 ELSE 1 END ASC,
                    CASE WHEN {$iNum} = 0 THEN 999 ELSE {$iNum} END ASC,
                    i.id ASC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId, $codigoEquipo]);
            $jugadores = $this->deduplicarJugadoresPorInscrito($stmt->fetchAll(PDO::FETCH_ASSOC));
            usort($jugadores, static function ($a, $b) {
                $ta = (int)($a['torneo_id'] ?? 0);
                $tb = (int)($b['torneo_id'] ?? 0);
                if ($ta !== $tb) {
                    return $ta <=> $tb;
                }
                $na = (int)($a['numero'] ?? 0);
                $nb = (int)($b['numero'] ?? 0);
                if ($na > 0 && $nb > 0) {
                    return $na <=> $nb;
                }
                if ($na > 0 xor $nb > 0) {
                    return $na > 0 ? -1 : 1;
                }

                return ((int)($a['id_inscrito'] ?? 0)) <=> ((int)($b['id_inscrito'] ?? 0));
            });
            $jugadores = array_slice($jugadores, 0, self::JUGADORES_POR_EQUIPO);
            
            $pos = 1;
            foreach ($jugadores as &$jugador) {
                $jugador['posicion_equipo'] = $pos;
                $pos++;
            }
            unset($jugador);
            
            return $this->marcarAlertaGeneroEnJugadores($torneoId, $jugadores);
        } catch (Exception $e) {
            error_log("Error en obtenerJugadoresEquipo: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Jugadores del equipo para rondas 2+ — homólogos 1…4 por puntos DESC, efectividad DESC.
     */
    private function obtenerJugadoresEquipoConClasificacion($torneoId, $codigoEquipo)
    {
        if (empty($codigoEquipo)) {
            return [];
        }
        
        $jg = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $je = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $jp = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
        $jpe = InscritosHelper::sqlExprColumnaNumerica('i.perdidos');
        $sql = "SELECT i.id as id_inscrito, i.id_usuario, i.codigo_equipo,
                       eq.id AS id_equipo,
                       u.cedula, u.nombre, u.id as usuario_id, u.sexo,
                       i.puntos, i.ganados, i.perdidos, i.efectividad, i.posicion, i.numero,
                       " . PartiresulEstatusSql::sqlSubqueryCountGffPorUsuarioTorneo() . " AS gff, i.sancion, i.tarjeta
                FROM inscritos i
                INNER JOIN equipos eq ON eq.id_torneo = i.torneo_id AND eq.codigo_equipo = i.codigo_equipo AND eq.estatus = 0
                " . $this->sqlJoinUsuarioInscritoEquipo() . "
                WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 4
                ORDER BY 
                    CASE WHEN u.id = i.id_usuario THEN 0 ELSE 1 END ASC,
                    $jg DESC,
                    $je DESC,
                    $jp DESC,
                    $jpe ASC,
                    i.id_usuario ASC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId, $codigoEquipo]);
            $jugadores = $this->deduplicarJugadoresPorInscrito($stmt->fetchAll(PDO::FETCH_ASSOC));
            usort($jugadores, static function ($a, $b) {
                $ga = (int) ($a['ganados'] ?? 0);
                $gb = (int) ($b['ganados'] ?? 0);
                if ($ga !== $gb) {
                    return $gb <=> $ga;
                }
                $ea = (float) ($a['efectividad'] ?? 0);
                $eb = (float) ($b['efectividad'] ?? 0);
                if ($ea != $eb) {
                    return $eb <=> $ea;
                }
                $pa = (int) ($a['puntos'] ?? 0);
                $pb = (int) ($b['puntos'] ?? 0);
                if ($pa !== $pb) {
                    return $pb <=> $pa;
                }
                $pra = (int) ($a['perdidos'] ?? 0);
                $prb = (int) ($b['perdidos'] ?? 0);
                if ($pra !== $prb) {
                    return $pra <=> $prb;
                }

                return ((int) ($a['id_usuario'] ?? 0)) <=> ((int) ($b['id_usuario'] ?? 0));
            });
            $jugadores = array_slice($jugadores, 0, self::JUGADORES_POR_EQUIPO);
            
            $pos = 1;
            foreach ($jugadores as &$jugador) {
                $jugador['posicion_equipo'] = $pos;
                $jugador['posicion_dentro_equipo'] = $pos;
                $pos++;
            }
            unset($jugador);
            
            return $this->marcarAlertaGeneroEnJugadores($torneoId, $jugadores);
        } catch (Exception $e) {
            error_log("Error en obtenerJugadoresEquipoConClasificacion: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene matriz de compañeros anteriores (rondas 1 a hastaRonda)
     */
    private function obtenerMatrizCompañerosEquipos($torneoId, $hastaRonda)
    {
        $matriz = [];
        
        for ($ronda = 1; $ronda <= $hastaRonda; $ronda++) {
            $sql = "SELECT pr1.id_usuario as id1, pr2.id_usuario as id2
                    FROM partiresul pr1
                    INNER JOIN partiresul pr2 ON pr1.id_torneo = pr2.id_torneo
                        AND pr1.partida = pr2.partida
                        AND pr1.mesa = pr2.mesa
                        AND pr1.id_usuario < pr2.id_usuario
                        AND ((pr1.secuencia IN (1,2) AND pr2.secuencia IN (1,2))
                             OR (pr1.secuencia IN (3,4) AND pr2.secuencia IN (3,4)))
                    WHERE pr1.id_torneo = ? AND pr1.partida = ? AND pr1.mesa > 0";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId, $ronda]);
            $parejas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($parejas as $pareja) {
                $id1 = $pareja['id1'];
                $id2 = $pareja['id2'];
                $matriz[$id1][$id2] = true;
                $matriz[$id2][$id1] = true;
            }
        }
        
        return $matriz;
    }

    /**
     * Busca jugador por posición en un equipo
     */
    private function buscarJugadorPorPosicion($equipo, $posicion)
    {
        foreach ($equipo['jugadores'] as $jugador) {
            if (($jugador['posicion_equipo'] ?? 0) == $posicion) {
                return $jugador;
            }
        }
        return null;
    }

    /**
     * Optimiza una mesa evitando compañeros anteriores
     */
    private function optimizarMesaEvitandoCompañeros($mesa, $matrizCompañeros)
    {
        if (empty($matrizCompañeros) || count($mesa) <= 2) {
            return $mesa;
        }

        // Intentar reordenar para minimizar compañeros anteriores
        $mejorMesa = $mesa;
        $menorConflictos = $this->contarConflictosMesa($mesa, $matrizCompañeros);

        // Probar algunas permutaciones
        $permutaciones = [
            [0, 1, 2, 3],
            [0, 2, 1, 3],
            [0, 3, 1, 2],
            [1, 0, 2, 3],
            [1, 2, 0, 3],
            [2, 0, 1, 3]
        ];

        foreach ($permutaciones as $perm) {
            if (count($mesa) < 4) break;
            
            $mesaPrueba = [
                $mesa[$perm[0] % count($mesa)],
                $mesa[$perm[1] % count($mesa)],
                $mesa[$perm[2] % count($mesa)],
                isset($perm[3]) ? $mesa[$perm[3] % count($mesa)] : null
            ];
            $mesaPrueba = array_filter($mesaPrueba);
            
            $conflictos = $this->contarConflictosMesa($mesaPrueba, $matrizCompañeros);
            if ($conflictos < $menorConflictos) {
                $menorConflictos = $conflictos;
                $mejorMesa = array_values($mesaPrueba);
            }
        }

        return $mejorMesa;
    }

    /**
     * Cuenta conflictos (compañeros anteriores) en una mesa
     */
    private function contarConflictosMesa($mesa, $matrizCompañeros)
    {
        $conflictos = 0;
        $idsMesa = array_column($mesa, 'id_usuario');
        
        // Verificar pareja A (posiciones 0-1)
        if (count($idsMesa) > 1) {
            $id1 = $idsMesa[0];
            $id2 = $idsMesa[1];
            if (isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1])) {
                $conflictos++;
            }
        }
        
        // Verificar pareja B (posiciones 2-3)
        if (count($idsMesa) > 3) {
            $id3 = $idsMesa[2];
            $id4 = $idsMesa[3];
            if (isset($matrizCompañeros[$id3][$id4]) || isset($matrizCompañeros[$id4][$id3])) {
                $conflictos++;
            }
        }
        
        return $conflictos;
    }

    /**
     * Optimiza todas las mesas evitando compañeros anteriores
     */
    private function optimizarTodasLasMesas($mesas, $matrizCompañeros)
    {
        foreach ($mesas as &$mesa) {
            if (count($mesa) >= 2) {
                $mesa = $this->optimizarMesaEvitandoCompañeros($mesa, $matrizCompañeros);
            }
        }
        unset($mesa);
        return $mesas;
    }

    /**
     * Intenta completar una mesa intercambiando jugadores para evitar compañeros
     */
    private function intentarCompletarMesa($mesa, $grupo, $posicionObjetivo, $matrizCompañeros)
    {
        if (count($mesa) >= self::JUGADORES_POR_MESA) {
            return $mesa;
        }

        $idsEnMesa = array_column($mesa, 'id_usuario');
        $faltantes = self::JUGADORES_POR_MESA - count($mesa);

        // Buscar jugadores de otros equipos del grupo que no estén en la mesa
        foreach ($grupo as $equipo) {
            if ($faltantes <= 0) break;
            
            $jugador = $this->buscarJugadorPorPosicion($equipo, $posicionObjetivo);
            if (!$jugador || in_array($jugador['id_usuario'], $idsEnMesa)) {
                continue;
            }

            // Verificar que no haya sido compañero antes
            $puedeAgregar = true;
            foreach ($mesa as $jEnMesa) {
                $id1 = $jugador['id_usuario'];
                $id2 = $jEnMesa['id_usuario'];
                if (isset($matrizCompañeros[$id1][$id2]) || isset($matrizCompañeros[$id2][$id1])) {
                    $puedeAgregar = false;
                    break;
                }
            }

            if ($puedeAgregar) {
                $mesa[] = $jugador;
                $idsEnMesa[] = $jugador['id_usuario'];
                $faltantes--;
            }
        }

        return $mesa;
    }

    /**
     * Nombre + id equipo coherentes con inscripción vigente antes de grabar partiresul.
     * V3.1: el género del usuario vs. torneo no bloquea el grabado (solo alerta en UI).
     */
    private function validarConsistenciaJugadoresParaGrabado(int $torneoId, array $mesas): ?string
    {
        $normNombre = static function ($s): string {
            return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)), 'UTF-8');
        };
        foreach ($mesas as $mesa) {
            if (count($mesa) !== self::JUGADORES_POR_MESA) {
                return 'Cada mesa debe tener exactamente 4 jugadores para grabar.';
            }
        }
        foreach ($mesas as $mesa) {
            foreach ($mesa as $jug) {
                $iid = (int) ($jug['id_inscrito'] ?? 0);
                if ($iid <= 0) {
                    return 'Falta id de inscripción en un jugador; no se puede validar.';
                }
                $sql = 'SELECT u.nombre AS nombre_u, eq.id AS id_equipo_db
                        FROM inscritos i
                        INNER JOIN usuarios u ON u.id = i.id_usuario
                        INNER JOIN equipos eq ON eq.id_torneo = i.torneo_id AND eq.codigo_equipo = i.codigo_equipo AND eq.estatus = 0
                        WHERE i.torneo_id = ? AND i.id = ? AND i.estatus != 4';
                $st = $this->pdo->prepare($sql);
                $st->execute([$torneoId, $iid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return 'Inscripción no válida para este torneo (datos de jugador/equipo).';
                }
                $idEqDb = (int) ($row['id_equipo_db'] ?? 0);
                $idEqJug = (int) ($jug['id_equipo'] ?? 0);
                if ($idEqDb > 0 && $idEqJug > 0 && $idEqDb !== $idEqJug) {
                    return 'Inconsistencia de equipo: revise código de equipo e id de equipo.';
                }
                if ($normNombre($row['nombre_u'] ?? '') !== $normNombre($jug['nombre'] ?? '')) {
                    return 'Inconsistencia de nombre: revise datos del usuario y la inscripción.';
                }
            }
        }

        return null;
    }

    /**
     * Guarda la asignación de mesas en la base de datos
     */
    private function guardarAsignacionRonda($torneoId, $ronda, $mesas)
    {
        $err = $this->validarConsistenciaJugadoresParaGrabado((int) $torneoId, $mesas);
        if ($err !== null) {
            throw new RuntimeException($err);
        }

        $this->pdo->beginTransaction();

        try {
            // Eliminar asignaciones previas de esta ronda
            $stmt = $this->pdo->prepare("DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?");
            $stmt->execute([$torneoId, $ronda]);

            $colEq = $this->partiresulColumnaIdEquipo();
            $numeroMesa = 1;
            foreach ($mesas as $mesa) {
                $secuencia = 1;
                foreach ($mesa as $jugador) {
                    $registrado_por = (class_exists('Auth') && method_exists('Auth', 'id')) ? ((int)Auth::id() ?: 1) : 1;
                    $idUsuarioPartida = (int)($jugador['usuario_id'] ?? $jugador['id_usuario'] ?? 0);
                    $idEq = (int)($jugador['id_equipo'] ?? 0);
                    if ($colEq === 'id_equipo') {
                        $sql = "INSERT INTO partiresul 
                                (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                                 resultado1, resultado2, efectividad, ff, id_equipo)
                                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0, ?, 0, 0, 0, 0, ?)";
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            $torneoId,
                            $idUsuarioPartida,
                            $ronda,
                            $numeroMesa,
                            $secuencia,
                            $registrado_por,
                            $idEq > 0 ? $idEq : null,
                        ]);
                    } elseif ($colEq === 'equipo_codigo') {
                        $codEqIns = trim((string) ($jugador['codigo_equipo'] ?? ''));
                        $sql = "INSERT INTO partiresul 
                                (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                                 resultado1, resultado2, efectividad, ff, equipo_codigo)
                                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0, ?, 0, 0, 0, 0, ?)";
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            $torneoId,
                            $idUsuarioPartida,
                            $ronda,
                            $numeroMesa,
                            $secuencia,
                            $registrado_por,
                            $codEqIns !== '' ? $codEqIns : null,
                        ]);
                    } else {
                        $sql = "INSERT INTO partiresul 
                                (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                                 resultado1, resultado2, efectividad, ff)
                                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0, ?, 0, 0, 0, 0)";
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            $torneoId,
                            $idUsuarioPartida,
                            $ronda,
                            $numeroMesa,
                            $secuencia,
                            $registrado_por,
                        ]);
                    }
                    $secuencia++;
                }
                $numeroMesa++;
            }

            $this->sincronizarMesasAsignacion((int) $torneoId, (int) $ronda, $mesas);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Copia la asignación a mesas_asignacion si la tabla existe (mismo tournament_id que partiresul).
     */
    private function tablaMesasAsignacionExiste(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $st = $this->pdo->query("SHOW TABLES LIKE 'mesas_asignacion'");
            $cache = $st && $st->rowCount() > 0;
        } catch (Exception $e) {
            $cache = false;
        }

        return $cache;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $mesas
     */
    private function sincronizarMesasAsignacion(int $tournamentId, int $ronda, array $mesas): void
    {
        if (!$this->tablaMesasAsignacionExiste()) {
            return;
        }
        $del = $this->pdo->prepare('DELETE FROM mesas_asignacion WHERE tournament_id = ? AND ronda = ?');
        $del->execute([$tournamentId, $ronda]);

        $ins = $this->pdo->prepare(
            'INSERT INTO mesas_asignacion (tournament_id, ronda, mesa, secuencia, id_usuario, codigo_equipo) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $numeroMesa = 1;
        foreach ($mesas as $mesa) {
            $secuencia = 1;
            foreach ($mesa as $jugador) {
                $uid = (int)($jugador['usuario_id'] ?? $jugador['id_usuario'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $cod = trim((string)($jugador['codigo_equipo'] ?? ''));
                $ins->execute([$tournamentId, $ronda, $numeroMesa, $secuencia, $uid, $cod !== '' ? $cod : null]);
                $secuencia++;
            }
            $numeroMesa++;
        }
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
}

