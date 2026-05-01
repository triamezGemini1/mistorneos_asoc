<?php

namespace Lib\Repository;

use PDO;

/**
 * ClubRepository - Acceso a datos de clubes
 */
class ClubRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca club por ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM clubes WHERE id = ?");
        $stmt->execute([$id]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        return $club ?: null;
    }

    /**
     * Homologa el texto leído de Excel/CSV: BOM, espacios Unicode, marcas invisibles, NFKC (dígitos ancho completo → ASCII).
     * Debe usarse antes de interpretar el valor como id de {@see findById}.
     */
    public static function sanitizarReferenciaClubImport(string $ref): string
    {
        $ref = preg_replace('/^\xEF\xBB\xBF/u', '', $ref);
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($ref, \Normalizer::FORM_KC);
            if ($n !== false) {
                $ref = $n;
            }
        }
        $ref = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}\x{200E}\x{200F}\x{2060}]/u', '', $ref);
        $ref = preg_replace('/[\x{00A0}\x{202F}\x{2007}]/u', ' ', $ref);

        return trim($ref);
    }

    /**
     * Busca club por nombre
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM clubes WHERE nombre = ? LIMIT 1");
        $stmt->execute([$name]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);
        return $club ?: null;
    }

    /**
     * Si el texto es un entero positivo (p. ej. "27", "27.0" desde Excel), devuelve ese id; si no, null.
     */
    private function parseImportClubIdPk(string $ref): ?int
    {
        $ref = self::sanitizarReferenciaClubImport($ref);
        if ($ref === '') {
            return null;
        }
        $probe = str_replace(',', '.', $ref);
        if (!is_numeric($probe)) {
            return null;
        }
        $f = (float) $probe;
        if ($f < 1 || $f > PHP_INT_MAX || (int) $f != $f) {
            return null;
        }

        return (int) $f;
    }

    /**
     * Resuelve el id de club a partir del valor del archivo de importación masiva:
     * - Valor numérico entero (incl. "27.0" de planilla): solo {@see findById} — es el id de `clubes`.
     * - En otro caso: nombre exacto, cod_org como texto, nombre sin distinguir mayúsculas.
     *
     * @return int|null id en clubes o null si no hay coincidencia
     */
    public function resolveFromImportReference(string $ref): ?int
    {
        $ref = self::sanitizarReferenciaClubImport($ref);
        if ($ref === '') {
            return null;
        }

        $maybeId = $this->parseImportClubIdPk($ref);
        if ($maybeId !== null) {
            $byId = $this->findById($maybeId);

            return $byId !== null ? $maybeId : null;
        }

        $byName = $this->findByName($ref);
        if ($byName !== null) {
            return (int) $byName['id'];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM clubes WHERE CAST(cod_org AS CHAR) = ? LIMIT 1');
            $stmt->execute([$ref]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        } catch (\Throwable $e) {
        }
        $stmt = $this->pdo->prepare('SELECT id FROM clubes WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->execute([$ref]);
        $id2 = $stmt->fetchColumn();

        return $id2 !== false ? (int) $id2 : null;
    }

    /**
     * Obtiene todos los clubes
     */
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM clubes ORDER BY nombre ASC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene clubes para select/dropdown
     */
    public function findForSelect(): array
    {
        $stmt = $this->pdo->query("SELECT id, nombre FROM clubes ORDER BY nombre ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cuenta total de clubes
     */
    public function count(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM clubes");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Crea un nuevo club.
     * Usa solo columnas existentes en la tabla clubes (nombre, direccion, delegado, telefono, email, estatus, cod_org, entidad, logo).
     */
    public function create(array $data): int
    {
        $cols = ['nombre', 'estatus'];
        $placeholders = ['?', '?'];
        $values = [$data['nombre'], $data['estatus'] ?? 1];

        $opt = ['direccion' => null, 'delegado' => null, 'telefono' => null, 'email' => null, 'cod_org' => null, 'entidad' => 0, 'logo' => null];
        foreach (array_keys($opt) as $key) {
            if (array_key_exists($key, $data)) {
                $cols[] = $key;
                $placeholders[] = '?';
                $values[] = $data[$key];
            }
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO clubes (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")"
        );
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Actualiza club
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];
        
        $allowedFields = ['nombre', 'direccion', 'delegado', 'telefono', 'email', 'logo', 'estatus', 'cod_org', 'entidad'];
        
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
        
        $sql = "UPDATE clubes SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Elimina club
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM clubes WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Obtiene estadísticas de un club
     */
    public function getStats(int $clubId): array
    {
        // Torneos organizados
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE club_responsable = ?");
        $stmt->execute([$clubId]);
        $torneos = (int) $stmt->fetchColumn();

        // Jugadores inscritos
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE club_id = ?");
        $stmt->execute([$clubId]);
        $jugadores = (int) $stmt->fetchColumn();

        return [
            'torneos_organizados' => $torneos,
            'jugadores_inscritos' => $jugadores
        ];
    }
}


