<?php

declare(strict_types=1);

/**
 * Alta mínima de usuario jugador cuando inscribe en sitio y no existe en `usuarios`
 * (tras búsqueda: plataforma → afiliados → manual).
 */
final class UsuarioInscripcionSitioHelper
{
    /**
     * Inserta fila en usuarios y devuelve el id. Si ya existe (por cédula), devuelve ese id.
     *
     * @throws RuntimeException
     */
    public static function obtenerOCrearUsuarioJugador(PDO $pdo, string $cedulaRaw, string $nombre, int $clubId, int $entidadClub): int
    {
        require_once __DIR__ . '/BusquedaJugadorInscripcionService.php';
        require_once __DIR__ . '/security.php';

        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new RuntimeException('El nombre es obligatorio para registrar un nuevo atleta.');
        }
        $dig = BusquedaJugadorInscripcionService::cedulaSoloDigitos($cedulaRaw);
        if ($dig === '') {
            throw new RuntimeException('Cédula inválida.');
        }
        $u = BusquedaJugadorInscripcionService::buscarUsuarioPorCedula($pdo, 'V', $dig);
        if ($u !== null) {
            $id = (int) ($u['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Usuario inconsistente en base de datos.');
            }
            $stmt = $pdo->prepare('UPDATE usuarios SET club_id = ?, entidad = ? WHERE id = ?');
            $stmt->execute([$clubId, $entidadClub, $id]);

            return $id;
        }

        $cedulaStore = 'V' . $dig;
        $email = 'ins_' . $dig . '@inscripcion-sitio.local';
        $username = 'ins_' . $dig . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $hash = Security::hashPassword(bin2hex(random_bytes(8)));
        $numfvd = $dig;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO usuarios (nombre, cedula, nacionalidad, numfvd, sexo, fechnac, email, username, password_hash, role, club_id, entidad, status)
                 VALUES (?, ?, \'V\', ?, \'M\', \'1990-01-01\', ?, ?, ?, \'usuario\', ?, ?, 0)'
            );
            $stmt->execute([$nombre, $cedulaStore, $numfvd, $email, $username, $hash, $clubId, $entidadClub]);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO usuarios (nombre, cedula, nacionalidad, numfvd, sexo, fechnac, email, username, password_hash, role, club_id, entidad, status)
                     VALUES (?, ?, \'V\', ?, \'M\', \'1990-01-01\', ?, ?, ?, \'usuario\', ?, ?, \'approved\')'
                );
                $stmt->execute([$nombre, $cedulaStore, $numfvd, $email, $username, $hash, $clubId, $entidadClub]);
            } catch (Throwable $e2) {
                $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? OR REPLACE(REPLACE(REPLACE(TRIM(CAST(cedula AS CHAR)), \'-\', \'\'), \'.\', \'\'), \' \', \'\') = ? LIMIT 1');
                $stmt->execute([$cedulaStore, $dig]);
                $id = (int) ($stmt->fetchColumn() ?: 0);
                if ($id <= 0) {
                    throw new RuntimeException('No se pudo crear el usuario: ' . $e2->getMessage());
                }

                return $id;
            }
        }

        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('No se obtuvo el ID del nuevo usuario.');
        }

        return $id;
    }
}
