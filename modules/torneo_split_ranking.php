<?php
/**
 * Admin general — Clasificación en dos bloques (mismo torneo): grupo A marcado, grupo B resto.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/TorneoSplitRankingService.php';

Auth::requireRole(['admin_general']);

$pdo = DB::pdo();
$baseUrl = 'index.php?page=torneo_split_ranking';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf === '' || ! hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $_SESSION['error'] = 'Token de seguridad inválido. Recargue la página e intente de nuevo.';
        $tid = (int) ($_POST['torneo_id'] ?? 0);
        header('Location: ' . $baseUrl . ($tid > 0 ? '&torneo_id=' . $tid : ''));
        exit;
    }

    $torneoId = (int) ($_POST['torneo_id'] ?? 0);
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'restaurar') {
        $res = TorneoSplitRankingService::restaurarClasificacionUnica($pdo, $torneoId);
        if (! empty($res['ok'])) {
            $_SESSION['success'] = 'Clasificación única restaurada para el torneo seleccionado.';
        } else {
            $_SESSION['error'] = implode(' ', $res['errores'] ?? ['No se pudo restaurar.']);
        }
        header('Location: ' . $baseUrl . ($torneoId > 0 ? '&torneo_id=' . $torneoId : ''));
        exit;
    }

    if ($accion === 'aplicar') {
        $grupoA = $_POST['grupo_a'] ?? [];
        if (! is_array($grupoA)) {
            $grupoA = [];
        }
        $codigos = [];
        foreach ($grupoA as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $codigos[] = $c;
            }
        }
        $res = TorneoSplitRankingService::aplicarClasificacionDosBloques($pdo, $torneoId, $codigos);
        if (! empty($res['ok'])) {
            $na = (int) ($res['equipos_grupo_a'] ?? 0);
            $nb = (int) ($res['equipos_grupo_b'] ?? 0);
            $_SESSION['success'] = "Clasificación en dos bloques aplicada: bloque A {$na} equipo(s), bloque B {$nb} equipo(s). Posiciones y puntos de ranking recalculados.";
        } else {
            $_SESSION['error'] = implode(' ', $res['errores'] ?? ['No se pudo aplicar el split.']);
        }
        header('Location: ' . $baseUrl . ($torneoId > 0 ? '&torneo_id=' . $torneoId : ''));
        exit;
    }

    header('Location: ' . $baseUrl);
    exit;
}

$torneoIdGet = (int) ($_GET['torneo_id'] ?? 0);

$stmtTorneos = $pdo->query(
    'SELECT id, nombre, modalidad FROM tournaments WHERE modalidad IN (2, 3, 4) ORDER BY fechator DESC, id DESC'
);
$torneos = $stmtTorneos ? $stmtTorneos->fetchAll(PDO::FETCH_ASSOC) : [];

$torneoActual = null;
$equipos = [];
$codigosGuardados = [];
$setGuardados = [];
$torneoModalidadNoAplica = false;

if ($torneoIdGet > 0) {
    $st = $pdo->prepare('SELECT id, nombre, modalidad FROM tournaments WHERE id = ? LIMIT 1');
    $st->execute([$torneoIdGet]);
    $torneoActual = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($torneoActual && ! in_array((int) ($torneoActual['modalidad'] ?? 0), [2, 3, 4], true)) {
        $torneoModalidadNoAplica = true;
    } elseif ($torneoActual) {
        TorneoSplitRankingService::ensureTabla($pdo);
        $codigosGuardados = TorneoSplitRankingService::obtenerCodigosGrupoA($pdo, $torneoIdGet);
        $setGuardados = array_flip($codigosGuardados);

        $og = InscritosHelper::sqlExprColumnaNumerica('ganados');
        $oe = InscritosHelper::sqlExprColumnaNumerica('efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('puntos');
        $ope = InscritosHelper::sqlExprColumnaNumerica('perdidos');

        $stmtEq = $pdo->prepare(
            "SELECT codigo_equipo, COALESCE(NULLIF(TRIM(nombre_equipo), ''), codigo_equipo) AS etiqueta, posicion, ganados, efectividad, puntos
             FROM equipos
             WHERE id_torneo = ? AND estatus = 0 AND codigo_equipo IS NOT NULL AND codigo_equipo != ''
             ORDER BY $og DESC, $oe DESC, $op DESC, $ope ASC, codigo_equipo ASC"
        );
        $stmtEq->execute([$torneoIdGet]);
        $equipos = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/../config/csrf.php';
$modalidadTxt = static function (int $m): string {
    if ($m === 2) {
        return 'Parejas';
    }
    if ($m === 3) {
        return 'Equipos';
    }
    if ($m === 4) {
        return 'Parejas fijas';
    }

    return (string) $m;
};

?>
<div class="container-fluid py-2" style="max-width: 960px;">
  <h1 class="h4 mb-3"><i class="fas fa-columns me-2 text-secondary"></i>Clasificación en dos bloques</h1>
  <p class="text-muted small mb-4">
    Solo torneos modalidad parejas, equipos o parejas fijas (2, 3 o 4). Marque los equipos del <strong>bloque A</strong> (grupo separado);
    el resto forma el <strong>bloque B</strong>. Se reasignan posiciones de equipo y atletas y se recalcula el ranking en cada bloque por rendimiento.
    Use <strong>Restaurar clasificación única</strong> para volver al criterio global del torneo.
  </p>

  <form method="get" action="index.php" class="row g-2 align-items-end mb-4">
    <input type="hidden" name="page" value="torneo_split_ranking">
    <div class="col-md-8">
      <label for="torneo_id_select" class="form-label">Torneo</label>
      <select name="torneo_id" id="torneo_id_select" class="form-select" onchange="this.form.submit()">
        <option value="0">— Seleccione un torneo —</option>
        <?php foreach ($torneos as $t): ?>
          <?php
            $tid = (int) ($t['id'] ?? 0);
            $mn = (int) ($t['modalidad'] ?? 0);
          ?>
          <option value="<?= $tid ?>" <?= $torneoIdGet === $tid ? ' selected' : '' ?>>
            <?= htmlspecialchars((string) ($t['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars($modalidadTxt($mn), ENT_QUOTES, 'UTF-8') ?> · id <?= $tid ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($torneoIdGet > 0 && $torneoActual === null): ?>
    <div class="alert alert-warning">No se encontró el torneo.</div>
  <?php elseif ($torneoIdGet > 0 && $torneoModalidadNoAplica): ?>
    <div class="alert alert-warning">Este torneo no es modalidad parejas, equipos o parejas fijas (2, 3 o 4). La herramienta no aplica.</div>
  <?php elseif ($torneoIdGet > 0 && $torneoActual !== null && $equipos === []): ?>
    <div class="alert alert-info">No hay equipos activos en este torneo.</div>
  <?php elseif ($torneoIdGet > 0 && $torneoActual !== null): ?>
    <?php
      $tid = $torneoIdGet;
      $nombreTorneo = htmlspecialchars((string) ($torneoActual['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h6 card-title"><?= $nombreTorneo ?></h2>
        <p class="small text-muted mb-3">Modalidad: <?= htmlspecialchars($modalidadTxt((int) ($torneoActual['modalidad'] ?? 0)), ENT_QUOTES, 'UTF-8') ?> · Marque el bloque A (mínimo 1 equipo, no todos).</p>

        <form method="post" action="index.php?page=torneo_split_ranking" class="mb-0">
          <?= CSRF::input() ?>
          <input type="hidden" name="torneo_id" value="<?= (int) $tid ?>">

          <div class="table-responsive border rounded">
            <table class="table table-sm table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th scope="col" style="width: 3rem;">A</th>
                  <th scope="col">Equipo</th>
                  <th scope="col" class="text-end">G</th>
                  <th scope="col" class="text-end">Pos. actual</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($equipos as $ix => $eq): ?>
                  <?php
                    $cod = trim((string) ($eq['codigo_equipo'] ?? ''));
                    $checked = isset($setGuardados[$cod]) ? ' checked' : '';
                    $idEq = 'split_eq_' . (int) $ix;
                  ?>
                  <tr>
                    <td>
                      <input class="form-check-input" type="checkbox" name="grupo_a[]" value="<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>" id="<?= htmlspecialchars($idEq, ENT_QUOTES, 'UTF-8') ?>"<?= $checked ?>>
                    </td>
                    <td>
                      <label class="form-check-label mb-0" for="<?= htmlspecialchars($idEq, ENT_QUOTES, 'UTF-8') ?>">
                        <strong><?= htmlspecialchars((string) ($eq['etiqueta'] ?? $cod), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="text-muted small"><?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?></span>
                      </label>
                    </td>
                    <td class="text-end"><?= (int) ($eq['ganados'] ?? 0) ?></td>
                    <td class="text-end"><?= (int) ($eq['posicion'] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" name="accion" value="aplicar" class="btn btn-primary">
              <i class="fas fa-check me-1"></i>Aplicar división y recalcular
            </button>
            <button type="submit" name="accion" value="restaurar" class="btn btn-outline-secondary"
                    onclick="return confirm('¿Restaurar la clasificación única global de este torneo? Se borrará la selección de bloque A.');">
              <i class="fas fa-undo me-1"></i>Restaurar clasificación única
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
