<?php

declare(strict_types=1);

namespace Tournament\Handlers;

use PDO;
use Throwable;

require_once __DIR__ . '/../../InscritosHelper.php';

/**
 * Sorteos aleatorios entre inscritos confirmados del torneo.
 * Persistencia en torneo_sorteos_ganadores (crear tabla con sql/sql/create_torneo_sorteos_ganadores_table.sql).
 */
final class RaffleHandler
{
    private function __construct()
    {
    }

    public static function ensureTable(\PDO $pdo): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS torneo_sorteos_ganadores (
          id INT AUTO_INCREMENT PRIMARY KEY,
          torneo_id INT NOT NULL,
          id_usuario INT NOT NULL,
          premio_label VARCHAR(255) NOT NULL DEFAULT \'\',
          batch_id VARCHAR(64) NOT NULL,
          orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL,
          KEY idx_torneo (torneo_id),
          KEY idx_batch (batch_id),
          KEY idx_torneo_usuario (torneo_id, id_usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        try {
            $pdo->exec($sql);

            return true;
        } catch (Throwable $e) {
            error_log('RaffleHandler::ensureTable: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return list<array{id_usuario:int, nombre:string, cedula:string}>
     */
    public static function listarInscritosElegibles(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            return [];
        }
        $sql = 'SELECT i.id_usuario, COALESCE(u.nombre, u.username) AS nombre, COALESCE(u.cedula, \'\') AS cedula
                FROM inscritos i
                INNER JOIN usuarios u ON u.id = i.id_usuario
                WHERE i.torneo_id = ?
                AND ' . \InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . '
                ORDER BY u.nombre ASC, i.id_usuario ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id_usuario' => (int) ($r['id_usuario'] ?? 0),
                'nombre' => (string) ($r['nombre'] ?? ''),
                'cedula' => (string) ($r['cedula'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $excluirIds
     * @return array{batch_id: string, ganadores: list<array{id_usuario:int, nombre:string, orden:int}>, error?: string}
     */
    public static function ejecutarSorteo(
        \PDO $pdo,
        int $torneoId,
        int $cantidadPremios,
        string $premioLabel,
        array $excluirIds = [],
        bool $excluirGanadoresPrevios = false
    ): array {
        if ($torneoId < 1 || $cantidadPremios < 1) {
            return ['batch_id' => '', 'ganadores' => [], 'error' => 'Parámetros inválidos'];
        }
        if (!self::ensureTable($pdo)) {
            return ['batch_id' => '', 'ganadores' => [], 'error' => 'No se pudo crear o acceder a la tabla de sorteos. Ejecute el script SQL create_torneo_sorteos_ganadores_table.sql'];
        }

        $lista = self::listarInscritosElegibles($pdo, $torneoId);
        $idsDisponibles = array_column($lista, 'id_usuario');
        $porId = [];
        foreach ($lista as $row) {
            $porId[(int) $row['id_usuario']] = $row['nombre'];
        }

        $excluir = array_fill_keys(array_map('intval', $excluirIds), true);
        if ($excluirGanadoresPrevios) {
            $st = $pdo->prepare('SELECT DISTINCT id_usuario FROM torneo_sorteos_ganadores WHERE torneo_id = ?');
            $st->execute([$torneoId]);
            while ($uid = $st->fetchColumn()) {
                $excluir[(int) $uid] = true;
            }
        }

        $candidatos = array_values(array_filter($idsDisponibles, static function ($id) use ($excluir) {
            return $id > 0 && empty($excluir[$id]);
        }));

        if (count($candidatos) < $cantidadPremios) {
            return [
                'batch_id' => '',
                'ganadores' => [],
                'error' => 'No hay suficientes inscritos elegibles (' . count($candidatos) . ') para ' . $cantidadPremios . ' premio(s).',
            ];
        }

        shuffle($candidatos);
        $elegidos = array_slice($candidatos, 0, $cantidadPremios);
        $batchId = bin2hex(random_bytes(16));
        $ahora = date('Y-m-d H:i:s');
        $premioLabel = mb_substr(trim($premioLabel), 0, 250);

        $ins = $pdo->prepare(
            'INSERT INTO torneo_sorteos_ganadores (torneo_id, id_usuario, premio_label, batch_id, orden, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $ganadores = [];
        $orden = 1;
        $pdo->beginTransaction();
        try {
            foreach ($elegidos as $uid) {
                $ins->execute([$torneoId, $uid, $premioLabel, $batchId, $orden, $ahora]);
                $ganadores[] = [
                    'id_usuario' => $uid,
                    'nombre' => (string) ($porId[$uid] ?? ('#' . $uid)),
                    'orden' => $orden,
                ];
                ++$orden;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('RaffleHandler::ejecutarSorteo: ' . $e->getMessage());

            return ['batch_id' => '', 'ganadores' => [], 'error' => 'Error al guardar el sorteo: ' . $e->getMessage()];
        }

        return ['batch_id' => $batchId, 'ganadores' => $ganadores];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function historialPorTorneo(\PDO $pdo, int $torneoId, int $limite = 200): array
    {
        if ($torneoId < 1) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT s.id, s.torneo_id, s.id_usuario, s.premio_label, s.batch_id, s.orden, s.created_at,
                        COALESCE(u.nombre, u.username) AS nombre
                 FROM torneo_sorteos_ganadores s
                 INNER JOIN usuarios u ON u.id = s.id_usuario
                 WHERE s.torneo_id = ?
                 ORDER BY s.created_at DESC, s.batch_id DESC, s.orden ASC
                 LIMIT ' . (int) max(1, min(500, $limite))
            );
            $st->execute([$torneoId]);

            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('RaffleHandler::historialPorTorneo: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Agrupa filas del historial por batch_id para la vista.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array{batch_id: string, created_at: string, premio_label: string, items: list<array<string, mixed>>}>
     */
    public static function agruparHistorialPorLote(array $filas): array
    {
        $map = [];
        foreach ($filas as $r) {
            $bid = (string) ($r['batch_id'] ?? '');
            if ($bid === '') {
                continue;
            }
            if (!isset($map[$bid])) {
                $map[$bid] = [
                    'batch_id' => $bid,
                    'created_at' => (string) ($r['created_at'] ?? ''),
                    'premio_label' => (string) ($r['premio_label'] ?? ''),
                    'items' => [],
                ];
            }
            $map[$bid]['items'][] = $r;
        }

        return array_values($map);
    }
}
