<?php
declare(strict_types=1);

/**
 * Homologa asociaciones territoriales con el catálogo entidad:
 * 1) Nombres y cod_org desde entidad
 * 2) PK organizaciones.id = código territorial (org canónica por entidad)
 * 3) Torneos (club_responsable, cod_org) y clubes.cod_org
 *
 * Uso:
 *   php scripts/homologar_asociaciones_entidades.php --dry-run
 *   php scripts/homologar_asociaciones_entidades.php --execute
 *   php scripts/homologar_asociaciones_entidades.php --execute --solo-nombres
 *   php scripts/homologar_asociaciones_entidades.php --execute --solo-vinculos
 *
 * Hacer respaldo de la base antes de --execute.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/HomologacionEntidadesService.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$execute = in_array('--execute', $argv ?? [], true);
$soloNombres = in_array('--solo-nombres', $argv ?? [], true);
$soloVinculos = in_array('--solo-vinculos', $argv ?? [], true);

if (! $dryRun && ! $execute) {
    fwrite(STDERR, "Indique --dry-run o --execute\n");
    exit(1);
}
if ($dryRun && $execute) {
    fwrite(STDERR, "Use solo uno: --dry-run o --execute\n");
    exit(1);
}

$pdo = DB::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$modo = $dryRun ? 'DRY-RUN' : 'EJECUTANDO';

echo "=== {$modo}: homologación asociaciones ↔ entidades ===\n\n";

if (! $soloVinculos) {
    echo "--- Auditoría: id ≠ entidad ---\n";
    $audit = HomologacionEntidadesService::auditIdDesalineados($pdo);
    if ($audit === []) {
        echo "Todas las orgs canónicas tienen id = entidad.\n";
    } else {
        foreach ($audit as $row) {
            echo sprintf(
                "  org#%d (%s) ent=%d → id objetivo %d\n",
                (int) $row['org_id_actual'],
                mb_substr((string) $row['nombre'], 0, 40),
                (int) $row['entidad'],
                (int) $row['org_id_objetivo']
            );
        }
        echo 'Total a realinear: ' . count($audit) . "\n";
    }

    echo "\n--- Fase 1: nombres y cod_org ---\n";
    $sync = HomologacionEntidadesService::syncNombresYCodigos($pdo, $dryRun);
    echo "Organizaciones a actualizar: {$sync['nombres_org']}\n";
    echo "cod_org a corregir: {$sync['cod_org']}\n";
    echo "Clubes asociación (nombre): {$sync['nombres_club']}\n";
}

if (! $soloNombres && ! $soloVinculos) {
    echo "\n--- Fase 2: reorganizar organizaciones.id = entidad ---\n";
    try {
        $reorg = HomologacionEntidadesService::reorganizarIdsOrganizaciones($pdo, $dryRun);
        echo "Orgs a mover: {$reorg['moved']}\n";
        if ($reorg['map'] !== []) {
            echo "Mapeo (muestra):\n";
            $n = 0;
            foreach ($reorg['map'] as $old => $new) {
                if ($n++ >= 15) {
                    echo "  ...\n";
                    break;
                }
                echo "  id {$old} → {$new}\n";
            }
        }
        if (! $dryRun) {
            echo "Referencias actualizadas (aprox.): {$reorg['fks_updated']}\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error fase 2: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

if (! $soloNombres || $soloVinculos) {
    echo "\n--- Fase 3: torneos y clubes responsables ---\n";
    $auditT = HomologacionEntidadesService::auditTorneosDesalineados($pdo);
    foreach ($auditT as $row) {
        echo sprintf(
            "  torneo#%d ent=%d resp=%d cod=%d → resp/cod=%d (%s)\n",
            (int) $row['id'],
            (int) $row['entidad'],
            (int) $row['club_responsable'],
            (int) $row['cod_org'],
            (int) $row['objetivo_resp'],
            (string) $row['motivo']
        );
    }
    $auditC = HomologacionEntidadesService::auditClubesCodOrgDesalineados($pdo);
    foreach ($auditC as $row) {
        echo sprintf(
            "  club#%d ent=%d cod_org=%d → %d | %s\n",
            (int) $row['id'],
            (int) $row['entidad'],
            (int) $row['cod_org'],
            (int) $row['objetivo_cod'],
            mb_substr((string) $row['nombre'], 0, 35)
        );
    }
    $syncTc = HomologacionEntidadesService::syncTorneosYClubes($pdo, $dryRun);
    echo "Torneos a corregir: {$syncTc['torneos']}\n";
    echo "Clubes cod_org a corregir: {$syncTc['clubes']}\n";
}

echo "\n";
if ($dryRun) {
    echo "[DRY-RUN] Sin cambios. Ejecute con --execute tras respaldo.\n";
} else {
    echo "Homologación completada.\n";
}

exit(0);
