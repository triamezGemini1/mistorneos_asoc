<?php

declare(strict_types=1);

/**
 * GestionTorneosViewsData - Datos para vistas de cuadricula y hojas de anotacion.
 * Usado por torneo_gestion y tournament_admin (wrappers).
 */
class GestionTorneosViewsData
{
    public static function obtenerCuadricula(int $torneo_id, int $ronda): array
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        $sql = "SELECT pr.id_usuario, pr.mesa, pr.secuencia, u.nombre as nombre_completo, u.username
                FROM partiresul pr INNER JOIN usuarios u ON pr.id_usuario = u.id
                WHERE pr.id_torneo = ? AND pr.partida = ? ORDER BY pr.id_usuario ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$torneo_id, $ronda]);
        $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return [
            'titulo' => 'Cuadricula - Ronda ' . $ronda,
            'torneo' => $torneo,
            'numRonda' => $ronda,
            'asignaciones' => $asignaciones,
            'totalAsignaciones' => count($asignaciones),
        ];
    }

    public static function obtenerHojasAnotacion(int $torneo_id, int $ronda): array
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        $es_torneo_equipos = (int)($torneo['modalidad'] ?? 0) === 3;
        $es_torneo_parejas = (int)($torneo['modalidad'] ?? 0) === 2;
        $stmt = $pdo->prepare("SELECT id_usuario, codigo_equipo, posicion, ganados, perdidos, efectividad, puntos, sancion, tarjeta, numero FROM inscritos WHERE torneo_id = ? ORDER BY posicion ASC");
        $stmt->execute([$torneo_id]);
        $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $inscritosMap = [];
        foreach ($inscritos as $i) {
            $inscritosMap[$i['id_usuario']] = $i;
        }
        $equiposMap = [];
        $estadisticasEquipos = [];
        if ($es_torneo_equipos || $es_torneo_parejas) {
            $stmt = $pdo->prepare("SELECT codigo_equipo, nombre_equipo, id_club FROM equipos WHERE id_torneo = ? AND estatus = 0");
            $stmt->execute([$torneo_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $equiposMap[$e['codigo_equipo']] = $e;
            }
            $stmt = $pdo->prepare("SELECT codigo_equipo, posicion, puntos, ganados, perdidos, efectividad FROM equipos WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != '' ORDER BY posicion ASC");
            $stmt->execute([$torneo_id]);
            $nj = $es_torneo_equipos ? 4 : 2;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $estadisticasEquipos[$s['codigo_equipo']] = [
                    'posicion' => (int)$s['posicion'], 'clasiequi' => (int)$s['posicion'],
                    'puntos' => (int)$s['puntos'], 'ganados' => (int)$s['ganados'], 'perdidos' => (int)$s['perdidos'], 'efectividad' => (int)$s['efectividad'], 'total_jugadores' => $nj,
                ];
            }
        }
        $stmt = $pdo->prepare("SELECT pr.*, u.nombre as nombre_completo, i.codigo_equipo, c.nombre as nombre_club FROM partiresul pr INNER JOIN usuarios u ON pr.id_usuario = u.id LEFT JOIN inscritos i ON i.id_usuario = u.id AND i.torneo_id = pr.id_torneo LEFT JOIN clubes c ON i.id_club = c.id WHERE pr.id_torneo = ? AND pr.partida = ? ORDER BY pr.mesa ASC, pr.secuencia ASC");
        $stmt->execute([$torneo_id, $ronda]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mesas = [];
        foreach ($resultados as $row) {
            $numMesa = (int)$row['mesa'];
            if ($numMesa <= 0) continue;
            if (!isset($mesas[$numMesa])) {
                $mesas[$numMesa] = ['numero' => $numMesa, 'jugadores' => []];
            }
            $inscritoData = $inscritosMap[$row['id_usuario']] ?? ['posicion' => 0, 'ganados' => 0, 'perdidos' => 0, 'efectividad' => 0, 'puntos' => 0, 'sancion' => 0, 'tarjeta' => 0, 'codigo_equipo' => null, 'numero' => null];
            $row['tarjeta'] = (int)($inscritoData['tarjeta'] ?? 0);
            $numClub = isset($inscritoData['numero']) && $inscritoData['numero'] !== null && $inscritoData['numero'] !== '' ? (int)$inscritoData['numero'] : 0;
            $row['inscrito'] = ['posicion' => (int)$inscritoData['posicion'], 'ganados' => (int)$inscritoData['ganados'], 'perdidos' => (int)$inscritoData['perdidos'], 'efectividad' => (int)$inscritoData['efectividad'], 'puntos' => (int)$inscritoData['puntos'], 'sancion' => (int)$inscritoData['sancion'], 'tarjeta' => (int)$inscritoData['tarjeta'], 'numero' => $numClub];
            $codigoEquipo = $row['codigo_equipo'] ?? $inscritoData['codigo_equipo'] ?? null;
            if (($es_torneo_equipos || $es_torneo_parejas) && $codigoEquipo && isset($equiposMap[$codigoEquipo])) {
                $row['nombre_equipo'] = $equiposMap[$codigoEquipo]['nombre_equipo'];
                $row['codigo_equipo_display'] = $equiposMap[$codigoEquipo]['codigo_equipo'];
            }
            if (($es_torneo_equipos || $es_torneo_parejas) && $codigoEquipo && isset($estadisticasEquipos[$codigoEquipo])) {
                $row['estadisticas_equipo'] = $estadisticasEquipos[$codigoEquipo];
            }
            $mesas[$numMesa]['jugadores'][] = $row;
        }
        return [
            'torneo' => $torneo,
            'ronda' => $ronda,
            'mesas' => array_values($mesas),
            'es_torneo_equipos' => $es_torneo_equipos,
            'es_torneo_parejas' => $es_torneo_parejas,
        ];
    }

    /**
     * Una sola línea de estadísticas para la pareja en hoja de anotación (impresión).
     */
    public static function lineaEstadisticasParejaHoja(?array $j1, ?array $j2): string
    {
        $j2 = $j2 ?? [];
        $j1 = $j1 ?? [];
        $c1 = $j1['codigo_equipo'] ?? null;
        $c2 = $j2['codigo_equipo'] ?? null;
        $mismoCod = $c1 !== null && $c1 !== '' && (string)$c1 === (string)($c2 ?? '');
        if ($j1 !== [] && $mismoCod && !empty($j1['estadisticas_equipo'])) {
            $s = $j1['estadisticas_equipo'];
            return sprintf(
                'Pos: %s · G: %d · P: %d · Efect: %d · Pts: %d',
                (string)($s['clasiequi'] ?? $s['posicion'] ?? 0),
                (int)($s['ganados'] ?? 0),
                (int)($s['perdidos'] ?? 0),
                (int)($s['efectividad'] ?? 0),
                (int)($s['puntos'] ?? 0)
            );
        }
        $i1 = $j1['inscrito'] ?? [];
        $i2 = $j2['inscrito'] ?? [];
        $p1 = (int)($i1['posicion'] ?? 0);
        $p2 = (int)($i2['posicion'] ?? 0);
        $g = (int)($i1['ganados'] ?? 0) + (int)($i2['ganados'] ?? 0);
        $p = (int)($i1['perdidos'] ?? 0) + (int)($i2['perdidos'] ?? 0);
        $pts = (int)($i1['puntos'] ?? 0) + (int)($i2['puntos'] ?? 0);
        $e1 = (int)($i1['efectividad'] ?? 0);
        $e2 = (int)($i2['efectividad'] ?? 0);
        return sprintf('Pos: %d / %d · G: %d · P: %d · Efect: %d / %d · Pts: %d', $p1, $p2, $g, $p, $e1, $e2, $pts);
    }

    /**
     * HTML seguro (internamente escapado) para la línea «nombre del equipo» en parejas.
     */
    public static function htmlLineaNombreEquipoPareja(?array $jugador): string
    {
        if (!$jugador) {
            return htmlspecialchars('Sin equipo', ENT_QUOTES, 'UTF-8');
        }
        if (!empty($jugador['nombre_equipo'])) {
            $out = '';
            if (!empty($jugador['codigo_equipo_display'])) {
                $out .= '<span class="equipo-codigo-inline">' . htmlspecialchars((string)$jugador['codigo_equipo_display'], ENT_QUOTES, 'UTF-8') . '</span> — ';
            }
            $out .= htmlspecialchars((string)$jugador['nombre_equipo'], ENT_QUOTES, 'UTF-8');
            $t = (int)($jugador['tarjeta'] ?? 0);
            if ($t > 0) {
                $c = $t === 1 ? 'Amarilla' : ($t === 3 ? 'Roja' : ($t === 4 ? 'Negra' : ''));
                if ($c !== '') {
                    $out .= ' <span class="tarjeta-club">* ' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . ' *</span>';
                }
            }
            return $out;
        }
        $club = htmlspecialchars((string)($jugador['nombre_club'] ?? $jugador['club_nombre'] ?? 'Sin Club'), ENT_QUOTES, 'UTF-8');
        $t = (int)($jugador['tarjeta'] ?? 0);
        if ($t > 0) {
            $c = $t === 1 ? 'Amarilla' : ($t === 3 ? 'Roja' : ($t === 4 ? 'Negra' : ''));
            if ($c !== '') {
                $club .= ' <span class="tarjeta-club">* ' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . ' *</span>';
            }
        }
        return $club;
    }

    /**
     * Número interno en el club/torneo (`inscritos.numero`: consecutivo por club, pareja, etc.).
     * Si no hay valor, se muestra el id de usuario como respaldo (compatibilidad).
     */
    public static function numeroClubParaHoja(?array $jugador): string
    {
        if (!$jugador) {
            return 'N/A';
        }
        $n = (int)($jugador['inscrito']['numero'] ?? 0);
        if ($n > 0) {
            return (string) $n;
        }
        $id = (int)($jugador['id_usuario'] ?? 0);

        return $id > 0 ? (string) $id : 'N/A';
    }

    /** Prefijo opcional «Nº X · » para líneas de nombre en hoja (solo si `inscritos.numero` > 0). */
    public static function prefijoNumeroClubHoja(?array $jugador): string
    {
        if (!$jugador) {
            return '';
        }
        $n = (int)($jugador['inscrito']['numero'] ?? 0);

        return $n > 0 ? 'Nº ' . $n . ' · ' : '';
    }
}
