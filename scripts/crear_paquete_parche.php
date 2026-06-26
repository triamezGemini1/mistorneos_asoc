<?php
/**
 * ZIP parche: solo archivos de la sesión (org. particulares, permisos, torneos, finanzas).
 * Uso: php scripts/crear_paquete_parche.php
 */
declare(strict_types=1);

$base = dirname(__DIR__);
$stamp = date('Ymd_His');
$output = $base . '/parche_mistorneos_' . $stamp . '.zip';

$paths = [
    // SQL
    'sql/migracion_estructura_organizaciones_2026.sql',
    'sql/fix_cod_org_organizaciones_particulares.sql',
    'DEPLOY_ESTRUCTURA_ORGANIZACIONES.md',
    // Config / auth
    'config/auth.php',
    'config/bootstrap.php',
    // Lib
    'lib/InscritosHelper.php',
    'lib/ResumenParticipacionHelper.php',
    'lib/ResultadosReporteData.php',
    'lib/ResultadosPublicHelper.php',
    'lib/AsociacionAdminHelper.php',
    'lib/FinanzasAsociacionData.php',
    'lib/OrganizacionDashboardStats.php',
    'lib/TorneosEstructuraService.php',
    'lib/PartiresulEstatusSql.php',
    'lib/Tournament/Handlers/RoundManagerHandler.php',
    'config/MesaAsignacionEquiposService.php',
    'lib/Core/MesaRepositoryPersistTrait.php',
    'modules/tournament_admin/ingreso_resultados.php',
    'actions/public_score_submit.php',
    'lib/ClubHelper.php',
    'lib/StoragePaths.php',
    // Panel FVD / asociación
    'modules/asociacion_panel.php',
    'modules/asociacion/solicitud.php',
    'modules/asociacion/torneo_ver.php',
    'modules/finanzas/resumen_asociacion.php',
    // Torneos (crear + permisos particulares)
    'modules/tournaments.php',
    'modules/tournaments/save.php',
    'modules/tournaments/update.php',
    'modules/torneo_gestion.php',
    'lib/Tournament/Handlers/TournamentActionHandler.php',
    'modules/gestion_torneos/index.php',
    'modules/gestion_torneos/resumen-individual.php',
    'resources/views/tournament/parts/resumen-individual.php',
    'modules/tournament_admin/generar_rondas.php',
    'public/resumen_jugador.php',
    'lib/PublicTorneoPortalHelper.php',
    // Estructura org / menú
    'modules/organizaciones_particulares.php',
    'modules/organizaciones/listado_particulares.php',
    'modules/organizaciones/listado_entidades.php',
    'modules/organizaciones/org_detail.php',
    'modules/organizaciones.php',
    'modules/entidades.php',
    'modules/mi_organizacion.php',
    'modules/torneos_estructura.php',
    'modules/torneos_estructura/lista.php',
    'modules/torneos_estructura/reporte.php',
    'modules/affiliate_requests/list.php',
    // Router / layout
    'public/index.php',
    'public/includes/layout.php',
    'public/check_env.php',
    'public/diagnose_home.php',
    'public/diagnose_asociacion_panel.php',
    // Asignación mesas: sin límite club en org. particular / un solo club
    'lib/Core/MesaRepository.php',
    'lib/Core/MesaAsignacion/MesaAsignacionLimiteClubMesaTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionRoundsTrait.php',
    'lib/Core/MesaAsignacion/MesaAsignacionClubInterclubTrait.php',
    'config/MesaAsignacionService.php',
    'config/MesaAsignacionParejasFijasService.php',
    'storage/logs/.gitkeep',
    'storage/sessions/.gitkeep',
];

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive no disponible.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear ZIP.\n");
    exit(1);
}

$added = 0;
$missing = [];
foreach ($paths as $rel) {
    $path = $base . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        $missing[] = $rel;
        continue;
    }
    $zip->addFile($path, str_replace('\\', '/', $rel));
    $added++;
}

$zip->addFromString(
    'INSTALAR_PARCHE.txt',
    "PARCHE MIS TORNEOS — {$stamp}\n\n"
    . "Incluye: org. particulares, sin panel FVD para particulares, crear torneos,\n"
    . "fix bucle home/asociacion_panel, finanzas sin movimiento_torneo FVD,\n"
    . "asignación mesas sin tope mismo club (org. particular o un solo club inscrito),\n"
    . "todos los inscritos salvo retirados cuentan para rondas; fix SQL estatus confirmado;\n"
    . "resumen individual muestra compañero de ronda en columna Pareja.\n\n"
    . "1. Backup BD y archivos.\n\n"
    . "2. SQL en phpMyAdmin (orden):\n"
    . "   sql/migracion_estructura_organizaciones_2026.sql\n"
    . "   sql/fix_cod_org_organizaciones_particulares.sql\n\n"
    . "3. Subir archivos conservando rutas (lib/, modules/, public/, config/).\n"
    . "   NO sobrescribir .env del servidor.\n\n"
    . "4. Ver DEPLOY_ESTRUCTURA_ORGANIZACIONES.md y public/check_env.php\n"
);
$zip->close();

echo "=== Parche generado ===\n";
echo basename($output) . "\n";
echo 'Ruta: ' . $output . "\n";
echo "Archivos: {$added}\n";
echo 'Tamaño: ' . round(filesize($output) / 1024, 1) . " KB\n";
if ($missing !== []) {
    echo "Faltantes:\n  - " . implode("\n  - ", $missing) . "\n";
    exit(1);
}
