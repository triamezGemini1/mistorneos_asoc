<?php
/**
 * Lógica de guardado equipo inscripción en sitio (una sola fuente de verdad).
 * Invocable desde index.php (torneo_gestion) o desde API — evita sesión/OPcache rotos en public/api.
 */
declare(strict_types=1);

final class GuardarEquipoSitioService
{
    /**
     * @param array $input Mismo shape que $_POST del formulario (csrf_token, torneo_id, club_id, nombre_equipo, jugadores)
     * @return array{success:bool, message:string, equipo_id?:int}
     */
    public static function ejecutar(PDO $pdo, array $input, ?int $creado_por): array
    {
        $torneo_id = (int)($input['torneo_id'] ?? 0);
        $equipo_id = (int)($input['equipo_id'] ?? 0);
        $nombre_equipo = trim((string)($input['nombre_equipo'] ?? ''));
        $club_id = (int)($input['club_id'] ?? 0);
        $jugadores = $input['jugadores'] ?? [];
        if (is_string($jugadores)) {
            $jugadores = json_decode($jugadores, true) ?: [];
        }
        if (!is_array($jugadores)) {
            $jugadores = [];
        }

        if ($torneo_id <= 0 || $club_id <= 0) {
            return ['success' => false, 'message' => 'Datos incompletos'];
        }
        $stmtEnt = $pdo->prepare('SELECT COALESCE(entidad, 0) FROM clubes WHERE id = ? LIMIT 1');
        $stmtEnt->execute([$club_id]);
        $entidad_club = (int)($stmtEnt->fetchColumn() ?: 0);
        $codigo_club_prefijo = trim((string)($input['codigo_club_prefijo'] ?? ''));
        $stmt = $pdo->prepare('SELECT modalidad FROM tournaments WHERE id = ? LIMIT 1');
        $stmt->execute([$torneo_id]);
        $modalidad = (int)($stmt->fetchColumn() ?: 0);
        $es_parejas = ($modalidad === 2);
        if (!$es_parejas && $nombre_equipo === '') {
            return ['success' => false, 'message' => 'El nombre del equipo es obligatorio'];
        }

        require_once __DIR__ . '/EquiposHelper.php';
        require_once __DIR__ . '/InscritosHelper.php';
        require_once __DIR__ . '/UserActivationHelper.php';
        require_once __DIR__ . '/UsuarioInscripcionSitioHelper.php';

        /* Carga masiva u otro caller puede tener ya una transacción abierta; PDO no admite anidar beginTransaction. */
        $transaccionPropia = !$pdo->inTransaction();
        if ($transaccionPropia) {
            $pdo->beginTransaction();
        }
        try {
            if ($equipo_id > 0) {
                $nombre_actualizar = $nombre_equipo !== '' ? strtoupper($nombre_equipo) : null;
                if ($nombre_actualizar !== null) {
                    $stmt = $pdo->prepare('UPDATE equipos SET nombre_equipo = ?, id_club = ? WHERE id = ? AND id_torneo = ?');
                    $stmt->execute([$nombre_actualizar, $club_id, $equipo_id, $torneo_id]);
                } else {
                    $stmt = $pdo->prepare('SELECT codigo_equipo FROM equipos WHERE id = ? AND id_torneo = ? LIMIT 1');
                    $stmt->execute([$equipo_id, $torneo_id]);
                    $codigo_actual = trim((string)($stmt->fetchColumn() ?: ''));
                    $nombre_actualizar = $codigo_actual !== '' ? ('Pareja ' . $codigo_actual) : 'Pareja';
                    $stmt = $pdo->prepare('UPDATE equipos SET nombre_equipo = ?, id_club = ? WHERE id = ? AND id_torneo = ?');
                    $stmt->execute([$nombre_actualizar, $club_id, $equipo_id, $torneo_id]);
                }
                $stmt = $pdo->prepare('SELECT codigo_equipo FROM equipos WHERE id = ?');
                $stmt->execute([$equipo_id]);
                $codigo_equipo = $stmt->fetchColumn() ?: null;
                if (empty($codigo_equipo)) {
                    throw new RuntimeException('No se encontró el código del equipo existente');
                }
                /* inscritos.codigo_equipo es NOT NULL: no usar NULL al liberar jugadores del equipo */
                $stmt = $pdo->prepare('UPDATE inscritos SET codigo_equipo = ? WHERE torneo_id = ? AND codigo_equipo = ?');
                $stmt->execute(['', $torneo_id, $codigo_equipo]);
            } else {
                $result = EquiposHelper::crearEquipo(
                    $torneo_id,
                    $club_id,
                    $nombre_equipo !== '' ? $nombre_equipo : '',
                    $creado_por,
                    $codigo_club_prefijo !== '' ? $codigo_club_prefijo : null
                );
                if (empty($result['success'])) {
                    throw new RuntimeException($result['message'] ?? 'Error al crear equipo');
                }
                $equipo_id = (int)$result['id'];
                $codigo_equipo = isset($result['codigo']) ? trim((string)$result['codigo']) : '';
                if ($equipo_id > 0 && $codigo_equipo === '') {
                    $stmt = $pdo->prepare('SELECT codigo_equipo FROM equipos WHERE id = ? AND id_torneo = ? LIMIT 1');
                    $stmt->execute([$equipo_id, $torneo_id]);
                    $codigo_equipo = trim((string)($stmt->fetchColumn() ?: ''));
                }
                if ($codigo_equipo === '') {
                    throw new RuntimeException('No se pudo generar el código del equipo (codigo_equipo obligatorio en BD)');
                }
            }

            foreach ($jugadores as $jugador_data) {
                if (empty($jugador_data['cedula']) || empty($jugador_data['nombre'])) {
                    continue;
                }
                $cedula = trim((string)$jugador_data['cedula']);
                $id_usuario = (int)($jugador_data['id_usuario'] ?? 0);
                $id_inscrito = (int)($jugador_data['id_inscrito'] ?? 0);
                $nombre_jugador = trim((string)($jugador_data['nombre'] ?? ''));

                /* Resolver usuario: id enviado, o búsqueda por cédula (variantes), o alta mínima si no existe (manual / afiliados sin cuenta). */
                if ($id_usuario <= 0) {
                    $id_usuario = UsuarioInscripcionSitioHelper::obtenerOCrearUsuarioJugador(
                        $pdo,
                        $cedula,
                        $nombre_jugador,
                        $club_id,
                        $entidad_club
                    );
                }
                if ($id_usuario <= 0) {
                    throw new RuntimeException('No se pudo resolver el usuario para la cédula ' . $cedula);
                }

                $stmt = $pdo->prepare('UPDATE usuarios SET club_id = ?, entidad = ? WHERE id = ?');
                $stmt->execute([$club_id, $entidad_club, $id_usuario]);

                if ($id_inscrito > 0) {
                    $stmt = $pdo->prepare('SELECT id FROM inscritos WHERE id = ? AND id_usuario = ? AND torneo_id = ? LIMIT 1');
                    $stmt->execute([$id_inscrito, $id_usuario, $torneo_id]);
                    if (!$stmt->fetch()) {
                        $id_inscrito = 0;
                    }
                }
                if ($id_inscrito <= 0) {
                    $stmt = $pdo->prepare('SELECT id FROM inscritos WHERE id_usuario = ? AND torneo_id = ? LIMIT 1');
                    $stmt->execute([$id_usuario, $torneo_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $id_inscrito = (int)$row['id'];
                    } else {
                        $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
                            'id_usuario' => $id_usuario,
                            'torneo_id' => $torneo_id,
                            'id_club' => $club_id,
                            'codigo_equipo' => $codigo_equipo,
                            'estatus' => 1,
                            'inscrito_por' => $creado_por,
                            'numero' => 0,
                        ]);
                        UserActivationHelper::activateUser($pdo, $id_usuario);
                    }
                }
                $stmt = $pdo->prepare('UPDATE inscritos SET id_usuario = ?, id_club = ?, codigo_equipo = ?, estatus = 1 WHERE id = ?');
                $stmt->execute([$id_usuario, $club_id, $codigo_equipo, $id_inscrito]);
            }

            if ($transaccionPropia) {
                $pdo->commit();
            }
            return [
                'success' => true,
                'message' => 'Equipo guardado exitosamente',
                'equipo_id' => $equipo_id,
            ];
        } catch (Throwable $e) {
            if ($transaccionPropia && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

}
