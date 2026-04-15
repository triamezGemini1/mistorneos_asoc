<?php

/**
 * Servicio de Asignación de Mesas para Torneos de Equipos de Dominó — V4
 *
 * Tabla equipos (referencia): id, id_torneo, id_club, nombre_equipo, codigo_equipo, consecutivo_club,
 * estatus (0=activo, 1=inactivo), ganados, perdidos, efectividad, puntos, gff, posicion, sancion,
 * creado_por, fechas. Los jugadores se resuelven vía inscritos: mismo torneo y codigo_equipo que equipos.
 *
 * PRIORIDADES DE ASIGNACIÓN:
 * 1. Número dentro del equipo (Rango 1-4 según rendimiento individual desde partiresul: JG, efectividad, PF).
 * 2. Lista maestra del bloque: todos los rango 1 (por ranking de equipo), luego rango 2, etc.; mesas 1..N
 *    se llenan en orden: cada atleta va a la primera mesa con hueco sin otro jugador de su equipo.
 * 3. Integridad: Prohibido dos jugadores del mismo equipo en la misma mesa.
 * 4. Diversidad: Rotación de parejas (ejes) según el número de ronda.
 *
 * Clasificación equipos/jugadores (rondas 2+): una sola consulta SQL con CTE + ROW_NUMBER (MySQL 8+ / MariaDB 10.2+).
 */

require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/PartiresulEstatusSql.php';

class MesaAsignacionEquiposService
{
    private $pdo;
    private $proxima_ronda_cache;

    const JUGADORES_POR_MESA = 4;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Punto de entrada principal
     */
    public function generarAsignacionRonda($torneo_id, $proxima_ronda, $total_rondas, $estrategia)
    {
        $this->proxima_ronda_cache = $proxima_ronda;

        if ($proxima_ronda == 1) {
            return $this->generarRonda1($torneo_id, $estrategia);
        }

        // Obtener equipos ordenados por clasificación (G/E/P)
        $equipos = $this->obtenerEquiposConJugadoresYClasificacion($torneo_id, $proxima_ronda - 1);
        
        if (empty($equipos)) {
            return ['success' => false, 'message' => 'No hay equipos con datos para procesar.'];
        }

        // Partir en lotes (4 a 7 equipos para manejar remanentes correctamente)
        $lotes = $this->partirEquiposEnLotes($equipos);
        
        // Lista maestra por rango + ranking de equipo; llenado secuencial de mesas 1..N
        $mesasArray = $this->construirMesasDesdeLotesHomologos($lotes);

        if (empty($mesasArray)) {
            return ['success' => false, 'message' => 'Error al generar la distribución de mesas.'];
        }

        try {
            $this->guardarAsignacionRonda($torneo_id, $proxima_ronda, $mesasArray);
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true, 
            'message' => "Ronda {$proxima_ronda} generada exitosamente respetando rangos y rotando parejas.",
            'total_mesas' => count($mesasArray)
        ];
    }

    /**
     * Generación de Ronda 1 (Mantiene lógica original de segmentos)
     */
    private function generarRonda1($torneo_id, $estrategia)
    {
        // Miembros del equipo: tabla equipos + inscritos (codigo_equipo), sin tabla puente equipo_jugadores
        $sql = "SELECT u.id AS id_usuario, e.id AS id_equipo, e.nombre_equipo AS nombre_equipo
                FROM equipos e
                INNER JOIN inscritos i ON i.torneo_id = e.id_torneo
                    AND i.codigo_equipo = e.codigo_equipo
                    AND i.estatus != 4
                INNER JOIN usuarios u ON u.id = i.id_usuario
                WHERE e.id_torneo = ?
                  AND e.estatus = 0
                  AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
                ORDER BY e.id ASC, u.id ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneo_id]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($jugadores)) return ['success' => false, 'message' => 'No hay jugadores'];

        $totalJugadores = count($jugadores);
        $n = $totalJugadores / 4;
        $mesas = [];

        for ($i = 0; $i < $n; $i++) {
            $mesa = [
                $jugadores[$i],
                $jugadores[$i + $n],
                $jugadores[$i + 2 * $n],
                $jugadores[$i + 3 * $n]
            ];
            $mesas[] = $mesa;
        }

        $this->guardarAsignacionRonda($torneo_id, 1, $mesas);

        return [
            'success' => true,
            'message' => 'Primera ronda generada exitosamente (equipos)',
            'total_mesas' => count($mesas),
        ];
    }

    /**
     * MOTOR PRINCIPAL: por cada bloque, lista maestra (rango 1→4 agrupado; dentro de cada rango por ranking de equipo)
     * y asignación secuencial a mesas 1..N (N = k equipos), colocando cada atleta en la primera mesa con hueco sin conflicto de equipo.
     * Bloques de 4 equipos: 4 mesas. Bloque 5–7 equipos: N = k mesas.
     */
    private function construirMesasDesdeLotesHomologos(array $lotes): array
    {
        $mesas = [];
        $ronda = $this->proxima_ronda_cache;

        foreach ($lotes as $lote) {
            $lote = array_values($lote);
            $k = count($lote);
            $listaMaestra = $this->construirListaMaestraBloque($lote);
            $mesasBloque = $this->asignarAtletasMesasSecuencial($listaMaestra, $k);

            foreach ($mesasBloque as $jugadoresMesa) {
                usort($jugadoresMesa, function ($a, $b) {
                    return ((int)($a['posicion_equipo'] ?? 0)) <=> ((int)($b['posicion_equipo'] ?? 0));
                });
                if (count($jugadoresMesa) !== self::JUGADORES_POR_MESA) {
                    throw new RuntimeException('Mesa incompleta: se esperaban 4 jugadores por mesa.');
                }
                if ($this->mesaTieneEquipoDuplicado($jugadoresMesa)) {
                    throw new RuntimeException(
                        'Asignación inválida: dos jugadores del mismo equipo en una mesa (revisar rangos o plantillas).'
                    );
                }
                $rotados = $this->rotarParejas($jugadoresMesa, $ronda);
                if ($this->mesaTieneEquipoDuplicado($rotados)) {
                    throw new RuntimeException('Rotación de parejas generó duplicado de equipo en mesa.');
                }
                $mesas[] = $rotados;
            }
        }

        return $mesas;
    }

    /**
     * Aplana atletas del bloque: orden rango interno ascendente (1,1,…, 2,2,…, 3,…, 4,…);
     * dentro de cada rango, por ranking del equipo (mejor equipo primero).
     *
     * @param array<int, array<string, mixed>> $lote
     * @return array<int, array<string, mixed>>
     */
    private function construirListaMaestraBloque(array $lote): array
    {
        $lista = [];
        foreach ($lote as $equipo) {
            foreach ($equipo['jugadores'] as $jugador) {
                $lista[] = $jugador;
            }
        }
        usort($lista, function ($a, $b) {
            $ra = (int)($a['posicion_equipo'] ?? 0);
            $rb = (int)($b['posicion_equipo'] ?? 0);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((int)($a['ranking_equipo'] ?? 0)) <=> ((int)($b['ranking_equipo'] ?? 0));
        });

        return $lista;
    }

    /**
     * N mesas (índice 0..N-1); para cada atleta en orden de lista maestra, primera mesa con &lt; 4 plazas
     * que no contenga ya a su equipo.
     *
     * @param array<int, array<string, mixed>> $listaMaestra
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function asignarAtletasMesasSecuencial(array $listaMaestra, int $numMesas): array
    {
        $mesas = [];
        for ($i = 0; $i < $numMesas; $i++) {
            $mesas[$i] = [];
        }

        foreach ($listaMaestra as $atleta) {
            $idEquipo = (int)($atleta['id_equipo'] ?? 0);
            $colocado = false;
            for ($m = 0; $m < $numMesas; $m++) {
                if (count($mesas[$m]) >= self::JUGADORES_POR_MESA) {
                    continue;
                }
                if ($this->mesaContieneEquipoId($mesas[$m], $idEquipo)) {
                    continue;
                }
                $mesas[$m][] = $atleta;
                $colocado = true;
                break;
            }
            if (!$colocado) {
                throw new RuntimeException(
                    'No se pudo asignar un atleta: ninguna mesa admite su equipo sin duplicar (bloque demasiado restringido).'
                );
            }
        }

        return $mesas;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresMesa
     */
    private function mesaContieneEquipoId(array $jugadoresMesa, int $idEquipo): bool
    {
        if ($idEquipo <= 0) {
            return false;
        }
        foreach ($jugadoresMesa as $j) {
            if ((int)($j['id_equipo'] ?? 0) === $idEquipo) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $jugadoresMesa
     */
    private function mesaTieneEquipoDuplicado(array $jugadoresMesa): bool
    {
        $vistos = [];
        foreach ($jugadoresMesa as $j) {
            $e = (int)($j['id_equipo'] ?? 0);
            if ($e <= 0) {
                continue;
            }
            if (isset($vistos[$e])) {
                return true;
            }
            $vistos[$e] = true;
        }
        return false;
    }

    /**
     * Alterna quién es pareja de quién para evitar repeticiones de compañeros
     */
    private function rotarParejas(array $j, $ronda)
    {
        // j[0]=Rango1, j[1]=Rango2, j[2]=Rango3, j[3]=Rango4
        // En dominó usualmente Pareja 1: (Eje 1-3) y Pareja 2: (Eje 2-4) 
        // o según tu guardado (1-2 vs 3-4).
        
        if ($ronda % 2 == 0) {
            // Compañeros: (0 con 1) y (2 con 3)
            return [$j[0], $j[1], $j[2], $j[3]]; 
        } else {
            // Compañeros: (0 con 2) y (1 con 3) -> Cambió la pareja
            return [$j[0], $j[2], $j[1], $j[3]];
        }
    }

    /**
     * Cortes de 4 equipos; si el remanente es 5–7, un único bloque final (N mesas = k equipos en ese bloque).
     */
    private function partirEquiposEnLotes(array $equipos): array
    {
        $lotes = [];
        $total = count($equipos);
        $i = 0;

        while ($i < $total) {
            $restante = $total - $i;
            if ($restante >= 4 && $restante <= 7) {
                $lotes[] = array_slice($equipos, $i);
                break;
            }
            $lotes[] = array_slice($equipos, $i, 4);
            $i += 4;
        }
        return $lotes;
    }

    /**
     * Clasificación desde partiresul hasta la ronda $rondaActual (inclusive):
     * equipos por SUM(JG), SUM(efectividad), SUM(PF); jugadores por JG, efectividad, PF con ROW_NUMBER 1–4.
     * Misma lógica de partida ganada / registrado que {@see InscritosPartiresulHelper::obtenerEstadisticas}.
     *
     * @return array<int, array{id:int, nombre:string, jugadores:array<int, array<string, mixed>>}>
     */
    private function obtenerEquiposConJugadoresYClasificacion($torneo_id, $rondaActual)
    {
        $sql = $this->sqlClasificacionEquiposJugadoresPartiresul();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneo_id, $torneo_id, (int)$rondaActual]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        $equipos = [];
        foreach ($rows as $row) {
            $eid = (int)$row['id_equipo'];
            if (!isset($equipos[$eid])) {
                $equipos[$eid] = [
                    'id' => $eid,
                    'nombre' => $row['nombre'],
                    'jugadores' => [],
                    '_rn' => (int)$row['rn_equipo'],
                ];
            }
            $equipos[$eid]['jugadores'][] = [
                'id_usuario' => (int)$row['id_usuario'],
                'nombre_usuario' => $row['nombre_usuario'],
                'posicion_equipo' => (int)$row['posicion_equipo'],
                'ranking_equipo' => (int)$row['rn_equipo'],
                'id_equipo' => $eid,
                'nombre_equipo' => $row['nombre'],
            ];
        }

        uasort($equipos, function ($a, $b) {
            return $a['_rn'] <=> $b['_rn'];
        });
        foreach ($equipos as &$eq) {
            unset($eq['_rn']);
        }
        unset($eq);

        return array_values($equipos);
    }

    /**
     * Una única consulta: CTE + ROW_NUMBER() sobre agregados de partiresul (JG, efectividad, PF).
     */
    private function sqlClasificacionEquiposJugadoresPartiresul(): string
    {
        $r1 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $r2 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado2');
        $sn = InscritosHelper::sqlExprColumnaNumerica('pr.sancion');
        $reg = PartiresulEstatusSql::whereRegistradoUno('pr');
        $ff = PartiresulEstatusSql::whereFfCero('pr');
        $sumEf = InscritosHelper::sqlExprColumnaNumerica('pr.efectividad');
        $sumR1 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $br1 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado1');
        $br2 = InscritosHelper::sqlExprColumnaNumerica('pr.resultado2');

        $winNormal = "pr.mesa > 0 AND {$reg} AND {$ff} AND ((({$sn}) = 0 AND ({$r1}) > ({$r2})) OR (({$sn}) > 0 AND (({$r1}) - ({$sn})) > ({$r2})))";
        $winBye = "pr.mesa = 0 AND {$reg} AND (({$br1}) > ({$br2}))";
        $ganoFila = "pr.id IS NOT NULL AND (({$winNormal}) OR ({$winBye}))";

        return "
WITH eu_base AS (
  SELECT e.id AS id_equipo, e.nombre_equipo AS nombre, i.id_usuario
  FROM equipos e
  INNER JOIN inscritos i ON i.torneo_id = e.id_torneo
    AND i.codigo_equipo = e.codigo_equipo
    AND i.estatus != 4
  WHERE e.id_torneo = ? AND e.estatus = 0
    AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
),
jugador_agg AS (
  SELECT
    eu.id_equipo,
    eu.id_usuario,
    COALESCE(SUM(CASE WHEN {$ganoFila} THEN 1 ELSE 0 END), 0) AS jg,
    COALESCE(SUM(CASE WHEN pr.id IS NOT NULL AND {$reg} THEN {$sumEf} ELSE 0 END), 0) AS ef,
    COALESCE(SUM(CASE WHEN pr.id IS NOT NULL AND {$reg} THEN {$sumR1} ELSE 0 END), 0) AS pf
  FROM eu_base eu
  LEFT JOIN partiresul pr ON pr.id_usuario = eu.id_usuario
    AND pr.id_torneo = ?
    AND pr.partida <= ?
  GROUP BY eu.id_equipo, eu.id_usuario
),
jugador_ranked AS (
  SELECT
    ja.id_equipo,
    ja.id_usuario,
    u.nombre AS nombre_usuario,
    ROW_NUMBER() OVER (
      PARTITION BY ja.id_equipo
      ORDER BY ja.jg DESC, ja.ef DESC, ja.pf DESC, ja.id_usuario ASC
    ) AS posicion_equipo
  FROM jugador_agg ja
  INNER JOIN usuarios u ON u.id = ja.id_usuario
),
equipo_agg AS (
  SELECT
    id_equipo,
    SUM(jg) AS jg_eq,
    SUM(ef) AS ef_eq,
    SUM(pf) AS pf_eq
  FROM jugador_agg
  GROUP BY id_equipo
),
equipo_ranked AS (
  SELECT
    id_equipo,
    ROW_NUMBER() OVER (
      ORDER BY jg_eq DESC, ef_eq DESC, pf_eq DESC, id_equipo ASC
    ) AS rn_equipo
  FROM equipo_agg
)
SELECT
  jr.id_equipo,
  e.nombre_equipo AS nombre,
  jr.id_usuario,
  jr.nombre_usuario,
  jr.posicion_equipo,
  er.rn_equipo
FROM jugador_ranked jr
INNER JOIN equipo_ranked er ON er.id_equipo = jr.id_equipo
INNER JOIN equipos e ON e.id = jr.id_equipo
WHERE jr.posicion_equipo <= 4
ORDER BY er.rn_equipo ASC, jr.posicion_equipo ASC
";
    }

    /**
     * Usuario que registra la asignación (misma lógica que MesaRepository / legacy).
     */
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
        if (class_exists('Auth', false) && method_exists('Auth', 'id')) {
            $id = (int) Auth::id();
            return $id > 0 ? $id : 1;
        }
        return 1;
    }

    /**
     * Esquema estándar partiresul (sin id_equipo / fecha_registro; igual que MesaRepositoryPersistTrait).
     */
    private function guardarAsignacionRonda($torneo_id, $ronda, $mesas)
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?");
            $stmt->execute([$torneo_id, $ronda]);

            $registradoPor = $this->resolveRegistradoPorUsuarioId();
            $sql = "INSERT INTO partiresul
                    (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                     resultado1, resultado2, efectividad, ff)
                    VALUES (?, ?, ?, ?, ?, NOW(), 0, ?, 0, 0, 0, 0)";
            $stmt = $this->pdo->prepare($sql);

            foreach ($mesas as $indexMesa => $jugadores) {
                $numMesa = $indexMesa + 1;
                foreach ($jugadores as $indexJugador => $j) {
                    $secuencia = $indexJugador + 1;
                    $stmt->execute([
                        $torneo_id,
                        (int) ($j['id_usuario'] ?? 0),
                        $ronda,
                        $numMesa,
                        $secuencia,
                        $registradoPor,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function obtenerUltimaRonda($torneoId)
    {
        $sql = "SELECT MAX(partida) as ultima_ronda FROM partiresul WHERE id_torneo = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['ultima_ronda'] ?? 0);
    }
}