<?php
/**
 * Ranking público de atletas (femenino / masculino) según acumulado en torneos finalizados.
 * Datos desde inscritos + usuarios + tournaments (misma lógica que resultados por evento).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/RankingAtletasPublicoService.php';

$pdo = DB::pdo();
$base_public = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';

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

// admin_club: solo la organización activa del usuario (ignora GET malicioso)
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
            // Siempre por PK (id de organización); no mezclar id con cod_org en el mismo placeholder.
            $stmtNom = $pdo->prepare('SELECT nombre FROM organizaciones WHERE estatus = 1 AND id = ? LIMIT 1');
            $stmtNom->execute([$organizacion_id]);
            $org_nombre_sesion = (string) ($stmtNom->fetchColumn() ?: '');
        }
    } else {
        // Listado público: valores option = id (PK) y nombre real de la tabla organizaciones.
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

/** Nombre para el encabezado: siempre por PK organizaciones.id (coincide con el filtro). */
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

/** Torneos presentes en el resultado (para vista matriz), ordenados por fecha descendente. */
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

$ranking_atletas_qs = static function (array $overrides = []) use ($genero, $organizacion_id, $vista): string {
    $p = ['genero' => $genero];
    if ($organizacion_id > 0) {
        $p['organizacion_id'] = $organizacion_id;
    }
    if ($vista !== 'resumen') {
        $p['vista'] = $vista;
    }
    $p = array_merge($p, $overrides);
    if ((int) ($p['organizacion_id'] ?? 0) <= 0) {
        unset($p['organizacion_id']);
    }
    if (($p['vista'] ?? 'resumen') === 'resumen') {
        unset($p['vista']);
    }

    return http_build_query($p);
};

$titulo_genero = $genero === 'F' ? 'Femenino' : 'Masculino';
$page_title = 'Ranking de atletas — ' . $titulo_genero . ' — La Estación del Dominó';

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos', 4 => 'Parejas fijas'];

function fmtfecha(?string $f): string
{
    if ($f === null || $f === '') {
        return '—';
    }
    $t = strtotime($f);

    return $t ? date('d/m/Y', $t) : '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="Ranking de atletas <?= htmlspecialchars($titulo_genero) ?>: torneos con ranking activado y rendimiento acumulado (puntos de ranking, efectividad, partidas ganadas).">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars($base_public . 'ranking_atletas.php?' . $ranking_atletas_qs()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #f8fafc;
            padding: 1.5rem 0;
        }
        .card-rank {
            background: rgba(255,255,255,0.98);
            color: #1e293b;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);
            overflow: hidden;
            max-width: 1200px;
        }
        .header-rank {
            background: linear-gradient(135deg, #0f172a 0%, #1e40af 100%);
            color: white;
            padding: 1.75rem;
        }
        .nav-genero .nav-link { color: #64748b; font-weight: 600; border-radius: 10px; }
        .nav-genero .nav-link.active {
            background: linear-gradient(135deg, #0f172a, #1e40af);
            color: #fff;
        }
        .tabla-rank th { font-size: 0.8rem; color: #475569; background: #f8fafc; }
        .pos-1 { background: linear-gradient(90deg, #fef3c7, #fde68a); }
        .pos-2 { background: linear-gradient(90deg, #f1f5f9, #e2e8f0); }
        .pos-3 { background: linear-gradient(90deg, #fed7aa, #fdba74); }
        .btn-volver {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .btn-volver:hover { background: rgba(255,255,255,0.25); color: white; }
        .detalle-torneos { font-size: 0.85rem; background: #f8fafc; }
        .card-rank-wide { max-width: min(1680px, 100%); }
        .card-detalle-atleta { border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; background: #fff; }
        .tabla-matriz th, .tabla-matriz td { white-space: nowrap; font-size: 0.72rem; vertical-align: middle; }
        .tabla-matriz .th-torneo { max-width: 7rem; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body>
<div class="container">
    <div class="card-rank mx-auto <?= $vista === 'matriz' ? 'card-rank-wide' : '' ?>">
        <div class="header-rank">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <?= AppHelpers::appLogo('mb-2', 'La Estación del Dominó', 40) ?>
                    <h1 class="h3 mb-1"><i class="fas fa-medal text-warning me-2"></i>Ranking de atletas</h1>
                    <p class="mb-0 opacity-90 small"><?= htmlspecialchars($titulo_genero) ?> · Solo torneos con ranking activado</p>
                    <?php if ($organizacion_id > 0): ?>
                        <?php if ($org_nombre_encabezado !== ''): ?>
                            <p class="mb-0 mt-2 fw-semibold fs-5 text-white"><i class="fas fa-building me-2 opacity-75"></i><?= htmlspecialchars($org_nombre_encabezado) ?></p>
                        <?php else: ?>
                            <p class="mb-0 mt-2 small opacity-75">Organización #<?= (int) $organizacion_id ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div>
                    <a href="landing-spa.php" class="btn btn-sm btn-volver me-1"><i class="fas fa-home me-1"></i>Inicio</a>
                    <a href="resultados.php" class="btn btn-sm btn-volver"><i class="fas fa-trophy me-1"></i>Resultados por evento</a>
                </div>
            </div>
            <ul class="nav nav-pills nav-genero gap-2 mt-3">
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?= $genero === 'F' ? 'active' : '' ?>" href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['genero' => 'F'])) ?>"><i class="fas fa-venus me-1"></i>Femenino</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?= $genero === 'M' ? 'active' : '' ?>" href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['genero' => 'M'])) ?>"><i class="fas fa-mars me-1"></i>Masculino</a>
                </li>
            </ul>
        </div>
        <div class="p-3 p-md-4">
            <?php if ($role === 'admin_club'): ?>
                <div class="alert alert-secondary border mb-3 py-2 px-3">
                    <i class="fas fa-building me-2"></i>
                    <strong>Organización:</strong>
                    <?= $organizacion_id > 0 ? htmlspecialchars($org_nombre_encabezado !== '' ? $org_nombre_encabezado : ('ID ' . $organizacion_id)) : '—' ?>
                    <span class="text-muted small ms-1">(solo el ranking de su organización)</span>
                </div>
            <?php elseif ($role === 'admin_general'): ?>
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="genero" value="<?= htmlspecialchars($genero) ?>">
                <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
                <div class="col-12 col-md-7">
                    <label class="form-label small text-muted mb-1">Organización <span class="text-danger">*</span></label>
                    <select name="organizacion_id" class="form-select form-select-sm">
                        <option value="0" <?= $organizacion_id === 0 ? 'selected' : '' ?>>— Seleccione organización —</option>
                        <?php foreach ($organizaciones as $org): ?>
                            <option value="<?= (int)($org['id'] ?? 0) ?>" <?= ((int)($org['id'] ?? 0) === $organizacion_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($org['nombre'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-5 d-grid d-md-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i>Ver ranking
                    </button>
                    <?php if ($organizacion_id > 0): ?>
                        <a href="ranking_atletas.php?genero=<?= htmlspecialchars($genero) ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eraser me-1"></i>Cambiar organización
                        </a>
                    <?php endif; ?>
                </div>
            </form>
            <?php else: ?>
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="genero" value="<?= htmlspecialchars($genero) ?>">
                <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
                <div class="col-12 col-md-7">
                    <label class="form-label small text-muted mb-1">Organización</label>
                    <select name="organizacion_id" class="form-select form-select-sm">
                        <option value="0">Todas las organizaciones</option>
                        <?php foreach ($organizaciones as $org): ?>
                            <option value="<?= (int)($org['id'] ?? 0) ?>" <?= ((int)($org['id'] ?? 0) === $organizacion_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($org['nombre'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-5 d-grid d-md-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i>Filtrar ranking
                    </button>
                    <?php if ($organizacion_id > 0): ?>
                        <a href="ranking_atletas.php?genero=<?= htmlspecialchars($genero) ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eraser me-1"></i>Quitar filtro
                        </a>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
            <?php if ($role === 'admin_general' && $ranking_sin_org_admin_general): ?>
                <div class="alert alert-warning mb-3 py-2">
                    <i class="fas fa-hand-pointer me-2"></i>Como administrador general, elija una organización arriba y pulse <strong>Ver ranking</strong> para cargar los datos.
                </div>
            <?php endif; ?>
            <p class="text-muted small mb-2"><i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($criterio) ?></p>
            <?php if ($atletas !== []): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <span class="small text-muted me-1">Vista del reporte:</span>
                <div class="btn-group btn-group-sm mb-0 flex-wrap" role="group" aria-label="Vista del reporte">
                    <a href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['vista' => 'resumen'])) ?>" class="btn btn-<?= $vista === 'resumen' ? 'primary' : 'outline-primary' ?>">Resumen</a>
                    <a href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['vista' => 'detalle'])) ?>" class="btn btn-<?= $vista === 'detalle' ? 'primary' : 'outline-primary' ?>">Detalle vertical</a>
                    <a href="ranking_atletas.php?<?= htmlspecialchars($ranking_atletas_qs(['vista' => 'matriz'])) ?>" class="btn btn-<?= $vista === 'matriz' ? 'primary' : 'outline-primary' ?>">Matriz por torneo</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($atletas === []): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>No hay datos de ranking para este grupo. Participa en torneos finalizados o verifica que el sexo esté registrado en tu perfil.
                </div>
            <?php elseif ($vista === 'detalle'): ?>
                <?php foreach ($atletas as $a): ?>
                    <?php
                    $rk = (int) $a['rank'];
                    $borderClass = $rk === 1 ? 'border-warning' : ($rk === 2 ? 'border-secondary' : ($rk === 3 ? 'border-danger' : ''));
                    ?>
                    <div class="card-detalle-atleta <?= $borderClass !== '' ? $borderClass : '' ?>" style="<?= $rk <= 3 ? 'border-width:2px;' : '' ?>">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <span class="badge bg-dark me-2">#<?= $rk ?></span>
                                <strong class="fs-6"><?= htmlspecialchars($a['nombre']) ?></strong>
                                <?php if (! empty($a['cedula'])): ?>
                                    <span class="text-muted small ms-1">CI <?= htmlspecialchars((string) $a['cedula']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-end">
                                <span class="text-muted">Torneos:</span> <?= (int) $a['torneos_count'] ?>
                                · <span class="text-muted">Pts ranking Σ:</span> <strong><?= (int) $a['total_ptosrnk'] ?></strong>
                                · <span class="text-muted">Efect. Σ:</span> <?= (int) $a['total_efectividad'] ?>
                                · <span class="text-muted">Ganados Σ:</span> <?= (int) $a['total_ganados'] ?>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 bg-white tabla-rank">
                                <thead class="table-light">
                                    <tr>
                                        <th>Torneo</th>
                                        <th>Fecha</th>
                                        <th>Modalidad</th>
                                        <th class="text-center">Pos.</th>
                                        <th class="text-end">G/P</th>
                                        <th class="text-end">Efect.</th>
                                        <th class="text-end">Pts rnk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($a['detalle_torneos'] as $t): ?>
                                        <tr>
                                            <td>
                                                <a href="evento_resultados.php?torneo_id=<?= (int) $t['torneo_id'] ?>"><?= htmlspecialchars($t['nombre']) ?></a>
                                            </td>
                                            <td><?= fmtfecha($t['fechator'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($modalidades[(int) ($t['modalidad'] ?? 0)] ?? '—') ?></td>
                                            <td class="text-center"><?= (int) ($t['posicion'] ?? 0) ?: '—' ?></td>
                                            <td class="text-end"><?= (int) ($t['ganados'] ?? 0) ?> / <?= (int) ($t['perdidos'] ?? 0) ?></td>
                                            <td class="text-end"><?= (int) ($t['efectividad'] ?? 0) ?></td>
                                            <td class="text-end"><?= (int) ($t['ptosrnk'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php elseif ($vista === 'matriz'): ?>
                <?php if ($torneos_matriz === []): ?>
                    <div class="alert alert-warning mb-0">No hay columnas de torneos para mostrar la matriz.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover tabla-matriz align-middle mb-0 bg-white">
                        <thead class="table-light">
                            <tr>
                                <th class="sticky-top bg-light">#</th>
                                <th class="sticky-top bg-light">Atleta</th>
                                <th class="sticky-top bg-light">CI</th>
                                <?php foreach ($torneos_matriz as $col): ?>
                                    <?php
                                    $nomCol = (string) ($col['nombre'] ?? '');
                                    $nomCorto = function_exists('mb_strlen') && function_exists('mb_substr')
                                        ? ((mb_strlen($nomCol) > 26) ? (mb_substr($nomCol, 0, 24) . '…') : $nomCol)
                                        : ((strlen($nomCol) > 26) ? (substr($nomCol, 0, 24) . '…') : $nomCol);
                                    ?>
                                    <th class="text-center th-torneo" title="<?= htmlspecialchars($nomCol . ' — ' . fmtfecha($col['fechator'] ?? '')) ?>">
                                        <small><?= htmlspecialchars($nomCorto) ?></small>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-end sticky-top bg-light">Pts Σ</th>
                                <th class="text-end sticky-top bg-light">Efect. Σ</th>
                                <th class="text-end sticky-top bg-light">G Σ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($atletas as $a): ?>
                                <?php
                                $rk = (int) $a['rank'];
                                $trClass = $rk === 1 ? 'pos-1' : ($rk === 2 ? 'pos-2' : ($rk === 3 ? 'pos-3' : ''));
                                $porTorneo = [];
                                foreach ($a['detalle_torneos'] as $t) {
                                    $porTorneo[(int) ($t['torneo_id'] ?? 0)] = $t;
                                }
                                ?>
                                <tr class="<?= $trClass ?>">
                                    <td><strong><?= $rk ?></strong></td>
                                    <td><strong><?= htmlspecialchars($a['nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars((string) ($a['cedula'] ?? '')) ?></td>
                                    <?php foreach ($torneos_matriz as $col): ?>
                                        <?php
                                        $tid = (int) $col['torneo_id'];
                                        $celda = $porTorneo[$tid] ?? null;
                                        ?>
                                        <td class="text-center">
                                            <?php if ($celda !== null): ?>
                                                <span title="Pos. <?= (int) ($celda['posicion'] ?? 0) ?> · G/P <?= (int) ($celda['ganados'] ?? 0) ?>/<?= (int) ($celda['perdidos'] ?? 0) ?>">
                                                    <?= (int) ($celda['ptosrnk'] ?? 0) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-end fw-semibold"><?= (int) $a['total_ptosrnk'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_efectividad'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_ganados'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">Cada columna es un torneo; el número es puntos de ranking (ptosrnk) en ese evento. Desplace horizontalmente si hay muchos torneos.</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover tabla-rank align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Atleta</th>
                                <th class="text-center">Torneos</th>
                                <th class="text-end">Pts ranking Σ</th>
                                <th class="text-end">Efectividad Σ</th>
                                <th class="text-end">Ganados Σ</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($atletas as $a): ?>
                                <?php
                                $rk = (int) $a['rank'];
                                $trClass = $rk === 1 ? 'pos-1' : ($rk === 2 ? 'pos-2' : ($rk === 3 ? 'pos-3' : ''));
                                ?>
                                <tr class="<?= $trClass ?>">
                                    <td><strong><?= $rk ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($a['nombre']) ?></strong>
                                        <?php if (! empty($a['cedula'])): ?>
                                            <span class="text-muted small d-block">CI <?= htmlspecialchars((string) $a['cedula']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= (int) $a['torneos_count'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_ptosrnk'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_efectividad'] ?></td>
                                    <td class="text-end"><?= (int) $a['total_ganados'] ?></td>
                                    <td>
                                        <details class="small">
                                            <summary class="text-primary" style="cursor:pointer;">Ver torneos</summary>
                                            <div class="detalle-torneos mt-2 p-2 border rounded">
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-bordered mb-0 bg-white">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Torneo</th>
                                                                <th>Fecha</th>
                                                                <th>Modalidad</th>
                                                                <th class="text-center">Pos.</th>
                                                                <th class="text-end">G/P</th>
                                                                <th class="text-end">Efect.</th>
                                                                <th class="text-end">Pts rnk</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($a['detalle_torneos'] as $t): ?>
                                                                <tr>
                                                                    <td>
                                                                        <a href="evento_resultados.php?torneo_id=<?= (int) $t['torneo_id'] ?>">
                                                                            <?= htmlspecialchars($t['nombre']) ?>
                                                                        </a>
                                                                    </td>
                                                                    <td><?= fmtfecha($t['fechator'] ?? '') ?></td>
                                                                    <td><?= htmlspecialchars($modalidades[(int) ($t['modalidad'] ?? 0)] ?? '—') ?></td>
                                                                    <td class="text-center"><?= (int) ($t['posicion'] ?? 0) ?: '—' ?></td>
                                                                    <td class="text-end"><?= (int) ($t['ganados'] ?? 0) ?> / <?= (int) ($t['perdidos'] ?? 0) ?></td>
                                                                    <td class="text-end"><?= (int) ($t['efectividad'] ?? 0) ?></td>
                                                                    <td class="text-end"><?= (int) ($t['ptosrnk'] ?? 0) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <p class="text-center text-white-50 small mt-3 mb-0">
        Estructura tipo reporte histórico: una fila por torneo en el detalle (similar a un Excel de ranking por categoría).
    </p>
</div>
</body>
</html>
