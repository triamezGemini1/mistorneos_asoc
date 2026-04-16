<?php
/**
 * Página dedicada: exportes y resumen de inscritos del torneo actual.
 */
require_once __DIR__ . '/../../config/db.php';
if (!class_exists('AppHelpers', false)) {
    require_once __DIR__ . '/../../lib/app_helpers.php';
}

$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php'], true);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$form_action_url = $use_standalone ? $script_actual : 'index.php';

if (!isset($torneo) || empty($torneo) || !is_array($torneo)) {
    $torneo_id_fallback = (int)($torneo_id ?? $_GET['torneo_id'] ?? 0);
    if ($torneo_id_fallback > 0) {
        try {
            $pdo = DB::pdo();
            $has_cod_org = false;
            try {
                $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $ignored) {
                $has_cod_org = false;
            }
            $org_join = $has_cod_org
                ? 'LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)'
                : 'LEFT JOIN organizaciones o ON t.club_responsable = o.id';
            $stmt = $pdo->prepare("SELECT t.*, o.nombre AS organizacion_nombre FROM tournaments t {$org_join} WHERE t.id = ?");
            $stmt->execute([$torneo_id_fallback]);
            $torneo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $torneo_id_fallback, 'nombre' => 'Torneo', 'modalidad' => 0];
        } catch (Exception $e) {
            $torneo = ['id' => $torneo_id_fallback, 'nombre' => 'Torneo', 'modalidad' => 0];
        }
    } else {
        $torneo = ['id' => 0, 'nombre' => 'Torneo', 'modalidad' => 0];
    }
}

$tid_report = (int)($torneo['id'] ?? $torneo_id ?? 0);
if ($tid_report > 0 && class_exists('AppHelpers', false)) {
    $url_pdf_det = AppHelpers::torneoGestionUrl('inscripciones_reporte_detallado_pdf', $tid_report);
    $url_xls_det = AppHelpers::torneoGestionUrl('inscripciones_reporte_detallado_xls', $tid_report);
    $url_pdf_simple = AppHelpers::torneoGestionUrl('inscripciones_export_pdf', $tid_report);
    $url_xls_simple = AppHelpers::torneoGestionUrl('inscripciones_export_xls', $tid_report);
    $url_pdf_ret = AppHelpers::torneoGestionUrl('retirados_export_pdf', $tid_report);
    $url_xls_ret = AppHelpers::torneoGestionUrl('retirados_export_xls', $tid_report);
    $url_xls_gestor = AppHelpers::torneoGestionUrl('inscripciones_gestor_excel', $tid_report);
    $url_panel = AppHelpers::torneoGestionUrl('panel', $tid_report);
} else {
    $baseQ = 'index.php?page=torneo_gestion&torneo_id=' . $tid_report;
    $url_pdf_det = $baseQ . '&action=inscripciones_reporte_detallado_pdf';
    $url_xls_det = $baseQ . '&action=inscripciones_reporte_detallado_xls';
    $url_pdf_simple = $baseQ . '&action=inscripciones_export_pdf';
    $url_xls_simple = $baseQ . '&action=inscripciones_export_xls';
    $url_pdf_ret = $baseQ . '&action=retirados_export_pdf';
    $url_xls_ret = $baseQ . '&action=retirados_export_xls';
    $url_xls_gestor = $baseQ . '&action=inscripciones_gestor_excel';
    $url_panel = $baseQ . '&action=panel';
}

$total_inscritos_rep = isset($total_inscritos) ? (int) $total_inscritos : (int) ($totalInscritos ?? 0);
$inscritos_confirmados_rep = isset($inscritos_confirmados) ? (int) $inscritos_confirmados : $total_inscritos_rep;
$total_equipos_rep = isset($total_equipos) ? (int) $total_equipos : (int) ($estadisticas['total_equipos'] ?? 0);
$page_title = 'Reportes de inscritos — ' . (string) ($torneo['nombre'] ?? 'Torneo');
?>

<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/modern-panel.css">
<?php if ($use_standalone): ?>
<link rel="stylesheet" href="assets/dist/output.css">
<?php endif; ?>
<style>
.btn-compact {
    display: inline-flex !important;
    width: auto !important;
    min-width: 170px;
    min-height: 44px;
    padding: 10px 14px !important;
    line-height: 1.1;
    font-size: 0.875rem;
}
</style>

<div class="tw-panel ds-root reportes-inscritos-page">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="flex items-center flex-wrap gap-2 text-sm text-gray-500">
            <li><a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=index" class="hover:text-blue-600">Gestión de Torneos</a></li>
            <li><i class="fas fa-chevron-right text-xs"></i></li>
            <li class="text-gray-700 font-medium">Reportes de inscritos</li>
        </ol>
    </nav>

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden mb-6">
        <div class="bg-gradient-to-r from-slate-600 to-slate-800 px-4 py-3 text-white reportes-inscritos-card-head">
            <h1 class="text-lg font-bold mb-0 flex items-center">
                <i class="fas fa-file-invoice mr-2"></i> Reportes de inscritos
            </h1>
        </div>
        <div class="px-4 py-4 bg-slate-50 border-b border-slate-200">
            <p class="text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Resumen</p>
            <div class="flex flex-wrap gap-2 text-xs" role="group" aria-label="Resumen de inscripciones">
                <span class="inline-flex items-center rounded-full bg-blue-100 text-blue-900 px-3 py-1 font-semibold border border-blue-200" title="Registros en inscritos">Inscritos <span class="ml-1 tabular-nums"><?php echo $total_inscritos_rep; ?></span></span>
                <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-900 px-3 py-1 font-semibold border border-emerald-200" title="Inscritos confirmados">Jugadores (confirmados) <span class="ml-1 tabular-nums"><?php echo $inscritos_confirmados_rep; ?></span></span>
                <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-800 px-3 py-1 font-semibold border border-slate-200" title="Equipos activos">Equipos <span class="ml-1 tabular-nums"><?php echo $total_equipos_rep; ?></span></span>
            </div>
        </div>
        <div class="p-5">
            <section class="border border-slate-200 rounded-xl p-4 bg-white mb-6">
                <h2 class="text-xl font-extrabold text-slate-800 mb-4">Reportes de inscritos</h2>
                <div class="mb-3">
                    <a href="<?php echo htmlspecialchars(AppHelpers::torneoGestionUrl('carga_excel_tablas', $tid_report), ENT_QUOTES, 'UTF-8'); ?>"
                       class="tw-btn btn-compact bg-indigo-700 hover:bg-indigo-800 text-white justify-center font-bold">
                        <i class="fas fa-upload mr-2"></i> Carga Excel a tablas
                    </a>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?php echo htmlspecialchars($url_pdf_det, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                       class="tw-btn btn-compact bg-rose-600 hover:bg-rose-700 text-white justify-center font-bold">
                        <i class="fas fa-file-pdf mr-2"></i> PDF detallado
                    </a>
                    <a href="<?php echo htmlspecialchars($url_xls_det, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                       class="tw-btn btn-compact bg-emerald-700 hover:bg-emerald-800 text-white justify-center font-bold">
                        <i class="fas fa-file-excel mr-2"></i> Excel detallado
                    </a>
                    <a href="<?php echo htmlspecialchars($url_pdf_simple, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                       class="tw-btn btn-compact bg-rose-500 hover:bg-rose-600 text-white justify-center font-bold">
                        <i class="fas fa-file-pdf mr-2"></i> PDF simple
                    </a>
                    <a href="<?php echo htmlspecialchars($url_xls_simple, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                       class="tw-btn btn-compact bg-emerald-600 hover:bg-emerald-700 text-white justify-center font-bold">
                        <i class="fas fa-file-excel mr-2"></i> Excel simple
                    </a>
                    <a href="<?php echo htmlspecialchars($url_pdf_ret, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                       class="tw-btn btn-compact bg-amber-600 hover:bg-amber-700 text-white justify-center font-bold">
                        <i class="fas fa-file-pdf mr-2"></i> PDF retirados
                    </a>
                    <a href="<?php echo htmlspecialchars($url_xls_ret, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                       class="tw-btn btn-compact bg-amber-700 hover:bg-amber-800 text-white justify-center font-bold">
                        <i class="fas fa-file-excel mr-2"></i> Excel retirados
                    </a>
                </div>
            </section>

            <section class="border border-slate-200 rounded-xl p-4 bg-slate-50">
                <h2 class="text-xl font-extrabold text-slate-800 mb-4">Gestor de reportes a solicitud</h2>
                <form method="get" action="<?php echo htmlspecialchars($form_action_url, ENT_QUOTES, 'UTF-8'); ?>" class="grid grid-cols-1 gap-4 items-start" id="form-gestor-excel">
                <?php if (!$use_standalone): ?>
                <input type="hidden" name="page" value="torneo_gestion">
                <?php endif; ?>
                <input type="hidden" name="action" value="inscripciones_gestor_excel">
                <input type="hidden" name="torneo_id" value="<?php echo (int)$tid_report; ?>">
                <input type="hidden" name="columnas_orden" value="" id="columnas_orden">

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
                    <div class="xl:col-span-1">
                        <label class="block text-[12px] font-extrabold text-slate-800 mb-1">Tabla a descargar</label>
                        <select name="tipo_reporte" class="w-full border-2 border-slate-300 rounded-lg px-3 py-2 text-[12px] font-semibold">
                            <option value="inscritos_detallado">Inscritos detallado</option>
                            <option value="inscritos_por_equipo">Inscritos por equipo</option>
                            <option value="partiresul_detallado">Partiresul detallado</option>
                            <option value="partiresul_por_ronda">Partiresul por ronda</option>
                            <option value="equipos_detallado">Equipos detallado</option>
                        </select>
                    </div>
                    <div class="xl:col-span-1">
                        <label class="block text-[12px] font-extrabold text-slate-800 mb-1">Rondas a incluir</label>
                        <select name="rondas_cantidad" id="gestor-rondas-cantidad" class="w-full border-2 border-slate-300 rounded-lg px-2 py-1.5 text-[12px] font-semibold">
                            <option value="99">Todas las rondas</option>
                            <option value="-1">Una sola ronda (número exacto)</option>
                            <option value="1">Última ronda</option>
                            <option value="2">Últimas 2 rondas</option>
                            <option value="3">Últimas 3 rondas</option>
                            <option value="5">Últimas 5 rondas</option>
                            <option value="10">Últimas 10 rondas</option>
                        </select>
                        <div id="wrap-ronda-numero-exacta" class="mt-2 hidden">
                            <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Número de ronda (partida)</label>
                            <input type="number" name="ronda_numero" id="gestor-ronda-numero" min="1" max="999" value="1" class="w-full border-2 border-slate-300 rounded-lg px-2 py-1 text-[12px] font-semibold">
                            <p class="text-[10px] text-slate-500 mt-1 mb-0">Solo aplica a «Partiresul por ronda»: un valor único evita solapar datos de varias rondas.</p>
                        </div>
                    </div>
                    <div class="xl:col-span-2">
                        <label class="block text-base font-extrabold text-slate-800 mb-2">Campos de la tabla seleccionada</label>
                        <div class="p-3 rounded-lg border border-slate-200 bg-white">
                            <div data-cols-for="inscritos_detallado inscritos_por_equipo" class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm font-semibold text-slate-800">
                                <label><input type="checkbox" name="columnas[]" value="asociacion_nombre" checked> Asociación</label>
                                <label><input type="checkbox" name="columnas[]" value="equipo_nombre" checked> Equipo</label>
                                <label><input type="checkbox" name="columnas[]" value="codigo_equipo" checked> Código equipo</label>
                                <label><input type="checkbox" name="columnas[]" value="id_usuario" checked> ID usuario</label>
                                <label><input type="checkbox" name="columnas[]" value="numfvd" checked> NUMFVD</label>
                                <label><input type="checkbox" name="columnas[]" value="cedula" checked> Cédula</label>
                                <label><input type="checkbox" name="columnas[]" value="usuario_nombre" checked> Nombre usuario</label>
                                <label><input type="checkbox" name="columnas[]" value="usuario_sexo" checked> Sexo</label>
                                <label><input type="checkbox" name="columnas[]" value="usuario_telefono" checked> Teléfono</label>
                            </div>
                            <div data-cols-for="partiresul_detallado partiresul_por_ronda" class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm font-semibold text-slate-800">
                                <label><input type="checkbox" name="columnas[]" value="partida" checked> Ronda</label>
                                <label><input type="checkbox" name="columnas[]" value="mesa" checked> Mesa</label>
                                <label><input type="checkbox" name="columnas[]" value="secuencia" checked> Secuencia</label>
                                <label><input type="checkbox" name="columnas[]" value="id_usuario" checked> ID usuario</label>
                                <label><input type="checkbox" name="columnas[]" value="usuario_nombre" checked> Nombre usuario</label>
                                <label><input type="checkbox" name="columnas[]" value="resultado1" checked> Resultado1</label>
                                <label><input type="checkbox" name="columnas[]" value="resultado2" checked> Resultado2</label>
                                <label><input type="checkbox" name="columnas[]" value="ff" checked> FF</label>
                                <label><input type="checkbox" name="columnas[]" value="tarjeta" checked> Tarjeta</label>
                                <label><input type="checkbox" name="columnas[]" value="sancion" checked> Sanción</label>
                                <label><input type="checkbox" name="columnas[]" value="registrado" checked> Registrado</label>
                            </div>
                            <div data-cols-for="equipos_detallado" class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm font-semibold text-slate-800">
                                <label><input type="checkbox" name="columnas[]" value="codigo_equipo" checked> Código equipo</label>
                                <label><input type="checkbox" name="columnas[]" value="nombre_equipo" checked> Nombre equipo</label>
                                <label><input type="checkbox" name="columnas[]" value="id_club" checked> ID club</label>
                                <label><input type="checkbox" name="columnas[]" value="club_nombre" checked> Club</label>
                                <label><input type="checkbox" name="columnas[]" value="ganados" checked> Ganados</label>
                                <label><input type="checkbox" name="columnas[]" value="perdidos" checked> Perdidos</label>
                                <label><input type="checkbox" name="columnas[]" value="efectividad" checked> Efectividad</label>
                                <label><input type="checkbox" name="columnas[]" value="puntos" checked> Puntos</label>
                                <label><input type="checkbox" name="columnas[]" value="sancion" checked> Sanción</label>
                                <label><input type="checkbox" name="columnas[]" value="posicion" checked> Posición</label>
                                <label><input type="checkbox" name="columnas[]" value="estatus" checked> Estatus</label>
                                <label><input type="checkbox" name="columnas[]" value="fecha_actualizacion" checked> Actualización</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-4 gap-4 items-end">
                    <div class="xl:col-span-4">
                        <label class="block text-base font-extrabold text-slate-800 mb-1">Resultado del reporte a descargar</label>
                        <ul id="preview-columnas" class="list-disc pl-5 text-sm font-semibold text-slate-800 bg-white border border-slate-200 rounded-lg p-3"></ul>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 mt-1">
                    <button type="submit" class="tw-btn bg-emerald-700 hover:bg-emerald-800 text-white">
                        <i class="fas fa-file-excel mr-2"></i> Descargar Excel
                    </button>
                    <a href="<?php echo htmlspecialchars($url_xls_gestor, ENT_QUOTES, 'UTF-8'); ?>" class="tw-btn bg-slate-200 hover:bg-slate-300 text-slate-900">
                        <i class="fas fa-bolt mr-2"></i> Rápido (inscritos_detallado)
                    </a>
                </div>
                </form>
            </section>

            <script>
                (function () {
                    var form = document.getElementById('form-gestor-excel');
                    if (!form) return;
                    var tipo = form.querySelector('select[name="tipo_reporte"]');
                    var rondasCantidad = form.querySelector('select[name="rondas_cantidad"]');
                    var wrapRondaExacta = document.getElementById('wrap-ronda-numero-exacta');
                    var grupos = form.querySelectorAll('[data-cols-for]');
                    var preview = document.getElementById('preview-columnas');
                    var ordenInput = document.getElementById('columnas_orden');
                    var ordenSeleccion = [];

                    function labelDeCheckbox(chk) {
                        return (chk.parentElement ? chk.parentElement.textContent : chk.value).trim();
                    }

                    function rebuildOrdenVisible() {
                        if (!tipo) return;
                        var t = tipo.value;
                        var visibles = [];
                        grupos.forEach(function (g) {
                            var allow = (' ' + (g.getAttribute('data-cols-for') || '') + ' ').indexOf(' ' + t + ' ') >= 0;
                            if (!allow) return;
                            g.querySelectorAll('input[type="checkbox"]').forEach(function (chk) {
                                if (chk.checked && !chk.disabled) visibles.push(chk.value);
                            });
                        });
                        ordenSeleccion = ordenSeleccion.filter(function (k) { return visibles.indexOf(k) >= 0; });
                        visibles.forEach(function (k) {
                            if (ordenSeleccion.indexOf(k) < 0) ordenSeleccion.push(k);
                        });
                    }

                    function renderPreview() {
                        if (!preview) return;
                        preview.innerHTML = '';
                        if (ordenSeleccion.length === 0) {
                            var li0 = document.createElement('li');
                            li0.textContent = 'Seleccione al menos un campo.';
                            preview.appendChild(li0);
                        } else {
                            ordenSeleccion.forEach(function (key) {
                                var chk = form.querySelector('input[type="checkbox"][value="' + key + '"]');
                                var li = document.createElement('li');
                                li.textContent = labelDeCheckbox(chk || { value: key, parentElement: null });
                                preview.appendChild(li);
                            });
                        }
                        if (ordenInput) {
                            ordenInput.value = ordenSeleccion.join(',');
                        }
                    }

                    function syncCols() {
                        var t = tipo ? tipo.value : '';
                        grupos.forEach(function (g) {
                            var allow = (' ' + (g.getAttribute('data-cols-for') || '') + ' ').indexOf(' ' + t + ' ') >= 0;
                            g.style.display = allow ? '' : 'none';
                            g.querySelectorAll('input[type="checkbox"]').forEach(function (chk) {
                                chk.disabled = !allow;
                            });
                        });
                        var usaRondas = (t === 'partiresul_por_ronda');
                        if (rondasCantidad) {
                            rondasCantidad.disabled = !usaRondas;
                        }
                        if (wrapRondaExacta) {
                            wrapRondaExacta.style.display = (usaRondas && rondasCantidad && rondasCantidad.value === '-1') ? '' : 'none';
                        }
                        rebuildOrdenVisible();
                        renderPreview();
                    }

                    form.querySelectorAll('input[type="checkbox"][name="columnas[]"]').forEach(function (chk) {
                        chk.addEventListener('change', function () {
                            if (chk.checked) {
                                ordenSeleccion = ordenSeleccion.filter(function (k) { return k !== chk.value; });
                                ordenSeleccion.push(chk.value);
                            } else {
                                ordenSeleccion = ordenSeleccion.filter(function (k) { return k !== chk.value; });
                            }
                            rebuildOrdenVisible();
                            renderPreview();
                        });
                    });
                    if (tipo) tipo.addEventListener('change', syncCols);
                    if (rondasCantidad) {
                        rondasCantidad.addEventListener('change', syncCols);
                    }
                    form.addEventListener('submit', function () {
                        rebuildOrdenVisible();
                        renderPreview();
                    });
                    syncCols();
                })();
            </script>
            <div class="pt-2">
                <a href="<?php echo htmlspecialchars($url_panel, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800 font-semibold">
                    <i class="fas fa-arrow-left mr-2"></i> Volver al panel del torneo
                </a>
            </div>
        </div>
    </div>
</div>
