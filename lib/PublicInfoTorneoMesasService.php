<?php
declare(strict_types=1);

/**
 * Consulta pública: mesa de un jugador en una ronda (sin perfil completo).
 * Replica el criterio de join de obtenerDatosMesas (usuarios.id / numfvd club 7).
 */
final class PublicInfoTorneoMesasService
{
    public static function getTorneoActivo(\PDO $pdo, int $torneoId): ?array
    {
        $st = $pdo->prepare('SELECT id, nombre, modalidad, rondas FROM tournaments WHERE id = ? AND estatus = 1');
        $st->execute([$torneoId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function ultimaRondaConPartidas(\PDO $pdo, int $torneoId): int
    {
        $st = $pdo->prepare('SELECT MAX(partida) FROM partiresul WHERE id_torneo = ?');
        $st->execute([$torneoId]);
        $m = (int) $st->fetchColumn();
        return $m > 0 ? $m : 1;
    }

    /** @return int[] */
    public static function rondasConDatos(\PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare('SELECT DISTINCT partida FROM partiresul WHERE id_torneo = ? ORDER BY partida ASC');
        $st->execute([$torneoId]);
        $out = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        return $out;
    }

    public static function estaInscrito(\PDO $pdo, int $torneoId, int $idUsuario): bool
    {
        $st = $pdo->prepare(
            "SELECT 1 FROM inscritos WHERE torneo_id = ? AND id_usuario = ?
             AND (estatus IS NULL OR estatus = 1 OR estatus = 2 OR estatus = '1' OR estatus = 'confirmado')
             LIMIT 1"
        );
        $st->execute([$torneoId, $idUsuario]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Mesa asignada: >0 mesa normal, 0 = BYE, null = sin fila en partiresul.
     */
    public static function mesaDelJugador(\PDO $pdo, int $torneoId, int $ronda, int $idUsuario): ?int
    {
        $sql = 'SELECT pr.mesa FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            WHERE pr.id_torneo = ? AND pr.partida = ? AND u.id = ?
            LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $ronda, $idUsuario]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return (int) $row['mesa'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function jugadoresMesa(\PDO $pdo, int $torneoId, int $ronda, int $numMesa): array
    {
        $sql = 'SELECT
                pr.*,
                u.id AS jugador_uid,
                u.nombre AS nombre_completo,
                u.nombre,
                u.sexo,
                i.codigo_equipo AS codigo_equipo_inscrito,
                c.nombre AS club_nombre
            FROM partiresul pr
            INNER JOIN usuarios u ON (
                u.id = pr.id_usuario
                OR (
                    u.numfvd = pr.id_usuario
                    AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                    AND EXISTS (
                        SELECT 1 FROM tournaments tx
                        WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                    )
                )
            )
            LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
            LEFT JOIN clubes c ON i.id_club = c.id
            WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = ?
            ORDER BY pr.secuencia ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $ronda, $numMesa]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array{tipo: string, mesa?: int, jugadores?: list<array<string, mixed>>, nombre?: string, club_nombre?: string}|null
     */
    public static function resumenAsignacion(
        \PDO $pdo,
        int $torneoId,
        int $ronda,
        int $idUsuario
    ): ?array {
        $mesa = self::mesaDelJugador($pdo, $torneoId, $ronda, $idUsuario);
        if ($mesa === null) {
            return null;
        }
        if ($mesa === 0) {
            $st = $pdo->prepare(
                'SELECT u.nombre, u.nombre AS nombre_completo, c.nombre AS club_nombre
                 FROM partiresul pr
                 INNER JOIN usuarios u ON (
                     u.id = pr.id_usuario
                     OR (
                         u.numfvd = pr.id_usuario
                         AND NOT EXISTS (SELECT 1 FROM usuarios u_pr_id WHERE u_pr_id.id = pr.id_usuario)
                         AND EXISTS (
                             SELECT 1 FROM tournaments tx
                             WHERE tx.id = pr.id_torneo AND tx.club_responsable = 7
                         )
                     )
                 )
                 LEFT JOIN inscritos i ON i.torneo_id = pr.id_torneo AND i.id_usuario = u.id
                 LEFT JOIN clubes c ON i.id_club = c.id
                 WHERE pr.id_torneo = ? AND pr.partida = ? AND pr.mesa = 0 AND u.id = ?
                 LIMIT 1'
            );
            $st->execute([$torneoId, $ronda, $idUsuario]);
            $bye = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$bye) {
                return ['tipo' => 'bye', 'nombre' => '', 'club_nombre' => ''];
            }
            return [
                'tipo' => 'bye',
                'nombre' => (string) ($bye['nombre'] ?? $bye['nombre_completo'] ?? ''),
                'club_nombre' => (string) ($bye['club_nombre'] ?? ''),
            ];
        }

        $jugadores = self::jugadoresMesa($pdo, $torneoId, $ronda, $mesa);
        return [
            'tipo' => 'mesa',
            'mesa' => $mesa,
            'jugadores' => $jugadores,
        ];
    }
}
