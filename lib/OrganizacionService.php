<?php
declare(strict_types=1);

/**
 * Acceso a datos de organizaciones (asociaciones afiliadas).
 */
final class OrganizacionService
{
    /**
     * @return object|null Objeto con al menos id, nombre, entidad.
     */
    public static function getById(int $orgId): ?object
    {
        if ($orgId <= 0) {
            return null;
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }

        $pdo = DB::pdo();

        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            if ($hasCodOrg) {
                $stmt = $pdo->prepare(
                    'SELECT id, nombre, entidad, estatus, cod_org, admin_user_id,
                            direccion, responsable, telefono, email, logo
                     FROM organizaciones
                     WHERE (id = ? OR cod_org = ?) AND estatus = 1
                     LIMIT 1'
                );
                $stmt->execute([$orgId, $orgId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, nombre, entidad, estatus, admin_user_id,
                            direccion, responsable, telefono, email, logo
                     FROM organizaciones
                     WHERE id = ? AND estatus = 1
                     LIMIT 1'
                );
                $stmt->execute([$orgId]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (! $row) {
                return null;
            }

            return (object) $row;
        } catch (Throwable $e) {
            error_log('OrganizacionService::getById: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Asociaciones territoriales (tipo_org = 0), activas e inactivas, para listado admin.
     *
     * @return list<array<string, mixed>>
     */
    public static function getAllAfiliadas(): array
    {
        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }
        require_once __DIR__ . '/OrganizacionDashboardStats.php';

        $pdo = DB::pdo();
        $sqlSoloAsoc = OrganizacionDashboardStats::sqlWhereSoloAsociaciones($pdo, 'o');

        try {
            $sql = "SELECT o.id, o.nombre, o.entidad, o.estatus, o.cod_org,
                           e.nombre AS entidad_nombre
                    FROM organizaciones o
                    LEFT JOIN entidad e ON o.entidad = e.id
                    WHERE ({$sqlSoloAsoc})
                    ORDER BY o.estatus DESC, e.nombre ASC, o.nombre ASC";
            $stmt = $pdo->query($sql);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('OrganizacionService::getAllAfiliadas: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Activa o desactiva una asociación territorial (solo tipo_org = 0).
     */
    public static function setEstatus(int $orgId, int $estatus): bool
    {
        if ($orgId <= 0) {
            return false;
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }
        require_once __DIR__ . '/OrganizacionDashboardStats.php';

        $pdo = DB::pdo();
        $estatus = $estatus === 1 ? 1 : 0;
        $sqlSoloAsoc = OrganizacionDashboardStats::sqlWhereSoloAsociaciones($pdo, 'organizaciones');

        try {
            $stmt = $pdo->prepare(
                "UPDATE organizaciones SET estatus = ?, updated_at = NOW()
                 WHERE id = ? AND ({$sqlSoloAsoc})"
            );

            return $stmt->execute([$estatus, $orgId]) && $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('OrganizacionService::setEstatus: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Campos editables desde el hub (whitelist).
     *
     * @return list<string>
     */
    private static function editableColumnNames(PDO $pdo): array
    {
        $allowed = ['nombre', 'email', 'telefono', 'direccion', 'logo'];
        $existing = [];
        foreach ($allowed as $col) {
            try {
                $st = $pdo->query("SHOW COLUMNS FROM organizaciones LIKE '{$col}'");
                if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                    $existing[] = $col;
                }
            } catch (Throwable $ignored) {
            }
        }

        return $existing;
    }

    /**
     * Actualiza datos permitidos de una organización activa.
     *
     * @param array<string, mixed> $data Claves: nombre, email, telefono, direccion, logo (o logo_url)
     */
    public static function update(int $orgId, array $data): bool
    {
        if ($orgId <= 0) {
            return false;
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }

        $pdo = DB::pdo();

        if (self::getById($orgId) === null) {
            return false;
        }

        if (isset($data['logo_url']) && ! isset($data['logo'])) {
            $data['logo'] = $data['logo_url'];
        }
        unset($data['logo_url']);

        $columns = self::editableColumnNames($pdo);
        if ($columns === []) {
            return false;
        }

        $sets = [];
        $params = [];
        foreach ($columns as $col) {
            if (! array_key_exists($col, $data)) {
                continue;
            }
            $value = $data[$col];
            if ($col === 'nombre') {
                $value = trim((string) $value);
                if ($value === '') {
                    return false;
                }
            } else {
                $value = trim((string) $value);
                if ($col === 'email' && $value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return false;
                }
            }
            $sets[] = "`{$col}` = ?";
            $params[] = $value === '' ? null : $value;
        }

        if ($sets === []) {
            return false;
        }

        $sets[] = 'updated_at = NOW()';
        $params[] = $orgId;

        try {
            $sql = 'UPDATE organizaciones SET ' . implode(', ', $sets) . ' WHERE id = ? AND estatus = 1';
            $stmt = $pdo->prepare($sql);

            return $stmt->execute($params);
        } catch (Throwable $e) {
            error_log('OrganizacionService::update: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * URL pública para mostrar el logo almacenado (ruta relativa o URL absoluta).
     */
    public static function logoPublicUrl(?string $logoPath): string
    {
        $logoPath = trim((string) $logoPath);
        if ($logoPath === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $logoPath)) {
            return $logoPath;
        }
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        return class_exists('AppHelpers', false) ? AppHelpers::imageUrl($logoPath) : '';
    }

    /**
     * Guarda archivo de logo subido y elimina el anterior si aplica.
     *
     * @param array<string, mixed> $file Fila de $_FILES['logo']
     * @return string Ruta relativa guardada (upload/organizaciones/…)
     */
    public static function saveLogoFromUpload(int $orgId, array $file): string
    {
        if ($orgId <= 0) {
            throw new InvalidArgumentException('Organización no válida.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se recibió un archivo de logo válido.');
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || ! is_uploaded_file($tmp)) {
            throw new RuntimeException('Archivo de logo no válido.');
        }

        $fileType = mime_content_type($tmp) ?: '';
        if (! in_array($fileType, $allowedTypes, true)) {
            throw new RuntimeException('Tipo de archivo no permitido. Use JPG, PNG, GIF o WEBP.');
        }

        $uploadDir = dirname(__DIR__) . '/upload/organizaciones/';
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true) && ! is_dir($uploadDir)) {
            throw new RuntimeException('No se pudo crear la carpeta de logos.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            if ($fileType === 'image/png') {
                $extension = 'png';
            } elseif ($fileType === 'image/gif') {
                $extension = 'gif';
            } elseif ($fileType === 'image/webp') {
                $extension = 'webp';
            } else {
                $extension = 'jpg';
            }
        }

        $filename = 'org_' . $orgId . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        if (! move_uploaded_file($tmp, $filepath)) {
            throw new RuntimeException('Error al guardar el logo.');
        }

        $relativePath = 'upload/organizaciones/' . $filename;

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT logo FROM organizaciones WHERE id = ? LIMIT 1');
        $stmt->execute([$orgId]);
        $oldLogo = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($oldLogo !== '' && ! preg_match('#^https?://#i', $oldLogo)) {
            $oldFull = dirname(__DIR__) . '/' . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $oldLogo), DIRECTORY_SEPARATOR);
            if (is_file($oldFull)) {
                @unlink($oldFull);
            }
        }

        return $relativePath;
    }
}
