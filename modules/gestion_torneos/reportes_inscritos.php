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
            $stmt = $pdo->prepare('SELECT t.*, o.nombre AS organizacion_nombre FROM tournaments t LEFT JOIN organizaciones o ON t.club_responsable = o.id WHERE t.id = ?');
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
        <div class="p-5 space-y-6">
            <section>
                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wide mb-3">Listados detallados</h2>
                <p class="text-sm text-slate-600 mb-3">Por asociación, equipo y jugadores (formato completo para revisión e impresión).</p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="<?php echo htmlspecialchars($url_pdf_det, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="tw-btn bg-rose-600 hover:bg-rose-700 text-white text-center flex-1 justify-center">
                        <i class="fas fa-file-pdf mr-2"></i> Imprimir PDF detallado
                    </a>
                    <a href="<?php echo htmlspecialchars($url_xls_det, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="tw-btn bg-emerald-700 hover:bg-emerald-800 text-white text-center flex-1 justify-center">
                        <i class="fas fa-file-excel mr-2"></i> Descargar Excel detallado
                    </a>
                </div>
            </section>
            <section class="pt-2 border-t border-slate-200">
                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wide mb-3">Listados simples</h2>
                <p class="text-sm text-slate-600 mb-3">Tabla compacta de inscritos (misma información base, menos formato).</p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="<?php echo htmlspecialchars($url_pdf_simple, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="tw-btn bg-rose-500 hover:bg-rose-600 text-white text-center flex-1 justify-center">
                        <i class="fas fa-file-pdf mr-2"></i> PDF simple
                    </a>
                    <a href="<?php echo htmlspecialchars($url_xls_simple, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="tw-btn bg-emerald-600 hover:bg-emerald-700 text-white text-center flex-1 justify-center">
                        <i class="fas fa-file-excel mr-2"></i> Excel simple
                    </a>
                </div>
            </section>
            <section class="pt-2 border-t border-slate-200">
                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wide mb-3">Retirados</h2>
                <p class="text-sm text-slate-600 mb-3">Jugadores con estatus retirado en este torneo.</p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="<?php echo htmlspecialchars($url_pdf_ret, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="tw-btn bg-amber-600 hover:bg-amber-700 text-white text-center flex-1 justify-center">
                        <i class="fas fa-file-pdf mr-2"></i> PDF retirados
                    </a>
                    <a href="<?php echo htmlspecialchars($url_xls_ret, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="tw-btn bg-amber-700 hover:bg-amber-800 text-white text-center flex-1 justify-center">
                        <i class="fas fa-file-excel mr-2"></i> Excel retirados
                    </a>
                </div>
            </section>
            <section class="pt-2 border-t border-slate-200">
                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wide mb-3">Gestor Excel a petición</h2>
                <p class="text-sm text-slate-600 mb-3">Descarga reportes desde <code>inscritos</code>, <code>partiresul</code> y <code>equipos</code>. Puedes elegir columnas con checkboxes antes de exportar.</p>
                <form method="get" action="<?php echo htmlspecialchars($form_action_url, ENT_QUOTES, 'UTF-8'); ?>" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-start" id="form-gestor-excel">
                    <?php if (!$use_standalone): ?>
                    <input type="hidden" name="page" value="torneo_gestion">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="inscripciones_gestor_excel">
                    <input type="hidden" name="torneo_id" value="<?php echo (int)$tid_report; ?>">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Tipo de reporte</label>
                        <select name="tipo_reporte" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                            <option value="inscritos_detallado">Inscritos detallado (con nombre)</option>
                            <option value="inscritos_por_equipo">Inscritos por equipo (con nombre)</option>
                            <option value="partiresul_detallado">Partiresul detallado (con nombre)</option>
                            <option value="partiresul_por_ronda">Partiresul por ronda (con nombre)</option>
                            <option value="equipos_detallado">Equipos detallado</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Ronda (solo por ronda)</label>
                        <input type="number" min="1" name="ronda" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="Ej: 1">
                    </div>
                    <div class="md:col-span-2 flex gap-2 mt-6">
                        <button type="submit" class="tw-btn bg-emerald-700 hover:bg-emerald-800 text-white">
                            <i class="fas fa-file-excel mr-2"></i> Descargar Excel
                        </button>
                        <a href="<?php echo htmlspecialchars($url_xls_gestor, ENT_QUOTES, 'UTF-8'); ?>" class="tw-btn bg-slate-200 hover:bg-slate-300 text-slate-900">
                            <i class="fas fa-bolt mr-2"></i> Rápido (inscritos_detallado)
                        </a>
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-xs font-semibold text-slate-700 mb-2">Columnas a exportar</label>
                        <div class="p-3 rounded-lg border border-slate-200 bg-slate-50">
                            <div data-cols-for="inscritos_detallado inscritos_por_equipo" class="grid grid-cols-2 md:grid-cols-3 gap-2">
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
                            <div data-cols-for="partiresul_detallado partiresul_por_ronda" class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-3">
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
                            <div data-cols-for="equipos_detallado" class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-3">
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
                </form>
                <script>
                (function () {
                    var form = document.getElementById('form-gestor-excel');
                    if (!form) return;
                    var tipo = form.querySelector('select[name="tipo_reporte"]');
                    var grupos = form.querySelectorAll('[data-cols-for]');
                    var rondaInput = form.querySelector('input[name="ronda"]');
                    function syncCols() {
                        var t = tipo ? tipo.value : '';
                        grupos.forEach(function (g) {
                            var allow = (' ' + (g.getAttribute('data-cols-for') || '') + ' ').indexOf(' ' + t + ' ') >= 0;
                            g.style.display = allow ? '' : 'none';
                            g.querySelectorAll('input[type="checkbox"]').forEach(function (chk) {
                                chk.disabled = !allow;
                            });
                        });
                        if (rondaInput) {
                            rondaInput.disabled = (t !== 'partiresul_por_ronda');
                        }
                    }
                    if (tipo) tipo.addEventListener('change', syncCols);
                    syncCols();
                })();
                </script>
            </section>
            <div class="pt-2">
                <a href="<?php echo htmlspecialchars($url_panel, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800 font-semibold">
                    <i class="fas fa-arrow-left mr-2"></i> Volver al panel del torneo
                </a>
            </div>
        </div>
    </div>
</div>
