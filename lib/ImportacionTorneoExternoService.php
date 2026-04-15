<?php
/**
 * Importación histórica desde otra plataforma: fase 1 (cedula→id_usuario), fase 2 (partiresul).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/php_polyfills.php';

final class ImportacionTorneoExternoService
{
    /**
     * @return list<list<string>>
     */
    public static function leerExcelOCsv(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx'], true)) {
            $r = self::leerXlsxCombinandoTodasLasHojas($path);
            if ($r !== []) {
                return $r;
            }
        }
        if (in_array($ext, ['csv', 'txt', 'xls'], true)) {
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return [];
            }
            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
                $raw = substr($raw, 3);
            }
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $lines = array_values(array_filter($lines, static fn ($l) => trim((string)$l) !== ''));
            if ($lines === []) {
                return [];
            }
            $delim = substr_count((string)$lines[0], "\t") >= 2 ? "\t" : (substr_count((string)$lines[0], ';') >= 2 ? ';' : ',');
            $rows = [];
            foreach ($lines as $line) {
                $row = $delim === "\t" ? array_map('trim', explode("\t", $line)) : str_getcsv($line, $delim, '"', '\\');
                $rows[] = array_map(static fn ($c) => trim((string)$c), $row);
            }
            return $rows;
        }
        require_once __DIR__ . '/CargaMasivaXlsxReader.php';
        $r = self::leerXlsxCombinandoTodasLasHojas($path);

        return $r !== [] ? $r : CargaMasivaXlsxReader::leerHojas($path);
    }

    /**
     * Lee un .xlsx concatenando todas las hojas (orden sheet1, sheet2, …).
     * Antes solo se usaba la hoja con más filas (leerHojas), perdiendo rondas en hojas separadas.
     *
     * @return list<list<string>>
     */
    private static function leerXlsxCombinandoTodasLasHojas(string $path): array
    {
        require_once __DIR__ . '/CargaMasivaXlsxReader.php';
        $sheets = CargaMasivaXlsxReader::leerTodasHojasEnOrden($path);
        if ($sheets === []) {
            return [];
        }
        if (count($sheets) === 1) {
            return $sheets[0];
        }
        $out = $sheets[0];
        $cab0 = $out[0] ?? [];
        for ($si = 1; $si < count($sheets); $si++) {
            $sh = $sheets[$si];
            if ($sh === []) {
                continue;
            }
            $ini = 0;
            if ($cab0 !== [] && isset($sh[0]) && self::filaPareceMismaCabeceraXlsx($cab0, $sh[0])) {
                $ini = 1;
            }
            for ($ri = $ini; $ri < count($sh); $ri++) {
                $out[] = $sh[$ri];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function filaPareceMismaCabeceraXlsx(array $a, array $b): bool
    {
        $n = min(count($a), count($b), 24);
        if ($n < 2) {
            return false;
        }
        $norm = static function (string $s): string {
            return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($s)));
        };
        $ig = 0;
        for ($i = 0; $i < $n; $i++) {
            $ca = $norm((string) ($a[$i] ?? ''));
            $cb = $norm((string) ($b[$i] ?? ''));
            if ($ca !== '' && $cb !== '' && $ca === $cb) {
                $ig++;
            }
        }

        return $ig >= max(2, (int) ceil($n * 0.45));
    }

    /**
     * Fase 1: añade columna id_usuario por cédula (usuarios.cedula).
     *
     * @return array{filas: list<list<string>>, no_encontradas: list<string>}
     */
    public static function fase1Enriquecer(PDO $pdo, array $rows): array
    {
        if ($rows === []) {
            return ['filas' => [], 'no_encontradas' => []];
        }
        $header = $rows[0];
        $map = self::mapearIndices($header, ['pareja' => ['pareja', 'id_pareja', 'parejas'], 'cedula' => ['cedula', 'cedula1', 'ci', 'documento', 'c_dula']]);
        if ($map['cedula'] < 0) {
            return ['filas' => $rows, 'no_encontradas' => ['No se encontró columna de cédula en la primera fila.']];
        }
        $out = [];
        $out[] = array_merge($header, ['id_usuario']);
        $noEnc = [];
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE cedula = ? LIMIT 1');
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            while (count($row) < count($header)) {
                $row[] = '';
            }
            $ced = self::normalizarCedula($row[$map['cedula']] ?? '');
            if ($ced === '') {
                $out[] = array_merge($row, ['']);
                continue;
            }
            $stmt->execute([$ced]);
            $id = $stmt->fetchColumn();
            if (!$id) {
                $stmt->execute([preg_replace('/\D/', '', $ced)]);
                $id = $stmt->fetchColumn();
            }
            if ($id) {
                $out[] = array_merge($row, [(string)(int)$id]);
            } else {
                $noEnc[] = $ced;
                $out[] = array_merge($row, ['']);
            }
        }
        return ['filas' => $out, 'no_encontradas' => array_values(array_unique($noEnc))];
    }

    /**
     * Fase 2: INSERT partiresul. Cabecera: partida, mesa, id_usuario (o cédula), secuencia,
     * resultado1 / Result1 / PF (puntos a favor), resultado2 / Result2 / PC (en contra), SancionP/sancion, ff.
     * Identidad por cédula: misma prioridad que inscripción en sitio (local → externa → alta mínima).
     *
     * @return array{insertados: int, errores: list<string>, auditoria_por_ronda: list<array{partida: int, gdu: int, mesas_incompletas: int, detalle: string}>}
     */
    public static function fase2InsertarPartiresul(
        PDO $pdo,
        int $torneo_id,
        int $registrado_por,
        string $fechaTorneoYmd,
        array $rows
    ): array {
        if ($rows === []) {
            return ['insertados' => 0, 'errores' => ['Archivo vacío'], 'auditoria_por_ronda' => []];
        }
        require_once __DIR__ . '/TorneoCampoNumerico.php';

        $stmtT = $pdo->prepare('SELECT puntos, rondas FROM tournaments WHERE id = ? LIMIT 1');
        $stmtT->execute([$torneo_id]);
        $tMeta = $stmtT->fetch(PDO::FETCH_ASSOC) ?: [];
        $puntosTorneo = (int) ($tMeta['puntos'] ?? 100);
        if ($puntosTorneo < 1) {
            $puntosTorneo = 100;
        }
        /** Máximo de rondas (partida) permitidas por configuración del torneo; importación completa 1..N. */
        $limiteRondasTorneo = (int) ($tMeta['rondas'] ?? 0);
        if ($limiteRondasTorneo < 1) {
            $limiteRondasTorneo = 99;
        }

        $header = array_map(static fn ($h) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h))), $rows[0]);
        $idx = static function (array $h, array $names): int {
            foreach ($names as $n) {
                $n = strtolower($n);
                foreach ($h as $i => $col) {
                    if (str_contains((string) $col, $n) || $col === $n) {
                        return $i;
                    }
                }
            }

            return -1;
        };
        $iPart = self::indiceColumnaPartida($header);
        if ($iPart < 0) {
            $iPart = $idx($header, ['partida', 'ronda', 'partida_']);
        }
        $iMesa = self::indiceColumnaMesa($header);
        if ($iMesa < 0) {
            $iMesa = $idx($header, ['mesa']);
        }
        $iUsr = $idx($header, ['id_usuario', 'idusuario']);
        $iCed = $idx($header, ['cedula', 'cedula1', 'ci', 'documento']);
        $iSeq = self::indiceColumnaSecuencia($header);
        if ($iSeq < 0) {
            $iSeq = $idx($header, ['secuencia', 'seq']);
        }
        /* PF / Result1 / r1… */
        $iR1 = $idx($header, ['resultado1', 'result1', 'result_1', 'r1', 'pts1', 'pf', 'puntos_favor', 'favor']);
        /* PC / Result2 / r2… */
        $iR2 = $idx($header, ['resultado2', 'result2', 'result_2', 'r2', 'pts2', 'pc', 'puntos_contra', 'contra']);
        $iFf = $idx($header, ['ff', 'forfait']);
        $iTar = self::indiceColumnaImport($header, ['tarjeta', 'amarilla', 'roja'], ['tarjeta_amarilla', 'tarjeta_roja', 'marca_tarjeta']);
        if ($iTar < 0) {
            $iTar = $idx($header, ['tarjeta', 'marca_tarjeta']);
        }
        $iSancionPuntosF2 = -1;
        foreach ($header as $hi => $col) {
            $c = (string) $col;
            if (str_contains($c, 'sancionp') || str_contains($c, 'sancion_p')) {
                $iSancionPuntosF2 = $hi;
                break;
            }
        }
        /** Columna «sancion» solo como marca si además hay «sancionp» (evita duplicar el mismo índice). */
        $iSancionMarcaF2 = -1;
        if ($iSancionPuntosF2 >= 0) {
            foreach ($header as $hi => $col) {
                if ((string) $col === 'sancion') {
                    $iSancionMarcaF2 = $hi;
                    break;
                }
            }
        }
        $iSanNum = -1;
        if ($iSancionPuntosF2 >= 0) {
            $iSanNum = $iSancionPuntosF2;
        } else {
            foreach ($header as $hi => $col) {
                $c = (string) $col;
                if (str_contains($c, 'penaliza') || $c === 'penal' || str_starts_with($c, 'penal_')) {
                    $iSanNum = $hi;
                    break;
                }
            }
            if ($iSanNum < 0) {
                foreach ($header as $hi => $col) {
                    if ($col === 'sancion' || $col === 'sanc') {
                        $iSanNum = $hi;
                        break;
                    }
                }
            }
        }
        $iNombre = $idx($header, ['nombre', 'n1', 'jugador', 'atleta']);
        $iNac = $idx($header, ['nacionalidad', 'nac']);
        $iEfect = $idx($header, ['efectiv', 'efectividad', 'efect']);
        if ($iPart < 0 || $iMesa < 0 || $iSeq < 0 || $iR1 < 0 || $iR2 < 0 || ($iUsr < 0 && $iCed < 0)) {
            return ['insertados' => 0, 'errores' => ['Faltan columnas: partida, mesa, secuencia, puntos a favor / resultado1, puntos en contra / resultado2 e id_usuario o cédula.'], 'auditoria_por_ronda' => []];
        }

        $maxPartidaArchivo = 0;
        for ($scan = 1; $scan < count($rows); $scan++) {
            $pv = (int) ($rows[$scan][$iPart] ?? 0);
            if ($pv > $maxPartidaArchivo) {
                $maxPartidaArchivo = $pv;
            }
        }
        if ($maxPartidaArchivo > 0) {
            $limiteRondasTorneo = max($limiteRondasTorneo, $maxPartidaArchivo);
        }

        $statsF2 = [];
        $statsF2['rondas_limite_torneo'] = $limiteRondasTorneo;
        /** @var list<array{Field: string, Null: string, Default: mixed, Extra: string}> $meta */
        $meta = $pdo->query('SHOW COLUMNS FROM partiresul')->fetchAll(PDO::FETCH_ASSOC);
        $insertFields = [];
        foreach ($meta as $c) {
            if (strtolower((string) $c['Field']) === 'id' && str_contains((string) $c['Extra'], 'auto_increment')) {
                continue;
            }
            $insertFields[] = $c;
        }
        if ($insertFields === []) {
            return ['insertados' => 0, 'errores' => ['partiresul: sin columnas insertables'], 'auditoria_por_ronda' => []];
        }
        $sqlParts = [];
        $placeholders = [];
        foreach ($insertFields as $c) {
            $sqlParts[] = '`' . str_replace('`', '``', $c['Field']) . '`';
            $placeholders[] = '?';
        }
        $sqlInsert = 'INSERT INTO partiresul (' . implode(', ', $sqlParts) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmtI = $pdo->prepare($sqlInsert);

        $insertados = 0;
        $errores = [];
        $pdo->beginTransaction();
        try {
            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $partida = (int) ($row[$iPart] ?? 0);
                $mesa = (int) ($row[$iMesa] ?? 0);
                $secuencia = (int) ($row[$iSeq] ?? 0);
                $idUsuario = $iUsr >= 0 ? (int) ($row[$iUsr] ?? 0) : 0;
                if ($idUsuario <= 0 && $iCed >= 0) {
                    $ced = self::normalizarCedula($row[$iCed] ?? '');
                    $nom = $iNombre >= 0 ? trim((string) ($row[$iNombre] ?? '')) : '';
                    $nac = $iNac >= 0 ? trim((string) ($row[$iNac] ?? '')) : '';
                    $idUsuario = self::resolverIdUsuarioInscripcionSitio(
                        $pdo,
                        $torneo_id,
                        $ced,
                        $nom,
                        $nac,
                        $registrado_por,
                        $statsF2
                    );
                }
                if ($idUsuario > 0) {
                    $statsF2['_ids_atletas'][$idUsuario] = true;
                    self::asegurarInscritoTorneoActivo($pdo, $torneo_id, $idUsuario, $registrado_por, $statsF2);
                }
                if ($partida < 1 || $mesa < 1 || $secuencia < 1 || $idUsuario < 1) {
                    continue;
                }
                if ($partida > $limiteRondasTorneo) {
                    $statsF2['filas_omitidas_partida_superior_limite'] = (int) ($statsF2['filas_omitidas_partida_superior_limite'] ?? 0) + 1;
                    continue;
                }
                $r1 = (int) ($row[$iR1] ?? 0);
                $r2 = (int) ($row[$iR2] ?? 0);
                $ff = $iFf >= 0 ? self::parseFfValor($row[$iFf] ?? 0) : 0;
                $tarjetaVal = 0;
                $sancionVal = 0;
                if ($iSancionMarcaF2 >= 0) {
                    $tarjetaVal = self::parseMarcaTarjeta($row[$iSancionMarcaF2] ?? '');
                } elseif ($iTar >= 0) {
                    $tarjetaVal = self::parseMarcaTarjeta($row[$iTar] ?? '');
                }
                if ($iSanNum >= 0) {
                    $celSan = $row[$iSanNum] ?? 0;
                    if ($iSancionMarcaF2 < 0 && $iTar < 0) {
                        $rawS = trim((string) $celSan);
                        if ($rawS !== '' && !is_numeric($rawS)) {
                            $tarjetaVal = self::parseMarcaTarjeta($celSan);
                        } else {
                            $sancionVal = \TorneoCampoNumerico::intEstadistica($celSan);
                        }
                    } else {
                        $sancionVal = \TorneoCampoNumerico::intEstadistica($celSan);
                    }
                    $sancionVal = min(80, max(0, $sancionVal));
                }
                $efect = ($iEfect >= 0)
                    ? \TorneoCampoNumerico::intEstadistica($row[$iEfect] ?? 0)
                    : self::efectividad($r1, $r2, $puntosTorneo, $ff);
                $fecha = $fechaTorneoYmd . ' 12:00:00';
                $params = [];
                foreach ($insertFields as $c) {
                    $f = strtolower((string) $c['Field']);
                    $nullable = ($c['Null'] ?? '') === 'YES';
                    $def = $c['Default'] ?? null;
                    switch ($f) {
                        case 'id_torneo':
                            $params[] = $torneo_id;
                            break;
                        case 'partida':
                            $params[] = $partida;
                            break;
                        case 'mesa':
                            $params[] = $mesa;
                            break;
                        case 'secuencia':
                            $params[] = $secuencia;
                            break;
                        case 'id_usuario':
                            $params[] = $idUsuario;
                            break;
                        case 'resultado1':
                            $params[] = $r1;
                            break;
                        case 'resultado2':
                            $params[] = $r2;
                            break;
                        case 'efectividad':
                            $params[] = $efect;
                            break;
                        case 'ff':
                            $params[] = $ff;
                            break;
                        case 'sancion':
                            $params[] = $sancionVal;
                            break;
                        case 'tarjeta':
                            $params[] = $tarjetaVal;
                            break;
                        case 'chancleta':
                        case 'zapato':
                            $params[] = 0;
                            break;
                        case 'fecha_partida':
                            $params[] = $fecha;
                            break;
                        case 'registrado_por':
                            $params[] = $registrado_por;
                            break;
                        case 'registrado':
                            $params[] = 1;
                            break;
                        case 'observaciones':
                            $params[] = '';
                            break;
                        case 'foto_acta':
                            $params[] = $nullable ? null : '';
                            break;
                        case 'origen_dato':
                            $params[] = 'admin';
                            break;
                        case 'estatus':
                            $params[] = $nullable ? null : 1;
                            break;
                        default:
                            if ($nullable) {
                                $params[] = null;
                            } elseif (is_numeric($def)) {
                                $params[] = (int) $def;
                            } elseif ($def !== null) {
                                $params[] = $def;
                            } else {
                                $params[] = 0;
                            }
                    }
                }
                try {
                    $stmtI->execute($params);
                    $insertados++;
                } catch (Throwable $e) {
                    $errores[] = 'Fila ' . ($r + 1) . ': ' . $e->getMessage();
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['insertados' => 0, 'errores' => [$e->getMessage()], 'auditoria_por_ronda' => []];
        }

        $omitLim = (int) ($statsF2['filas_omitidas_partida_superior_limite'] ?? 0);
        if ($omitLim > 0) {
            $errores[] = $omitLim . ' filas no insertadas: partida/ronda mayor al límite del torneo (' . $limiteRondasTorneo . ' rondas configuradas).';
        }

        $auditoriaPorRonda = [];
        /* Auditoría de las rondas 1..N según el torneo (carga completa), no solo las que tuvieron INSERT en este lote. */
        $partidasLista = range(1, $limiteRondasTorneo);
        $gduTorneoCompleto = [];
        try {
            require_once __DIR__ . '/Tournament/OpEspecialesHelper.php';
            $gduTorneoCompleto = \Tournament\OpEspecialesHelper::obtenerReporteAnomalias($torneo_id);
        } catch (Throwable $e) {
            error_log('ImportacionTorneoExternoService obtenerReporteAnomalias: ' . $e->getMessage());
            $errores[] = 'Auditoría GDU no disponible: ' . $e->getMessage();
        }
        foreach ($partidasLista as $pnum) {
            try {
                $chk = self::validarPostCargaRondaPartiresul($pdo, $torneo_id, (int) $pnum, $gduTorneoCompleto);
            } catch (Throwable $e) {
                error_log('ImportacionTorneoExternoService validarPostCargaRondaPartiresul: ' . $e->getMessage());
                $auditoriaPorRonda[] = [
                    'partida' => (int) $pnum,
                    'gdu' => 0,
                    'mesas_incompletas' => 0,
                    'detalle' => 'Validación post-carga falló: ' . $e->getMessage(),
                ];
                continue;
            }
            $nGdu = count($chk['gdu']);
            $nInc = count($chk['mesas_incompletas']);
            $det = '';
            if ($nGdu > 0) {
                $det .= 'GDU: ' . $nGdu . ' anomalía(s). ';
            }
            if ($nInc > 0) {
                $det .= 'Mesas incompletas: ' . $nInc . '. ';
            }
            if ($det === '') {
                $det = 'Sin anomalías GDU ni mesas incompletas en esta ronda.';
            }
            $auditoriaPorRonda[] = [
                'partida' => (int) $pnum,
                'gdu' => $nGdu,
                'mesas_incompletas' => $nInc,
                'detalle' => trim($det),
            ];
        }

        if (isset($statsF2['_ids_atletas']) && is_array($statsF2['_ids_atletas'])) {
            $statsF2['atletas_vinculados'] = count($statsF2['_ids_atletas']);
            unset($statsF2['_ids_atletas']);
        }

        return [
            'insertados' => $insertados,
            'errores' => $errores,
            'auditoria_por_ronda' => $auditoriaPorRonda,
            'resolucion_identidad_fase2' => $statsF2,
        ];
    }

    /**
     * Dos archivos. Archivo 1: cédula → id_usuario (usuarios); opcional pareja; opcional columna usuario (id del otro
     * sistema) para mapear resultado.usuario → id_usuario. Archivo 2: partida, mesa, secuencia, r1/r2, usuario (externo)
     * o cédula o pareja+jugador. El valor "usuario" del export NO es id de Mistorneos: se traduce con el archivo 1.
     *
     * @return array{insertados: int, errores: list<string>, homologacion_sin_usuario: int, resultados_sin_resolver: int, cedulas_no_encontradas: list<string>}
     */
    public static function importarDosArchivosPartiresul(
        PDO $pdo,
        int $torneo_id,
        int $registrado_por,
        string $fechaTorneoYmd,
        array $rowsHomologacion,
        array $rowsResultados
    ): array {
        $stats = [
            'insertados' => 0,
            'errores' => [],
            'homologacion_sin_usuario' => 0,
            'resultados_sin_resolver' => 0,
            'cedulas_no_encontradas' => [],
            'filas_bloque_cedulas' => max(0, count($rowsHomologacion) - 1),
            'filas_bloque_resultados' => max(0, count($rowsResultados) - 1),
            'mapeos_usuario_externo' => 0,
            'columna_usuario_homolog' => false,
            'columna_usuario_resultados' => false,
            'atletas_vinculados' => 0,
            'parejas_reconstruidas' => 0,
            'inscripciones_nuevas' => 0,
            'resultados_celdas_rellenadas_pareja' => 0,
            'resultados_celdas_rellenadas_usuario' => 0,
            'matriz_homologados_n' => 0,
            'matriz_rondas' => 0,
            'matriz_total_partidas_esperadas' => 0,
            'matriz_total_filas' => 0,
            'mensaje_homologacion_matriz' => '',
        ];
        if ($rowsHomologacion === [] || $rowsResultados === []) {
            $stats['errores'][] = 'Faltan filas en archivo de homologación o de resultados.';
            return $stats;
        }

        $h0raw = $rowsHomologacion[0];
        $maxCols = 0;
        for ($k = 0; $k < min(5, count($rowsHomologacion)); $k++) {
            $maxCols = max($maxCols, count($rowsHomologacion[$k] ?? []));
        }
        $hNormHom = array_map(static function ($x) {
            $s = strtolower(trim((string)$x));
            $s = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'], ['a', 'e', 'i', 'o', 'u', 'n', 'u'], $s);
            return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $s));
        }, $h0raw);
        $mapHom = self::mapearIndices($h0raw, ['pareja' => ['pareja', 'id_pareja', 'parejas'], 'cedula' => ['cedula', 'cedula1', 'ci', 'documento', 'c_dula']]);
        if ($mapHom['cedula'] < 0) {
            foreach ($hNormHom as $hi => $col) {
                if ($col === 'cedula' || strpos($col, 'cedul') !== false || $col === 'ci' || strpos($col, 'documento') !== false) {
                    $mapHom['cedula'] = $hi;
                    break;
                }
            }
        }
        $iExtHom = -1;
        $candidatosExt = ['usuario', 'id_externo', 'cod_jugador', 'id_jugador', 'id', 'codigo', 'cod', 'externo', 'jugador_id', 'idjugador', 'nro', 'numero'];
        foreach ($hNormHom as $hi => $col) {
            if ($hi === $mapHom['cedula'] || $hi === $mapHom['pareja']) {
                continue;
            }
            if (in_array($col, $candidatosExt, true) || ($col !== '' && strpos($col, 'id_') === 0)) {
                $iExtHom = $hi;
                break;
            }
        }
        if ($iExtHom < 0 && $mapHom['cedula'] >= 0) {
            foreach ($hNormHom as $hi => $col) {
                if ($hi !== $mapHom['cedula'] && $hi !== $mapHom['pareja']) {
                    $iExtHom = $hi;
                    break;
                }
            }
        }
        $homologRows = $rowsHomologacion;
        $dataStartIdx = 1;
        if ($mapHom['cedula'] < 0 || $iExtHom < 0) {
            if ($maxCols >= 2) {
                $r0 = $rowsHomologacion[0] ?? [];
                $r1 = $rowsHomologacion[1] ?? $r0;
                $a0 = trim((string)($r0[0] ?? ''));
                $b0 = trim((string)($r0[1] ?? ''));
                $lenA = strlen(preg_replace('/\D/', '', $a0));
                $lenB = strlen(preg_replace('/\D/', '', $b0));
                $digitsA = preg_replace('/\D/', '', $a0);
                $digitsB = preg_replace('/\D/', '', $b0);
                if ($lenA >= 5 && $lenB < $lenA) {
                    $mapHom['cedula'] = 0;
                    $iExtHom = 1;
                } else {
                    $mapHom['cedula'] = 1;
                    $iExtHom = 0;
                }
                $soloDatosNumericosFila0 = $a0 !== '' && $b0 !== ''
                    && ctype_digit($digitsA) && ctype_digit($digitsB)
                    && strlen($digitsA) <= 12 && strlen($digitsB) <= 12;
                if ($soloDatosNumericosFila0) {
                    if ($lenA >= 5 && $lenB < $lenA) {
                        $mapHom['cedula'] = 0;
                        $iExtHom = 1;
                    } else {
                        $mapHom['cedula'] = 1;
                        $iExtHom = 0;
                    }
                    $homologRows = array_merge([['id_externo', 'cedula']], $rowsHomologacion);
                    $dataStartIdx = 1;
                }
            }
        }
        if ($mapHom['cedula'] < 0 || $iExtHom < 0 || $mapHom['cedula'] === $iExtHom) {
            $stats['errores'][] = 'Homologación: hoja 1 con al menos 2 columnas. Orden recomendado: id externo | cédula (ej. 37 y 4906763). Puede poner fila títulos usuario + cedula, o solo filas de datos.';
            $stats['filas_bloque_cedulas'] = max(0, count($rowsHomologacion) - 1);
            return $stats;
        }

        $iNombreHom = -1;
        foreach ($hNormHom as $hi => $col) {
            if ($hi === $mapHom['cedula'] || $hi === $iExtHom || ($mapHom['pareja'] >= 0 && $hi === $mapHom['pareja'])) {
                continue;
            }
            if ($col === 'nombre' || str_contains((string) $col, 'nombre') || $col === 'n1' || str_contains((string) $col, 'atleta')) {
                $iNombreHom = $hi;
                break;
            }
        }
        $iNacHom = -1;
        foreach ($hNormHom as $hi => $col) {
            if ($hi === $mapHom['cedula'] || $hi === $iExtHom) {
                continue;
            }
            if ($col === 'nac' || $col === 'nacionalidad' || str_contains((string) $col, 'nacionalidad')) {
                $iNacHom = $hi;
                break;
            }
        }

        $cedulaToId = [];
        $parejaToIds = [];
        /** @var array<int, string> id_usuario → clave pareja del archivo (homologación unificada / matriz) */
        $idUsuarioToClavePareja = [];
        $extUsuarioToId = [];
        $filasHomologConId = 0;
        $noEncCed = [];
        for ($i = $dataStartIdx; $i < count($homologRows); $i++) {
            $row = $homologRows[$i];
            $maxIdxHom = max($mapHom['cedula'], $iExtHom);
            if ($mapHom['pareja'] >= 0) {
                $maxIdxHom = max($maxIdxHom, $mapHom['pareja']);
            }
            if ($iNombreHom >= 0) {
                $maxIdxHom = max($maxIdxHom, $iNombreHom);
            }
            if ($iNacHom >= 0) {
                $maxIdxHom = max($maxIdxHom, $iNacHom);
            }
            while (count($row) <= $maxIdxHom) {
                $row[] = '';
            }
            $ced = self::normalizarCedula($row[$mapHom['cedula']] ?? '');
            $extKey = trim((string) ($row[$iExtHom] ?? ''));
            if ($ced === '' || $extKey === '') {
                continue;
            }
            $nomH = $iNombreHom >= 0 ? trim((string) ($row[$iNombreHom] ?? '')) : '';
            $nacH = $iNacHom >= 0 ? trim((string) ($row[$iNacHom] ?? '')) : '';
            $idU = self::resolverIdUsuarioInscripcionSitio($pdo, $torneo_id, $ced, $nomH, $nacH, $registrado_por, $stats);
            if ($idU > 0) {
                $filasHomologConId++;
                $cedulaToId[$ced] = $idU;
                $cedulaToId[preg_replace('/\D/', '', $ced)] = $idU;
                $extUsuarioToId[$extKey] = $idU;
                if (is_numeric($extKey)) {
                    $extUsuarioToId[(string) (int) $extKey] = $idU;
                }
            } else {
                $noEncCed[] = $ced;
            }
            if ($mapHom['pareja'] >= 0 && $idU > 0) {
                $pkey = trim((string) ($row[$mapHom['pareja']] ?? ''));
                if ($pkey !== '') {
                    $parejaToIds[$pkey][] = $idU;
                    $idUsuarioToClavePareja[$idU] = $pkey;
                }
            }
        }
        foreach ($parejaToIds as $pk => $idsP) {
            $parejaToIds[$pk] = array_values(array_unique(array_map('intval', $idsP)));
        }
        $stats['homologacion_sin_usuario'] = count(array_unique($noEncCed));
        $stats['cedulas_no_encontradas'] = array_values(array_unique($noEncCed));
        $stats['atletas_vinculados'] = count(array_unique(array_values($extUsuarioToId)));
        foreach ($parejaToIds as $pk => $idsP) {
            if (count(array_unique(array_map('intval', $idsP))) >= 2) {
                $stats['parejas_reconstruidas']++;
            }
        }

        $enr = self::fase1Enriquecer($pdo, $homologRows);
        $filasHom = $enr['filas'];
        if (count($filasHom) < 2) {
            $stats['errores'][] = 'Homologación sin filas de datos.';
            return $stats;
        }
        $hHom = $filasHom[0];
        $idxIdUsuarioHom = count($hHom) - 1;
        $stats['filas_bloque_cedulas'] = count($filasHom) - 1;
        $stats['mapeos_usuario_externo'] = count($extUsuarioToId);
        $stats['columna_usuario_homolog'] = $iExtHom >= 0;
        $stats['cedulas_con_usuario_mistorneos'] = $filasHomologConId;

        $headerRes = $rowsResultados[0];
        $hNorm = array_map(static fn ($x) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string)$x))), $headerRes);
        $find = static function (array $h, array $names): int {
            foreach ($names as $n) {
                $n = strtolower($n);
                foreach ($h as $i => $col) {
                    if (str_contains((string)$col, $n) || $col === $n) {
                        return $i;
                    }
                }
            }
            return -1;
        };
        $iPart = self::indiceColumnaPartida($hNorm);
        if ($iPart < 0) {
            $iPart = $find($hNorm, ['partida', 'ronda']);
        }
        $iMesa = self::indiceColumnaMesa($hNorm);
        if ($iMesa < 0) {
            $iMesa = $find($hNorm, ['mesa']);
        }
        $iSeq = self::indiceColumnaSecuencia($hNorm);
        if ($iSeq < 0) {
            $iSeq = $find($hNorm, ['secuencia', 'seq']);
        }
        $iR1 = $find($hNorm, ['resultado1', 'result1', 'result_1', 'r1', 'pts1', 'pf', 'puntos_favor', 'favor']);
        $iR2 = $find($hNorm, ['resultado2', 'result2', 'result_2', 'r2', 'pts2', 'pc', 'puntos_contra', 'contra']);
        $iFf = $find($hNorm, ['ff', 'forfait']);
        $iTarRes = self::indiceColumnaImport($hNorm, ['tarjeta', 'amarilla', 'roja'], ['tarjeta_amarilla', 'tarjeta_roja', 'marca_tarjeta']);
        if ($iTarRes < 0) {
            $iTarRes = $find($hNorm, ['tarjeta', 'marca_tarjeta']);
        }
        $iSancionPuntos = -1;
        foreach ($hNorm as $ri => $col) {
            $c = (string) $col;
            if (str_contains($c, 'sancionp') || str_contains($c, 'sancion_p')) {
                $iSancionPuntos = $ri;
                break;
            }
        }
        /** «Sancion» como marca solo si existe «SancionP» aparte (si no, una sola columna usa la lógica mixta). */
        $iSancionMarca = -1;
        if ($iSancionPuntos >= 0) {
            foreach ($hNorm as $ri => $col) {
                if ((string) $col === 'sancion') {
                    $iSancionMarca = $ri;
                    break;
                }
            }
        }
        $iSanRes = -1;
        if ($iSancionPuntos >= 0) {
            $iSanRes = $iSancionPuntos;
        } else {
            foreach ($hNorm as $ri => $col) {
                $c = (string) $col;
                if (str_contains($c, 'penaliza') || $c === 'penal' || str_starts_with($c, 'penal_')) {
                    $iSanRes = $ri;
                    break;
                }
            }
            if ($iSanRes < 0) {
                foreach ($hNorm as $ri => $col) {
                    if ($col === 'sancion' || $col === 'sanc') {
                        $iSanRes = $ri;
                        break;
                    }
                }
            }
            if ($iSanRes < 0) {
                $iSanRes = $find($hNorm, ['sancionp', 'sancion_p', 'penal', 'penalizacion']);
            }
        }
        $iCed = $find($hNorm, ['cedula', 'cedula1', 'ci', 'documento']);
        $iNombreRes = $find($hNorm, ['nombre', 'n1', 'jugador', 'atleta']);
        $iNacRes = $find($hNorm, ['nacionalidad', 'nac']);
        $iPareja = $find($hNorm, ['pareja', 'id_pareja', 'parejas']);
        $iJug = $find($hNorm, ['jugador', 'miembro', 'pos_pareja', 'jp', 'slot']);
        /* Id del otro sistema en hoja resultados (no es id_usuario Mistorneos hasta homologar) */
        $iExtRes = -1;
        $candidatosExt = ['usuario', 'id_externo', 'id_jugador_externo', 'cod_jugador', 'codigo', 'cod', 'externo'];
        foreach ($hNorm as $ri => $col) {
            if ($col === 'usuario' || $col === 'id_usuario_externo') {
                $iExtRes = $ri;
                break;
            }
            if (in_array($col, $candidatosExt, true)) {
                $iExtRes = $ri;
                break;
            }
        }
        if ($iPart < 0 || $iMesa < 0 || $iSeq < 0 || $iR1 < 0 || $iR2 < 0) {
            $stats['errores'][] = 'Resultados: faltan partida, mesa, secuencia, r1/resultado1 o r2/resultado2.';
            $stats['columna_usuario_resultados'] = $iExtRes >= 0;
            return $stats;
        }
        $stats['columna_usuario_resultados'] = $iExtRes >= 0;
        $puedePorExt = $iExtRes >= 0 && $extUsuarioToId !== [];
        if ($iExtRes >= 0 && $extUsuarioToId === [] && $iPareja < 0 && $iCed < 0) {
            $muestra = array_slice($noEncCed, 0, 15);
            $stats['errores'][] = 'Mapa vacío: no se resolvió ningún id externo → usuario (revisar homologación: cédula, nombre si aplica, o BD externa). Cédulas sin resolver (muestra): ' . implode(', ', $muestra) . '.';
            return $stats;
        }
        if ($iCed < 0 && $iPareja < 0 && !$puedePorExt) {
            $stats['errores'][] = 'En resultados hace falta una de: cédula, columna usuario/id externo (homologación), o pareja (con homologación de parejas).';
            return $stats;
        }

        if ($iPareja >= 0) {
            $rowsResultados = self::rellenarHuecosColumnaPorPartidaMesa(
                $rowsResultados,
                $iPart,
                $iMesa,
                $iSeq,
                $iPareja,
                $stats,
                'resultados_celdas_rellenadas_pareja'
            );
        }
        if ($iExtRes >= 0) {
            $rowsResultados = self::rellenarHuecosColumnaPorPartidaMesa(
                $rowsResultados,
                $iPart,
                $iMesa,
                $iSeq,
                $iExtRes,
                $stats,
                'resultados_celdas_rellenadas_usuario'
            );
        }

        require_once __DIR__ . '/TorneoCampoNumerico.php';
        $iEfectRes = $find($hNorm, ['efectiv', 'efectividad', 'efect']);
        $hdrRes = ['partida', 'mesa', 'secuencia', 'id_usuario', 'resultado1', 'resultado2', 'ff', 'sancion', 'tarjeta'];
        if ($iEfectRes >= 0) {
            $hdrRes[] = 'efectividad';
        }
        /**
         * Matriz en memoria (sin INSERT todavía): [ id_usuario string ] => lista de partidas con datos de origen.
         * Se rellena solo después de: (1) parseo completo del archivo en $staged, (2) resolución por lote de cédulas.
         *
         * @var array<string, list<array{ronda: int, mesa: int, secuencia: int, result1: int, result2: int, sancionp: int, tarjeta: int, ff: int, efectividad?: int}>>
         */
        $matrizAtletas = [];
        $vectorPorUsuario = [];

        /** @var list<array{id_map: int, ced: string, nom: string, nac: string, pkey: string, j: int, fm: array<string, int>}> */
        $staged = [];
        for ($r = 1; $r < count($rowsResultados); $r++) {
            $row = $rowsResultados[$r];
            $idUsuario = 0;
            if ($iExtRes >= 0 && $extUsuarioToId !== []) {
                $uk = trim((string) ($row[$iExtRes] ?? ''));
                if ($uk !== '') {
                    $idUsuario = (int) ($extUsuarioToId[$uk] ?? $extUsuarioToId[(string) (int) $uk] ?? 0);
                }
            }
            $ced = '';
            $nomR = '';
            $nacR = '';
            if ($idUsuario <= 0 && $iCed >= 0) {
                $ced = self::normalizarCedula($row[$iCed] ?? '');
                if ($ced !== '') {
                    $idUsuario = (int) ($cedulaToId[$ced] ?? $cedulaToId[preg_replace('/\D/', '', $ced)] ?? 0);
                    $nomR = $iNombreRes >= 0 ? trim((string) ($row[$iNombreRes] ?? '')) : '';
                    $nacR = $iNacRes >= 0 ? trim((string) ($row[$iNacRes] ?? '')) : '';
                }
            }
            $pkey = '';
            $jSlot = 1;
            if ($idUsuario <= 0 && $iPareja >= 0) {
                $pkey = trim((string) ($row[$iPareja] ?? ''));
                if ($pkey !== '') {
                    if ($iJug >= 0) {
                        $jSlot = max(1, min(2, (int) ($row[$iJug] ?? 1)));
                    } else {
                        $seqM = (int) ($row[$iSeq] ?? 1);
                        $jSlot = self::indiceJugadorEnParejaPorSecuenciaMesa($seqM);
                    }
                    if (isset($parejaToIds[$pkey][$jSlot - 1])) {
                        $idUsuario = (int) $parejaToIds[$pkey][$jSlot - 1];
                    }
                }
            }
            $pNum = (int) ($row[$iPart] ?? 0);
            $mNum = (int) ($row[$iMesa] ?? 0);
            $sNum = (int) ($row[$iSeq] ?? 0);
            $ff = $iFf >= 0 ? self::parseFfValor($row[$iFf] ?? 0) : 0;
            $tarjetaFila = 0;
            $sancionFila = 0;
            if ($iSancionMarca >= 0) {
                $tarjetaFila = self::parseMarcaTarjeta($row[$iSancionMarca] ?? '');
            } elseif ($iTarRes >= 0) {
                $tarjetaFila = self::parseMarcaTarjeta($row[$iTarRes] ?? '');
            }
            if ($iSanRes >= 0) {
                $celSan = $row[$iSanRes] ?? 0;
                if ($iSancionMarca < 0 && $iTarRes < 0) {
                    $rawS = trim((string) $celSan);
                    if ($rawS !== '' && !is_numeric($rawS)) {
                        $tarjetaFila = self::parseMarcaTarjeta($celSan);
                    } else {
                        $sancionFila = \TorneoCampoNumerico::intEstadistica($celSan);
                    }
                } else {
                    $sancionFila = \TorneoCampoNumerico::intEstadistica($celSan);
                }
                $sancionFila = min(80, max(0, $sancionFila));
            }
            $filaMatriz = [
                'ronda' => $pNum,
                'mesa' => $mNum,
                'secuencia' => $sNum,
                'result1' => (int) ($row[$iR1] ?? 0),
                'result2' => (int) ($row[$iR2] ?? 0),
                'sancionp' => $sancionFila,
                'tarjeta' => $tarjetaFila,
                'ff' => $ff,
            ];
            if ($iEfectRes >= 0) {
                $filaMatriz['efectividad'] = \TorneoCampoNumerico::intEstadistica($row[$iEfectRes] ?? 0);
            }
            $staged[] = [
                'id_map' => $idUsuario,
                'ced' => $ced,
                'nom' => $nomR,
                'nac' => $nacR,
                'pkey' => $pkey,
                'j' => $jSlot,
                'fm' => $filaMatriz,
            ];
        }

        $cedulasUnicasResolver = [];
        foreach ($staged as $st) {
            if ($st['id_map'] > 0) {
                continue;
            }
            if ($st['ced'] === '') {
                continue;
            }
            $cedK = $st['ced'];
            $digK = preg_replace('/\D/', '', $cedK);
            if ((int) ($cedulaToId[$cedK] ?? $cedulaToId[$digK] ?? 0) > 0) {
                continue;
            }
            $cedulasUnicasResolver[$cedK] = ['nom' => $st['nom'], 'nac' => $st['nac']];
        }
        foreach ($cedulasUnicasResolver as $cedK => $meta) {
            $idR = self::resolverIdUsuarioInscripcionSitio(
                $pdo,
                $torneo_id,
                $cedK,
                (string) ($meta['nom'] ?? ''),
                (string) ($meta['nac'] ?? ''),
                $registrado_por,
                $stats
            );
            if ($idR > 0) {
                $cedulaToId[$cedK] = $idR;
                $cedulaToId[preg_replace('/\D/', '', $cedK)] = $idR;
            }
        }

        foreach ($staged as $st) {
            $idUsuario = (int) $st['id_map'];
            $ced = $st['ced'];
            if ($idUsuario <= 0 && $ced !== '') {
                $idUsuario = (int) ($cedulaToId[$ced] ?? $cedulaToId[preg_replace('/\D/', '', $ced)] ?? 0);
            }
            if ($idUsuario <= 0 && $st['pkey'] !== '') {
                $jk = (int) $st['j'];
                if (isset($parejaToIds[$st['pkey']][$jk - 1])) {
                    $idUsuario = (int) $parejaToIds[$st['pkey']][$jk - 1];
                }
            }
            if ($idUsuario <= 0) {
                $stats['resultados_sin_resolver']++;
                continue;
            }
            if ($st['pkey'] !== '' && (($idUsuarioToClavePareja[$idUsuario] ?? '') === '')) {
                $idUsuarioToClavePareja[$idUsuario] = $st['pkey'];
            }
            $sid = (string) $idUsuario;
            if (! isset($matrizAtletas[$sid])) {
                $matrizAtletas[$sid] = [];
            }
            $matrizAtletas[$sid][] = $st['fm'];
            $fm = $st['fm'];
            $vectorPorUsuario[$idUsuario] = [
                'torneo_id' => $torneo_id,
                'partida' => (int) ($fm['ronda'] ?? 0),
                'mesa' => (int) ($fm['mesa'] ?? 0),
                'secuencia' => (int) ($fm['secuencia'] ?? 0),
                'resultado1' => (int) ($fm['result1'] ?? 0),
                'resultado2' => (int) ($fm['result2'] ?? 0),
                'ff' => (int) ($fm['ff'] ?? 0),
                'sancion' => (int) ($fm['sancionp'] ?? 0),
            ];
        }

        $rondasArchivo = 0;
        foreach ($matrizAtletas as $filasM) {
            foreach ($filasM as $it) {
                $rondasArchivo = max($rondasArchivo, (int) ($it['ronda'] ?? 0));
            }
        }
        if ($rondasArchivo < 1) {
            $stR = $pdo->prepare('SELECT COALESCE(NULLIF(rondas, 0), 9) AS r FROM tournaments WHERE id = ? LIMIT 1');
            $stR->execute([$torneo_id]);
            $rondasArchivo = max(1, (int) ($stR->fetchColumn() ?: 9));
        }
        $N = count($matrizAtletas);
        $M = $N * $rondasArchivo;
        $totalFilasMatriz = 0;
        foreach ($matrizAtletas as $filasM) {
            $totalFilasMatriz += count($filasM);
        }
        $stats['matriz_homologados_n'] = $N;
        $stats['matriz_rondas'] = $rondasArchivo;
        $stats['matriz_total_partidas_esperadas'] = $M;
        $stats['matriz_total_filas'] = $totalFilasMatriz;
        $stats['mensaje_homologacion_matriz'] = $N > 0
            ? ('Homologados ' . $N . ' atletas con un total de ' . $M . ' partidas')
            : '';

        ksort($matrizAtletas, SORT_STRING);
        foreach ($matrizAtletas as $uidStr => $filasM) {
            usort($filasM, static function (array $a, array $b): int {
                if (($a['ronda'] ?? 0) !== ($b['ronda'] ?? 0)) {
                    return ($a['ronda'] ?? 0) <=> ($b['ronda'] ?? 0);
                }
                if (($a['mesa'] ?? 0) !== ($b['mesa'] ?? 0)) {
                    return ($a['mesa'] ?? 0) <=> ($b['mesa'] ?? 0);
                }

                return ($a['secuencia'] ?? 0) <=> ($b['secuencia'] ?? 0);
            });
            $matrizAtletas[$uidStr] = $filasM;
        }
        foreach (array_keys($matrizAtletas) as $uidStr) {
            $uid = (int) $uidStr;
            $claveP = $idUsuarioToClavePareja[$uid] ?? '';
            self::asegurarInscritoYAsignarParejaDesdeHomologacion($pdo, $torneo_id, $uid, $claveP, $registrado_por, $stats);
        }

        $nuevasFilas = [];
        $nuevasFilas[] = $hdrRes;
        $incluirEfect = $iEfectRes >= 0;
        foreach ($matrizAtletas as $uidStr => $filasM) {
            $uid = (int) $uidStr;
            foreach ($filasM as $filaMatriz) {
                $nuevasFilas[] = self::partiresulFilaDesdeMatrizAtleta($uid, $filaMatriz, $incluirEfect);
            }
        }

        $resInsert = self::fase2InsertarPartiresul($pdo, $torneo_id, $registrado_por, $fechaTorneoYmd, $nuevasFilas);
        $stats['insertados'] = $resInsert['insertados'];
        $stats['errores'] = $resInsert['errores'];
        $stats['auditoria_por_ronda'] = $resInsert['auditoria_por_ronda'] ?? [];
        $stats['vector_atletas_mapeados'] = count($vectorPorUsuario);
        $f2rid = $resInsert['resolucion_identidad_fase2'] ?? [];
        foreach ($f2rid as $k => $v) {
            if ($k === '_ids_atletas' || $k === 'atletas_vinculados' || $k === 'rondas_limite_torneo' || $k === 'filas_omitidas_partida_superior_limite') {
                continue;
            }
            if (is_numeric($v)) {
                $stats[$k] = (int) ($stats[$k] ?? 0) + (int) $v;
            }
        }
        if (isset($f2rid['rondas_limite_torneo'])) {
            $stats['rondas_limite_torneo'] = (int) $f2rid['rondas_limite_torneo'];
        }
        if (isset($f2rid['filas_omitidas_partida_superior_limite'])) {
            $stats['filas_omitidas_partida_superior_limite'] = (int) $f2rid['filas_omitidas_partida_superior_limite'];
        }
        $stats['atletas_vinculados'] = max(
            (int) ($stats['atletas_vinculados'] ?? 0),
            (int) ($f2rid['atletas_vinculados'] ?? 0)
        );
        $stats['filas_listas_para_insertar'] = max(0, count($nuevasFilas) - 1);

        return $stats;
    }

    /**
     * Un solo Excel/CSV:
     * - Opción A: dos hojas — Hoja1 = homologación (cédula + usuario id externo), Hoja2 = resultados.
     * - Opción B: una hoja — arriba bloque homologación (1ª fila encabezados cédula + usuario), debajo fila de encabezados
     *   de resultados (partida, mesa, secuencia, usuario, r1, r2…) y el resto de filas.
     *
     * @return array{0: list<list<string>>, 1: list<list<string>>, error: string}
     */
    public static function dividirArchivoUnico(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xlsx' && is_readable($path)) {
            require_once __DIR__ . '/CargaMasivaXlsxReader.php';
            $sheets = CargaMasivaXlsxReader::leerTodasHojasEnOrden($path);
            if (count($sheets) >= 2) {
                return [$sheets[0], $sheets[1], ''];
            }
            if (count($sheets) === 1) {
                [$a, $b] = self::dividirUnaTablaEnDosBloques($sheets[0]);
                return [$a, $b, ($a === [] || $b === []) ? 'En una sola hoja no se encontró el bloque de resultados (fila con columnas partida, mesa, secuencia) después del bloque cédula+usuario.' : ''];
            }
            return [[], [], 'No se pudo leer el Excel.'];
        }
        $rows = self::leerExcelOCsv($path, $originalName);
        [$a, $b] = self::dividirUnaTablaEnDosBloques($rows);
        return [$a, $b, ($a === [] || $b === []) ? 'No se detectaron dos bloques: arriba cédula+usuario, abajo cabecera partida/mesa/secuencia.' : ''];
    }

    /**
     * @return array{0: list<list<string>>, 1: list<list<string>>}
     */
    private static function dividirUnaTablaEnDosBloques(array $rows): array
    {
        if (count($rows) < 2) {
            return [[], []];
        }
        for ($i = 0; $i < count($rows); $i++) {
            if (self::headerFilaDefineResultadosPartiresul($rows[$i])) {
                $homolog = array_slice($rows, 0, $i);
                if ($homolog === []) {
                    return [[], []];
                }
                return [$homolog, array_slice($rows, $i)];
            }
        }
        return [[], []];
    }

    /**
     * Fila de encabezados del bloque resultados (partida/mesa/secuencia) sin confundir con «partidas» u otras columnas.
     *
     * @param list<string> $filaTitulos
     */
    private static function headerFilaDefineResultadosPartiresul(array $filaTitulos): bool
    {
        $h = array_map(static fn ($x) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $x))), $filaTitulos);

        return self::indiceColumnaPartida($h) >= 0
            && self::indiceColumnaMesa($h) >= 0
            && self::indiceColumnaSecuencia($h) >= 0;
    }

    /**
     * @return array{insertados: int, errores: list<string>, homologacion_sin_usuario: int, resultados_sin_resolver: int, cedulas_no_encontradas: list<string>, split_error: string}
     */
    public static function importarUnSoloArchivoPartiresul(
        PDO $pdo,
        int $torneo_id,
        int $registrado_por,
        string $fechaTorneoYmd,
        string $path,
        string $originalName
    ): array {
        [$hom, $res, $err] = self::dividirArchivoUnico($path, $originalName);
        $stats = self::importarDosArchivosPartiresul($pdo, $torneo_id, $registrado_por, $fechaTorneoYmd, $hom, $res);
        $stats['split_error'] = $err;
        $stats['filas_hoja_homolog_raw'] = max(0, count($hom) - 1);
        $stats['filas_hoja_resultados_raw'] = max(0, count($res) - 1);
        return $stats;
    }

    /**
     * Propaga valores no vacíos en una columna dentro de cada grupo (partida, mesa), ordenado por secuencia.
     * Cubre celdas combinadas en Excel (pareja / id externo) que al exportar quedan vacías salvo la primera fila.
     *
     * @param list<list<string>> $rows
     * @param array<string, int|float|string|bool> $statsAcum
     * @return list<list<string>>
     */
    private static function rellenarHuecosColumnaPorPartidaMesa(
        array $rows,
        int $iPart,
        int $iMesa,
        int $iSeq,
        int $iCol,
        ?array &$statsAcum = null,
        string $statKey = ''
    ): array {
        if ($iCol < 0 || count($rows) < 2 || $iPart < 0 || $iMesa < 0 || $iSeq < 0) {
            return $rows;
        }
        $out = $rows;
        $byPartida = [];
        for ($r = 1; $r < count($out); $r++) {
            $p = (int) ($out[$r][$iPart] ?? 0);
            if ($p < 1) {
                continue;
            }
            $byPartida[$p][] = $r;
        }
        foreach ($byPartida as $indices) {
            usort($indices, static function (int $a, int $b) use ($out, $iMesa, $iSeq): int {
                $ma = (int) ($out[$a][$iMesa] ?? 0);
                $mb = (int) ($out[$b][$iMesa] ?? 0);
                if ($ma !== $mb) {
                    return $ma <=> $mb;
                }
                $sa = (int) ($out[$a][$iSeq] ?? 0);
                $sb = (int) ($out[$b][$iSeq] ?? 0);

                return $sa <=> $sb;
            });
            $byMesa = [];
            foreach ($indices as $r) {
                $m = (int) ($out[$r][$iMesa] ?? 0);
                if ($m < 1) {
                    continue;
                }
                $byMesa[$m][] = $r;
            }
            foreach ($byMesa as $mesaRows) {
                $last = '';
                foreach ($mesaRows as $r) {
                    self::filaAseguraIndice($out[$r], $iCol);
                    $v = trim((string) ($out[$r][$iCol] ?? ''));
                    if ($v !== '') {
                        $last = $v;
                    } elseif ($last !== '') {
                        $out[$r][$iCol] = $last;
                        if ($statKey !== '' && $statsAcum !== null) {
                            $statsAcum[$statKey] = (int) ($statsAcum[$statKey] ?? 0) + 1;
                        }
                    }
                }
                $last = '';
                for ($k = count($mesaRows) - 1; $k >= 0; $k--) {
                    $r = $mesaRows[$k];
                    self::filaAseguraIndice($out[$r], $iCol);
                    $v = trim((string) ($out[$r][$iCol] ?? ''));
                    if ($v !== '') {
                        $last = $v;
                    } elseif ($last !== '') {
                        $out[$r][$iCol] = $last;
                        if ($statKey !== '' && $statsAcum !== null) {
                            $statsAcum[$statKey] = (int) ($statsAcum[$statKey] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $row
     */
    private static function filaAseguraIndice(array &$row, int $idx): void
    {
        while (count($row) <= $idx) {
            $row[] = '';
        }
    }

    /**
     * Convierte una fila de la matriz en memoria ($matrizAtletas) al formato tabular esperado por fase2InsertarPartiresul.
     *
     * @param array{ronda?: int, mesa?: int, secuencia?: int, result1?: int, result2?: int, sancionp?: int, tarjeta?: int, ff?: int, efectividad?: int} $fm
     * @return list<string>
     */
    private static function partiresulFilaDesdeMatrizAtleta(int $idUsuario, array $fm, bool $incluirEfectividad): array
    {
        $fila = [
            (string) (int) ($fm['ronda'] ?? 0),
            (string) (int) ($fm['mesa'] ?? 0),
            (string) (int) ($fm['secuencia'] ?? 0),
            (string) $idUsuario,
            (string) (int) ($fm['result1'] ?? 0),
            (string) (int) ($fm['result2'] ?? 0),
            (string) (int) ($fm['ff'] ?? 0),
            (string) (int) ($fm['sancionp'] ?? 0),
            (string) (int) ($fm['tarjeta'] ?? 0),
        ];
        if ($incluirEfectividad) {
            $fila[] = (string) (int) ($fm['efectividad'] ?? 0);
        }

        return $fila;
    }

    /**
     * Tras agrupar por jugador (matriz): asegura inscripción y persiste número/código de pareja desde homologación.
     * Usa codigo_equipo (formato 000-NNN) y numero; si existe columna numero_pareja en inscritos, también se actualiza.
     */
    private static function asegurarInscritoYAsignarParejaDesdeHomologacion(
        PDO $pdo,
        int $torneoId,
        int $idUsuario,
        string $clavePareja,
        int $registradoPor,
        ?array &$statsAcum = null
    ): void {
        self::asegurarInscritoTorneoActivo($pdo, $torneoId, $idUsuario, $registradoPor, $statsAcum);
        $clave = trim($clavePareja);
        if ($clave === '' || $idUsuario <= 0 || $torneoId <= 0) {
            return;
        }
        require_once __DIR__ . '/InscritosHelper.php';
        $st = $pdo->prepare(
            'SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? AND ' . InscritosHelper::SQL_WHERE_NO_RETIRADO . ' LIMIT 1'
        );
        $st->execute([$torneoId, $idUsuario]);
        $idInscrito = (int) ($st->fetchColumn() ?: 0);
        if ($idInscrito <= 0) {
            return;
        }
        $digits = preg_replace('/\D/', '', $clave);
        $numPareja = $digits !== '' ? (int) substr($digits, -8) : 0;
        if ($numPareja > 99999999) {
            $numPareja %= 100000000;
        }
        $suf3 = $numPareja % 1000;
        $codigoEquipo = sprintf('000-%03d', $suf3);
        $numeroCol = min(99999, $numPareja % 100000);

        $colNames = [];
        foreach ($pdo->query('SHOW COLUMNS FROM inscritos')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $colNames[strtolower((string) $c['Field'])] = (string) $c['Field'];
        }
        $sets = ['`numero` = ?', '`codigo_equipo` = ?'];
        $params = [$numeroCol, $codigoEquipo];
        if (isset($colNames['numero_pareja'])) {
            $f = '`' . str_replace('`', '``', $colNames['numero_pareja']) . '` = ?';
            $sets[] = $f;
            $params[] = $numeroCol;
        }
        $params[] = $idInscrito;
        $sql = 'UPDATE inscritos SET ' . implode(', ', $sets) . ' WHERE id = ?';
        try {
            $up = $pdo->prepare($sql);
            $up->execute($params);
        } catch (Throwable $e) {
            error_log('ImportacionTorneoExternoService asegurarInscritoYAsignarParejaDesdeHomologacion: ' . $e->getMessage());
        }
    }

    /**
     * Mesa de 4: secuencias 1–4 suelen alternar jugador 1 y 2 dentro de cada pareja (1↔2, 3↔4).
     */
    private static function indiceJugadorEnParejaPorSecuenciaMesa(int $secuencia): int
    {
        if ($secuencia < 1) {
            return 1;
        }

        return (($secuencia - 1) % 2) + 1;
    }

    private static function normalizarCedula(string $c): string
    {
        return trim(preg_replace('/\s+/', '', $c));
    }

    /**
     * Club responsable del torneo + entidad territorial (misma lógica que carga masiva en sitio).
     *
     * @return array{0: int, 1: int} [club_id, entidad]
     */
    private static function clubYEntidadDesdeTorneo(PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare('SELECT club_responsable FROM tournaments WHERE id = ? LIMIT 1');
        $st->execute([$torneoId]);
        $clubId = (int) ($st->fetchColumn() ?: 0);
        $entidad = 0;
        if ($clubId > 0) {
            try {
                $st2 = $pdo->prepare('SELECT COALESCE(entidad, 0) FROM clubes WHERE id = ? LIMIT 1');
                $st2->execute([$clubId]);
                $entidad = (int) $st2->fetchColumn();
            } catch (Throwable $e) {
                $entidad = 0;
            }
        }

        return [$clubId, $entidad];
    }

    /**
     * Garantiza fila activa en inscritos para el torneo (transparente para partiresul).
     *
     * @param array<string, int|bool>|null $statsAcum Si se inserta inscripción nueva, incrementa inscripciones_nuevas
     */
    private static function asegurarInscritoTorneoActivo(PDO $pdo, int $torneoId, int $idUsuario, int $registradoPor, ?array &$statsAcum = null): bool
    {
        if ($torneoId <= 0 || $idUsuario <= 0) {
            return false;
        }
        require_once __DIR__ . '/InscritosHelper.php';
        $st = $pdo->prepare(
            'SELECT id FROM inscritos WHERE torneo_id = ? AND id_usuario = ? AND ' . InscritosHelper::SQL_WHERE_NO_RETIRADO . ' LIMIT 1'
        );
        $st->execute([$torneoId, $idUsuario]);
        if ($st->fetchColumn()) {
            return false;
        }
        [$idClub, ] = self::clubYEntidadDesdeTorneo($pdo, $torneoId);
        try {
            InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $idUsuario,
                'torneo_id' => $torneoId,
                'id_club' => $idClub > 0 ? $idClub : null,
                'estatus' => 1,
                'inscrito_por' => $registradoPor > 0 ? $registradoPor : null,
                'numero' => 0,
                'codigo_equipo' => '000-000',
            ]);
            if ($statsAcum !== null) {
                $statsAcum['inscripciones_nuevas'] = (int) ($statsAcum['inscripciones_nuevas'] ?? 0) + 1;
            }

            return true;
        } catch (Throwable $e) {
            error_log('ImportacionTorneoExternoService asegurarInscritoTorneoActivo: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Mapeador de identidad alineado con inscripción en sitio: usuarios → BD externa de personas → alta mínima con datos del archivo.
     *
     * @param array<string, int|bool> $statsAcum referencia opcional para acumular contadores (p. ej. usuarios_creados_import)
     */
    private static function resolverIdUsuarioInscripcionSitio(
        PDO $pdo,
        int $torneoId,
        string $cedRaw,
        string $nombrePreferido,
        string $nacPreferido,
        int $registradoPor,
        ?array &$statsAcum = null
    ): int {
        require_once __DIR__ . '/BusquedaJugadorInscripcionService.php';
        require_once __DIR__ . '/UsuarioInscripcionSitioHelper.php';

        $dig = BusquedaJugadorInscripcionService::cedulaSoloDigitos($cedRaw);
        if ($dig === '') {
            return 0;
        }
        $nac = BusquedaJugadorInscripcionService::normalizarNacionalidad($nacPreferido !== '' ? $nacPreferido : 'V');

        $u = BusquedaJugadorInscripcionService::buscarUsuarioPorCedula($pdo, $nac, $dig);
        $idU = 0;
        if ($u !== null) {
            $idU = (int) ($u['id'] ?? 0);
        }

        $nombre = trim($nombrePreferido);
        $ext = null;
        if ($idU <= 0) {
            $ext = BusquedaJugadorInscripcionService::buscarPersonaExternaPorCedula($nac, $dig);
            if ($ext !== null) {
                $p = $ext['persona'];
                if ($nombre === '') {
                    $nombre = trim((string) ($p['nombre'] ?? ''));
                }
            }
        }

        if ($idU <= 0) {
            if ($nombre === '') {
                $nombre = 'Atleta ' . $dig;
            }
            [$clubId, $entidadClub] = self::clubYEntidadDesdeTorneo($pdo, $torneoId);
            try {
                $cedParaAlta = self::normalizarCedula($cedRaw) !== '' ? self::normalizarCedula($cedRaw) : ($nac . $dig);
                $idU = UsuarioInscripcionSitioHelper::obtenerOCrearUsuarioJugador(
                    $pdo,
                    $cedParaAlta,
                    $nombre,
                    max(0, $clubId),
                    max(0, $entidadClub)
                );
            } catch (Throwable $e) {
                error_log('ImportacionTorneoExternoService resolverIdUsuarioInscripcionSitio: ' . $e->getMessage());

                return 0;
            }
        }

        if ($idU > 0) {
            self::asegurarInscritoTorneoActivo($pdo, $torneoId, $idU, $registradoPor, $statsAcum);
        }
        if ($statsAcum !== null && $u === null && $idU > 0) {
            $statsAcum['resoluciones_cedula_sin_usuario_previo'] = (int) ($statsAcum['resoluciones_cedula_sin_usuario_previo'] ?? 0) + 1;
            if ($ext !== null) {
                $statsAcum['resoluciones_via_bd_externa'] = (int) ($statsAcum['resoluciones_via_bd_externa'] ?? 0) + 1;
            }
        }

        return $idU;
    }

    /** @param mixed $v */
    private static function parseFfValor($v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_numeric($v)) {
            return ((int) $v) === 1 ? 1 : 0;
        }
        $s = strtoupper(trim((string) $v));

        return in_array($s, ['1', 'S', 'SI', 'Y', 'YES', 'TRUE', 'FF', 'FORFAIT'], true) ? 1 : 0;
    }

    /**
     * Marca administrativa (tarjeta amarilla/roja/negra) → código TorneoCampoNumerico (0/1/3/4).
     */
    /** @param mixed $v */
    private static function parseMarcaTarjeta($v): int
    {
        require_once __DIR__ . '/TorneoCampoNumerico.php';
        if ($v === null) {
            return 0;
        }
        if (is_numeric($v) && trim((string) $v) !== '') {
            return \TorneoCampoNumerico::codigoTarjeta($v);
        }
        $s = trim(strtolower((string) $v));
        if ($s === '' || $s === '-' || $s === 'no' || $s === 'ninguna' || $s === 'n' || $s === 'sin') {
            return 0;
        }
        if (str_contains($s, 'negra') || str_contains($s, 'black')) {
            return 4;
        }
        if (str_contains($s, 'roja') || $s === 'r' || str_contains($s, 'red')) {
            return 3;
        }
        if (str_contains($s, 'amar') || $s === 'a' || str_contains($s, 'yellow')) {
            return 1;
        }

        return \TorneoCampoNumerico::codigoTarjeta($v);
    }

    /**
     * Columna «partida» / n.º de ronda: no usar subcadena "partida" sobre «partidas», «partidas_jugadas», etc.
     *
     * @param list<string> $hNorm Cabeceras normalizadas (minúsculas, guiones bajos)
     */
    private static function indiceColumnaPartida(array $hNorm): int
    {
        $exact = ['partida', 'nro_partida', 'num_partida', 'nr_partida', 'numero_partida', 'num_part', 'n_partida', 'round', 'nronda', 'no_partida'];
        foreach ($exact as $ex) {
            foreach ($hNorm as $i => $col) {
                if ((string) $col === $ex) {
                    return $i;
                }
            }
        }
        foreach ($hNorm as $i => $col) {
            $c = (string) $col;
            if ($c === 'ronda' || $c === 'ronda_partida' || (strlen($c) >= 9 && str_ends_with($c, '_partida'))) {
                return $i;
            }
        }
        foreach ($hNorm as $i => $col) {
            $c = (string) $col;
            if (! str_contains($c, 'partida')) {
                continue;
            }
            if (str_starts_with($c, 'partidas')) {
                continue;
            }

            return $i;
        }
        foreach ($hNorm as $i => $col) {
            $c = (string) $col;
            if ($c === '' || ! str_contains($c, 'ronda')) {
                continue;
            }
            if ($c === 'rondas' || str_starts_with($c, 'rondas_')) {
                continue;
            }

            return $i;
        }

        return -1;
    }

    /**
     * @param list<string> $hNorm
     */
    private static function indiceColumnaMesa(array $hNorm): int
    {
        foreach (['mesa', 'n_mesa', 'num_mesa', 'nro_mesa', 'mesa_num', 'nro_de_mesa', 'table', 'mesa_numero'] as $ex) {
            foreach ($hNorm as $i => $col) {
                if ((string) $col === $ex) {
                    return $i;
                }
            }
        }
        foreach ($hNorm as $i => $col) {
            $c = (string) $col;
            if (! str_contains($c, 'mesa')) {
                continue;
            }
            if (str_starts_with($c, 'mesas') && $c !== 'mesas') {
                continue;
            }

            return $i;
        }

        return -1;
    }

    /**
     * @param list<string> $hNorm
     */
    private static function indiceColumnaSecuencia(array $hNorm): int
    {
        foreach (['secuencia', 'sequencia', 'orden', 'no_secuencia', 'nro_secuencia', 'posicion_mesa', 'pos_mesa', 'seq'] as $ex) {
            foreach ($hNorm as $i => $col) {
                if ((string) $col === $ex) {
                    return $i;
                }
            }
        }
        foreach ($hNorm as $i => $col) {
            $c = (string) $col;
            if (str_contains($c, 'secuencia')) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Índice de columna: primero coincidencia exacta del nombre normalizado, luego contiene (sin confundir sancion vs sancionp).
     *
     * @param list<string> $headerNorm Cabecera ya normalizada (minúsculas, guiones bajos)
     */
    private static function indiceColumnaImport(array $headerNorm, array $nombresExactos, array $contieneEnOrden): int
    {
        foreach ($nombresExactos as $ex) {
            $ex = strtolower($ex);
            foreach ($headerNorm as $i => $col) {
                if ((string) $col === $ex) {
                    return $i;
                }
            }
        }
        foreach ($contieneEnOrden as $sub) {
            $sub = strtolower($sub);
            foreach ($headerNorm as $i => $col) {
                $c = (string) $col;
                if ($c === $sub || str_contains($c, $sub)) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * Tras cargar una ronda: anomalías GDU (desde caché de obtenerReporteAnomalias) y mesas con distinto de 4 jugadores.
     *
     * @param list<array<string, mixed>> $gduTorneoCompleto Resultado de una sola llamada a obtenerReporteAnomalias($torneoId)
     *
     * @return array{gdu: list<array<string, mixed>>, mesas_incompletas: list<array<string, mixed>>}
     */
    private static function validarPostCargaRondaPartiresul(
        PDO $pdo,
        int $torneoId,
        int $partida,
        array $gduTorneoCompleto
    ): array {
        $gdu = [];
        foreach ($gduTorneoCompleto as $row) {
            if ((int) ($row['partida'] ?? 0) === $partida) {
                $gdu[] = $row;
            }
        }
        $st = $pdo->prepare(
            'SELECT mesa, COUNT(*) AS c FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0 GROUP BY mesa HAVING c <> 4'
        );
        $st->execute([$torneoId, $partida]);
        $mesasInc = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['gdu' => $gdu, 'mesas_incompletas' => $mesasInc];
    }

    /**
     * @param list<string> $header
     * @param array<string, list<string>> $keys
     * @return array<string, int>
     */
    private static function mapearIndices(array $header, array $keys): array
    {
        $h = array_map(static fn ($x) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string)$x))), $header);
        $out = ['pareja' => -1, 'cedula' => -1];
        foreach ($keys as $name => $aliases) {
            foreach ($aliases as $a) {
                $a = strtolower($a);
                foreach ($h as $i => $col) {
                    if ($col === $a || str_contains($col, $a)) {
                        $out[$name] = $i;
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    private static function efectividad(int $r1, int $r2, int $puntosTorneo, int $ff): int
    {
        if ($ff === 1) {
            return -$puntosTorneo;
        }
        $max = max($r1, $r2);
        if ($max >= $puntosTorneo) {
            if ($r1 === $r2) {
                return 0;
            }
            return $r1 > $r2 ? ($puntosTorneo - $r2) : -($puntosTorneo - $r1);
        }
        if ($r1 === $r2) {
            return 0;
        }
        return $r1 > $r2 ? ($r1 - $r2) : -($r2 - $r1);
    }

    /**
     * Organización y entidad por defecto según el torneo (club_responsable puede ser id de organización o de club).
     *
     * @return array{0: int, 1: int} [organizacion_id, entidad]
     */
    public static function organizacionYEntidadDefaultPorTorneo(PDO $pdo, int $torneoId): array
    {
        $st = $pdo->prepare('SELECT club_responsable FROM tournaments WHERE id = ? LIMIT 1');
        $st->execute([$torneoId]);
        $cr = (int) ($st->fetchColumn() ?: 0);
        if ($cr <= 0) {
            return [0, 0];
        }
        $stO = $pdo->prepare('SELECT id, entidad FROM organizaciones WHERE id = ? LIMIT 1');
        $stO->execute([$cr]);
        $o = $stO->fetch(PDO::FETCH_ASSOC);
        if ($o) {
            return [(int) ($o['id'] ?? 0), (int) ($o['entidad'] ?? 0)];
        }
        $stC = $pdo->prepare('SELECT organizacion_id, entidad FROM clubes WHERE id = ? LIMIT 1');
        $stC->execute([$cr]);
        $c = $stC->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            return [(int) ($c['organizacion_id'] ?? 0), (int) ($c['entidad'] ?? 0)];
        }

        return [0, 0];
    }

    /**
     * Busca club existente por nombre dentro de la misma organización (o global si org = 0).
     */
    private static function buscarClubIdPorNombreYOrg(PDO $pdo, string $nombre, int $orgId): int
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return 0;
        }
        if ($orgId > 0) {
            $st = $pdo->prepare('SELECT id FROM clubes WHERE organizacion_id = ? AND UPPER(TRIM(nombre)) = UPPER(?) LIMIT 1');
            $st->execute([$orgId, $nombre]);
        } else {
            $st = $pdo->prepare('SELECT id FROM clubes WHERE (organizacion_id IS NULL OR organizacion_id = 0) AND UPPER(TRIM(nombre)) = UPPER(?) LIMIT 1');
            $st->execute([$nombre]);
        }

        return (int) ($st->fetchColumn() ?: 0);
    }

    /**
     * Crea clubes desde Excel/CSV para que existan antes de homologar inscritos (mismo ámbito que el torneo).
     * Cabecera obligatoria: nombre (o club / nombre_club).
     * Opcionales: direccion, telefono, email, delegado, organizacion_id, entidad, codigo_externo (solo informativo en log).
     *
     * @param list<list<string>> $rows Primera fila = cabeceras
     *
     * @return array{creados: int, omitidos_duplicado: int, errores: list<string>, filas_datos: int, organizacion_default: int, entidad_default: int}
     */
    public static function importarClubesDesdeExcel(PDO $pdo, int $torneoId, array $rows): array
    {
        $out = [
            'creados' => 0,
            'omitidos_duplicado' => 0,
            'errores' => [],
            'filas_datos' => 0,
            'organizacion_default' => 0,
            'entidad_default' => 0,
        ];
        if ($rows === [] || count($rows) < 2) {
            $out['errores'][] = 'El archivo no tiene cabecera o datos.';

            return $out;
        }
        [$orgDef, $entDef] = self::organizacionYEntidadDefaultPorTorneo($pdo, $torneoId);
        $out['organizacion_default'] = $orgDef;
        $out['entidad_default'] = $entDef;

        $header = array_map(static fn ($h) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $h))), $rows[0]);
        $find = static function (array $h, array $names): int {
            foreach ($names as $n) {
                $n = strtolower($n);
                foreach ($h as $i => $col) {
                    if ($col === $n || str_contains((string) $col, $n)) {
                        return $i;
                    }
                }
            }

            return -1;
        };
        $iNom = $find($header, ['nombre', 'club', 'nombre_club', 'club_nombre']);
        if ($iNom < 0) {
            $out['errores'][] = 'Falta columna de nombre del club (nombre, club o nombre_club).';

            return $out;
        }
        $iDir = $find($header, ['direccion', 'dirección', 'domicilio']);
        $iTel = $find($header, ['telefono', 'teléfono', 'celular', 'phone']);
        $iEmail = $find($header, ['email', 'correo', 'mail']);
        $iDel = $find($header, ['delegado', 'contacto', 'responsable']);
        $iOrg = $find($header, ['organizacion_id', 'id_organizacion', 'organizacion']);
        $iEnt = $find($header, ['entidad', 'cod_entidad', 'id_entidad']);

        $pdo->beginTransaction();
        try {
            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $nombre = trim((string) ($row[$iNom] ?? ''));
                if ($nombre === '') {
                    continue;
                }
                $out['filas_datos']++;
                $org = $orgDef;
                if ($iOrg >= 0 && trim((string) ($row[$iOrg] ?? '')) !== '') {
                    $org = max(0, (int) ($row[$iOrg] ?? 0));
                }
                $ent = $entDef;
                if ($iEnt >= 0 && trim((string) ($row[$iEnt] ?? '')) !== '') {
                    $ent = max(0, (int) ($row[$iEnt] ?? 0));
                }
                if ($org <= 0 && $orgDef > 0) {
                    $org = $orgDef;
                }
                if ($ent <= 0 && $entDef > 0) {
                    $ent = $entDef;
                }

                if (self::buscarClubIdPorNombreYOrg($pdo, $nombre, $org) > 0) {
                    $out['omitidos_duplicado']++;
                    continue;
                }

                $dir = $iDir >= 0 ? trim((string) ($row[$iDir] ?? '')) : '';
                $tel = $iTel >= 0 ? trim((string) ($row[$iTel] ?? '')) : '';
                $em = $iEmail >= 0 ? trim((string) ($row[$iEmail] ?? '')) : '';
                $del = $iDel >= 0 ? trim((string) ($row[$iDel] ?? '')) : '';

                $stmt = $pdo->prepare(
                    'INSERT INTO clubes (nombre, direccion, delegado, telefono, email, estatus, organizacion_id, entidad)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
                );
                $stmt->execute([
                    $nombre,
                    $dir !== '' ? $dir : null,
                    $del !== '' ? $del : null,
                    $tel !== '' ? $tel : null,
                    $em !== '' ? $em : null,
                    $org > 0 ? $org : null,
                    $ent,
                ]);
                $out['creados']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['errores'][] = $e->getMessage();
        }

        return $out;
    }
}
