<?php
/**
 * Servicio de Asignación de Mesas para Torneos de Equipos de Dominó
 * Algoritmos matemáticos especializados para equipos de 4 jugadores
 * 
 * Características:
 * - Cada equipo = 4 jugadores = 1 mesa
 * - Ronda 1: Distribución secuencial cíclica
 * - Rondas 2+: Agrupación por bloques de 4 equipos con evasión de compañeros anteriores
 * - Soporte para asignaciones alternativas (intercaladas, por rendimiento)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';

class MesaAsignacionEquiposService
{
    private $pdo;
    private const JUGADORES_POR_EQUIPO = 4;
    private const JUGADORES_POR_MESA = 4;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    /**
     * Genera la asignación de mesas para una ronda específica de torneo de equipos
     */
    public function generarAsignacionRonda($torneoId, $numRonda, $totalRondas, $estrategia = 'secuencial')
    {
        if ($numRonda === 1) {
            return $this->generarRonda1($torneoId, $estrategia);
        } else {
            return $this->generarRonda2Plus($torneoId, $numRonda, $estrategia);
        }
    }

    /**
     * RONDA 1: Distribución secuencial cíclica
     * PASO 1: Clasificar equipos dentro del club (ordenar por club, luego por código)
     * PASO 2: Distribuir secuencialmente los jugadores de cada equipo en forma cíclica
     * 
     * Ejemplo con 9 mesas:
     * - Equipo 1: Jug1→Mesa1, Jug2→Mesa2, Jug3→Mesa3, Jug4→Mesa4
     * - Equipo 2: Jug1→Mesa5, Jug2→Mesa6, Jug3→Mesa7, Jug4→Mesa8
     * - Equipo 3: Jug1→Mesa9, Jug2→Mesa1 (reinicia), Jug3→Mesa2, Jug4→Mesa3
     * Y así sucesivamente hasta completar todas las mesas
     * 
     * La cantidad de equipos = cantidad de mesas (cada equipo = 1 mesa)
     * Los jugadores deben ser siempre múltiplos de 4
     */
    private function generarRonda1($torneoId, $estrategia)
    {
        // Obtener equipos con sus jugadores
        $equipos = $this->obtenerEquiposConJugadores($torneoId);
        
        if (empty($equipos)) {
            return [
                'success' => false,
                'message' => 'No hay equipos inscritos en el torneo'
            ];
        }

        // Verificar que todos los equipos tengan 4 jugadores completos
        foreach ($equipos as $equipo) {
            if (count($equipo['jugadores']) !== self::JUGADORES_POR_EQUIPO) {
                return [
                    'success' => false,
                    'message' => "El equipo '{$equipo['nombre_equipo']}' no tiene " . self::JUGADORES_POR_EQUIPO . " jugadores completos"
                ];
            }
        }

        // PASO 1: Clasificar equipos dentro del club
        // Los equipos ya vienen ordenados por club desde la consulta SQL
        // Pero por si acaso, reordenamos para asegurar el orden correcto:
        // Primero por club, luego por código del equipo dentro del club
        usort($equipos, function($a, $b) {
            // Primero por club (id_club)
            $clubA = (int)($a['id_club'] ?? 0);
            $clubB = (int)($b['id_club'] ?? 0);
            if ($clubA != $clubB) {
                return $clubA <=> $clubB;
            }
            // Si mismo club, ordenar por código del equipo
            $codigoA = $a['codigo_equipo'] ?? '';
            $codigoB = $b['codigo_equipo'] ?? '';
            return strcmp($codigoA, $codigoB);
        });

        // Verificar que el total de jugadores sea múltiplo de 4
        $totalJugadores = count($equipos) * self::JUGADORES_POR_EQUIPO;
        if ($totalJugadores % self::JUGADORES_POR_MESA !== 0) {
            return [
                'success' => false,
                'message' => "El total de jugadores ({$totalJugadores}) debe ser múltiplo de " . self::JUGADORES_POR_MESA . " para asignar correctamente las mesas"
            ];
        }

        // Calcular total de mesas (equivalente a número de equipos)
        $totalEquipos = count($equipos);
        $totalMesas = $totalEquipos;

        // PASO 2: Distribución secuencial cíclica
        // La cantidad de equipos = cantidad de mesas
        // Cada equipo distribuye sus 4 jugadores secuencialmente de forma cíclica:
        // Equipo 1: Jug1→Mesa1, Jug2→Mesa2, Jug3→Mesa3, Jug4→Mesa4
        // Equipo 2: Jug1→Mesa5, Jug2→Mesa6, Jug3→Mesa7, Jug4→Mesa8
        // Equipo 3: Jug1→Mesa9, Jug2→Mesa1 (reinicia), Jug3→Mesa2, Jug4→Mesa3
        // Y así hasta completar todos los equipos
        
        // Crear estructura de mesas vacías (indexadas desde 1)
        $mesas = [];
        for ($mesa = 1; $mesa <= $totalMesas; $mesa++) {
            $mesas[$mesa] = [];
        }

        // Contador global para la siguiente mesa disponible (empieza en 1)
        // Se incrementa secuencialmente y usa módulo para reiniciar cíclicamente
        $mesaActual = 1;
        
        // Recorrer cada equipo y asignar sus jugadores secuencialmente
        foreach ($equipos as $indiceEquipo => $equipo) {
            // Ordenar jugadores del equipo por posicion_equipo (1, 2, 3, 4)
            usort($equipo['jugadores'], function($a, $b) {
                return ($a['posicion_equipo'] ?? 999) <=> ($b['posicion_equipo'] ?? 999);
            });
            
            // Asignar cada jugador del equipo a una mesa secuencial
            foreach ($equipo['jugadores'] as $jugador) {
                // Calcular la mesa asignada usando módulo para ciclo
                // (mesaActual - 1) % totalMesas + 1 convierte cualquier número a rango 1..totalMesas
                // Ejemplo con 9 mesas: 1→1, 9→9, 10→1, 11→2, 12→3, 13→4, etc.
                $mesaAsignar = (($mesaActual - 1) % $totalMesas) + 1;
                
                // Asignar jugador a la mesa calculada
                $mesas[$mesaAsignar][] = $jugador;
                
                // Avanzar al siguiente número de mesa
                $mesaActual++;
            }
        }

        // Verificar que cada mesa tenga exactamente 4 jugadores
        foreach ($mesas as $numMesa => $mesa) {
            if (count($mesa) !== self::JUGADORES_POR_MESA) {
                return [
                    'success' => false,
                    'message' => "Error en asignación: La mesa {$numMesa} tiene " . count($mesa) . " jugadores en lugar de " . self::JUGADORES_POR_MESA . ". Esto indica que el total de jugadores no es múltiplo de 4 o hay un error en el algoritmo."
                ];
            }
        }

        // Convertir a array indexado numéricamente
        $mesasArray = array_values($mesas);

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

        // Guardar asignación
        $this->guardarAsignacionRonda($torneoId, 1, $mesasArray);

        return [
            'success' => true,
            'message' => 'Primera ronda generada exitosamente (distribución secuencial cíclica)',
            'total_equipos' => $totalEquipos,
            'total_mesas' => count($mesasArray),
            'jugadores_bye' => 0,
            'mesas' => $mesasArray
        ];
    }

    /**
     * RONDAS 2+: Agrupación por bloques de 4 equipos
     * Si hay fracción, el último grupo tendrá 4+equipos restantes
     * Dentro de cada grupo: jugadores posición 1 juntos, posición 2 juntos, etc.
     * Evita compañeros anteriores cuando es posible
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

        // Para rondas 2+, usar directamente las ventanas solicitadas:
        //  - Primer bloque: clasiequi < 5 (equipos 1..4) ordenado por numero ASC, clasiequi ASC
        //  - Resto: clasiequi > 4 con el mismo orden
        $mesasTop = $this->crearMesasDesdeConsulta($torneoId, '< 5');
        $mesasResto = $this->crearMesasDesdeConsulta($torneoId, '> 4');
        $mesas = array_merge($mesasTop, $mesasResto);

        // Guardar asignación
        $this->guardarAsignacionRonda($torneoId, $numRonda, $mesas);

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
        $ig = InscritosHelper::sqlExprColumnaNumerica('i.ganados');
        $ie = InscritosHelper::sqlExprColumnaNumerica('i.efectividad');
        $ip = InscritosHelper::sqlExprColumnaNumerica('i.puntos');
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
              AND i.clasiequi $condicionClasiequi
            ORDER BY 
              i.numero ASC,
              i.posicion ASC,
              i.clasiequi ASC,
              $ig DESC,
              $ie DESC,
              $ip DESC,
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
     * Obtiene equipos con sus jugadores ordenados
     * Primero por club, luego por código del equipo dentro del club
     */
    private function obtenerEquiposConJugadores($torneoId)
    {
        $sql = "SELECT e.id, e.codigo_equipo, e.nombre_equipo, e.id_club, c.nombre as nombre_club
                FROM equipos e
                LEFT JOIN clubes c ON e.id_club = c.id
                WHERE e.id_torneo = ? AND e.estatus = 0
                ORDER BY COALESCE(e.id_club, 0) ASC, e.codigo_equipo ASC, e.nombre_equipo ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agregar jugadores a cada equipo
        foreach ($equipos as &$equipo) {
            $jugadores = $this->obtenerJugadoresEquipo($torneoId, $equipo['codigo_equipo']);
            $equipo['jugadores'] = $jugadores;
        }
        unset($equipo);

        return $equipos;
    }

    /**
     * Obtiene equipos con jugadores y clasificación del torneo
     */
    private function obtenerEquiposConJugadoresYClasificacion($torneoId, $hastaRonda)
    {
        $sql = "SELECT e.id, e.codigo_equipo, e.nombre_equipo, e.id_club, c.nombre as nombre_club,
                       COALESCE(SUM(i.puntos), 0) as puntos_equipo,
                       COALESCE(SUM(i.ganados), 0) as ganados_equipo,
                       COALESCE(AVG(i.efectividad), 0) as efectividad_equipo
                FROM equipos e
                LEFT JOIN clubes c ON e.id_club = c.id
                LEFT JOIN inscritos i ON i.torneo_id = e.id_torneo 
                    AND i.codigo_equipo = e.codigo_equipo 
                    AND i.estatus != 4
                WHERE e.id_torneo = ? AND e.estatus = 0
                GROUP BY e.id, e.codigo_equipo, e.nombre_equipo, e.id_club, c.nombre
                ORDER BY puntos_equipo DESC, ganados_equipo DESC, efectividad_equipo DESC, e.codigo_equipo ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agregar jugadores con su clasificación individual
        foreach ($equipos as &$equipo) {
            $jugadores = $this->obtenerJugadoresEquipoConClasificacion($torneoId, $equipo['codigo_equipo']);
            $equipo['jugadores'] = $jugadores;
            $equipo['clasificacion_equipo'] = $this->calcularPosicionEquipo($equipos, $equipo);
        }
        unset($equipo);

        // Reordenar por clasificación calculada
        usort($equipos, function($a, $b) {
            return ($a['clasificacion_equipo'] ?? 999) <=> ($b['clasificacion_equipo'] ?? 999);
        });

        return $equipos;
    }

    /**
     * Obtiene jugadores de un equipo con su posición en el equipo
     */
    private function obtenerJugadoresEquipo($torneoId, $codigoEquipo)
    {
        if (empty($codigoEquipo)) {
            return [];
        }
        
        // Obtener jugadores directamente desde inscritos usando codigo_equipo
        // La tabla equipo_jugadores no existe en este sistema, usamos inscritos directamente
        // El campo codigo_equipo en inscritos identifica a qué equipo pertenece cada jugador
        $sql = "SELECT i.id as id_inscrito, i.id_usuario, i.codigo_equipo, 
                       u.cedula, u.nombre, u.id as usuario_id,
                       i.puntos, i.ganados, i.perdidos, i.efectividad, i.posicion,
                       (SELECT COUNT(*) FROM partiresul pr_gff WHERE pr_gff.id_usuario = i.id_usuario AND pr_gff.id_torneo = i.torneo_id AND pr_gff.ff = 1) AS gff, i.sancion, i.tarjeta
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 4
                ORDER BY 
                    CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                    i.ganados DESC,
                    i.efectividad DESC,
                    i.puntos DESC,
                    i.id_usuario ASC
                LIMIT 4";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId, $codigoEquipo]);
            $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Asignar posición dentro del equipo basada en el orden de clasificación individual
            $pos = 1;
            foreach ($jugadores as &$jugador) {
                $jugador['posicion_equipo'] = $pos;
                $pos++;
            }
            unset($jugador);
            
            return $jugadores;
        } catch (Exception $e) {
            error_log("Error en obtenerJugadoresEquipo: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene jugadores de un equipo con su clasificación individual
     */
    private function obtenerJugadoresEquipoConClasificacion($torneoId, $codigoEquipo)
    {
        if (empty($codigoEquipo)) {
            return [];
        }
        
        // Obtener jugadores directamente desde inscritos usando codigo_equipo
        // La tabla equipo_jugadores no existe en este sistema, usamos inscritos directamente
        // El campo codigo_equipo en inscritos identifica a qué equipo pertenece cada jugador
        $sql = "SELECT i.id as id_inscrito, i.id_usuario, i.codigo_equipo, 
                       u.cedula, u.nombre, u.id as usuario_id,
                       i.puntos, i.ganados, i.perdidos, i.efectividad, i.posicion,
                       (SELECT COUNT(*) FROM partiresul pr_gff WHERE pr_gff.id_usuario = i.id_usuario AND pr_gff.id_torneo = i.torneo_id AND pr_gff.ff = 1) AS gff, i.sancion, i.tarjeta
                FROM inscritos i
                INNER JOIN usuarios u ON i.id_usuario = u.id
                WHERE i.torneo_id = ? AND i.codigo_equipo = ? AND i.estatus != 4
                ORDER BY 
                    CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                    i.ganados DESC,
                    i.efectividad DESC,
                    i.puntos DESC,
                    i.id_usuario ASC
                LIMIT 4";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$torneoId, $codigoEquipo]);
            $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Asignar posición dentro del equipo basada en el orden de clasificación individual
            $pos = 1;
            foreach ($jugadores as &$jugador) {
                $jugador['posicion_equipo'] = $pos;
                $jugador['posicion_dentro_equipo'] = $pos;
                $pos++;
            }
            unset($jugador);
            
            return $jugadores;
        } catch (Exception $e) {
            error_log("Error en obtenerJugadoresEquipoConClasificacion: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Calcula la posición del equipo en la clasificación
     */
    private function calcularPosicionEquipo($todosEquipos, $equipo)
    {
        $posicion = 1;
        foreach ($todosEquipos as $e) {
            if ($e['id'] == $equipo['id']) {
                break;
            }
            // Comparar por puntos, ganados, efectividad
            if ($e['puntos_equipo'] > $equipo['puntos_equipo'] ||
                ($e['puntos_equipo'] == $equipo['puntos_equipo'] && $e['ganados_equipo'] > $equipo['ganados_equipo']) ||
                ($e['puntos_equipo'] == $equipo['puntos_equipo'] && $e['ganados_equipo'] == $equipo['ganados_equipo'] && 
                 $e['efectividad_equipo'] > $equipo['efectividad_equipo'])) {
                $posicion++;
            }
        }
        return $posicion;
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

    private function resolveRegistradoPorUsuarioId(): int
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $admin = $_SESSION['admin_user'] ?? null;
            if (is_array($admin) && !empty($admin['id'])) {
                return max(1, (int) $admin['id']);
            }
            $user = $_SESSION['user'] ?? null;
            if (is_array($user) && !empty($user['id'])) {
                return max(1, (int) $user['id']);
            }
        }
        if (class_exists('Auth') && method_exists('Auth', 'id')) {
            $id = (int) Auth::id();

            return $id > 0 ? $id : 1;
        }

        return 1;
    }

    /**
     * Guarda la asignación de mesas en la base de datos
     */
    private function guardarAsignacionRonda($torneoId, $ronda, $mesas)
    {
        $this->pdo->beginTransaction();

        try {
            // Eliminar asignaciones previas de esta ronda
            $stmt = $this->pdo->prepare("DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?");
            $stmt->execute([$torneoId, $ronda]);

            $registrado_por = $this->resolveRegistradoPorUsuarioId();
            $numeroMesa = 1;
            foreach ($mesas as $mesa) {
                $secuencia = 1;
                foreach ($mesa as $jugador) {
                    $sql = "INSERT INTO partiresul 
                            (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                             resultado1, resultado2, efectividad, ff)
                            VALUES (?, ?, ?, ?, ?, NOW(), 0, ?, 0, 0, 0, 0)";
                    
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([
                        $torneoId,
                        $jugador['id_usuario'],
                        $ronda,
                        $numeroMesa,
                        $secuencia,
                        $registrado_por
                    ]);
                    $secuencia++;
                }
                $numeroMesa++;
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
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
        $sql = "SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND (registrado = 0 OR registrado IS NULL)";
        
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
        $sql = "SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND (registrado = 0 OR registrado IS NULL)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['mesas_incompletas'] ?? 0);
    }
}

