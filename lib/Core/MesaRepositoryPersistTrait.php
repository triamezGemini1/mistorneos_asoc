<?php

declare(strict_types=1);

/**
 * Escrituras partiresul / historial_parejas (usado por MesaRepository).
 *
 * Fuente única: app/Core/MesaRepository.php carga este archivo (no el duplicado bajo app/Core/).
 */
require_once __DIR__ . '/../PartiresulEstatusSql.php';

trait MesaRepositoryPersistTrait
{
    /**
     * Usuario que genera la ronda: $preferido, sesión actual o creador del torneo.
     */
    private function mesaRegistradoPorUsuarioId(int $torneoId, ?int $preferido = null): int
    {
        if ($preferido !== null && $preferido > 0) {
            return $preferido;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
            if ($sessionUserId > 0) {
                return $sessionUserId;
            }
            $admin = $_SESSION['admin_user'] ?? null;
            if (is_array($admin) && !empty($admin['id'])) {
                return max(1, (int) $admin['id']);
            }
            $user = $_SESSION['user'] ?? null;
            if (is_array($user) && !empty($user['id'])) {
                return max(1, (int) $user['id']);
            }
        }

        $creador = $this->torneoCreadorUsuarioId($torneoId);
        if ($creador > 0) {
            return $creador;
        }

        return 1;
    }

    /**
     * Busca el usuario creador del torneo en esquemas históricos.
     */
    private function torneoCreadorUsuarioId(int $torneoId): int
    {
        if ($torneoId <= 0) {
            return 0;
        }
        $columnas = ['owner_user_id', 'creado_por', 'user_id', 'admin_id', 'creador_id'];
        foreach ($columnas as $columna) {
            try {
                $stmt = $this->pdo->prepare("SELECT $columna AS uid FROM tournaments WHERE id = ? LIMIT 1");
                $stmt->execute([$torneoId]);
                $uid = (int) ($stmt->fetchColumn() ?: 0);
                if ($uid > 0) {
                    return $uid;
                }
            } catch (Exception $e) {
                // Columna inexistente en este esquema; probar la siguiente.
            }
        }

        return 0;
    }

    /**
     * @param list<list<array<string, mixed>>> $mesas
     */
    public function guardarAsignacionRonda(int $torneoId, int $ronda, array $mesas, ?int $registradoPorUsuarioId = null): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?')
                ->execute([$torneoId, $ronda]);
            try {
                $this->pdo->prepare('DELETE FROM historial_parejas WHERE torneo_id = ? AND ronda_id = ?')
                    ->execute([$torneoId, $ronda]);
            } catch (Exception $e) {
                // Tabla ausente o esquema distinto: INSERT IGNORE en guardarHistorialParejas sigue siendo best-effort
            }

            $fechaPartida = $this->fechaPartidaAhora();
            $registradoPor = (int) ($_SESSION['user_id'] ?? 0);
            if ($registradoPor <= 0) {
                $registradoPor = 1;
            }
            $numeroMesa = 1;
            foreach ($mesas as $mesa) {
                $secuencia = 1;
                foreach ($mesa as $jugador) {
                    $idUsuario = (int) ($jugador['id_usuario'] ?? 0);
                    if ($idUsuario <= 0) {
                        continue;
                    }
                    $sql = 'INSERT INTO partiresul
                            (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
                             resultado1, resultado2, efectividad, ff)
                            VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0, 0, 0, 0)';
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$torneoId, $idUsuario, $ronda, $numeroMesa, $secuencia, $fechaPartida, $registradoPor]);
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
     * @param list<list<array<string, mixed>>> $mesasAsignadas
     */
    private function guardarHistorialParejas(array $mesasAsignadas, int $torneoId, int $rondaId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                $this->sqlInsertIgnoreInto('historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id, llave) VALUES (?, ?, ?, ?, ?)')
            );
            foreach ($mesasAsignadas as $mesa) {
                if (count($mesa) < 4) {
                    continue;
                }
                $ids = array_column($mesa, 'id_usuario');
                $a = (int) ($ids[0] ?? 0);
                $c = (int) ($ids[1] ?? 0);
                $b = (int) ($ids[2] ?? 0);
                $d = (int) ($ids[3] ?? 0);
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
            try {
                $stmt = $this->pdo->prepare(
                    $this->sqlInsertIgnoreInto('historial_parejas (torneo_id, ronda_id, jugador_1_id, jugador_2_id) VALUES (?, ?, ?, ?)')
                );
                foreach ($mesasAsignadas as $mesa) {
                    if (count($mesa) < 4) {
                        continue;
                    }
                    $ids = array_column($mesa, 'id_usuario');
                    $a = (int) ($ids[0] ?? 0);
                    $c = (int) ($ids[1] ?? 0);
                    $b = (int) ($ids[2] ?? 0);
                    $d = (int) ($ids[3] ?? 0);
                    if ($a > 0 && $c > 0) {
                        $stmt->execute([$torneoId, $rondaId, min($a, $c), max($a, $c)]);
                    }
                    if ($b > 0 && $d > 0) {
                        $stmt->execute([$torneoId, $rondaId, min($b, $d), max($b, $d)]);
                    }
                }
            } catch (Exception $e2) {
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $jugadoresBye
     */
    public function aplicarBye(int $torneoId, int $ronda, array $jugadoresBye, int $maxJugadoresBye = 3, ?int $registradoPorUsuarioId = null): void
    {
        $jugadoresBye = array_slice($jugadoresBye, 0, $maxJugadoresBye);
        if ($jugadoresBye === []) {
            return;
        }
        $puntosTorneo = 200;
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(NULLIF(puntos, 0), 200) AS puntos FROM tournaments WHERE id = ?');
            $stmt->execute([$torneoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['puntos'])) {
                $puntosTorneo = (int) $row['puntos'];
            }
            if ($puntosTorneo <= 0) {
                $puntosTorneo = 200;
            }
        } catch (Exception $e) {
        }
        $efectividadBye = (int) round($puntosTorneo * 0.5);
        $registradoPor = (int) ($_SESSION['user_id'] ?? 0);
        if ($registradoPor <= 0) {
            $registradoPor = 1;
        }

        $this->pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa = 0')
            ->execute([$torneoId, $ronda]);
        $fechaPartida = $this->fechaPartidaAhora();
        $stmt = $this->pdo->prepare(
            'INSERT INTO partiresul (id_torneo, id_usuario, partida, mesa, secuencia, fecha_partida, registrado, registrado_por,
             resultado1, resultado2, efectividad, ff)
            VALUES (?, ?, ?, 0, 1, ?, 0, ?, 0, 0, 0, 0)'
        );
        foreach ($jugadoresBye as $jugador) {
            $idUsuario = (int) ($jugador['id_usuario'] ?? 0);
            if ($idUsuario <= 0) {
                continue;
            }
            $stmt->execute([$torneoId, $idUsuario, $ronda, $fechaPartida, $registradoPor]);
        }

        $this->pdo->prepare(
            'UPDATE partiresul
            SET resultado1 = ?, resultado2 = 0, efectividad = ?, registrado = 1, registrado_por = ?
            WHERE id_torneo = ? AND partida = ? AND mesa = 0'
        )->execute([$puntosTorneo, $efectividadBye, $registradoPor, $torneoId, $ronda]);
    }

    public function obtenerUltimaRonda(int $torneoId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(partida) as ultima_ronda FROM partiresul WHERE id_torneo = ?');
        $stmt->execute([$torneoId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['ultima_ronda'] ?? 0);
    }

    public function obtenerProximaRonda(int $torneoId): int
    {
        return $this->obtenerUltimaRonda($torneoId) + 1;
    }

    public function todasLasMesasCompletas(int $torneoId, int $ronda): bool
    {
        $noReg = PartiresulEstatusSql::whereRegistradoNoCompleto();
        $sql = 'SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND ' . $noReg;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['mesas_incompletas'] ?? 0) === 0;
    }

    public function contarMesasIncompletas(int $torneoId, int $ronda): int
    {
        $noReg = PartiresulEstatusSql::whereRegistradoNoCompleto();
        $sql = 'SELECT COUNT(DISTINCT mesa) as mesas_incompletas
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND mesa > 0
                AND ' . $noReg;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$torneoId, $ronda]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['mesas_incompletas'] ?? 0);
    }

    public function rondaTieneResultadosEnMesas(int $torneoId, int $ronda): bool
    {
        $regOk = PartiresulEstatusSql::whereRegistradoUno();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0 AND {$regOk}"
        );
        $stmt->execute([$torneoId, $ronda]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function eliminarRonda(int $torneoId, int $ronda): bool
    {
        try {
            $this->pdo->beginTransaction();

            $ent = $this->whereEntidad();
            try {
                $stmtH = $this->pdo->prepare('DELETE FROM historial_parejas WHERE torneo_id = ? AND ronda_id = ?' . $ent['sql']);
                $stmtH->execute(array_merge([$torneoId, $ronda], $ent['bind']));
            } catch (Exception $e) {
                error_log('eliminarRonda historial_parejas: ' . $e->getMessage());
            }

            $stmt = $this->pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ? AND partida = ?');
            $stmt->execute([$torneoId, $ronda]);

            $this->pdo->commit();

            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('eliminarRonda: ' . $e->getMessage());

            return false;
        }
    }
}
