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
$organizacion_id = isset($_GET['organizacion_id']) ? (int) $_GET['organizacion_id'] : 0;
if ($organizacion_id < 0) {
    $organizacion_id = 0;
}

$organizaciones = [];
try {
    $stmtOrg = $pdo->query("
        SELECT DISTINCT
            COALESCE(o.id, t.organizacion_id, t.club_responsable) AS id,
            COALESCE(NULLIF(TRIM(o.nombre), ''), CONCAT('Organización ', COALESCE(t.organizacion_id, t.club_responsable))) AS nombre
        FROM tournaments t
        LEFT JOIN organizaciones o ON o.id = COALESCE(t.organizacion_id, t.club_responsable)
        WHERE t.estatus = 1
          AND COALESCE(t.ranking, 0) = 1
          AND COALESCE(t.organizacion_id, t.club_responsable, 0) > 0
        ORDER BY nombre ASC
    ");
    $organizaciones = $stmtOrg->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $organizaciones = [];
}

$svc = new RankingAtletasPublicoService($pdo);
$data = $svc->construirRanking($genero, $organizacion_id);
$atletas = $data['atletas'];
$criterio = $data['criterio_orden'];

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
    <link rel="canonical" href="<?= htmlspecialchars($base_public . 'ranking_atletas.php?genero=' . $genero) ?>">
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
    </style>
</head>
<body>
<div class="container">
    <div class="card-rank mx-auto">
        <div class="header-rank">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <?= AppHelpers::appLogo('mb-2', 'La Estación del Dominó', 40) ?>
                    <h1 class="h3 mb-1"><i class="fas fa-medal text-warning me-2"></i>Ranking de atletas</h1>
                    <p class="mb-0 opacity-90 small"><?= htmlspecialchars($titulo_genero) ?> · Solo torneos con ranking activado</p>
                </div>
                <div>
                    <a href="landing-spa.php" class="btn btn-sm btn-volver me-1"><i class="fas fa-home me-1"></i>Inicio</a>
                    <a href="resultados.php" class="btn btn-sm btn-volver"><i class="fas fa-trophy me-1"></i>Resultados por evento</a>
                </div>
            </div>
            <ul class="nav nav-pills nav-genero gap-2 mt-3">
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?= $genero === 'F' ? 'active' : '' ?>" href="ranking_atletas.php?genero=F<?= $organizacion_id > 0 ? '&organizacion_id=' . (int)$organizacion_id : '' ?>"><i class="fas fa-venus me-1"></i>Femenino</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?= $genero === 'M' ? 'active' : '' ?>" href="ranking_atletas.php?genero=M<?= $organizacion_id > 0 ? '&organizacion_id=' . (int)$organizacion_id : '' ?>"><i class="fas fa-mars me-1"></i>Masculino</a>
                </li>
            </ul>
        </div>
        <div class="p-3 p-md-4">
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="genero" value="<?= htmlspecialchars($genero) ?>">
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
            <p class="text-muted small mb-3"><i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($criterio) ?></p>
            <?php if ($atletas === []): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>No hay datos de ranking para este grupo. Participa en torneos finalizados o verifica que el sexo esté registrado en tu perfil.
                </div>
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
