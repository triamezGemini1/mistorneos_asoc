<?php
/**
 * Contexto compartido: ranking_atletas.php y ranking_atletas_pdf.php.
 * Requiere: $pdo, RankingAtletasPublicoService cargado, Auth.
 *
 * Define: $genero, $vista, $user, $role, $organizacion_id, $hasCodOrg, $organizaciones,
 * $org_nombre_sesion, $ranking_sin_org_admin_general, $org_nombre_encabezado,
 * $data, $atletas, $criterio, $torneos_matriz
 */
declare(strict_types=1);

$genero = isset($_GET['genero']) ? strtoupper((string) $_GET['genero']) : 'F';
if ($genero !== 'M' && $genero !== 'F') {
    $genero = 'F';
}

$vista = isset($_GET['vista']) ? (string) $_GET['vista'] : 'resumen';
if (! in_array($vista, ['resumen', 'detalle', 'matriz'], true)) {
    $vista = 'resumen';
}

$user = Auth::user();
$role = is_array($user) ? (string) ($user['role'] ?? '') : '';

$organizacion_id = isset($_GET['organizacion_id']) ? (int) $_GET['organizacion_id'] : 0;
if ($organizacion_id < 0) {
    $organizacion_id = 0;
}

if ($role === 'admin_club') {
    $organizacion_id = (int) Auth::getUserOrganizacionId();
}

$hasCodOrg = false;
try {
    $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $hasCodOrg = false;
}

$organizaciones = [];
$org_nombre_sesion = '';
$ranking_sin_org_admin_general = false;

try {
    if ($role === 'admin_general') {
        $stmtOrg = $pdo->query('SELECT id, nombre FROM organizaciones WHERE estatus = 1 ORDER BY nombre ASC');
        $organizaciones = $stmtOrg->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($organizacion_id > 0) {
            $valida = false;
            foreach ($organizaciones as $rowOrg) {
                if ((int) ($rowOrg['id'] ?? 0) === $organizacion_id) {
                    $valida = true;
                    break;
                }
            }
            if (! $valida) {
                $organizacion_id = 0;
            }
        }
        $ranking_sin_org_admin_general = ($organizacion_id <= 0);
    } elseif ($role === 'admin_club') {
        if ($organizacion_id > 0) {
            $stmtNom = $pdo->prepare('SELECT nombre FROM organizaciones WHERE estatus = 1 AND id = ? LIMIT 1');
            $stmtNom->execute([$organizacion_id]);
            $org_nombre_sesion = (string) ($stmtNom->fetchColumn() ?: '');
        }
    } else {
        if (! $hasCodOrg) {
            $stmtOrg = $pdo->query("
                SELECT DISTINCT o.id, o.nombre
                FROM tournaments t
                INNER JOIN organizaciones o ON o.id = t.club_responsable AND o.estatus = 1
                WHERE t.estatus = 1
                  AND COALESCE(t.ranking, 0) = 1
                  AND DATE(t.fechator) < CURDATE()
                ORDER BY o.nombre ASC
            ");
        } else {
            $stmtOrg = $pdo->query("
                SELECT DISTINCT o.id, o.nombre
                FROM tournaments t
                INNER JOIN organizaciones o ON o.estatus = 1
                  AND (
                    o.id = COALESCE(NULLIF(t.cod_org, 0), t.club_responsable)
                    OR (o.cod_org > 0 AND o.cod_org = COALESCE(NULLIF(t.cod_org, 0), t.club_responsable))
                  )
                WHERE t.estatus = 1
                  AND COALESCE(t.ranking, 0) = 1
                  AND DATE(t.fechator) < CURDATE()
                ORDER BY o.nombre ASC
            ");
        }
        $organizaciones = $stmtOrg->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $organizaciones = [];
}

$org_nombre_encabezado = '';
if ($organizacion_id > 0) {
    try {
        $stHdr = $pdo->prepare('SELECT nombre FROM organizaciones WHERE estatus = 1 AND id = ? LIMIT 1');
        $stHdr->execute([$organizacion_id]);
        $org_nombre_encabezado = trim((string) ($stHdr->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $org_nombre_encabezado = '';
    }
    if ($org_nombre_encabezado === '' && $role === 'admin_club' && $org_nombre_sesion !== '') {
        $org_nombre_encabezado = $org_nombre_sesion;
    }
}

$svc = new RankingAtletasPublicoService($pdo);
if ($role === 'admin_general' && $ranking_sin_org_admin_general) {
    $data = [
        'atletas' => [],
        'criterio_orden' => 'Seleccione la organización cuyo ranking desea consultar. Hasta entonces no se muestran datos acumulados.',
    ];
} elseif ($role === 'admin_club' && $organizacion_id <= 0) {
    $data = [
        'atletas' => [],
        'criterio_orden' => 'Su cuenta no tiene una organización asignada. No es posible mostrar el ranking.',
    ];
} else {
    $data = $svc->construirRanking($genero, $organizacion_id);
}
$atletas = $data['atletas'];
$criterio = $data['criterio_orden'];

$torneos_matriz = [];
if ($atletas !== []) {
    $metaTorneos = [];
    foreach ($atletas as $a) {
        foreach ($a['detalle_torneos'] as $t) {
            $tid = (int) ($t['torneo_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (! isset($metaTorneos[$tid])) {
                $metaTorneos[$tid] = [
                    'torneo_id' => $tid,
                    'nombre' => (string) ($t['nombre'] ?? ''),
                    'fechator' => (string) ($t['fechator'] ?? ''),
                ];
            }
        }
    }
    if ($metaTorneos !== []) {
        $torneos_matriz = array_values($metaTorneos);
        usort($torneos_matriz, static function (array $x, array $y): int {
            return strcmp((string) ($y['fechator'] ?? ''), (string) ($x['fechator'] ?? ''));
        });
    }
}
