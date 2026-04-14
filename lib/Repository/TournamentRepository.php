<?php

namespace Lib\Repository;

use PDO;

/**
 * TournamentRepository - Acceso a datos de torneos
 */
class TournamentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca torneo por ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, c.nombre as club_nombre 
             FROM tournaments t 
             LEFT JOIN clubes c ON t.club_responsable = c.id 
             WHERE t.id = ?"
        );
        $stmt->execute([$id]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        return $tournament ?: null;
    }

    /**
     * Obtiene torneos con filtros opcionales
     */
    public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['club_id'])) {
            $where[] = "t.club_responsable = ?";
            $params[] = $filters['club_id'];
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'upcoming') {
                $where[] = "t.fechator >= CURDATE()";
            } elseif ($filters['status'] === 'past') {
                $where[] = "t.fechator < CURDATE()";
            }
        }

        if (!empty($filters['search'])) {
            $where[] = "(t.nombre LIKE ? OR t.lugar LIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT t.*, c.nombre as club_nombre 
                FROM tournaments t 
                LEFT JOIN clubes c ON t.club_responsable = c.id 
                {$whereClause}
                ORDER BY t.fechator DESC 
                LIMIT ? OFFSET ?";

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cuenta torneos con filtros
     */
    public function count(array $filters = []): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['club_id'])) {
            $where[] = "club_responsable = ?";
            $params[] = $filters['club_id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tournaments {$whereClause}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Obtiene torneos próximos
     */
    public function findUpcoming(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, c.nombre as club_nombre 
             FROM tournaments t 
             LEFT JOIN clubes c ON t.club_responsable = c.id 
             WHERE t.fechator >= CURDATE() 
             ORDER BY t.fechator ASC 
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene estadísticas de un torneo
     */
    public function getStats(int $tournamentId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT 
                COUNT(*) as total_inscritos,
                COUNT(DISTINCT club_id) as total_clubes,
                SUM(CASE WHEN estado = 'confirmado' THEN 1 ELSE 0 END) as confirmados,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
             FROM inscripciones 
             WHERE torneo_id = ?"
        );
        $stmt->execute([$tournamentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_inscritos' => 0,
            'total_clubes' => 0,
            'confirmados' => 0,
            'pendientes' => 0
        ];
    }

    /**
     * Crea un nuevo torneo
     */
    public function create(array $data): int
    {
        $cols = $this->pdo->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
        $hasParentEventId = is_array($cols) && in_array('parent_event_id', $cols, true);
        $sql = "INSERT INTO tournaments (nombre, fechator, lugar, club_responsable, modalidad, clase, created_at";
        $vals = "VALUES (?, ?, ?, ?, ?, ?, NOW()";
        $params = [
            $data['nombre'],
            $data['fechator'],
            $data['lugar'] ?? null,
            $data['club_responsable'] ?? null,
            $data['modalidad'] ?? null,
            $data['clase'] ?? null,
        ];
        if ($hasParentEventId) {
            $sql .= ", parent_event_id";
            $vals .= ", ?";
            $params[] = 0;
        }
        $sql .= ") " . $vals . ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Actualiza torneo
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['nombre', 'fechator', 'lugar', 'club_responsable', 'modalidad', 'clase', 'afiche_path'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        
        $sql = "UPDATE tournaments SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Elimina torneo
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM tournaments WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Verifica si el torneo ya pasó
     */
    public function isPast(int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT fechator FROM tournaments WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || !$row['fechator']) {
            return false;
        }
        
        return strtotime($row['fechator']) < strtotime(date('Y-m-d'));
    }
}


