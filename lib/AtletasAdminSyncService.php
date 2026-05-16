<?php
declare(strict_types=1);

final class AtletasAdminSyncService
{
    /**
     * Homologa textos a UTF-8 en atletas/usuarios para evitar pérdida de caracteres.
     *
     * @return array{
     *   atletas_revisados:int,
     *   atletas_actualizados:int,
     *   usuarios_revisados:int,
     *   usuarios_actualizados:int
     * }
     */
    public static function homologarUtf8AtletasUsuarios(PDO $pdoMain): array
    {
        $out = [
            'atletas_revisados' => 0,
            'atletas_actualizados' => 0,
            'usuarios_revisados' => 0,
            'usuarios_actualizados' => 0,
        ];

        $pdoMain->beginTransaction();
        try {
            $stA = $pdoMain->query("SELECT id, nombre, email, celular FROM atletas ORDER BY id ASC");
            $upA = $pdoMain->prepare("UPDATE atletas SET nombre = ?, email = ?, celular = ? WHERE id = ?");
            foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['atletas_revisados']++;
                $id = (int)($r['id'] ?? 0);
                $nombreOld = (string)($r['nombre'] ?? '');
                $emailOld = (string)($r['email'] ?? '');
                $celOld = (string)($r['celular'] ?? '');

                $nombreNew = self::normalizarTextoUtf8($nombreOld);
                $emailNew = self::normalizarTextoUtf8($emailOld);
                $celNew = self::normalizarTextoUtf8($celOld);

                if ($nombreNew !== $nombreOld || $emailNew !== $emailOld || $celNew !== $celOld) {
                    $upA->execute([$nombreNew, $emailNew, $celNew, $id]);
                    if ($upA->rowCount() > 0) {
                        $out['atletas_actualizados']++;
                    }
                }
            }

            $stU = $pdoMain->query("SELECT id, nombre, username, email, celular FROM usuarios ORDER BY id ASC");
            $upU = $pdoMain->prepare("UPDATE usuarios SET nombre = ?, username = ?, email = ?, celular = ? WHERE id = ?");
            foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['usuarios_revisados']++;
                $id = (int)($r['id'] ?? 0);
                $nombreOld = (string)($r['nombre'] ?? '');
                $userOld = (string)($r['username'] ?? '');
                $emailOld = (string)($r['email'] ?? '');
                $celOld = (string)($r['celular'] ?? '');

                $nombreNew = self::normalizarTextoUtf8($nombreOld);
                $userNew = self::normalizarTextoUtf8($userOld);
                $emailNew = self::normalizarTextoUtf8($emailOld);
                $celNew = self::normalizarTextoUtf8($celOld);

                if ($nombreNew !== $nombreOld || $userNew !== $userOld || $emailNew !== $emailOld || $celNew !== $celOld) {
                    $upU->execute([$nombreNew, $userNew, $emailNew, $celNew, $id]);
                    if ($upU->rowCount() > 0) {
                        $out['usuarios_actualizados']++;
                    }
                }
            }

            $pdoMain->commit();
        } catch (Throwable $e) {
            if ($pdoMain->inTransaction()) {
                $pdoMain->rollBack();
            }
            throw $e;
        }

        return $out;
    }

    /**
     * Crea usuarios faltantes para atletas que aún no existan en usuarios (por cédula),
     * usando el procedimiento estándar Security::createUser.
     *
     * @return array{
     *   atletas_procesados:int,
     *   ya_existian:int,
     *   creados:int,
     *   errores:int,
     *   detalle_errores:list<string>
     * }
     */
    public static function incluirAtletasFaltantesComoUsuarios(PDO $pdoMain): array
    {
        require_once __DIR__ . '/security.php';

        $atletas = $pdoMain->query(
            "SELECT id, cedula, sexo, numfvd, asociacion, nombre, celular, email, fechnac
             FROM atletas
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cedulasUsuarios = [];
        $stmtCed = $pdoMain->query("SELECT cedula FROM usuarios WHERE cedula IS NOT NULL AND cedula != ''");
        foreach ($stmtCed->fetchAll(PDO::FETCH_COLUMN) as $ced) {
            $c = self::normalizarCedula((string)$ced);
            if ($c !== '') {
                $cedulasUsuarios[$c] = true;
            }
        }

        $usernamesUsados = [];
        $stmtUsr = $pdoMain->query("SELECT username FROM usuarios");
        foreach ($stmtUsr->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $uu = trim((string)$u);
            if ($uu !== '') {
                $usernamesUsados[$uu] = true;
            }
        }

        $reporte = [
            'atletas_procesados' => count($atletas),
            'ya_existian' => 0,
            'creados' => 0,
            'errores' => 0,
            'detalle_errores' => [],
        ];

        foreach ($atletas as $a) {
            $cedula = self::normalizarCedula((string)($a['cedula'] ?? ''));
            if ($cedula === '') {
                $reporte['errores']++;
                $reporte['detalle_errores'][] = 'Atleta ID ' . (int)($a['id'] ?? 0) . ': cédula vacía o inválida';
                continue;
            }

            if (isset($cedulasUsuarios[$cedula])) {
                $reporte['ya_existian']++;
                continue;
            }

            $idAtleta = (int)($a['id'] ?? 0);
            $numfvd = (int)($a['numfvd'] ?? 0);
            $clubId = (int)($a['asociacion'] ?? 0);
            $entidad = $clubId;
            $organizacionId = self::resolverOrganizacionPorEntidad($pdoMain, $entidad);
            $sexo = self::normalizarSexo($a['sexo'] ?? 'M');
            $nombre = trim((string)($a['nombre'] ?? ''));
            if ($nombre === '') {
                $nombre = 'Atleta ' . $cedula;
            }
            $nombre = mb_substr($nombre, 0, 62);

            $usernameBase = 'user00' . ($numfvd > 0 ? (string)$numfvd : (string)max(1, $idAtleta));
            $username = self::usernameUnico($usernameBase, $usernamesUsados);

            $email = trim((string)($a['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = $username . '@atletas.local';
            }

            $password = strlen($cedula) >= 6 ? $cedula : str_pad($cedula, 6, '0', STR_PAD_LEFT);

            $data = [
                'username' => $username,
                'password' => $password,
                'role' => 'usuario',
                'status' => 0,
                'nombre' => $nombre,
                'cedula' => $cedula,
                'nacionalidad' => 'V',
                'sexo' => $sexo,
                'numfvd' => $numfvd,
                'email' => $email,
                'celular' => self::nullableString($a['celular'] ?? null),
                'fechnac' => self::normalizarFecha($a['fechnac'] ?? null),
                'club_id' => $clubId,
                'entidad' => $entidad,
                '_allow_club_for_usuario' => true,
            ];
            if (self::usuariosTieneCodOrg($pdoMain)) {
                $data['cod_org'] = $organizacionId;
            }

            $create = Security::createUser($data);
            if (!empty($create['success'])) {
                $newUserId = (int)($create['user_id'] ?? 0);
                if ($newUserId > 0) {
                    if (self::usuariosTieneCodOrg($pdoMain)) {
                        $upd = $pdoMain->prepare('UPDATE usuarios SET numfvd = ?, cod_org = ? WHERE id = ?');
                        $upd->execute([$numfvd, $organizacionId, $newUserId]);
                    } else {
                        $upd = $pdoMain->prepare('UPDATE usuarios SET numfvd = ? WHERE id = ?');
                        $upd->execute([$numfvd, $newUserId]);
                    }
                }
                $reporte['creados']++;
                $cedulasUsuarios[$cedula] = true;
                continue;
            }

            $reporte['errores']++;
            $reporte['detalle_errores'][] = 'Atleta ID ' . $idAtleta . ' (cédula ' . $cedula . '): ' . implode(', ', (array)($create['errors'] ?? ['error desconocido']));
        }

        return $reporte;
    }

    /**
     * Copia completa de tabla atletas: BD secundaria -> BD principal.
     *
     * @return array{copiados:int,columnas:list<string>}
     */
    public static function copiarAtletasDesdeConverma(PDO $pdoMain, PDO $pdoSecondary): array
    {
        $colsMain = self::getColumns($pdoMain, 'atletas');
        $colsSec = self::getColumns($pdoSecondary, 'atletas');
        $columnas = array_values(array_intersect($colsMain, $colsSec));
        if ($columnas === []) {
            throw new RuntimeException('No hay columnas comunes entre atletas (converma/mistorneos).');
        }

        $selectCols = implode(', ', array_map(static fn ($c) => "`{$c}`", $columnas));
        $stmtRead = $pdoSecondary->query("SELECT {$selectCols} FROM atletas ORDER BY id ASC");
        $rows = $stmtRead->fetchAll(PDO::FETCH_ASSOC);

        $pdoMain->beginTransaction();
        try {
            $pdoMain->exec("DELETE FROM atletas");

            if (!empty($rows)) {
                $insertCols = implode(', ', array_map(static fn ($c) => "`{$c}`", $columnas));
                $placeholders = implode(', ', array_fill(0, count($columnas), '?'));
                $stmtInsert = $pdoMain->prepare("INSERT INTO atletas ({$insertCols}) VALUES ({$placeholders})");
                foreach ($rows as $row) {
                    $params = [];
                    foreach ($columnas as $c) {
                        $val = $row[$c] ?? null;
                        if (is_string($val)) {
                            $val = self::normalizarTextoUtf8($val);
                        }
                        $params[] = $val;
                    }
                    $stmtInsert->execute($params);
                }
            }

            $pdoMain->commit();
        } catch (Throwable $e) {
            if ($pdoMain->inTransaction()) {
                $pdoMain->rollBack();
            }
            throw $e;
        }

        return [
            'copiados' => count($rows),
            'columnas' => $columnas,
        ];
    }

    /**
     * Sincroniza usuarios desde atletas por cédula y genera reporte.
     *
     * @return array{
     *   total_atletas:int,
     *   coincidencias:int,
     *   actualizados:int,
     *   sin_cambios:int,
     *   no_encontradas:int,
     *   celulares_actualizados:int,
     *   email_actualizados:int,
     *   fechnac_actualizados:int,
     *   club_id_actualizados:int,
     *   numfvd_actualizados:int,
     *   sexo_actualizados:int,
     *   por_club:array<int,array{total:int,m:int,f:int,o:int}>,
     *   csv_path:string
     * }
     */
    public static function sincronizarUsuariosDesdeAtletas(PDO $pdoMain, string $csvDir): array
    {
        $hasUsuarioCodOrg = self::usuariosTieneCodOrg($pdoMain);
        $selectUsuCols = $hasUsuarioCodOrg
            ? 'id, cedula, sexo, numfvd, club_id, entidad, cod_org, celular, email, fechnac'
            : 'id, cedula, sexo, numfvd, club_id, entidad, celular, email, fechnac';

        $usuarios = $pdoMain->query(
            "SELECT {$selectUsuCols} FROM usuarios"
        )->fetchAll(PDO::FETCH_ASSOC);

        $usuariosPorCedula = [];
        foreach ($usuarios as $u) {
            $ced = self::normalizarCedula((string)($u['cedula'] ?? ''));
            if ($ced !== '') {
                $usuariosPorCedula[$ced] = $u;
            }
        }

        $atletas = $pdoMain->query(
            "SELECT id, cedula, sexo, numfvd, asociacion, celular, email, fechnac FROM atletas"
        )->fetchAll(PDO::FETCH_ASSOC);

        $reporte = [
            'total_atletas' => count($atletas),
            'coincidencias' => 0,
            'actualizados' => 0,
            'sin_cambios' => 0,
            'no_encontradas' => 0,
            'celulares_actualizados' => 0,
            'email_actualizados' => 0,
            'fechnac_actualizados' => 0,
            'club_id_actualizados' => 0,
            'entidad_actualizados' => 0,
            'cod_org_actualizados' => 0,
            'numfvd_actualizados' => 0,
            'sexo_actualizados' => 0,
            'por_club' => [],
            'csv_path' => '',
        ];

        $noEncontradas = [];

        $stmtUpdate = $hasUsuarioCodOrg
            ? $pdoMain->prepare(
                "UPDATE usuarios
             SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, cod_org = ?, celular = ?, email = ?, fechnac = ?
             WHERE id = ?"
            )
            : $pdoMain->prepare(
                "UPDATE usuarios
             SET sexo = ?, numfvd = ?, club_id = ?, entidad = ?, celular = ?, email = ?, fechnac = ?
             WHERE id = ?"
            );

        $pdoMain->beginTransaction();
        try {
            foreach ($atletas as $a) {
                $ced = self::normalizarCedula((string)($a['cedula'] ?? ''));
                if ($ced === '' || !isset($usuariosPorCedula[$ced])) {
                    $reporte['no_encontradas']++;
                    $noEncontradas[] = [
                        'cedula' => $ced,
                        'atleta_id' => (int)($a['id'] ?? 0),
                        'numfvd' => (string)($a['numfvd'] ?? ''),
                    ];
                    continue;
                }

                $reporte['coincidencias']++;
                $u = $usuariosPorCedula[$ced];
                $uid = (int)($u['id'] ?? 0);

                $nuevoSexo = self::normalizarSexo($a['sexo'] ?? 'M');
                $nuevoNumfvd = (int)($a['numfvd'] ?? 0);
                $nuevaEntidad = (int)($a['asociacion'] ?? 0);
                $nuevoClubId = $nuevaEntidad; // Primera fase: club_id queda con código de entidad
                $nuevaOrganizacionId = self::resolverOrganizacionPorEntidad($pdoMain, $nuevaEntidad);
                $nuevoCel = self::nullableString($a['celular'] ?? null);
                $nuevoEmail = self::nullableString($a['email'] ?? null);
                $nuevaFechnac = self::normalizarFecha($a['fechnac'] ?? null);

                $oldSexo = self::normalizarSexo($u['sexo'] ?? 'M');
                $oldNumfvd = (int)($u['numfvd'] ?? 0);
                $oldClubId = (int)($u['club_id'] ?? 0);
                $oldEntidad = (int)($u['entidad'] ?? 0);
                $oldOrganizacionId = $hasUsuarioCodOrg ? (int)($u['cod_org'] ?? 0) : 0;
                $oldCel = self::nullableString($u['celular'] ?? null);
                $oldEmail = self::nullableString($u['email'] ?? null);
                $oldFechnac = self::normalizarFecha($u['fechnac'] ?? null);

                $huboCambio = false;
                if ($oldSexo !== $nuevoSexo) {
                    $reporte['sexo_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldNumfvd !== $nuevoNumfvd) {
                    $reporte['numfvd_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldClubId !== $nuevoClubId) {
                    $reporte['club_id_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldEntidad !== $nuevaEntidad) {
                    $reporte['entidad_actualizados']++;
                    $huboCambio = true;
                }
                if ($hasUsuarioCodOrg && $oldOrganizacionId !== $nuevaOrganizacionId) {
                    $reporte['cod_org_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldCel !== $nuevoCel) {
                    $reporte['celulares_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldEmail !== $nuevoEmail) {
                    $reporte['email_actualizados']++;
                    $huboCambio = true;
                }
                if ($oldFechnac !== $nuevaFechnac) {
                    $reporte['fechnac_actualizados']++;
                    $huboCambio = true;
                }

                $clubKey = $nuevoClubId;
                if (!isset($reporte['por_club'][$clubKey])) {
                    $reporte['por_club'][$clubKey] = ['total' => 0, 'm' => 0, 'f' => 0, 'o' => 0];
                }
                $reporte['por_club'][$clubKey]['total']++;
                if ($nuevoSexo === 'F') {
                    $reporte['por_club'][$clubKey]['f']++;
                } elseif ($nuevoSexo === 'O') {
                    $reporte['por_club'][$clubKey]['o']++;
                } else {
                    $reporte['por_club'][$clubKey]['m']++;
                }

                if (!$huboCambio) {
                    $reporte['sin_cambios']++;
                    continue;
                }

                if ($hasUsuarioCodOrg) {
                    $stmtUpdate->execute([
                        $nuevoSexo,
                        $nuevoNumfvd,
                        $nuevoClubId,
                        $nuevaEntidad,
                        $nuevaOrganizacionId,
                        $nuevoCel,
                        $nuevoEmail,
                        $nuevaFechnac,
                        $uid,
                    ]);
                } else {
                    $stmtUpdate->execute([
                        $nuevoSexo,
                        $nuevoNumfvd,
                        $nuevoClubId,
                        $nuevaEntidad,
                        $nuevoCel,
                        $nuevoEmail,
                        $nuevaFechnac,
                        $uid,
                    ]);
                }
                if ($stmtUpdate->rowCount() > 0) {
                    $reporte['actualizados']++;
                }
            }

            $pdoMain->commit();
        } catch (Throwable $e) {
            if ($pdoMain->inTransaction()) {
                $pdoMain->rollBack();
            }
            throw $e;
        }

        ksort($reporte['por_club']);
        $reporte['csv_path'] = self::guardarCsvNoEncontradas($csvDir, $noEncontradas);
        return $reporte;
    }

    private static function usuariosTieneCodOrg(PDO $pdo): bool
    {
        static $cached = [];
        $key = spl_object_hash($pdo);
        if (array_key_exists($key, $cached)) {
            return $cached[$key];
        }
        try {
            $cached[$key] = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            $cached[$key] = false;
        }

        return $cached[$key];
    }

    private static function getColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') {
                $cols[] = $f;
            }
        }
        return $cols;
    }

    private static function guardarCsvNoEncontradas(string $dir, array $rows): string
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cedulas_no_encontradas_' . date('Ymd_His') . '.csv';
        $fh = fopen($path, 'w');
        if ($fh === false) {
            return '';
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['cedula', 'atleta_id', 'numfvd']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['cedula'] ?? '', $r['atleta_id'] ?? 0, $r['numfvd'] ?? '']);
        }
        fclose($fh);
        return $path;
    }

    private static function normalizarCedula(string $cedula): string
    {
        return preg_replace('/\D/', '', $cedula) ?? '';
    }

    private static function normalizarSexo($sexo): string
    {
        $s = strtoupper(trim((string)$sexo));
        if (in_array($s, ['M', 'F', 'O'], true)) {
            return $s;
        }
        if ($s === '2' || strpos($s, 'F') !== false) {
            return 'F';
        }
        if ($s === '3' || strpos($s, 'O') !== false) {
            return 'O';
        }
        return 'M';
    }

    private static function normalizarFecha($fecha): ?string
    {
        $s = trim((string)$fecha);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    private static function nullableString($value): ?string
    {
        $s = self::normalizarTextoUtf8(trim((string)$value));
        return $s === '' ? null : $s;
    }

    /**
     * Convierte texto a UTF-8 válido, quita BOM e intenta Latin-1/Windows-1252.
     */
    private static function normalizarTextoUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $s = substr($s, 3);
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
            if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
                $enc = mb_detect_encoding($s, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15'], true);
                if ($enc !== false && $enc !== 'UTF-8') {
                    $s = mb_convert_encoding($s, 'UTF-8', $enc);
                } else {
                    $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
                }
            } elseif (function_exists('iconv')) {
                $tmp = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
                if ($tmp !== false) {
                    $s = $tmp;
                }
            }
        }
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n) && $n !== '') {
                $s = $n;
            }
        }
        return trim($s);
    }

    private static function resolverOrganizacionPorEntidad(PDO $pdoMain, int $entidad): int
    {
        if ($entidad <= 0) {
            return 0;
        }
        $stmt = $pdoMain->prepare(
            "SELECT id
             FROM organizaciones
             WHERE entidad = ?
             ORDER BY estatus DESC, id ASC
             LIMIT 1"
        );
        $stmt->execute([$entidad]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function usernameUnico(string $base, array &$usados): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_\.]/', '', $base) ?: 'user00';
        $username = $base;
        $i = 2;
        while (isset($usados[$username])) {
            $username = $base . '_' . $i;
            $i++;
        }
        $usados[$username] = true;
        return $username;
    }
}

