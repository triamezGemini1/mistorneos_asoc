<?php
/**
 * Admin general — Carga externa transparente.
 * Homologación: cédula → usuario (inscripción en sitio / BD externa / alta mínima). Opción A: id externo + cédula (como individual).
 * Opción B (parejas): cédula + columna pareja (sin id externo); se actualiza inscritos.numero = número de pareja para enlazar resultados.
 * Resultados: usuario externo, cédula o columna pareja (valor lineal = inscritos.numero, único por atleta) → partiresul.
 * Post-carga: por ronda se valida GDU (obtenerReporteAnomalias) y mesas incompletas.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
Auth::requireRole(['admin_general']);

require_once __DIR__ . '/../lib/ImportacionTorneoExternoService.php';

/**
 * @param array<string, mixed> $res
 * @return array{icon: string, title: string, html: string}
 */
function importacionTorneoExternoSwalPayload(array $res, string $origen, string $splitError = ''): array
{
    $ins = (int)($res['insertados'] ?? 0);
    $fh = (int)($res['filas_bloque_cedulas'] ?? $res['filas_hoja_homolog_raw'] ?? 0);
    $fr = (int)($res['filas_bloque_resultados'] ?? $res['filas_hoja_resultados_raw'] ?? 0);
    $map = (int)($res['mapeos_usuario_externo'] ?? 0);
    $sinBd = (int)($res['homologacion_sin_usuario'] ?? 0);
    $sinJug = (int)($res['resultados_sin_resolver'] ?? 0);
    $listas = (int)($res['filas_listas_para_insertar'] ?? 0);
    $ch = !empty($res['columna_usuario_homolog']);
    $cr = !empty($res['columna_usuario_resultados']);
    $errList = $res['errores'] ?? [];
    $cedNo = $res['cedulas_no_encontradas'] ?? [];

    $html = '<div class="text-start small" style="max-width:420px">';
    $html .= '<table class="table table-sm table-bordered mb-2"><tbody>';
    $html .= '<tr><td>Origen</td><td><strong>' . htmlspecialchars($origen) . '</strong></td></tr>';
    if ($splitError !== '') {
        $html .= '<tr class="table-danger"><td colspan="2">' . htmlspecialchars($splitError) . '</td></tr>';
    }
    $html .= '<tr><td>Filas bloque cédulas (datos)</td><td><strong>' . $fh . '</strong></td></tr>';
    $html .= '<tr><td>Con usuario en Mistorneos (homolog.)</td><td><strong>' . (int)($res['cedulas_con_usuario_mistorneos'] ?? max(0, $fh - $sinBd)) . '</strong></td></tr>';
    $html .= '<tr><td>Cédulas sin resolver en homologación</td><td><strong>' . $sinBd . '</strong></td></tr>';
    $soloCp = !empty($res['homologacion_solo_cedula_pareja']);
    $html .= '<tr><td>Mapeos usuario externo → id</td><td><strong>' . $map . '</strong> ' . ($soloCp ? '(modo cédula + pareja)' : ($ch ? '(col. usuario en homolog.)' : '(sin col. usuario)')) . '</td></tr>';
    if ($soloCp) {
        $html .= '<tr class="table-light"><td colspan="2" class="small">Homologación reconocida como <strong>solo cédula + pareja</strong> (sin columna id externo). Los resultados pueden ir solo con columna pareja + secuencia.</td></tr>';
    }
    $html .= '<tr><td>Filas bloque resultados (datos)</td><td><strong>' . $fr . '</strong></td></tr>';
    $html .= '<tr><td>Columna usuario en resultados</td><td>' . ($cr ? 'Sí' : 'No') . '</td></tr>';
    $html .= '<tr><td>Filas que podían insertarse</td><td><strong>' . $listas . '</strong></td></tr>';
    $html .= '<tr><td>Sin asignar jugador</td><td><strong>' . $sinJug . '</strong></td></tr>';
    $html .= '<tr class="table-' . ($ins > 0 ? 'success' : 'warning') . '"><td><strong>Insertadas partiresul</strong></td><td><strong>' . $ins . '</strong></td></tr>';
    $vec = (int) ($res['vector_atletas_mapeados'] ?? 0);
    $html .= '<tr><td>Vector atletas (id únicos)</td><td><strong>' . $vec . '</strong></td></tr>';
    $msgMat = (string) ($res['mensaje_homologacion_matriz'] ?? '');
    if ($msgMat !== '') {
        $html .= '<tr class="table-info"><td colspan="2"><strong>' . htmlspecialchars($msgMat, ENT_QUOTES, 'UTF-8') . '</strong>';
        $nMat = (int) ($res['matriz_homologados_n'] ?? 0);
        $rMat = (int) ($res['matriz_rondas'] ?? 0);
        $mEsp = (int) ($res['matriz_total_partidas_esperadas'] ?? 0);
        $mFil = (int) ($res['matriz_total_filas'] ?? 0);
        if ($nMat > 0 && $rMat > 0) {
            $html .= '<br><span class="text-muted small">N=' . $nMat . ', rondas=' . $rMat . ', N×rondas=' . $mEsp . ', filas agrupadas=' . $mFil . '</span>';
        }
        $html .= '</td></tr>';
    }
    $av = (int) ($res['atletas_vinculados'] ?? 0);
    $pr = (int) ($res['parejas_reconstruidas'] ?? 0);
    $insN = (int) ($res['inscripciones_nuevas'] ?? 0);
    if ($av > 0 || $pr > 0 || $insN > 0) {
        $html .= '<tr class="table-light"><td colspan="2" class="fw-bold">Homologación (integridad)</td></tr>';
        $html .= '<tr><td>Atletas vinculados</td><td><strong>' . $av . '</strong></td></tr>';
        $html .= '<tr><td>Parejas reconstruidas (id pareja → 2 jugadores)</td><td><strong>' . $pr . '</strong></td></tr>';
        $html .= '<tr><td>Inscripciones nuevas en el torneo</td><td><strong>' . $insN . '</strong></td></tr>';
    }
    $rExt = (int) ($res['resoluciones_via_bd_externa'] ?? 0);
    $rAlta = (int) ($res['resoluciones_cedula_sin_usuario_previo'] ?? 0);
    if ($rAlta > 0 || $rExt > 0) {
        $html .= '<tr><td>Resolución identidad</td><td>Alta/externa: <strong>' . $rAlta . '</strong> filas; con datos afiliados: <strong>' . $rExt . '</strong></td></tr>';
    }
    $rlt = (int) ($res['rondas_limite_torneo'] ?? 0);
    if ($rlt > 0) {
        $html .= '<tr><td>Rondas auditadas (límite torneo)</td><td><strong>1–' . $rlt . '</strong> (todas las rondas configuradas)</td></tr>';
    }
    $fOmit = (int) ($res['filas_omitidas_partida_superior_limite'] ?? 0);
    if ($fOmit > 0) {
        $html .= '<tr class="table-warning"><td>Filas omitidas (partida &gt; límite)</td><td><strong>' . $fOmit . '</strong></td></tr>';
    }
    $crP = (int) ($res['resultados_celdas_rellenadas_pareja'] ?? 0);
    $crU = (int) ($res['resultados_celdas_rellenadas_usuario'] ?? 0);
    if ($crP > 0 || $crU > 0) {
        $html .= '<tr class="table-light"><td colspan="2" class="fw-bold">Propagación Excel (celdas combinadas)</td></tr>';
        if ($crP > 0) {
            $html .= '<tr><td>Celdas pareja rellenadas automáticamente</td><td><strong>' . $crP . '</strong></td></tr>';
        }
        if ($crU > 0) {
            $html .= '<tr><td>Celdas usuario/id externo rellenadas automáticamente</td><td><strong>' . $crU . '</strong></td></tr>';
        }
    }
    $html .= '</tbody></table>';
    $aud = $res['auditoria_por_ronda'] ?? [];
    if (is_array($aud) && $aud !== []) {
        $html .= '<p class="mb-1 fw-bold">Validación post-carga (todas las rondas 1…' . (int) (count($aud)) . ')</p><ul class="small mb-2 ps-3" style="max-height:280px;overflow-y:auto">';
        foreach ($aud as $a) {
            $p = (int) ($a['partida'] ?? 0);
            $html .= '<li>Ronda ' . $p . ': GDU=' . (int) ($a['gdu'] ?? 0) . ', mesas incompletas=' . (int) ($a['mesas_incompletas'] ?? 0) . ' — ' . htmlspecialchars((string) ($a['detalle'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';
    }
    if ($cedNo !== []) {
        $html .= '<p class="mb-1"><strong>Cédulas sin usuario (muestra):</strong> ' . htmlspecialchars(implode(', ', array_slice($cedNo, 0, 12))) . '</p>';
    }
    if ($errList !== []) {
        $html .= '<p class="text-danger mb-0"><strong>SQL / detalle:</strong> ' . htmlspecialchars(implode(' | ', array_slice($errList, 0, 3))) . '</p>';
    }
    if ($ins === 0 && $sinJug > 0 && $map === 0 && $ch && empty($res['homologacion_solo_cedula_pareja'])) {
        $html .= '<p class="text-warning mt-2 mb-0">El mapa usuario externo está vacío: ninguna fila de cédulas obtuvo id_usuario. Revise cédulas en BD y que la 1.ª fila del bloque tenga columnas <code>cédula</code> y <code>usuario</code>, o use modo <code>cédula</code> + <code>pareja</code> sin id externo.</p>';
    }
    if ($ins === 0 && $sinJug > 0 && $map > 0) {
        $html .= '<p class="text-warning mt-2 mb-0">Hay mapeos pero muchas filas sin jugador: los valores en columna <code>usuario</code> del bloque resultados deben coincidir exactamente con los del bloque cédulas (mismo número).</p>';
    }
    $html .= '</div>';

    $icon = 'error';
    $title = 'Error en la importación';
    if ($splitError !== '') {
        $title = 'No se pudo separar el archivo';
    } elseif ($ins > 0) {
        $icon = 'success';
        $title = 'Importación completada';
    } elseif (!empty($errList) && strpos((string)($errList[0] ?? ''), 'Faltan') !== false) {
        $icon = 'error';
    } else {
        $icon = 'warning';
        $title = '0 filas insertadas — revisar resumen';
    }

    return ['icon' => $icon, 'title' => $title, 'html' => $html];
}

$userId = (int)(Auth::id() ?: 0);
$baseList = 'index.php?page=importacion_torneo_externo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $_SESSION['import_swal'] = ['icon' => 'error', 'title' => 'Sesión', 'html' => '<p>Token CSRF inválido. Recargue la página e intente de nuevo.</p>'];
        $tid = (int)($_POST['torneo_id'] ?? $_GET['torneo_id'] ?? 0);
        header('Location: ' . $baseList . ($tid > 0 ? '&torneo_id=' . $tid : ''));
        exit;
    }
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'fase1' && isset($_FILES['archivo']) && is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        $rows = ImportacionTorneoExternoService::leerExcelOCsv(
            (string)$_FILES['archivo']['tmp_name'],
            (string)($_FILES['archivo']['name'] ?? 'x.xlsx')
        );
        $pdo = DB::pdo();
        $out = ImportacionTorneoExternoService::fase1Enriquecer($pdo, $rows);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="jugadores_con_id_usuario_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $fh = fopen('php://output', 'w');
        foreach ($out['filas'] as $line) {
            fputcsv($fh, $line);
        }
        fclose($fh);
        exit;
    }

    if ($accion === 'importar_clubes_excel' && isset($_FILES['archivo_clubes']) && is_uploaded_file($_FILES['archivo_clubes']['tmp_name'])) {
        $torneo_id = (int) ($_POST['torneo_id'] ?? 0);
        if ($torneo_id <= 0) {
            $_SESSION['import_swal'] = ['icon' => 'warning', 'title' => 'Torneo', 'html' => '<p>Seleccione el torneo en el paso 1 antes de importar clubes.</p>'];
            header('Location: ' . $baseList);
            exit;
        }
        $pdo = DB::pdo();
        $rows = ImportacionTorneoExternoService::leerExcelOCsv(
            (string) $_FILES['archivo_clubes']['tmp_name'],
            (string) ($_FILES['archivo_clubes']['name'] ?? 'clubes.xlsx')
        );
        $res = ImportacionTorneoExternoService::importarClubesDesdeExcel($pdo, $torneo_id, $rows);
        $c = (int) ($res['creados'] ?? 0);
        $dup = (int) ($res['omitidos_duplicado'] ?? 0);
        $fd = (int) ($res['filas_datos'] ?? 0);
        $orgD = (int) ($res['organizacion_default'] ?? 0);
        $entD = (int) ($res['entidad_default'] ?? 0);
        $err = $res['errores'] ?? [];
        $html = '<div class="text-start small" style="max-width:420px">';
        $html .= '<p class="mb-2"><strong>Clubes creados:</strong> ' . $c . '</p>';
        $html .= '<p class="mb-2"><strong>Omitidos (ya existían):</strong> ' . $dup . '</p>';
        $html .= '<p class="mb-2"><strong>Filas con nombre:</strong> ' . $fd . '</p>';
        $html .= '<p class="mb-2 text-muted small">Organización por defecto (torneo): ' . $orgD . ' · Entidad: ' . $entD . '</p>';
        if ($err !== []) {
            $html .= '<p class="text-danger mb-0"><strong>Detalle:</strong> ' . htmlspecialchars(implode(' | ', array_slice($err, 0, 5)), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $html .= '</div>';
        $_SESSION['import_swal'] = [
            'icon' => !empty($err) && $c === 0 ? 'error' : ($c > 0 ? 'success' : 'info'),
            'title' => 'Importación de clubes',
            'html' => $html,
        ];
        header('Location: ' . $baseList . '&torneo_id=' . $torneo_id);
        exit;
    }

    if ($accion === 'diagnostico_pareja_resultados'
        && isset($_FILES['archivo_resultados_diag']) && is_uploaded_file($_FILES['archivo_resultados_diag']['tmp_name'])
    ) {
        $torneo_id = (int) ($_POST['torneo_id'] ?? 0);
        if ($torneo_id <= 0) {
            $_SESSION['import_swal'] = ['icon' => 'warning', 'title' => 'Torneo', 'html' => '<p>Seleccione el torneo en el paso 1.</p>'];
            header('Location: ' . $baseList);
            exit;
        }
        $pdo = DB::pdo();
        $rows = ImportacionTorneoExternoService::leerExcelOCsv(
            (string) $_FILES['archivo_resultados_diag']['tmp_name'],
            (string) ($_FILES['archivo_resultados_diag']['name'] ?? 'resultados.xlsx')
        );
        $res = ImportacionTorneoExternoService::diagnosticarParejaResultadosVsInscritos($pdo, $torneo_id, $rows);
        if (! $res['ok']) {
            $msg = implode(' ', $res['errores'] ?? []);
            $_SESSION['import_swal'] = [
                'icon' => 'error',
                'title' => 'Diagnóstico pareja',
                'html' => '<div class="text-start small"><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>',
            ];
            header('Location: ' . $baseList . '&torneo_id=' . $torneo_id);
            exit;
        }
        $noList = $res['parejas_no_encontradas'] ?? [];
        $html = '<div class="text-start small" style="max-width:520px">';
        $html .= '<p><strong>Filas de datos:</strong> ' . (int) $res['filas_datos'] . ' · <strong>Columna pareja:</strong> ' . htmlspecialchars((string) $res['columna_pareja_titulo'], ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p class="text-muted small mb-2">Mismo criterio que la importación: cada valor de <strong>pareja</strong> debe coincidir con <code>inscritos.numero</code> de un atleta en el torneo (identificador lineal único, como en la carga masiva de parejas).</p>';
        $html .= '<p><strong>Encontrados:</strong> <span class="text-success">' . (int) $res['encontrados'] . '</span> · ';
        $html .= '<strong>No encontrados:</strong> <span class="text-danger">' . (int) $res['no_encontrados'] . '</span> · ';
        $html .= '<strong>Pareja vacía:</strong> ' . (int) $res['pareja_vacia'] . '</p>';
        $html .= '<p class="text-muted mb-1"><strong>inscritos.numero distintos (torneo, no retirado):</strong> ' . (int) $res['inscritos_numero_distintos'] . '</p>';
        $muestraBd = $res['muestra_numeros_inscritos'] ?? [];
        if ($muestraBd !== []) {
            $html .= '<p class="text-muted small mb-2">Muestra números en BD: ' . htmlspecialchars(implode(', ', array_map('strval', array_slice($muestraBd, 0, 40))), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $html .= '<p class="fw-bold mb-1">Parejas del archivo sin coincidencia (hasta 40, por frecuencia):</p><ul class="small ps-3 mb-0" style="max-height:240px;overflow-y:auto">';
        $n = 0;
        foreach ($noList as $pk => $info) {
            if ($n++ >= 40) {
                break;
            }
            $html .= '<li><code>' . htmlspecialchars((string) $pk, ENT_QUOTES, 'UTF-8') . '</code> — ' . (int) ($info['conteo'] ?? 0) . ' filas; ej. filas Excel: '
                . htmlspecialchars(implode(', ', array_map('strval', $info['muestra_filas_excel'] ?? [])), ENT_QUOTES, 'UTF-8') . '</li>';
        }
        if ($noList === []) {
            $html .= '<li class="text-success">Ninguna pareja sin resolver.</li>';
        }
        $html .= '</ul></div>';
        $_SESSION['import_swal'] = [
            'icon' => ((int) $res['no_encontrados'] > 0) ? 'warning' : 'success',
            'title' => 'Diagnóstico búsqueda por pareja',
            'html' => $html,
        ];
        header('Location: ' . $baseList . '&torneo_id=' . $torneo_id);
        exit;
    }

    if ($accion === 'fase2_dual'
        && isset($_FILES['archivo_homologacion'], $_FILES['archivo_resultados'])
        && is_uploaded_file($_FILES['archivo_homologacion']['tmp_name'])
        && is_uploaded_file($_FILES['archivo_resultados']['tmp_name'])
    ) {
        $torneo_id = (int)($_POST['torneo_id'] ?? 0);
        $reemplazar = !empty($_POST['reemplazar_partiresul_dual']);
        if ($torneo_id <= 0) {
            $_SESSION['import_swal'] = ['icon' => 'warning', 'title' => 'Paso 1', 'html' => '<p>Seleccione el torneo y pulse <strong>Aplicar</strong> antes de importar.</p>'];
            header('Location: ' . $baseList);
            exit;
        }
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT id, nombre, fechator FROM tournaments WHERE id = ?');
        $st->execute([$torneo_id]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            $_SESSION['import_swal'] = ['icon' => 'error', 'title' => 'Torneo', 'html' => '<p>Torneo no encontrado.</p>'];
            header('Location: ' . $baseList);
            exit;
        }
        $fecha = substr((string)($torneo['fechator'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = date('Y-m-d');
        }
        $rowsH = ImportacionTorneoExternoService::leerExcelOCsv(
            (string)$_FILES['archivo_homologacion']['tmp_name'],
            (string)($_FILES['archivo_homologacion']['name'] ?? 'x.xlsx')
        );
        $rowsR = ImportacionTorneoExternoService::leerExcelOCsv(
            (string)$_FILES['archivo_resultados']['tmp_name'],
            (string)($_FILES['archivo_resultados']['name'] ?? 'x.xlsx')
        );
        if ($reemplazar) {
            $pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ?')->execute([$torneo_id]);
        }
        $res = ImportacionTorneoExternoService::importarDosArchivosPartiresul($pdo, $torneo_id, $userId, $fecha, $rowsH, $rowsR);
        $_SESSION['import_swal'] = importacionTorneoExternoSwalPayload($res, 'Dos archivos');
        header('Location: ' . $baseList . '&torneo_id=' . $torneo_id);
        exit;
    }

    if ($accion === 'fase2_unificado' && isset($_FILES['archivo_unico']) && is_uploaded_file($_FILES['archivo_unico']['tmp_name'])) {
        $torneo_id = (int)($_POST['torneo_id'] ?? 0);
        $reemplazar = !empty($_POST['reemplazar_unificado']);
        if ($torneo_id <= 0) {
            $_SESSION['import_swal'] = ['icon' => 'warning', 'title' => 'Paso 1', 'html' => '<p>Seleccione el torneo y pulse <strong>Aplicar</strong> antes de importar.</p>'];
            header('Location: ' . $baseList);
            exit;
        }
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT id, nombre, fechator FROM tournaments WHERE id = ?');
        $st->execute([$torneo_id]);
        $torneo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            $_SESSION['import_swal'] = ['icon' => 'error', 'title' => 'Torneo', 'html' => '<p>Torneo no encontrado.</p>'];
            header('Location: ' . $baseList);
            exit;
        }
        $fecha = substr((string)($torneo['fechator'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = date('Y-m-d');
        }
        $tmp = (string)$_FILES['archivo_unico']['tmp_name'];
        $nom = (string)($_FILES['archivo_unico']['name'] ?? 'x.xlsx');
        if ($reemplazar) {
            $pdo->prepare('DELETE FROM partiresul WHERE id_torneo = ?')->execute([$torneo_id]);
        }
        $res = ImportacionTorneoExternoService::importarUnSoloArchivoPartiresul($pdo, $torneo_id, $userId, $fecha, $tmp, $nom);
        $_SESSION['import_swal'] = importacionTorneoExternoSwalPayload($res, 'Un solo archivo', $res['split_error'] ?? '');
        header('Location: ' . $baseList . '&torneo_id=' . $torneo_id);
        exit;
    }
}

$pdo = DB::pdo();
$torneos = $pdo->query('SELECT id, nombre, fechator, modalidad FROM tournaments ORDER BY fechator DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
$torneo_id_sel = (int)($_GET['torneo_id'] ?? 0);
$torneo_actual = null;
foreach ($torneos as $t) {
    if ((int)$t['id'] === $torneo_id_sel) {
        $torneo_actual = $t;
        break;
    }
}
/* Torneo por URL pero fuera del top 300 por fecha: cargar por id (evita null y error 500 en PHP 8+). */
if ($torneo_id_sel > 0 && $torneo_actual === null) {
    $stT = $pdo->prepare('SELECT id, nombre, fechator, modalidad FROM tournaments WHERE id = ? LIMIT 1');
    $stT->execute([$torneo_id_sel]);
    $filaT = $stT->fetch(PDO::FETCH_ASSOC);
    if ($filaT) {
        $torneo_actual = $filaT;
    }
}
$modalidad = $torneo_actual !== null ? (int)($torneo_actual['modalidad'] ?? 0) : 0;
$es_equipos = $modalidad === 3;
$etiqueta_modalidad = $es_equipos ? 'Equipos (4 integrantes)' : ($modalidad === 4 ? 'Parejas fijas' : 'Individual / mesas');
$url_panel = 'index.php?page=torneo_gestion&action=panel&torneo_id=' . $torneo_id_sel;
$url_carga_equipos = 'index.php?page=torneo_gestion&action=carga_masiva_equipos_sitio&torneo_id=' . $torneo_id_sel;
$url_plantilla_equipos = 'index.php?page=torneo_gestion&action=carga_masiva_equipos_plantilla&torneo_id=' . $torneo_id_sel;
$url_import_individual = $url_panel . '#importacion-masiva';
?>
<style>
    .imp-contenedor-carga { max-width: 880px; margin-left: auto; margin-right: auto; }
    .imp-paso-num { width:2.25rem; height:2.25rem; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:1.1rem; }
    .imp-card-paso { border-left: 4px solid var(--bs-card-border-color); }
    .imp-card-paso.paso-1 { border-left-color: #0d6efd; }
    .imp-card-paso.paso-15 { border-left-color: #6c757d; }
    .imp-card-paso.paso-2 { border-left-color: #198754; }
    .imp-card-paso.paso-3 { border-left-color: #0dcaf0; }
    .imp-card-paso.paso-4 { border-left-color: #198754; }
    .imp-card-paso.paso-aux { border-left-color: #0dcaf0; }
    .imp-seccion { background: #f8f9fa; border-radius: 8px; padding: .75rem 1rem; margin-bottom: .75rem; }
    .imp-seccion h6 { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; margin-bottom: .35rem; }
    .imp-seccion:last-child { margin-bottom: 0; }
    .imp-paso-texto .imp-seccion:last-child { margin-bottom: 0; }
    .imp-paso-completado { max-width: 100%; }
    @media (min-width: 992px) {
        .imp-paso-completado { max-width: 50%; }
    }
</style>
<div class="container-fluid py-4 imp-contenedor-carga">
    <div class="card mb-4 shadow-sm border-0 bg-light">
        <div class="card-body py-3">
            <h1 class="h4 mb-1"><i class="fas fa-file-import text-primary me-2"></i>Carga de datos desde otra plataforma</h1>
            <p class="text-muted small mb-0">Solo <strong>administrador general</strong>. Puede usar <strong>un solo Excel</strong> (recomendado) o dos archivos separados.</p>
        </div>
    </div>

    <?php
    $swalJson = null;
    if (!empty($_SESSION['import_swal']) && is_array($_SESSION['import_swal'])) {
        $swalJson = json_encode($_SESSION['import_swal'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
        unset($_SESSION['import_swal']);
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if ($swalJson): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        try {
            var o = <?= $swalJson ?>;
            Swal.fire({
                icon: o.icon || 'info',
                title: o.title || 'Mensaje',
                html: o.html || '',
                width: '32rem',
                confirmButtonText: 'Aceptar',
                confirmButtonColor: '#0d6efd'
            });
        } catch (e) { console.error(e); }
    });
    </script>
    <?php endif; ?>

    <!-- Tarjeta índice -->
    <div class="card mb-4 shadow imp-card-paso paso-1">
        <div class="card-header bg-white d-flex align-items-center gap-2 py-3">
            <span class="imp-paso-num bg-primary text-white">0</span>
            <div>
                <span class="fw-bold">Secuencia del proceso</span>
                <div class="small text-muted">Vista rápida antes de ejecutar cada paso</div>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="row g-2 small">
                        <div class="col-12 col-sm-6">
                            <div class="border rounded p-2 h-100 bg-primary bg-opacity-10">
                                <strong class="text-primary">Paso 1</strong> — Elegir torneo destino.
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="border rounded p-2 h-100 bg-warning bg-opacity-25">
                                <strong class="text-dark">Paso 2</strong> — <strong>Clubes (Excel)</strong> antes de homologar: crear clubes de origen para que los atletas queden vinculados a su club.
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="border rounded p-2 h-100 bg-secondary bg-opacity-10">
                                <strong class="text-secondary">(Opcional)</strong> — Inscribir jugadores/equipos si aún no están.
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="border rounded p-2 h-100 bg-success bg-opacity-10">
                                <strong class="text-success">Paso 3</strong> — Excel homologación + resultados → <code>partiresul</code>.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="alert alert-info small mb-0 h-100">
                        <strong>Cómo encajan los tres pasos</strong>
                        <ol class="mb-0 ps-3 mt-2">
                            <li><strong>Carga masiva</strong> (equipos/atletas): deja inscripciones en el torneo como en el individual.</li>
                            <li><strong>Homologación:</strong> <em>id externo + cédula</em> (como individual) <strong>o</strong> <em>cédula + pareja</em> sin id externo: se resuelve el jugador y se escribe <code>inscritos.numero</code> = número de pareja.</li>
                            <li><strong>Resultados:</strong> por <code>usuario</code> (id externo), <code>cédula</code> o <code>pareja</code> (= <code>inscritos.numero</code> por atleta) → <code>partiresul</code>. Partida/mesa/secuencia describen la fila de partida.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PASO 1 -->
    <div class="card mb-4 shadow imp-card-paso paso-1">
        <div class="card-header bg-primary text-white d-flex align-items-center gap-2 flex-wrap py-3">
            <span class="imp-paso-num bg-white text-primary">1</span>
            <div>
                <span class="fw-bold">Paso 1 — Seleccionar torneo</span>
                <div class="small opacity-90">Primero fije dónde se guardarán los resultados</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Qué hace este paso</h6>
                        <p class="small mb-0">Asocia toda la carga al torneo que elija. Los registros van a <code>partiresul</code> con ese <code>id_torneo</code>. La <strong>fecha de partida</strong> de cada fila será la <strong>fecha del torneo</strong> en Mistorneos.</p>
                    </div>
                    <div class="imp-seccion">
                        <h6>Qué debe hacer usted</h6>
                        <ol class="small mb-0 ps-3">
                            <li>El torneo debe existir ya (creado en gestión de torneos).</li>
                            <li>Elija el torneo en la lista y pulse <strong>Aplicar</strong>.</li>
                            <li>Después podrá crear clubes desde Excel (paso 2) y luego cargar homologación/resultados (paso 3).</li>
                        </ol>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <form method="get" action="index.php" class="mb-0">
                        <input type="hidden" name="page" value="importacion_torneo_externo">
                        <label class="form-label fw-semibold">Torneo destino</label>
                        <select name="torneo_id" class="form-select" required>
                            <option value="">— Seleccione un torneo —</option>
                            <?php foreach ($torneos as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $torneo_id_sel === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nombre'] . ' · ' . $t['fechator'] . ' · mod.' . (int)$t['modalidad']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary w-100 mt-2"><i class="fas fa-check me-1"></i> Aplicar paso 1</button>
                    </form>
                    <?php if ($torneo_actual): ?>
                        <div class="mt-3 p-3 border border-success rounded bg-success bg-opacity-10 imp-paso-completado">
                            <div class="small text-success fw-bold mb-1"><i class="fas fa-check-circle me-1"></i> Paso 1 completado</div>
                            <div><strong><?= htmlspecialchars((string)$torneo_actual['nombre']) ?></strong> <span class="badge bg-secondary"><?= htmlspecialchars($etiqueta_modalidad) ?></span></div>
                            <a href="<?= htmlspecialchars($url_panel) ?>" class="btn btn-sm btn-outline-dark mt-2" target="_blank" rel="noopener"><i class="fas fa-external-link-alt me-1"></i> Abrir panel del torneo</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (! $torneo_actual): ?>
                <p class="text-warning small mt-3 mb-0"><i class="fas fa-hand-point-up me-1"></i> Debe completar el paso 1 para desbloquear la creación de clubes y la carga de archivos (pasos 2 y 3).</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($torneo_actual): ?>
    <!-- PASO 2: CLUBES (antes de homologación / inscritos) -->
    <div class="card mb-4 shadow imp-card-paso paso-15 border-warning">
        <div class="card-header bg-warning bg-gradient d-flex align-items-center gap-2 flex-wrap py-3">
            <span class="imp-paso-num bg-dark text-white">2</span>
            <div>
                <span class="fw-bold">Crear clubes desde Excel</span>
                <div class="small text-dark">Ejecutar <strong>antes</strong> de la homologación de jugadores para que existan los clubes de origen (misma organización que el torneo).</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Columnas del archivo</h6>
                        <p class="small mb-0"><strong>Obligatoria:</strong> <code>nombre</code> (o <code>club</code> / <code>nombre_club</code>). <strong>Opcionales:</strong> <code>direccion</code>, <code>telefono</code>, <code>email</code>, <code>delegado</code>, <code>organizacion_id</code>, <code>entidad</code>. Si no indica organización, se usa la del torneo (organización responsable). Los clubes con el mismo nombre en la misma organización no se duplican.</p>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="importar_clubes_excel">
                        <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id_sel ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Archivo (.xlsx, .csv o .txt)</label>
                            <input type="file" name="archivo_clubes" class="form-control" accept=".xlsx,.csv,.txt" required>
                        </div>
                        <button type="submit" class="btn btn-warning text-dark fw-bold w-100"><i class="fas fa-building me-2"></i>Importar clubes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- PASO 3: UN SOLO ARCHIVO (recomendado) -->
    <div class="card mb-4 shadow-lg imp-card-paso paso-4 border-primary">
        <div class="card-header text-white d-flex align-items-center gap-2 py-3" style="background: linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);">
            <span class="imp-paso-num bg-white text-primary"><i class="fas fa-file-excel"></i></span>
            <div>
                <span class="fw-bold">Paso 3 — Un solo Excel: homologación + resultados</span>
                <div class="small opacity-90">Recomendado: todo en un libro</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Formato del archivo</h6>
                        <p class="small mb-2"><strong>Hoja 1 — Homologación:</strong> dos columnas: <code>usuario</code> (o id externo: 37, 81…) y <code>cédula</code>. La cédula solo sirve para buscar en Mistorneos. Mismo <strong>37</strong> debe aparecer en resultados.</p>
                        <p class="small mb-2"><strong>Hoja 2 — Resultados:</strong> <strong>sin cédula</strong>. Columnas partida, mesa, secuencia, <code>usuario</code> (= 37, igual que hoja 1), r1, r2…</p>
                        <p class="small mb-0"><strong>Una sola hoja:</strong> arriba bloque homologación (usuario + cédula por fila); debajo fila con partida/mesa/secuencia y resto de resultados.</p>
                    </div>
                    <div class="imp-seccion">
                        <h6>Qué hace el sistema</h6>
                        <ol class="small mb-0 ps-3">
                            <li>Homologación: id externo + cédula → mapa <strong>id_externo → id_usuario</strong> (la cédula no se usa en resultados).</li>
                            <li>Resultados: solo el id externo en <code>usuario</code>; se reemplaza por <code>id_usuario</code> del mapa.</li>
                            <li>Inserta en <code>partiresul</code> con los mismos parámetros que el panel (mesa, secuencia, r1/r2, ff, efectividad, zapato/chancleta, fecha torneo, registrado_por).</li>
                        </ol>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="fase2_unificado">
                        <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id_sel ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Archivo Excel (.xlsx)</label>
                            <input type="file" name="archivo_unico" class="form-control" accept=".xlsx,.csv,.txt" required>
                            <div class="form-text">CSV solo si en un mismo archivo puede ponerse primero el bloque cédula+usuario y luego una fila con partida/mesa/secuencia (sin líneas en blanco entre bloques).</div>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" name="reemplazar_unificado" value="1" id="repU">
                            <label class="form-check-label small" for="repU">Vaciar <code>partiresul</code> de este torneo antes de importar</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fas fa-cloud-upload-alt me-2"></i>Procesar un solo archivo → partiresul</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- OPCIONAL inscripciones -->
    <div class="card mb-4 shadow imp-card-paso paso-15">
        <div class="card-header bg-secondary text-white d-flex align-items-center gap-2 py-3">
            <span class="imp-paso-num bg-white text-secondary">1b</span>
            <div>
                <span class="fw-bold">Opcional — Inscripciones antes de resultados</span>
                <div class="small opacity-90">Solo si el torneo aún no tiene inscritos</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Qué hace este paso</h6>
                        <p class="small mb-0">Es el mismo flujo que en el <strong>panel del torneo</strong>: carga masiva de equipos o importación masiva individual. No sustituye la homologación ni el archivo de resultados.</p>
                    </div>
                    <div class="imp-seccion">
                        <h6>Cuándo usarlo</h6>
                        <p class="small mb-0">Si los jugadores aún no están inscritos en este torneo, hágalo antes o después del paso 1, pero <strong>antes</strong> de depender de listados del panel (posiciones, etc.).</p>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <?php if ($es_equipos): ?>
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($url_carga_equipos) ?>" class="btn btn-success"><i class="fas fa-file-upload me-1"></i> Carga masiva equipos</a>
                            <a href="<?= htmlspecialchars($url_plantilla_equipos) ?>" class="btn btn-outline-secondary"><i class="fas fa-download me-1"></i> Plantilla CSV</a>
                        </div>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($url_import_individual) ?>" class="btn btn-success w-100"><i class="fas fa-file-csv me-1"></i> Importación masiva (abre panel)</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Guía homologación (solo texto) -->
    <div class="card mb-3 shadow imp-card-paso paso-2">
        <div class="card-header bg-success bg-opacity-10 text-success border-success d-flex align-items-center gap-2 py-3">
            <span class="imp-paso-num bg-success text-white">H</span>
            <div>
                <span class="fw-bold text-dark">Guía — Archivo de homologación (parejas y cédulas)</span>
                <div class="small text-muted">Para la alternativa «dos archivos» o para preparar la hoja 1 del Excel único</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Identidad (igual criterio que el individual)</h6>
                        <p class="small mb-0"><strong>Opción A:</strong> <code>usuario</code> / id externo (37) + <strong>cédula</strong> → mapa 37 → <code>id_usuario</code>. <strong>Opción B (recomendada si ya cargó equipos/atletas):</strong> solo <strong>cédula</strong> + columna <code>pareja</code> (número de equipo). Se actualiza <code>inscritos.numero</code> con ese valor para enlazar el archivo de resultados.</p>
                    </div>
                    <div class="imp-seccion">
                        <h6>Columnas homologación (fila 1 = títulos)</h6>
                        <ul class="small mb-0 ps-3">
                            <li><strong>A:</strong> id externo (<code>usuario</code>, <code>id</code>…) + <strong>cédula</strong>.</li>
                            <li><strong>B:</strong> <strong>cédula</strong> + <code>pareja</code> (sin id externo). Opcional: nombre / nacionalidad para resolver cédulas.</li>
                        </ul>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="imp-seccion border border-warning h-100">
                        <h6 class="text-warning">Atención</h6>
                        <p class="small mb-0">Cada cédula debe existir en Mistorneos; si no, ese id externo no entrará al mapa y en resultados las filas con ese <code>usuario</code> no se podrán guardar.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Guía resultados -->
    <div class="card mb-3 shadow imp-card-paso paso-3">
        <div class="card-header bg-info bg-opacity-10 text-info border-info d-flex align-items-center gap-2 py-3">
            <span class="imp-paso-num bg-info text-dark">R</span>
            <div>
                <span class="fw-bold text-dark">Guía — Resultados</span>
                <div class="small text-muted">Por <code>usuario</code> (id externo), <code>cédula</code> o <code>pareja</code> (número lineal único en <code>inscritos.numero</code>)</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Resolución de jugador por fila</h6>
                        <p class="small mb-0">Si la homologación fue con id externo: columna <code>usuario</code> en resultados = mismo valor. Si homologó con <strong>cédula + pareja</strong> (o carga masiva de parejas): en resultados la columna <code>pareja</code> debe ser el mismo valor lineal que quedó en <code>inscritos.numero</code> por atleta (un registro, un valor). Opcional <code>cédula</code> como respaldo.</p>
                    </div>
                    <div class="imp-seccion">
                        <h6>Columnas</h6>
                        <ul class="small mb-0 ps-3">
                            <li><code>partida</code>, <code>mesa</code>, <code>secuencia</code>, <code>r1</code>/<code>r2</code> (u resultado1/2)</li>
                            <li>Una de: <code>usuario</code> (id externo), <code>cédula</code> o <code>pareja</code> (número de equipo).</li>
                        </ul>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="imp-seccion border border-success bg-success bg-opacity-10 h-100">
                        <h6 class="text-success">Ejemplo</h6>
                        <p class="small mb-0">Ej. id externo: homologación 37 + cédula → 7009; resultados <code>usuario</code> 37. Ej. parejas: cada atleta con <code>inscritos.numero</code> = valor pareja (ej. 101, 102…); resultados fila <code>pareja</code> 101 → ese jugador.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario ejecución 2+3 (dos archivos) -->
    <div class="card mb-4 shadow-lg imp-card-paso paso-4 border-success">
        <div class="card-header bg-success text-white d-flex align-items-center gap-2 py-3">
            <span class="imp-paso-num bg-white text-success"><i class="fas fa-copy"></i></span>
            <div>
                <span class="fw-bold">Alternativa — Dos archivos (homologación + resultados)</span>
                <div class="small opacity-90">Mismo proceso que un solo Excel, en dos ficheros</div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Secuencia al pulsar el botón</h6>
                        <ol class="small mb-0 ps-3">
                            <li>Lee el archivo de <strong>homologación</strong>: cédula → usuario (como inscripción en sitio); si hay columna pareja, sincroniza <code>inscritos.numero</code> (mismo entero que en resultados).</li>
                            <li>Lee el archivo de <strong>resultados</strong>: por usuario externo, cédula o pareja+secuencia asigna <code>id_usuario</code> por fila.</li>
                            <li>Si marcó vaciar, borra antes los resultados previos de este torneo en <code>partiresul</code>.</li>
                            <li>Inserta cada fila con la misma lógica que el panel (por mesa y secuencia).</li>
                        </ol>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="fase2_dual">
                        <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id_sel ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold"><span class="badge bg-success me-1">H</span> Archivo homologación (cédula + pareja, o id externo + cédula)</label>
                            <input type="file" name="archivo_homologacion" class="form-control" accept=".xlsx,.csv,.txt" required>
                            <div class="form-text">Mismo contenido descrito en la guía «H».</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><span class="badge bg-info text-dark me-1">R</span> Archivo resultados (mesa, secuencia, puntos)</label>
                            <input type="file" name="archivo_resultados" class="form-control" accept=".xlsx,.csv,.txt" required>
                            <div class="form-text">Mismo contenido descrito en la guía «R».</div>
                        </div>
                        <div class="card bg-light mb-3">
                            <div class="card-body py-2">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="reemplazar_partiresul_dual" value="1" id="rep2">
                                    <label class="form-check-label small" for="rep2"><strong>Vaciar partiresul</strong> de este torneo antes de importar (solo si va a recargar todo el histórico de resultados de una vez).</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success btn-lg w-100"><i class="fas fa-save me-2"></i>Ejecutar pasos 2 y 3 → guardar en partiresul</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4 shadow imp-card-paso paso-aux border-info">
        <div class="card-header bg-info bg-opacity-25 text-dark d-flex align-items-center gap-2 py-2">
            <span class="imp-paso-num bg-info text-white small" style="width:1.75rem;height:1.75rem;font-size:.85rem;">T</span>
            <span class="fw-bold">Prueba — Búsqueda pareja → inscritos.numero (sin grabar)</span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Qué comprueba</h6>
                        <p class="small mb-0">Misma lógica que la importación: valor <code>pareja</code> → <code>inscritos.numero</code> en el torneo. Relleno partida/mesa/pareja como en la carga real. <strong>Pareja vacía</strong>: celda sin texto tras leer el archivo.</p>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="diagnostico_pareja_resultados">
                        <input type="hidden" name="torneo_id" value="<?= (int)$torneo_id_sel ?>">
                        <div class="mb-2">
                            <label class="form-label fw-bold small">Archivo de resultados (.xlsx / .csv)</label>
                            <input type="file" name="archivo_resultados_diag" class="form-control form-control-sm" accept=".xlsx,.csv,.txt" required>
                        </div>
                        <button type="submit" class="btn btn-outline-info w-100"><i class="fas fa-search me-1"></i> Ejecutar prueba de búsqueda</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card mb-4 border-secondary imp-card-paso paso-15">
        <div class="card-body py-4 text-muted">
            <div class="row justify-content-center">
                <div class="col-12 col-lg-6 text-center">
                    <i class="fas fa-lock fa-2x mb-2 d-block"></i>
                    <strong>Pasos 2 y 3 bloqueados</strong>
                    <p class="small mb-0">Complete primero el <strong>paso 1</strong> (seleccionar torneo y Aplicar) para ver las indicaciones y el formulario de archivos.</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Auxiliar -->
    <div class="card mb-2 shadow-sm imp-card-paso paso-aux">
        <div class="card-header bg-info text-white d-flex align-items-center gap-2 py-2">
            <span class="imp-paso-num bg-white text-info small" style="width:1.75rem;height:1.75rem;font-size:.85rem;">A</span>
            <span class="fw-bold">Auxiliar — Revisar homologación sin cargar resultados</span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-6 imp-paso-texto">
                    <div class="imp-seccion">
                        <h6>Qué hace</h6>
                        <p class="small mb-0">Sube el mismo archivo de <strong>homologación</strong> que usaría en el paso 3 y descarga un CSV con la columna <code>id_usuario</code> para revisar cédulas mal cargadas o usuarios faltantes, <strong>sin</strong> tocar <code>partiresul</code>.</p>
                    </div>
                </div>
                <div class="col-12 col-lg-6 imp-paso-acciones">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::token()) ?>">
                        <input type="hidden" name="accion" value="fase1">
                        <input type="file" name="archivo" class="form-control mb-2" accept=".xlsx,.csv,.txt" required>
                        <button type="submit" class="btn btn-info text-white w-100"><i class="fas fa-download me-1"></i> Descargar CSV revisión</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
