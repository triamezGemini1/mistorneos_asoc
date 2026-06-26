<?php

declare(strict_types=1);

require_once __DIR__ . '/PartiresulEstatusSql.php';

/**
 * Resumen individual: jugadores de mesa, compañero (pareja AC/BD) y contrarios.
 */
final class ResumenParticipacionHelper
{
    /**
     * JOIN usuarios ↔ partiresul (id o numfvd legacy).
     */
    public static function sqlJoinUsuariosDesdePartiresul(string $prAlias = 'pr', string $uAlias = 'u', string $joinType = 'LEFT'): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prAlias)) {
            $prAlias = 'pr';
        }
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $uAlias)) {
            $uAlias = 'u';
        }
        $jt = strtoupper($joinType) === 'INNER' ? 'INNER JOIN' : 'LEFT JOIN';

        return "{$jt} usuarios {$uAlias} ON (
            {$uAlias}.id = {$prAlias}.id_usuario
            OR (
                {$uAlias}.numfvd = {$prAlias}.id_usuario
                AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = {$prAlias}.id_usuario)
            )
        )";
    }

    public static function sqlExprNombreUsuarioPartiresul(string $uAlias = 'u', string $prAlias = 'pr'): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $uAlias)) {
            $uAlias = 'u';
        }
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prAlias)) {
            $prAlias = 'pr';
        }

        return "COALESCE(
            NULLIF(TRIM({$uAlias}.nombre), ''),
            NULLIF(TRIM({$uAlias}.username), ''),
            CONCAT('Jugador ', CAST({$prAlias}.id_usuario AS CHAR))
        )";
    }

    /**
     * @param list<int> $idsPartiresulPosibles id_usuario en inscritos / usuarios / numfvd
     * @return list<int>
     */
    public static function normalizarIdsPartiresulJugador(int $idUsuario, int $inscritoIdParam, int $numfvd = 0): array
    {
        $ids = [];
        foreach ([$idUsuario, $inscritoIdParam, $numfvd] as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $fila
     * @param list<int> $idsPropios
     */
    public static function esMismaFilaJugador(array $fila, array $idsPropios): bool
    {
        $id = (int) ($fila['id_usuario'] ?? 0);

        return $id > 0 && in_array($id, $idsPropios, true);
    }

    /**
     * @param list<array<string, mixed>> $jugadoresMesa filas con secuencia, id_usuario, nombre_completo, club_nombre
     * @param list<int> $idsPropios
     * @return array{companero: ?array{nombre: string, club: string, id_usuario: int}, contrarios: list<array{nombre: string, club: string, id_usuario: int}>}
     */
    public static function resolverCompaneroYContrarios(array $jugadoresMesa, array $idsPropios, int $secuencia): array
    {
        $companero = null;
        $contrarios = [];
        if ($jugadoresMesa === []) {
            return ['companero' => null, 'contrarios' => []];
        }

        $porSecuencia = [];
        foreach ($jugadoresMesa as $j) {
            $s = (int) ($j['secuencia'] ?? 0);
            if ($s >= 1 && $s <= 4) {
                $porSecuencia[$s] = $j;
            }
        }

        $sec = $secuencia;
        if ($sec < 1 || $sec > 4) {
            foreach ($jugadoresMesa as $j) {
                if (self::esMismaFilaJugador($j, $idsPropios)) {
                    $sec = (int) ($j['secuencia'] ?? 0);
                    break;
                }
            }
        }

        $mapaPareja = [1 => 2, 2 => 1, 3 => 4, 4 => 3];
        $secComp = isset($mapaPareja[$sec]) ? $mapaPareja[$sec] : 0;
        if ($secComp > 0 && isset($porSecuencia[$secComp])) {
            $companero = self::formatearJugadorMesa($porSecuencia[$secComp]);
        }

        if ($companero === null) {
            $secsPareja = in_array($sec, [1, 2], true) ? [1, 2] : (in_array($sec, [3, 4], true) ? [3, 4] : [1, 2]);
            foreach ($jugadoresMesa as $j) {
                if (self::esMismaFilaJugador($j, $idsPropios)) {
                    continue;
                }
                if (in_array((int) ($j['secuencia'] ?? 0), $secsPareja, true)) {
                    $companero = self::formatearJugadorMesa($j);
                    break;
                }
            }
        }

        if ($companero === null) {
            $ordenados = $jugadoresMesa;
            usort($ordenados, static function ($a, $b) {
                return (int) ($a['secuencia'] ?? 0) <=> (int) ($b['secuencia'] ?? 0);
            });
            foreach ($ordenados as $idx => $j) {
                if (! self::esMismaFilaJugador($j, $idsPropios)) {
                    continue;
                }
                $otroIdx = ($idx % 2 === 0) ? $idx + 1 : $idx - 1;
                if (isset($ordenados[$otroIdx]) && ! self::esMismaFilaJugador($ordenados[$otroIdx], $idsPropios)) {
                    $companero = self::formatearJugadorMesa($ordenados[$otroIdx]);
                }
                break;
            }
        }

        $secsContrarios = in_array($sec, [1, 2], true) ? [3, 4] : [1, 2];
        foreach ($secsContrarios as $secCont) {
            if (! isset($porSecuencia[$secCont])) {
                continue;
            }
            $contrarios[] = self::formatearJugadorMesa($porSecuencia[$secCont]);
        }

        return ['companero' => $companero, 'contrarios' => $contrarios];
    }

    /**
     * @return ?array{nombre: string, club: string, id_usuario: int}
     */
    public static function buscarCompaneroEnHistorialParejas(PDO $pdo, int $torneoId, int $ronda, array $idsPropios): ?array
    {
        if ($torneoId <= 0 || $ronda <= 0 || $idsPropios === []) {
            return null;
        }
        foreach ($idsPropios as $idJ) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT jugador_1_id, jugador_2_id FROM historial_parejas
                     WHERE torneo_id = ? AND ronda_id = ? AND (jugador_1_id = ? OR jugador_2_id = ?)
                     LIMIT 1'
                );
                $stmt->execute([$torneoId, $ronda, $idJ, $idJ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (! $row) {
                    continue;
                }
                $j1 = (int) ($row['jugador_1_id'] ?? 0);
                $j2 = (int) ($row['jugador_2_id'] ?? 0);
                $otroId = ($j1 === $idJ) ? $j2 : $j1;
                if ($otroId <= 0) {
                    continue;
                }
                $stmtN = $pdo->prepare(
                    'SELECT COALESCE(NULLIF(TRIM(nombre), \'\'), NULLIF(TRIM(username), \'\'), CONCAT(\'Jugador \', ?)) AS nombre
                     FROM usuarios WHERE id = ? OR numfvd = ? LIMIT 1'
                );
                $stmtN->execute([(string) $otroId, $otroId, $otroId]);
                $nom = $stmtN->fetchColumn();
                if ($nom === false || trim((string) $nom) === '') {
                    continue;
                }

                return [
                    'nombre' => (string) $nom,
                    'club' => 'Sin Club',
                    'id_usuario' => $otroId,
                ];
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $j
     * @return array{nombre: string, club: string, id_usuario: int}
     */
    private static function formatearJugadorMesa(array $j): array
    {
        $nombre = trim((string) ($j['nombre_completo'] ?? $j['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = 'Jugador ' . (int) ($j['id_usuario'] ?? 0);
        }

        return [
            'nombre' => $nombre,
            'club' => trim((string) ($j['club_nombre'] ?? '')) !== '' ? (string) $j['club_nombre'] : 'Sin Club',
            'id_usuario' => (int) ($j['id_usuario'] ?? 0),
        ];
    }

    /**
     * Posición ya calculada en clasificación (solo lectura de inscritos / equipos).
     *
     * @param array<string, mixed> $inscrito
     * @param array<string, mixed>|null $equipo
     */
    public static function resolverPosicionMostrada(
        array $inscrito,
        bool $esModalidadEquipos,
        ?array $equipo = null
    ): int {
        if ($esModalidadEquipos) {
            $pos = (int) ($inscrito['clasiequi'] ?? 0);
            if ($pos > 0) {
                return $pos;
            }

            return (int) ($equipo['posicion'] ?? 0);
        }

        return (int) ($inscrito['posicion'] ?? 0);
    }
}
