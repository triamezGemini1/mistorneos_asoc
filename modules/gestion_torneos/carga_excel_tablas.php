<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/app_helpers.php';

$tid = (int)($torneo_id ?? 0);
$u = static function (string $action, array $extra = []) use ($tid): string {
    return AppHelpers::url('index.php', array_merge([
        'page' => 'torneo_gestion',
        'action' => $action,
        'torneo_id' => $tid,
    ], $extra));
};
?>
<link rel="stylesheet" href="assets/dist/output.css">

<div class="min-h-screen bg-gradient-to-br from-slate-700 via-slate-800 to-slate-900 p-6">
    <div class="max-w-6xl mx-auto">
        <div class="mb-4">
            <a href="<?php echo htmlspecialchars($u('panel')); ?>" class="inline-flex items-center px-4 py-2 bg-slate-200 text-slate-900 rounded-lg font-bold">
                <i class="fas fa-arrow-left mr-2"></i> Volver al panel
            </a>
        </div>

        <div class="bg-white rounded-xl shadow-xl p-6">
            <h1 class="text-2xl font-extrabold text-slate-800 mb-2">Carga Excel a tablas</h1>
            <p class="text-sm text-slate-600 mb-4">Sube un archivo y selecciona la tabla destino. Solo se procesan los campos seleccionados.</p>

            <form method="post" action="<?php echo htmlspecialchars($u('carga_excel_tablas_procesar')); ?>" enctype="multipart/form-data" id="form-carga-tablas" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="torneo_id" value="<?php echo (int)$tid; ?>">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-1">Tabla destino</label>
                        <select name="tabla_destino" class="w-full border-2 border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold">
                            <option value="inscritos">inscritos</option>
                            <option value="equipos">equipos</option>
                            <option value="partiresul">partiresul</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-slate-800 mb-1">Archivo (.xlsx/.csv/.txt)</label>
                        <input type="file" name="archivo" accept=".xlsx,.csv,.txt" required class="w-full border-2 border-slate-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div data-cols="inscritos" class="p-3 border rounded-lg bg-slate-50">
                        <p class="font-bold text-sm mb-2">Campos inscritos</p>
                        <div class="space-y-1 text-sm">
                            <label><input type="checkbox" name="columnas[]" value="id_usuario" checked> id_usuario</label><br>
                            <label><input type="checkbox" name="columnas[]" value="id_club"> id_club</label><br>
                            <label><input type="checkbox" name="columnas[]" value="codigo_equipo"> codigo_equipo</label><br>
                            <label><input type="checkbox" name="columnas[]" value="estatus"> estatus</label><br>
                            <label><input type="checkbox" name="columnas[]" value="cedula"> cedula</label><br>
                            <label><input type="checkbox" name="columnas[]" value="numfvd"> numfvd</label>
                        </div>
                    </div>
                    <div data-cols="equipos" class="p-3 border rounded-lg bg-slate-50">
                        <p class="font-bold text-sm mb-2">Campos equipos</p>
                        <div class="space-y-1 text-sm">
                            <label><input type="checkbox" name="columnas[]" value="codigo_equipo" checked> codigo_equipo</label><br>
                            <label><input type="checkbox" name="columnas[]" value="nombre_equipo"> nombre_equipo</label><br>
                            <label><input type="checkbox" name="columnas[]" value="id_club"> id_club</label><br>
                            <label><input type="checkbox" name="columnas[]" value="estatus"> estatus</label><br>
                            <label><input type="checkbox" name="columnas[]" value="ganados"> ganados</label><br>
                            <label><input type="checkbox" name="columnas[]" value="perdidos"> perdidos</label><br>
                            <label><input type="checkbox" name="columnas[]" value="efectividad"> efectividad</label><br>
                            <label><input type="checkbox" name="columnas[]" value="puntos"> puntos</label>
                        </div>
                    </div>
                    <div data-cols="partiresul" class="p-3 border rounded-lg bg-slate-50">
                        <p class="font-bold text-sm mb-2">Campos partiresul</p>
                        <div class="space-y-1 text-sm">
                            <label><input type="checkbox" name="columnas[]" value="partida" checked> partida</label><br>
                            <label><input type="checkbox" name="columnas[]" value="mesa" checked> mesa</label><br>
                            <label><input type="checkbox" name="columnas[]" value="secuencia" checked> secuencia</label><br>
                            <label><input type="checkbox" name="columnas[]" value="resultado1" checked> resultado1</label><br>
                            <label><input type="checkbox" name="columnas[]" value="resultado2" checked> resultado2</label><br>
                            <label><input type="checkbox" name="columnas[]" value="ff"> ff</label><br>
                            <label><input type="checkbox" name="columnas[]" value="sancion"> sancion</label><br>
                            <label><input type="checkbox" name="columnas[]" value="tarjeta"> tarjeta</label>
                        </div>
                    </div>
                </div>

                <div>
                    <button type="submit" class="px-6 py-3 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-bold">
                        <i class="fas fa-upload mr-2"></i> Procesar carga
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('form-carga-tablas');
    if (!form) return;
    var sel = form.querySelector('select[name="tabla_destino"]');
    var blocks = form.querySelectorAll('[data-cols]');
    function sync() {
        var t = sel ? sel.value : 'inscritos';
        blocks.forEach(function (b) {
            var on = b.getAttribute('data-cols') === t;
            b.style.display = on ? '' : 'none';
            b.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.disabled = !on; });
        });
    }
    if (sel) sel.addEventListener('change', sync);
    sync();
})();
</script>
