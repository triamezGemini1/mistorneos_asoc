<?php
/**
 * Datos agregados para reportes PDF/Excel del módulo de resultados.
 *
 * GFF (ganadas por forfait): mismo criterio que resultados_detalle.php y
 * ResultadosPublicHelper::getPosiciones — COUNT en partiresul con ff = 1 por jugador/torneo.
 */
declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';

final class ResultadosReporteData
{
    /** Normaliza M/F desde GET u otros valores. */
    public static function normalizarGeneroQuery(?string $g): ?string
    {
        if ($g === null || $g === '') {
            return null;
        }
        $x = strtoupper(trim($g));
        if ($x === 'F' || str_starts_with($x, 'F')) {
            return 'F';
        }
        if ($x === 'M' || str_starts_with($x, 'M') || $x === 'H') {
            return 'M';
        }

        return null;
    }

    /**
     * M o F elegido para el reporte (p. ej. GET genero). No usa datos del torneo (p. ej. tournaments.tipo):
     * la pertenencia de cada fila al grupo es por el sexo del participante en esa inscripción
     * (filas construidas desde inscritos + usuarios / partiresul), no por la configuración del torneo.
     */
    public static function generoFiltroDesdeParametro(?string $generoGet): string
    {
        $n = self::normalizarGeneroQuery($generoGet);

        return $n ?? 'M';
    }

    /**
     * Texto para metadatos de impresión/PDF: M/F solo si el parámetro es explícito; si no, listado único.
     */
    public static function etiquetaFiltroClasificacionReporte(?string $generoGet): string
    {
        $n = self::normalizarGeneroQuery($generoGet);
        if ($n === null) {
            return 'Todos los participantes';
        }

        return $n === 'F' ? 'Femenino' : 'Masculino';
    }

    /** @deprecated Usar {@see generoFiltroDesdeParametro}; el parámetro torneo ya no se usa */
    public static function generoFiltroEfectivo(array $torneo, ?string $generoGet): string
    {
        return self::generoFiltroDesdeParametro($generoGet);
    }

    /**
     * M o F del participante en esa inscripción: si existen columnas en la fila procedentes de `inscritos`
     * (p. ej. snapshot), tienen prioridad; si no, `usuarios.sexo` en el JOIN habitual inscritos → usuarios.
     */
    public static function sexoUsuarioFila(array $row): string
    {
        foreach (['sexo_inscripcion', 'sexo_inscrito', 'genero_inscripcion'] as $k) {
            if (! array_key_exists($k, $row)) {
                continue;
            }
            $raw = trim((string) ($row[$k] ?? ''));
            if ($raw === '') {
                continue;
            }
            $m = self::sexoParticipanteDesdeTexto($raw);
            if ($m !== '') {
                return $m;
            }
        }

        return self::sexoParticipanteDesdeTexto((string) ($row['sexo'] ?? ''));
    }

    private static function sexoParticipanteDesdeTexto(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '' || $s === 'O') {
            return '';
        }
        if (isset($s[0]) && $s[0] === 'F') {
            return 'F';
        }
        if ($s === 'H' || (isset($s[0]) && $s[0] === 'M')) {
            return 'M';
        }
        if ($s === '2') {
            return 'F';
        }
        if ($s === '1') {
            return 'M';
        }

        return '';
    }

    /**
     * Deja solo jugadores/parejas homogéneas del género indicado (en parejas mixtas no entra ningún integrante).
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function filtrarFilasClasificacionPorGenero(array $filas, string $generoObjetivo, int $modalidad): array
    {
        $g = strtoupper($generoObjetivo) === 'F' ? 'F' : 'M';
        if (! in_array($modalidad, [2, 4], true)) {
            $out = [];
            foreach ($filas as $r) {
                if (self::sexoUsuarioFila($r) === $g) {
                    $out[] = $r;
                }
            }

            return $out;
        }
        $grupos = [];
        foreach ($filas as $r) {
            $cod = trim((string) ($r['codigo_equipo'] ?? ''));
            if ($cod === '' || $cod === '000-000') {
                if (self::sexoUsuarioFila($r) === $g) {
                    $grupos['__singles'][] = $r;
                }
                continue;
            }
            if (! isset($grupos[$cod])) {
                $grupos[$cod] = [];
            }
            $grupos[$cod][] = $r;
        }
        $out = $grupos['__singles'] ?? [];
        unset($grupos['__singles']);
        foreach ($grupos as $cod => $rows) {
            $sexos = [];
            foreach ($rows as $r) {
                $sx = self::sexoUsuarioFila($r);
                if ($sx !== '') {
                    $sexos[$sx] = true;
                }
            }
            if (count($sexos) !== 1) {
                continue;
            }
            $only = array_key_first($sexos);
            if ($only === $g) {
                foreach ($rows as $r) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /**
     * Orden oficial de clasificación: posición ascendente (1,2,3...); no prioriza estatus.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function ordenarFilasComoPosicionesTorneo(array $filas): array
    {
        usort($filas, static function (array $a, array $b): int {
            $pa = (int) ($a['posicion'] ?? 0);
            $pb = (int) ($b['posicion'] ?? 0);
            if ($pa === 0) {
                $pa = 999999;
            }
            if ($pb === 0) {
                $pb = 999999;
            }
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $ga = (int) ($a['ganados'] ?? 0);
            $gb = (int) ($b['ganados'] ?? 0);
            if ($ga !== $gb) {
                return $gb <=> $ga;
            }
            $efa = (int) ($a['efectividad'] ?? 0);
            $efb = (int) ($b['efectividad'] ?? 0);
            if ($efa !== $efb) {
                return $efb <=> $efa;
            }
            $pta = (int) ($a['puntos'] ?? 0);
            $ptb = (int) ($b['puntos'] ?? 0);
            if ($pta !== $ptb) {
                return $ptb <=> $pta;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $filas;
    }

    /**
     * No modifica posiciones: la clasificación del torneo ya guardó inscritos.posicion (y clasiequi).
     * Los reportes solo muestran ese valor; filtrar por género no renumera 1…N.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    public static function reenumerarPosicionMostrada(array $filas): array
    {
        return $filas;
    }

    /** Subconsulta COUNT GFF (forfait) por jugador/torneo; segura si ff no es numérico. */
    public static function sqlGffSubquery(): string
    {
        return PartiresulEstatusSql::sqlSubqueryCountGffPorUsuarioTorneo();
    }

    /**
     * Modalidad parejas (2) o parejas fijas (4): una fila por codigo_equipo con ambos nombres en nombre_completo.
     *
     * @param list<array<string, mixed>> $filas Filas ya ordenadas (p. ej. por posición).
     * @return list<array<string, mixed>>
     */
    public static function colapsarFilasPorPareja(array $filas, PDO $pdo, int $torneoId): array
    {
        $stmtParejas = $pdo->prepare("
            SELECT i.codigo_equipo, u.nombre AS nombre_completo
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            WHERE i.torneo_id = ?
              AND i.codigo_equipo IS NOT NULL
              AND TRIM(i.codigo_equipo) != ''
              AND i.codigo_equipo != '000-000'
            ORDER BY i.codigo_equipo ASC, u.nombre ASC
        ");
        $stmtParejas->execute([$torneoId]);
        $nombresPorCodigo = [];
        foreach ($stmtParejas->fetchAll(PDO::FETCH_ASSOC) as $filaPareja) {
            $codigo = trim((string)($filaPareja['codigo_equipo'] ?? ''));
            $nombre = trim((string)($filaPareja['nombre_completo'] ?? ''));
            if ($codigo === '' || $nombre === '') {
                continue;
            }
            if (!isset($nombresPorCodigo[$codigo])) {
                $nombresPorCodigo[$codigo] = [];
            }
            $nombresPorCodigo[$codigo][] = $nombre;
        }

        $vistos = [];
        $salida = [];
        foreach ($filas as $p) {
            $codigo = trim((string)($p['codigo_equipo'] ?? ''));
            if ($codigo === '' || $codigo === '000-000') {
                $salida[] = $p;
                continue;
            }
            if (isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;
            $nombres = array_values(array_unique($nombresPorCodigo[$codigo] ?? []));
            $parejaDisplay = implode(' / ', array_slice($nombres, 0, 2));
            if ($parejaDisplay !== '') {
                $p['nombre_completo'] = $parejaDisplay;
            }
            $p['id_usuario'] = $codigo;
            $salida[] = $p;
        }

        return $salida;
    }

    /**
     * @param string|null $generoPreferencia Si M o F (normalizado), filtra la clasificación; si null o vacío, todos los inscritos activos.
     * @return array{torneo: array, participantes: array, resumen_clubes: array, equipos: array, rondas: array}
     */
    public static function cargar(PDO $pdo, int $torneoId, array $torneo, ?string $generoPreferencia = null): array
    {
        $gffSql = self::sqlGffSubquery();
        $wRegPr = PartiresulEstatusSql::whereRegistradoUno('pr');
        $sqlParticipantes = "
            SELECT
                i.id,
                i.id_usuario,
                i.torneo_id,
                i.codigo_equipo,
                i.posicion,
                i.ganados,
                i.perdidos,
                i.efectividad,
                i.puntos,
                i.ptosrnk,
                {$gffSql} AS gff,
                i.sancion,
                i.tarjeta,
                (SELECT COUNT(*) FROM partiresul pr WHERE pr.id_usuario = i.id_usuario AND pr.id_torneo = i.torneo_id
                    AND {$wRegPr} AND pr.mesa = 0 AND pr.resultado1 > pr.resultado2) AS partidas_bye,
                u.nombre AS nombre_completo,
                u.username,
                u.cedula,
                u.sexo,
                c.nombre AS club_nombre,
                c.id AS club_id,
                e.nombre_equipo
            FROM inscritos i
            INNER JOIN usuarios u ON i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            LEFT JOIN equipos e ON i.torneo_id = e.id_torneo AND i.codigo_equipo = e.codigo_equipo AND e.estatus = 0
            WHERE i.torneo_id = ?
              AND i.estatus != 'retirado'
            ORDER BY
                CASE WHEN i.posicion = 0 OR i.posicion IS NULL THEN 9999 ELSE i.posicion END ASC,
                i.ganados DESC,
                i.efectividad DESC,
                i.puntos DESC
        ";
        $stmt = $pdo->prepare($sqlParticipantes);
        $stmt->execute([$torneoId]);
        $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($participantes as &$p) {
            if (empty($p['nombre_equipo']) && !empty($p['codigo_equipo'])) {
                $p['nombre_equipo'] = 'Equipo ' . $p['codigo_equipo'];
            }
        }
        unset($p);

        $modalidad = (int) ($torneo['modalidad'] ?? 0);
        $genNorm = self::normalizarGeneroQuery($generoPreferencia);
        if ($genNorm !== null) {
            $participantes = self::filtrarFilasClasificacionPorGenero($participantes, $genNorm, $modalidad);
        }
        $participantes = self::ordenarFilasComoPosicionesTorneo($participantes);
        if (in_array($modalidad, [2, 4], true)) {
            $participantes = self::colapsarFilasPorPareja($participantes, $pdo, $torneoId);
        }

        $sqlClubes = "
            SELECT
                COALESCE(c.id, 0) AS club_id,
                COALESCE(c.nombre, 'Sin club') AS club_nombre,
                COUNT(*) AS jugadores,
                SUM(i.ganados) AS sum_ganados,
                SUM(i.perdidos) AS sum_perdidos,
                AVG(i.efectividad) AS avg_efectividad,
                SUM(i.puntos) AS sum_puntos
            FROM inscritos i
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE i.torneo_id = ? AND i.estatus != 'retirado'
            GROUP BY COALESCE(c.id, 0), COALESCE(c.nombre, 'Sin club')
            ORDER BY club_nombre
        ";
        $stmtClub = $pdo->prepare($sqlClubes);
        $stmtClub->execute([$torneoId]);
        $resumenClubes = $stmtClub->fetchAll(PDO::FETCH_ASSOC);

        $equipos = [];
        if ((int)($torneo['modalidad'] ?? 0) === 3) {
            $sqlEq = "
                SELECT
                    e.codigo_equipo,
                    e.nombre_equipo,
                    e.posicion AS pos_equipo,
                    e.ganados AS g_eq,
                    e.perdidos AS p_eq,
                    e.efectividad AS ef_eq,
                    e.puntos AS pts_eq
                FROM equipos e
                WHERE e.id_torneo = ? AND e.estatus = 0
                  AND e.codigo_equipo IS NOT NULL AND e.codigo_equipo != ''
                ORDER BY
                    CASE WHEN e.posicion IS NULL OR e.posicion = 0 THEN 9999 ELSE e.posicion END,
                    e.ganados DESC
            ";
            $stmtEq = $pdo->prepare($sqlEq);
            $stmtEq->execute([$torneoId]);
            $equipos = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
        }

        $sqlRondas = "
            SELECT partida AS num_ronda,
                   COUNT(DISTINCT mesa) AS mesas,
                   SUM(registrado) AS registros
            FROM partiresul
            WHERE id_torneo = ?
            GROUP BY partida
            ORDER BY partida
        ";
        $stmtR = $pdo->prepare($sqlRondas);
        $stmtR->execute([$torneoId]);
        $rondas = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        return [
            'torneo' => $torneo,
            'participantes' => $participantes,
            'resumen_clubes' => $resumenClubes,
            'equipos' => $equipos,
            'rondas' => $rondas,
        ];
    }

    public static function tarjetaTexto($tarjeta): string
    {
        switch ((int)$tarjeta) {
            case 1:
                return 'Amarilla';
            case 3:
                return 'Roja';
            case 4:
                return 'Negra';
            default:
                return '—';
        }
    }
}
