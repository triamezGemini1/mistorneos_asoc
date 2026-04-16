<?php
/**
 * Carga masiva equipos (4 integrantes): validación previa, borrado inscritos/equipos del torneo con confirmación, GuardarEquipoSitioService.
 */
declare(strict_types=1);

final class CargaMasivaEquiposSitioService
{
    public const JUGADORES_REQUERIDOS = 4;
    private static ?bool $hasCodOrgColumn = null;

    private static function hasCodOrg(PDO $pdo): bool
    {
        if (self::$hasCodOrgColumn !== null) {
            return self::$hasCodOrgColumn;
        }
        try {
            self::$hasCodOrgColumn = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$hasCodOrgColumn = false;
        }
        return self::$hasCodOrgColumn;
    }

    /** Frase exacta que el operador debe enviar para ejecutar el reemplazo total. */
    public const CONFIRMACION_REEMPLAZO = 'SI_REEMPLAZAR_INSCRITOS_Y_EQUIPOS';

    /**
     * CSV de plantilla (una línea de encabezados + ejemplo).
     */
    public static function contenidoPlantillaCsv(): string
    {
        // Columna «club»: id numérico en tabla clubes (mismo criterio que Excel de carga; se usa como prefijo del código de equipo).
        $enc = 'NAC,Cedula,,N1,,sexo,fecha_nac,telefono,email,equipo,club,organizacion';
        $ejR = 'R,,,,,,,,,EQUIPO EJEMPLO,42,NOMBRE ORG';
        $ej1 = ',V12345678,,JUAN PEREZ,,M,1990-05-10,04140000000,juan@mail.com,,,';
        $ej2 = ',V87654321,,MARIA LOPEZ,,F,1992-01-20,,maria@mail.com,,,';
        $ej3 = ',V11111111,,PEDRO RUIZ,,M,,,,,,,';
        $ej4 = ',V22222222,,ANA GOMEZ,,F,,,,,,,';
        return "\xEF\xBB\xBF" . implode("\n", [$enc, $ejR, $ej1, $ej2, $ej3, $ej4]) . "\n";
    }

    /**
     * @return array{filas: list<array<int,string>>, map: array<string,int>, bloques: list<array>, error?: string}
     */
    public static function parseArchivo(string $tmpPath, string $originalName): array
    {
        require_once __DIR__ . '/EquiposHelper.php';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || $ext === 'tsv') {
            $ext = 'txt';
        }
        $errLectura = null;
        $rows = self::leerFilas($tmpPath, $ext, $errLectura);
        if ($rows === []) {
            $msg = 'No se pudo leer el archivo o no tiene filas con datos.';
            if ($errLectura) {
                $msg .= ' ' . $errLectura;
            } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
                $msg .= ' Si es Excel: use .xlsx (no .xls antiguo sin PhpSpreadsheet), primera hoja con datos, o guarde como CSV UTF-8 e intente de nuevo.';
            }
            return ['filas' => [], 'map' => [], 'bloques' => [], 'error' => $msg];
        }
        $header = array_shift($rows);
        $map = self::mapearCabeceras($header);
        $bloques = self::agruparPorEquipo($rows, $map);
        return ['filas' => $rows, 'map' => $map, 'bloques' => $bloques];
    }

    /**
     * @param list<array> $bloques
     * @return array{
     *   puede_proceder: bool,
     *   cedulas_duplicadas: list<array{cedula: string, apariciones: list<array{equipo: string, linea: int}>}>,
     *   equipos_incompletos: list<array{equipo: string, linea_inicio: int, integrantes: int, requeridos: int, detalle: string}>,
     *   bloques_sin_r: list<string>,
     *   clubs_excel_invalidos: list<array{equipo: string, linea_inicio: int, detalle: string}>,
     *   resumen: array{equipos_en_archivo: int, total_inscritos_torneo: int, total_equipos_torneo: int}
     * }
     */
    public static function validarPrevio(PDO $pdo, int $torneo_id, array $bloques): array
    {
        $cedulaApariciones = [];
        $equiposIncompletos = [];
        $bloquesSinR = [];
        $clubsExcelInvalidos = [];
        $reporteEquipos = [];

        foreach ($bloques as $bloque) {
            $nombreEquipo = $bloque['nombre_equipo'];
            $linea = $bloque['linea_inicio'];
            $miembros = $bloque['miembros'];
            if ($nombreEquipo === '' && count($miembros) === 0) {
                continue;
            }
            if ($nombreEquipo === '') {
                $bloquesSinR[] = "Bloque sin fila de equipo (R) cerca de línea {$linea}";
                continue;
            }

            $codigoColumna = self::resolverIdClubDesdeBloque($bloque);
            $resClub = self::validarResolverClubDesdeExcel($pdo, $codigoColumna);
            $erroresEquipo = [];
            if (!$resClub['ok']) {
                $clubsExcelInvalidos[] = [
                    'equipo' => $nombreEquipo,
                    'linea_inicio' => $linea,
                    'detalle' => $resClub['detalle'],
                ];
                $erroresEquipo[] = [
                    'tipo' => 'club',
                    'detalle' => $resClub['detalle'],
                    'como_resolver' => 'Revise la columna club del archivo. Debe contener un id de club activo existente en la tabla clubes.',
                ];
            }
            $validos = 0;
            $integrantesReporte = [];
            foreach ($miembros as $m) {
                $c = trim((string)($m['cedula'] ?? ''));
                $n = trim((string)($m['n1'] ?? ''));
                $completo = ($c !== '' && $n !== '');
                $integrantesReporte[] = [
                    'cedula' => $c,
                    'nombre' => $n,
                    'completo' => $completo,
                    'id_usuario' => self::idUsuarioPorCedula($pdo, $c),
                    'numfvd' => self::numfvdPorCedula($pdo, $c),
                ];
                if ($c !== '' && $n !== '') {
                    $validos++;
                    $cNorm = self::normalizarCedula($c);
                    if (!isset($cedulaApariciones[$cNorm])) {
                        $cedulaApariciones[$cNorm] = [];
                    }
                    $cedulaApariciones[$cNorm][] = ['cedula' => $c, 'equipo' => $nombreEquipo, 'linea' => $linea];
                }
            }
            if ($validos !== self::JUGADORES_REQUERIDOS) {
                $faltan = self::JUGADORES_REQUERIDOS - $validos;
                $equiposIncompletos[] = [
                    'equipo' => $nombreEquipo,
                    'linea_inicio' => $linea,
                    'integrantes' => $validos,
                    'requeridos' => self::JUGADORES_REQUERIDOS,
                    'detalle' => $validos < self::JUGADORES_REQUERIDOS
                        ? "Faltan {$faltan} jugador(es) con Cedula y N1 completos."
                        : "Sobran " . ($validos - self::JUGADORES_REQUERIDOS) . " fila(s) con datos válidos (máximo " . self::JUGADORES_REQUERIDOS . ").",
                ];
                $erroresEquipo[] = [
                    'tipo' => 'integrantes',
                    'detalle' => $validos < self::JUGADORES_REQUERIDOS
                        ? "Tiene {$validos} integrantes válidos de " . self::JUGADORES_REQUERIDOS . '.'
                        : "Tiene más de " . self::JUGADORES_REQUERIDOS . " integrantes válidos.",
                    'como_resolver' => 'Deje exactamente 4 jugadores por equipo, cada uno con cédula y nombre completos.',
                ];
            }
            $reporteEquipos[] = [
                'equipo' => $nombreEquipo,
                'linea_inicio' => $linea,
                'club_excel' => $codigoColumna,
                'club_resuelto' => (int)($resClub['club_id'] ?? 0),
                'integrantes' => $integrantesReporte,
                'ok' => $erroresEquipo === [],
                'errores' => $erroresEquipo,
            ];
        }

        $duplicadas = [];
        foreach ($cedulaApariciones as $norm => $aps) {
            if (count($aps) > 1) {
                $duplicadas[] = [
                    'cedula' => $aps[0]['cedula'],
                    'apariciones' => array_map(static fn ($a) => ['equipo' => $a['equipo'], 'linea' => $a['linea']], $aps),
                ];
            }
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscritos WHERE torneo_id = ?');
        $stmt->execute([$torneo_id]);
        $nInsc = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM equipos WHERE id_torneo = ?');
        $stmt->execute([$torneo_id]);
        $nEq = (int)$stmt->fetchColumn();

        if (count($bloques) === 0) {
            $bloquesSinR[] = 'No se encontró ningún bloque: use NAC=R + equipo + club, o fila con 0 en cédula y nombre del equipo en N1 (formato ADEAZ/TSV).';
        }
        $puede = $duplicadas === [] && $equiposIncompletos === [] && $bloquesSinR === [] && $clubsExcelInvalidos === [] && count($bloques) > 0;

        return [
            'puede_proceder' => $puede,
            'cedulas_duplicadas' => $duplicadas,
            'equipos_incompletos' => $equiposIncompletos,
            'bloques_sin_r' => $bloquesSinR,
            'clubs_excel_invalidos' => $clubsExcelInvalidos,
            'resumen' => [
                'equipos_en_archivo' => count($bloques),
                'total_inscritos_torneo' => $nInsc,
                'total_equipos_torneo' => $nEq,
            ],
            'reporte_detallado' => [
                'equipos' => $reporteEquipos,
                'recomendaciones_generales' => [
                    'Verifique que cada equipo tenga exactamente 4 integrantes completos (cédula y nombre).',
                    'Corrija cédulas duplicadas en todo el archivo antes de ejecutar.',
                    'Asegure que la columna club contenga id de club activo (tabla clubes).',
                ],
            ],
        ];
    }

    /**
     * @return array{success:bool,message:string,...}
     */
    public static function ejecutarDesdeArchivo(
        PDO $pdo,
        int $torneo_id,
        string $tmpPath,
        string $originalName,
        ?int $creado_por,
        string $confirmacion
    ): array {
        require_once __DIR__ . '/GuardarEquipoSitioService.php';
        require_once __DIR__ . '/EquiposHelper.php';
        require_once __DIR__ . '/security.php';

        if (!hash_equals(self::CONFIRMACION_REEMPLAZO, $confirmacion)) {
            return [
                'success' => false,
                'message' => 'Debe confirmar el reemplazo total con la frase indicada en pantalla.',
                'equipos_procesados' => 0,
                'equipos_ok' => 0,
                'equipos_error' => 0,
                'detalles' => [],
            ];
        }

        $stmt = $pdo->prepare('SELECT id, modalidad, cod_org, club_responsable FROM tournaments WHERE id = ?');
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$torneo || (int)$torneo['modalidad'] !== EquiposHelper::MODALIDAD_EQUIPOS) {
            return [
                'success' => false,
                'message' => 'Torneo no existe o no es modalidad equipos.',
                'equipos_procesados' => 0,
                'equipos_ok' => 0,
                'equipos_error' => 0,
                'detalles' => [],
            ];
        }
        $parsed = self::parseArchivo($tmpPath, $originalName);
        if (isset($parsed['error'])) {
            return [
                'success' => false,
                'message' => $parsed['error'],
                'equipos_procesados' => 0,
                'equipos_ok' => 0,
                'equipos_error' => 0,
                'detalles' => [],
            ];
        }
        $bloques = $parsed['bloques'];
        $val = self::validarPrevio($pdo, $torneo_id, $bloques);
        if (!$val['puede_proceder']) {
            return [
                'success' => false,
                'message' => 'Validación fallida: corrija cédulas duplicadas, equipos incompletos, columna club (id numérico en tabla clubes) o formato y vuelva a validar.',
                'validacion' => $val,
                'equipos_procesados' => 0,
                'equipos_ok' => 0,
                'equipos_error' => 0,
                'detalles' => [],
            ];
        }

        $detalles = [];
        $reporteProceso = [];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM inscritos WHERE torneo_id = ?')->execute([$torneo_id]);
            $pdo->prepare('DELETE FROM equipos WHERE id_torneo = ?')->execute([$torneo_id]);
            $ok = 0;
            $err = 0;
            foreach ($bloques as $bloque) {
                $nombreEquipo = $bloque['nombre_equipo'];
                $linea = $bloque['linea_inicio'];
                $miembros = $bloque['miembros'];
                $integrantesReporte = [];
                foreach ($miembros as $m) {
                    $cedulaR = trim((string)($m['cedula'] ?? ''));
                    $nombreR = trim(self::normalizarTextoUtf8((string)($m['n1'] ?? '')));
                    $integrantesReporte[] = [
                        'cedula' => $cedulaR,
                        'nombre' => $nombreR,
                        'completo' => ($cedulaR !== '' && $nombreR !== ''),
                        'id_usuario' => self::idUsuarioPorCedula($pdo, $cedulaR),
                        'numfvd' => self::numfvdPorCedula($pdo, $cedulaR),
                    ];
                }

                $codigoColumna = self::resolverIdClubDesdeBloque($bloque);
                $resClub = self::validarResolverClubDesdeExcel($pdo, $codigoColumna);
                if (!$resClub['ok']) {
                    $err++;
                    $detalles[] = [
                        'equipo' => $nombreEquipo,
                        'ok' => false,
                        'message' => $resClub['detalle'],
                        'linea_inicio' => $linea,
                    ];
                    $reporteProceso[] = [
                        'equipo' => $nombreEquipo,
                        'linea_inicio' => $linea,
                        'club_excel' => $codigoColumna,
                        'club_resuelto' => 0,
                        'integrantes' => $integrantesReporte,
                        'ok' => false,
                        'error' => $resClub['detalle'],
                        'como_resolver' => 'Corrija el id de club en la columna club y vuelva a ejecutar.',
                    ];
                    continue;
                }
                $club_id = $resClub['club_id'];
                $entidad_club = self::entidadDesdeClubId($pdo, $club_id);

                $jugadores = [];
                foreach ($miembros as $m) {
                    $cedula = trim((string)($m['cedula'] ?? ''));
                    $nombre = trim(self::normalizarTextoUtf8((string)($m['n1'] ?? '')));
                    if ($cedula === '' || $nombre === '') {
                        continue;
                    }
                    self::asegurarUsuarioAfiliado($pdo, $cedula, $nombre, $club_id, $m, $entidad_club);
                    $jugadores[] = ['cedula' => $cedula, 'nombre' => $nombre, 'id_usuario' => 0, 'id_inscrito' => 0];
                }

                // Tras crear/afiliar usuarios faltantes, recalcular ids para reporte y verificación post-grabado
                // (el primer armado de $integrantesReporte ocurre antes de asegurarUsuarioAfiliado y quedaba con id_usuario=0).
                $integrantesReporte = [];
                foreach ($miembros as $m) {
                    $cedulaR = trim((string)($m['cedula'] ?? ''));
                    $nombreR = trim(self::normalizarTextoUtf8((string)($m['n1'] ?? '')));
                    $integrantesReporte[] = [
                        'cedula' => $cedulaR,
                        'nombre' => $nombreR,
                        'completo' => ($cedulaR !== '' && $nombreR !== ''),
                        'id_usuario' => self::idUsuarioPorCedula($pdo, $cedulaR),
                        'numfvd' => self::numfvdPorCedula($pdo, $cedulaR),
                    ];
                }

                $prefPlantilla = trim((string)($bloque['codigo_club_prefijo'] ?? ''));
                $input = [
                    'torneo_id' => $torneo_id,
                    'equipo_id' => 0,
                    'nombre_equipo' => $nombreEquipo,
                    'club_id' => $club_id,
                    'codigo_club_prefijo' => $prefPlantilla,
                    'jugadores' => $jugadores,
                ];
                try {
                    $out = GuardarEquipoSitioService::ejecutar($pdo, $input, $creado_por);
                    if (!empty($out['success'])) {
                        $ok++;
                        $detalles[] = ['equipo' => $nombreEquipo, 'ok' => true, 'message' => $out['message'] ?? 'OK', 'linea_inicio' => $linea];
                        $reporteProceso[] = [
                            'equipo' => $nombreEquipo,
                            'linea_inicio' => $linea,
                            'club_excel' => $codigoColumna,
                            'club_resuelto' => (int)$club_id,
                            'integrantes' => $integrantesReporte,
                            'ok' => true,
                            'error' => '',
                            'como_resolver' => '',
                        ];
                        $verif = self::verificarRegistrosEquipoGrabados($pdo, $torneo_id, $integrantesReporte);
                        if (!$verif['ok']) {
                            throw new RuntimeException('Verificación post-grabado falló para ' . $nombreEquipo . ': ' . $verif['detalle']);
                        }
                    } else {
                        $err++;
                        $msgErr = $out['message'] ?? 'Error';
                        $detalles[] = ['equipo' => $nombreEquipo, 'ok' => false, 'message' => $msgErr, 'linea_inicio' => $linea];
                        $reporteProceso[] = [
                            'equipo' => $nombreEquipo,
                            'linea_inicio' => $linea,
                            'club_excel' => $codigoColumna,
                            'club_resuelto' => (int)$club_id,
                            'integrantes' => $integrantesReporte,
                            'ok' => false,
                            'error' => $msgErr,
                            'como_resolver' => 'Revise los integrantes del equipo (cédulas/nombres/duplicados) y la consistencia del club antes de reintentar.',
                        ];
                    }
                } catch (Throwable $e) {
                    $err++;
                    $msgErr = $e->getMessage();
                    $detalles[] = ['equipo' => $nombreEquipo, 'ok' => false, 'message' => $msgErr, 'linea_inicio' => $linea];
                    $reporteProceso[] = [
                        'equipo' => $nombreEquipo,
                        'linea_inicio' => $linea,
                        'club_excel' => $codigoColumna,
                        'club_resuelto' => (int)$club_id,
                        'integrantes' => $integrantesReporte,
                        'ok' => false,
                        'error' => $msgErr,
                        'como_resolver' => 'Corrija el problema indicado y vuelva a ejecutar la carga con un archivo validado.',
                    ];
                }
            }
            if ($err > 0) {
                throw new RuntimeException('Se detectaron errores durante la carga. La transacción fue revertida para evitar inconsistencias entre torneos.');
            }
            $pdo->commit();
            $total = count($bloques);
            return [
                'success' => $ok > 0 && $err === 0,
                'message' => "Reemplazo ejecutado. Equipos en archivo: {$total}. OK: {$ok}. Errores: {$err}.",
                'equipos_procesados' => $total,
                'equipos_ok' => $ok,
                'equipos_error' => $err,
                'detalles' => $detalles,
                'reporte_proceso' => [
                    'resumen' => ['total' => $total, 'ok' => $ok, 'error' => $err],
                    'equipos' => $reporteProceso,
                    'recomendaciones_generales' => [
                        'Si hubo errores, corrija el archivo y vuelva a validar antes de ejecutar.',
                        'Mantenga un solo registro por jugador (sin cédulas duplicadas entre equipos).',
                        'Verifique que todos los clubs referenciados existan y estén activos.',
                    ],
                ],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'equipos_procesados' => 0,
                'equipos_ok' => 0,
                'equipos_error' => 0,
                'detalles' => $detalles,
                'reporte_proceso' => [
                    'resumen' => ['total' => count($bloques ?? []), 'ok' => 0, 'error' => max(1, count($detalles))],
                    'equipos' => $reporteProceso,
                    'recomendaciones_generales' => [
                        'La carga se revirtió por seguridad. Corrija los errores y ejecute nuevamente.',
                        'Verifique IDs utilizados y que cada integrante pertenezca al torneo correcto.',
                    ],
                ],
            ];
        }
    }

    private static function idUsuarioPorCedula(PDO $pdo, string $cedula): int
    {
        $ced = preg_replace('/\D/', '', trim($cedula));
        if ($ced === '') {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE REPLACE(REPLACE(cedula, "-", ""), " ", "") = ? LIMIT 1');
            $stmt->execute([$ced]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return 0;
            }
            return (int)($row['id'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function numfvdPorCedula(PDO $pdo, string $cedula): int
    {
        $ced = preg_replace('/\D/', '', trim($cedula));
        if ($ced === '') {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(numfvd, 0) AS numfvd FROM usuarios WHERE REPLACE(REPLACE(cedula, "-", ""), " ", "") = ? LIMIT 1');
            $stmt->execute([$ced]);
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @param list<array{cedula:string,nombre:string,completo:bool,id_usuario:int,numfvd:int}> $integrantesReporte
     * @return array{ok: bool, detalle: string}
     */
    private static function verificarRegistrosEquipoGrabados(PDO $pdo, int $torneoId, array $integrantesReporte): array
    {
        $ids = [];
        foreach ($integrantesReporte as $j) {
            $id = (int)($j['id_usuario'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) !== self::JUGADORES_REQUERIDOS) {
            return ['ok' => false, 'detalle' => 'No se pudieron resolver 4 identificadores de integrantes para verificación.'];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT COUNT(*) FROM inscritos WHERE torneo_id = ? AND id_usuario IN ($ph)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$torneoId], $ids));
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt !== self::JUGADORES_REQUERIDOS) {
            return ['ok' => false, 'detalle' => 'No coinciden los registros insertados en inscritos para el torneo activo.'];
        }
        return ['ok' => true, 'detalle' => ''];
    }

    /**
     * Limpia caché local del proyecto al entrar a la carga masiva.
     * @return array{ok: bool, message: string, archivos_eliminados: int}
     */
    public static function limpiarCacheCargaMasiva(): array
    {
        $cacheDir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            return ['ok' => true, 'message' => 'No existe directorio de caché para limpiar.', 'archivos_eliminados' => 0];
        }
        $deleted = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $path = $item->getPathname();
                if ($item->isFile()) {
                    if (@unlink($path)) {
                        $deleted++;
                    }
                } elseif ($item->isDir()) {
                    @rmdir($path);
                }
            }
            return ['ok' => true, 'message' => 'Caché limpiada correctamente.', 'archivos_eliminados' => $deleted];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo limpiar toda la caché: ' . $e->getMessage(), 'archivos_eliminados' => $deleted];
        }
    }

    private static function normalizarCedula(string $c): string
    {
        return strtoupper(preg_replace('/\s+/', '', $c));
    }

    /**
     * Prefijo numérico solo desde la celda del Excel (código federación/asociación).
     * No usar el id del club resuelto en BD: si la celda está vacía o mal mapeada, se deja vacío y EquiposHelper usa id de club.
     */
    private static function prefijoCodigoSoloDesdeExcel(string $celda): string
    {
        return preg_replace('/\D/', '', trim($celda));
    }

    /**
     * Id de club solo desde el Excel: club_id_directo (formato ADEAZ) o dígitos de la columna club.
     */
    private static function resolverIdClubDesdeBloque(array $bloque): int
    {
        $directo = (int)($bloque['club_id_directo'] ?? 0);
        if ($directo > 0) {
            return $directo;
        }
        $celda = trim((string)($bloque['club'] ?? ''));
        $soloDig = preg_replace('/\D/', '', $celda);
        if ($soloDig !== '' && ctype_digit($soloDig)) {
            return (int)$soloDig;
        }
        return 0;
    }

    /**
     * @return array{ok: bool, club_id: int, detalle: string}
     */
    private static function validarResolverClubDesdeExcel(PDO $pdo, int $codigoColumna): array
    {
        if ($codigoColumna <= 0) {
            return [
                'ok' => false,
                'club_id' => 0,
                'detalle' => 'La columna club debe contener el id numérico del club (tabla clubes).',
            ];
        }
        $st = $pdo->prepare('SELECT id FROM clubes WHERE id = ? AND estatus = 1 LIMIT 1');
        $st->execute([$codigoColumna]);
        $id = $st->fetchColumn();
        if ($id) {
            return ['ok' => true, 'club_id' => (int)$id, 'detalle' => ''];
        }
        return [
            'ok' => false,
            'club_id' => 0,
            'detalle' => "No existe un club activo con id {$codigoColumna}.",
        ];
    }

    private static function codigoOrganizacion(PDO $pdo, int $orgId): string
    {
        if ($orgId <= 0) {
            return 'ORG0';
        }
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM organizaciones')->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($cols) && in_array('codigo', $cols, true)) {
                $stmt = $pdo->prepare('SELECT codigo FROM organizaciones WHERE id = ?');
                $stmt->execute([$orgId]);
                $c = trim((string)($stmt->fetchColumn() ?: ''));
                if ($c !== '') {
                    return $c;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return 'ORG-' . $orgId;
    }

    /**
     * Club por nombre; si no existe en la org del torneo, club ficticio con nombre = código organización.
     */
    private static function resolverClubId(PDO $pdo, string $clubNombre, string $orgNombre, int $orgTorneo): int
    {
        if ($orgNombre !== '') {
            $orgJoin = self::hasCodOrg($pdo)
                ? 'INNER JOIN organizaciones o ON (o.id = c.cod_org OR o.cod_org = c.cod_org)'
                : 'INNER JOIN organizaciones o ON o.id = c.cod_org';
            $stmt = $pdo->prepare(
                'SELECT c.id FROM clubes c
                 ' . $orgJoin . '
                 WHERE c.estatus = 1 AND UPPER(TRIM(c.nombre)) = UPPER(TRIM(?))
                 AND UPPER(TRIM(o.nombre)) = UPPER(TRIM(?)) LIMIT 1'
            );
            $stmt->execute([$clubNombre, $orgNombre]);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int)$id;
            }
        }
        if ($orgTorneo > 0) {
            if (self::hasCodOrg($pdo)) {
                $stmt = $pdo->prepare(
                    'SELECT c.id FROM clubes c
                     WHERE c.estatus = 1 AND (c.cod_org = ? OR c.cod_org = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND UPPER(TRIM(c.nombre)) = UPPER(TRIM(?)) LIMIT 1'
                );
                $stmt->execute([$orgTorneo, $orgTorneo, $clubNombre]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT c.id FROM clubes c
                     WHERE c.estatus = 1 AND c.cod_org = ? AND UPPER(TRIM(c.nombre)) = UPPER(TRIM(?)) LIMIT 1'
                );
                $stmt->execute([$orgTorneo, $clubNombre]);
            }
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int)$id;
            }
        }
        $stmt = $pdo->prepare('SELECT id FROM clubes WHERE estatus = 1 AND UPPER(TRIM(nombre)) = UPPER(TRIM(?)) LIMIT 1');
        $stmt->execute([$clubNombre]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        if ($orgTorneo <= 0) {
            return 0;
        }
        $codigo = self::codigoOrganizacion($pdo, $orgTorneo);
        if (self::hasCodOrg($pdo)) {
            $stmt = $pdo->prepare(
                'SELECT id FROM clubes WHERE (cod_org = ? OR cod_org = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) AND UPPER(TRIM(nombre)) = UPPER(TRIM(?)) LIMIT 1'
            );
            $stmt->execute([$orgTorneo, $orgTorneo, $codigo]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id FROM clubes WHERE cod_org = ? AND UPPER(TRIM(nombre)) = UPPER(TRIM(?)) LIMIT 1'
            );
            $stmt->execute([$orgTorneo, $codigo]);
        }
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        return self::crearClubDefectoOrganizacion($pdo, $orgTorneo, $codigo);
    }

    private static function crearClubDefectoOrganizacion(PDO $pdo, int $orgId, string $nombreClub): int
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO clubes (nombre, cod_org, estatus, direccion, delegado, telefono, email)
                 VALUES (?, ?, 1, \'\', \'\', \'\', \'\')'
            );
            $stmt->execute([$nombreClub, $orgId]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            if (self::hasCodOrg($pdo)) {
                $stmt = $pdo->prepare('SELECT id FROM clubes WHERE (cod_org = ? OR cod_org = (SELECT id FROM organizaciones WHERE cod_org = ? LIMIT 1)) ORDER BY id DESC LIMIT 1');
                $stmt->execute([$orgId, $orgId]);
            } else {
                $stmt = $pdo->prepare('SELECT id FROM clubes WHERE cod_org = ? ORDER BY id DESC LIMIT 1');
                $stmt->execute([$orgId]);
            }
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : 0;
        }
    }

    /**
     * Convierte texto a UTF-8 válido, quita BOM, intenta Latin-1/Windows-1252 si hace falta y aplica NFC.
     * Público para reutilizar en carga masiva de parejas u otros importadores.
     */
    public static function normalizarTextoUtf8(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $s = substr($s, 3);
        }
        if (function_exists('mb_check_encoding')) {
            $okUtf8 = mb_check_encoding($s, 'UTF-8');
            if (!$okUtf8) {
                if (function_exists('mb_detect_encoding')) {
                    $enc = mb_detect_encoding($s, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15'], true);
                    if ($enc !== false && $enc !== 'UTF-8') {
                        $s = mb_convert_encoding($s, 'UTF-8', $enc);
                    } elseif (function_exists('mb_convert_encoding')) {
                        $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
                    }
                }
                if ($s !== '' && !mb_check_encoding($s, 'UTF-8') && function_exists('iconv')) {
                    $t = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
                    if ($t !== false && $t !== '') {
                        $s = $t;
                    }
                }
            }
        } elseif (function_exists('iconv') && @preg_match('//u', $s) !== 1) {
            $t = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
            if ($t !== false) {
                $s = $t;
            }
        }
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if ($n !== false) {
                $s = $n;
            }
        }
        return $s;
    }

    /**
     * @param list<list<string|string>> $rows
     * @return list<list<string>>
     */
    /**
     * Normaliza todas las celdas a UTF-8 (reutilizable por carga masiva parejas).
     *
     * @param list<list<mixed>> $rows
     * @return list<list<string>>
     */
    public static function normalizarFilasUtf8(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = array_map(
                static fn ($c) => self::normalizarTextoUtf8($c === null ? '' : (string)$c),
                $row
            );
        }
        return $out;
    }

    /**
     * @param string|null $errorDetalle se rellena si falla la lectura
     * @return list<list<string>>
     */
    /**
     * Lectura de filas para cargas masivas (equipos/parejas). Reutiliza la misma normalización UTF-8.
     *
     * @return list<list<string>>
     */
    public static function leerFilasDesdeArchivo(string $path, string $originalName, ?string &$errorDetalle = null): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || $ext === 'tsv') {
            $ext = 'txt';
        }
        return self::leerFilas($path, $ext, $errorDetalle);
    }

    private static function leerFilas(string $path, string $ext, ?string &$errorDetalle = null): array
    {
        $errorDetalle = null;
        /** CSV / TXT / TSV: detectar tabulador (exportaciones tipo ADEAZ). */
        if (in_array($ext, ['csv', 'txt'], true)) {
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return [];
            }
            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
                $raw = substr($raw, 3);
            }
            $raw = self::normalizarTextoUtf8($raw);
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $lines = array_values(array_filter($lines, static fn ($l) => trim((string)$l) !== ''));
            if ($lines === []) {
                return [];
            }
            $first = (string)$lines[0];
            $tabCount = substr_count($first, "\t");
            $semiCount = substr_count($first, ';');
            $commaCount = substr_count($first, ',');
            $delim = ',';
            if ($tabCount >= 2) {
                $delim = "\t";
            } elseif ($semiCount >= 2 && $commaCount < 2) {
                $delim = ';';
            }
            $rows = [];
            foreach ($lines as $line) {
                if ($delim === "\t") {
                    $row = array_map('trim', explode("\t", $line));
                } else {
                    $row = str_getcsv($line, $delim, '"', '\\');
                    $row = array_map(static fn ($c) => trim((string)$c), $row);
                }
                $rows[] = array_map(static fn ($c) => (string)$c, $row);
            }
            return self::normalizarFilasUtf8($rows);
        }
        if (in_array($ext, ['xlsx'], true)) {
            require_once __DIR__ . '/CargaMasivaXlsxReader.php';
            $rows = CargaMasivaXlsxReader::leerHojas($path);
            if ($rows !== []) {
                return self::normalizarFilasUtf8($rows);
            }
            $errorDetalle = 'Lector nativo XLSX no obtuvo filas (¿hoja vacía o archivo dañado?).';
            $autoloads = [
                __DIR__ . '/../vendor/autoload.php',
                dirname(__DIR__, 2) . '/vendor/autoload.php',
            ];
            foreach ($autoloads as $auto) {
                if (!is_file($auto)) {
                    continue;
                }
                require_once $auto;
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
                    $rows = [];
                    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                        $chunk = [];
                        foreach ($sheet->toArray(null, true, true, false) as $row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $chunk[] = array_map(static fn ($c) => $c === null ? '' : (string)$c, $row);
                        }
                        if (count($chunk) > count($rows)) {
                            $rows = $chunk;
                        }
                    }
                    if ($rows !== []) {
                        $errorDetalle = null;
                        return self::normalizarFilasUtf8($rows);
                    }
                } catch (Throwable $e) {
                    $errorDetalle = 'PhpSpreadsheet: ' . $e->getMessage();
                }
                break;
            }
            if (!class_exists('ZipArchive', false)) {
                $errorDetalle = 'PHP necesita la extensión ZipArchive para leer .xlsx.';
            }
            return [];
        }
        if ($ext === 'xls') {
            foreach ([__DIR__ . '/../vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $auto) {
                if (!is_file($auto)) {
                    continue;
                }
                require_once $auto;
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
                    $rows = [];
                    foreach ($spreadsheet->getActiveSheet()->toArray(null, true, true, false) as $row) {
                        if (is_array($row)) {
                            $rows[] = array_map(static fn ($c) => $c === null ? '' : (string)$c, $row);
                        }
                    }
                    return self::normalizarFilasUtf8($rows);
                } catch (Throwable $e) {
                    $errorDetalle = $e->getMessage();
                }
                break;
            }
            $errorDetalle = 'Archivo .xls requiere PhpSpreadsheet (ejecute composer install). Guarde en Excel como .xlsx o CSV.';
            return [];
        }
        $errorDetalle = 'Extensión no soportada para lectura directa. Use .xlsx, .csv o .txt.';
        return [];
    }

    private static function mapearCabeceras(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $norm = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string)$h)));
            $norm = trim($norm, '_');
            if ($norm !== '' && !isset($map[$norm])) {
                $map[$norm] = $i;
            }
            if (str_contains($norm, 'cedula') || $norm === 'cedula1') {
                $map['cedula'] = $i;
            }
            if ($norm === 'n1' || (str_contains($norm, 'nombre') && !str_contains($norm, 'equipo'))) {
                $map['n1'] = $i;
            }
            if (str_contains($norm, 'equipo') && (str_contains($norm, 'nombre') || $norm === 'equipo')) {
                $map['equipo'] = $i;
            }
            // Columna solo para código numérico de club/asociación (prefijo codigo_equipo)
            $esCodClub = in_array($norm, ['cod_club', 'club_codigo', 'codigo_club', 'id_asociacion', 'cod_asoc'], true)
                || ((str_contains($norm, 'codigo') || $norm === 'cod' || str_starts_with($norm, 'cod_'))
                    && (str_contains($norm, 'club') || str_contains($norm, 'asoc')));
            if ($esCodClub) {
                $map['club_codigo'] = $i;
            }
            if ($norm === 'club') {
                $map['club'] = $i;
            }
        }
        // Formato ancho (NAC, cedula col 1, N1 col 3…)
        $wide = ['nac' => 0, 'cedula' => 1, 'n1' => 3, 'sexo' => 5, 'fecha_nac' => 6, 'telefono' => 7, 'email' => 8, 'equipo' => 9, 'club' => 10, 'organizacion' => 11];
        // Formato ADEAZ/TSV: Cedula1, N1, sexo, fecha_nac, telefono, email, equipo, club, organizacion → índices 0..8
        $tsv = ['cedula' => 0, 'n1' => 1, 'sexo' => 2, 'fecha_nac' => 3, 'telefono' => 4, 'email' => 5, 'equipo' => 6, 'club' => 7, 'organizacion' => 8];
        $colCount = count($header);
        $useTsv = $colCount >= 7 && $colCount <= 11
            && (int)($map['cedula'] ?? 99) === 0 && (int)($map['n1'] ?? 99) === 1;
        $defaults = $useTsv ? $tsv : $wide;
        foreach ($defaults as $k => $i) {
            if (!isset($map[$k]) || $useTsv) {
                $map[$k] = $i;
            }
        }
        if ($useTsv) {
            $map['nac'] = 0;
        }
        return $map;
    }

    private static function agruparPorEquipo(array $rows, array $map): array
    {
        $bloques = [];
        $current = null;
        $lineNum = 2;
        $idxCed = (int)($map['cedula'] ?? 0);
        $idxN1 = (int)($map['n1'] ?? 1);
        $idxClubCodigo = isset($map['club_codigo']) ? (int)$map['club_codigo'] : null;
        foreach ($rows as $row) {
            $nac = strtoupper(trim(self::cel($row, $map['nac'] ?? 0)));
            $equipoNom = trim(self::cel($row, $map['equipo'] ?? 9));
            $club = trim(self::cel($row, $map['club'] ?? 10));
            $org = trim(self::cel($row, $map['organizacion'] ?? 11));
            $c0 = trim(self::cel($row, $idxCed));
            $n1 = trim(self::cel($row, $idxN1));

            // Formato clásico: NAC=R + nombre equipo + club
            // El nombre legible del equipo suele ir en N1 (ej. AVALANCHA); la columna «equipo» a veces trae un id fijo (ej. 1 en todas las filas).
            $nombreEquipoR = trim($n1) !== '' ? trim($n1) : $equipoNom;
            if ($nac === 'R' && $club !== '' && $nombreEquipoR !== '') {
                if ($current !== null) {
                    $bloques[] = $current;
                }
                $celdaCodigo = ($idxClubCodigo !== null) ? trim(self::cel($row, $idxClubCodigo)) : '';
                if ($celdaCodigo === '') {
                    $celdaCodigo = $club;
                }
                $current = [
                    'nombre_equipo' => $nombreEquipoR,
                    'club' => $club,
                    'organizacion' => $org,
                    'club_id_directo' => 0,
                    'codigo_club_prefijo' => self::prefijoCodigoSoloDesdeExcel($celdaCodigo),
                    'linea_inicio' => $lineNum,
                    'miembros' => [],
                ];
                $lineNum++;
                continue;
            }

            // Formato ADEAZ: primera columna 0 = fila de equipo; N1 = nombre del equipo; columna club = id en clubes
            $esFilaEquipoCero = ($c0 === '0' && $n1 !== '' && !ctype_digit($n1));
            if ($esFilaEquipoCero) {
                if ($current !== null) {
                    $bloques[] = $current;
                }
                $clubRaw = trim(self::cel($row, $map['club'] ?? 7));
                $celdaCodigo = ($idxClubCodigo !== null) ? trim(self::cel($row, $idxClubCodigo)) : '';
                if ($celdaCodigo === '') {
                    $celdaCodigo = $clubRaw;
                }
                $soloDig = preg_replace('/\D/', '', $clubRaw);
                $clubId = ($soloDig !== '' && ctype_digit($soloDig)) ? (int)$soloDig : 0;
                $current = [
                    'nombre_equipo' => $n1,
                    'club' => ($clubRaw !== '' ? $clubRaw : $celdaCodigo),
                    'organizacion' => trim(self::cel($row, $map['organizacion'] ?? 8)),
                    'club_id_directo' => $clubId,
                    'codigo_club_prefijo' => self::prefijoCodigoSoloDesdeExcel($celdaCodigo),
                    'linea_inicio' => $lineNum,
                    'miembros' => [],
                ];
                $lineNum++;
                continue;
            }

            if ($current !== null) {
                $nacionalidad = 'V';
                $cedula = $c0;
                $nombreJ = $n1;
                $c1 = trim(self::cel($row, $idxCed + 1));
                // Fila tipo Excel: col0 = V/E/J/P, col1 = número cédula, col2 = nombre
                if (strlen($c0) === 1 && in_array(strtoupper($c0), ['V', 'E', 'J', 'P'], true)
                    && preg_match('/^\d{6,12}$/', $c1)) {
                    $nacionalidad = strtoupper($c0);
                    $cedula = $c1;
                    $nombreJ = trim(self::cel($row, $idxCed + 2));
                    $current['miembros'][] = [
                        'cedula' => $cedula,
                        'n1' => $nombreJ,
                        'nacionalidad' => $nacionalidad,
                        'sexo' => trim(self::cel($row, $idxCed + 4)),
                        'fecha_nac' => trim(self::cel($row, $idxCed + 3)),
                        'telefono' => trim(self::cel($row, $idxCed + 6)),
                        'email' => trim(self::cel($row, $idxCed + 7)),
                    ];
                } elseif ($cedula !== '' && $cedula !== '0' && $nombreJ !== '' && preg_match('/\d/', $cedula)) {
                    $current['miembros'][] = [
                        'cedula' => $cedula,
                        'n1' => $nombreJ,
                        'nacionalidad' => $nacionalidad,
                        'sexo' => trim(self::cel($row, $map['sexo'] ?? 2)),
                        'fecha_nac' => trim(self::cel($row, $map['fecha_nac'] ?? 3)),
                        'telefono' => trim(self::cel($row, $map['telefono'] ?? 4)),
                        'email' => trim(self::cel($row, $map['email'] ?? 5)),
                    ];
                }
            }
            $lineNum++;
        }
        if ($current !== null) {
            $bloques[] = $current;
        }
        return $bloques;
    }

    private static function cel(array $row, int $idx): string
    {
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }

    /** Campo territorial: debe coincidir con clubes.entidad del club asignado. */
    private static function entidadDesdeClubId(PDO $pdo, int $club_id): int
    {
        if ($club_id <= 0) {
            return 0;
        }
        static $cache = [];
        if (isset($cache[$club_id])) {
            return $cache[$club_id];
        }
        try {
            $st = $pdo->prepare('SELECT COALESCE(entidad, 0) FROM clubes WHERE id = ? LIMIT 1');
            $st->execute([$club_id]);
            $v = (int)($st->fetchColumn() ?: 0);
            $cache[$club_id] = $v;
            return $v;
        } catch (Throwable $e) {
            $cache[$club_id] = 0;
            return 0;
        }
    }

    private static function asegurarUsuarioAfiliado(PDO $pdo, string $cedula, string $nombre, int $club_id, array $m, int $entidad_club): void
    {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
        $stmt->execute([$cedula]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            $upd = $pdo->prepare('UPDATE usuarios SET club_id = ?, entidad = ? WHERE id = ?');
            $upd->execute([$club_id, $entidad_club, (int)$uid]);
            return;
        }
        $email = trim($m['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'bulk_' . preg_replace('/\W/', '', $cedula) . '@carga-masiva.local';
        }
        $username = 'cm_' . preg_replace('/\W/', '_', $cedula) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $sexo = strtoupper(substr(trim($m['sexo'] ?? ''), 0, 1));
        if (!in_array($sexo, ['M', 'F'], true)) {
            $sexo = 'M';
        }
        $fechnac = trim($m['fecha_nac'] ?? '');
        if ($fechnac !== '' && preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/', $fechnac)) {
            $parts = preg_split('/[\/\-]/', $fechnac);
            if (count($parts) === 3) {
                $d = (int)$parts[0];
                $mo = (int)$parts[1];
                $y = (int)$parts[2];
                if ($y < 100) {
                    $y += 2000;
                }
                $fechnac = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        } elseif ($fechnac === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechnac)) {
            $fechnac = '1990-01-01';
        }
        $hash = Security::hashPassword(bin2hex(random_bytes(8)));
        $nacionalidad = strtoupper(substr(trim((string)($m['nacionalidad'] ?? '')), 0, 1));
        if (!in_array($nacionalidad, ['V', 'E', 'J', 'P'], true)) {
            $nacionalidad = 'V';
        }
        $numfvd = preg_replace('/\D/', '', $cedula);
        if ($numfvd === '') {
            $numfvd = '0';
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO usuarios (nombre, cedula, nacionalidad, numfvd, sexo, fechnac, email, username, password_hash, role, club_id, entidad, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'usuario\', ?, ?, \'approved\')'
            );
            $stmt->execute([$nombre, $cedula, $nacionalidad, $numfvd, $sexo, $fechnac, $email, $username, $hash, $club_id, $entidad_club]);
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO usuarios (nombre, cedula, nacionalidad, numfvd, sexo, fechnac, email, username, password_hash, role, club_id, entidad, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'usuario\', ?, ?, 0)'
                );
                $stmt->execute([$nombre, $cedula, $nacionalidad, $numfvd, $sexo, $fechnac, $email, $username, $hash, $club_id, $entidad_club]);
            } catch (Throwable $e2) {
                $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
                $stmt->execute([$cedula]);
                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('No se pudo crear usuario para cédula ' . $cedula . ': ' . $e2->getMessage());
                }
            }
        }
    }
}
