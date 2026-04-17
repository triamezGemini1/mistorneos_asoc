<?php
/**
 * Admin general — Segmentación permanente: equipos marcados pasan a un nuevo torneo (TorneoSegmentacionService).
 * Reutiliza la misma UI de listado + checkboxes que el antiguo “split virtual”.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/InscritosHelper.php';
require_once __DIR__ . '/../lib/TorneoSegmentacionService.php';

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

    if ($accion === 'segmentar') {
        $rawIds = $_POST['equipos_segmentar'] ?? [];
        if (! is_array($rawIds)) {
            $rawIds = [];
        }
        $idsEquipos = [];
        foreach ($rawIds as $x) {
            $id = (int) $x;
            if ($id > 0) {
                $idsEquipos[] = $id;
            }
        }
        $idsEquipos = array_values(array_unique($idsEquipos));
        $nombreNuevo = trim((string) ($_POST['nombre_nuevo_torneo'] ?? ''));

        $res = TorneoSegmentacionService::segmentarTorneoEquipos($pdo, $torneoId, $idsEquipos, $nombreNuevo);
        if (! empty($res['ok'])) {
            $nid = (int) ($res['id_torneo_nuevo'] ?? 0);
            $em = (int) ($res['equipos_movidos'] ?? 0);
            $im = (int) ($res['inscritos_movidos'] ?? 0);
            $pm = (int) ($res['partiresul_movidos'] ?? 0);
            $_SESSION['success'] = "Segmentación completada. Nuevo torneo id {$nid}. Equipos movidos: {$em}, inscritos: {$im}, filas de resultados: {$pm}. El torneo original se recalculó sin esos equipos.";
        } else {
            $_SESSION['error'] = implode(' ', $res['errores'] ?? ['No se pudo segmentar el torneo.']);
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
$torneoModalidadNoAplica = false;

if ($torneoIdGet > 0) {
    $st = $pdo->prepare('SELECT id, nombre, modalidad FROM tournaments WHERE id = ? LIMIT 1');
    $st->execute([$torneoIdGet]);
    $torneoActual = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($torneoActual && ! in_array((int) ($torneoActual['modalidad'] ?? 0), [2, 3, 4], true)) {
        $torneoModalidadNoAplica = true;
    } elseif ($torneoActual) {
        $og = InscritosHelper::sqlExprColumnaNumerica('ganados');
        $oe = InscritosHelper::sqlExprColumnaNumerica('efectividad');
        $op = InscritosHelper::sqlExprColumnaNumerica('puntos');
        $ope = InscritosHelper::sqlExprColumnaNumerica('perdidos');

        $stmtEq = $pdo->prepare(
            "SELECT id, codigo_equipo, COALESCE(NULLIF(TRIM(nombre_equipo), ''), codigo_equipo) AS etiqueta, posicion, ganados, efectividad, puntos
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
  <h1 class="h4 mb-3"><i class="fas fa-code-branch me-2 text-secondary"></i>Segmentar torneo (separar equipos)</h1>
  <p class="text-muted small mb-4">
    Torneos modalidad parejas, equipos o parejas fijas (2, 3 o 4). Marque los equipos que deben pasar a un <strong>nuevo torneo</strong>
    (registro nuevo en la base de datos). La operación es <strong>definitiva</strong>: inscripciones y resultados de esos equipos se migran al nuevo id;
    el torneo actual queda solo con el resto y puede recalcularse con normalidad. Indique un nombre para el torneo destino antes de confirmar.
  </p>

  <form method="get" action="index.php" class="row g-2 align-items-end mb-4">
    <input type="hidden" name="page" value="torneo_split_ranking">
    <div class="col-md-8">
      <label for="torneo_id_select" class="form-label">Torneo origen</label>
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
        <p class="small text-muted mb-3">
          Modalidad: <?= htmlspecialchars($modalidadTxt((int) ($torneoActual['modalidad'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
          · Marque los equipos a mover (mínimo 1, no todos). Los no marcados permanecen en este torneo.
        </p>

        <form method="post" action="index.php?page=torneo_split_ranking" class="mb-0"
              onsubmit="return confirm('¿Segmentar de forma permanente? Se creará un nuevo torneo y los equipos marcados (con sus inscripciones y resultados) pasarán a ese id. Esta acción no se puede deshacer con un botón.');">
          <?= CSRF::input() ?>
          <input type="hidden" name="torneo_id" value="<?= (int) $tid ?>">

          <div class="mb-3">
            <label for="nombre_nuevo_torneo" class="form-label">Nombre del nuevo torneo (grupo segmentado)</label>
            <input type="text" name="nombre_nuevo_torneo" id="nombre_nuevo_torneo" class="form-control" required
                   maxlength="200" placeholder="Ej.: Copa B — Grupo avanzado"
                   value="<?= htmlspecialchars(trim((string) ($torneoActual['nombre'] ?? '')) . ' — Segmento', ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="table-responsive border rounded">
            <table class="table table-sm table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th scope="col" style="width: 3rem;" title="Mover a nuevo torneo">↗</th>
                  <th scope="col">Equipo</th>
                  <th scope="col" class="text-end">G</th>
                  <th scope="col" class="text-end">Pos. actual</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($equipos as $ix => $eq): ?>
                  <?php
                    $eid = (int) ($eq['id'] ?? 0);
                    $cod = trim((string) ($eq['codigo_equipo'] ?? ''));
                    $idEq = 'seg_eq_' . (int) $ix;
                  ?>
                  <tr>
                    <td>
                      <input class="form-check-input" type="checkbox" name="equipos_segmentar[]" value="<?= $eid ?>" id="<?= htmlspecialchars($idEq, ENT_QUOTES, 'UTF-8') ?>">
                    </td>
                    <td>
                      <label class="form-check-label mb-0" for="<?= htmlspecialchars($idEq, ENT_QUOTES, 'UTF-8') ?>">
                        <strong><?= htmlspecialchars((string) ($eq['etiqueta'] ?? $cod), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="text-muted small"><?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?> · id <?= $eid ?></span>
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
            <button type="submit" name="accion" value="segmentar" class="btn btn-primary">
              <i class="fas fa-code-branch me-1"></i>Crear nuevo torneo y mover equipos marcados
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
