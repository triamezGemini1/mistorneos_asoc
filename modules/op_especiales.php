<?php

/**
 * Op Especiales — carga especial / simulación (solo tournaments.estatus = 9).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/Tournament/OpEspecialesHelper.php';
require_once __DIR__ . '/../lib/Tournament/Handlers/RoundManagerHandler.php';

use Tournament\OpEspecialesHelper;
use Tournament\Handlers\RoundManagerHandler;

Auth::requireRole(['admin_general', 'admin_torneo', 'admin_club']);

$torneo_id = (int) ($_POST['torneo_id'] ?? $_GET['torneo_id'] ?? 0);
$view = trim((string) ($_GET['view'] ?? 'carga'));
if (! in_array($view, ['carga', 'swap', 'auditoria'], true)) {
    $view = 'carga';
}

if ($torneo_id <= 0) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('Indique un torneo para Operaciones Especiales'));
    exit;
}

if (! Auth::canAccessTournament($torneo_id)) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode('Sin permisos para este torneo'));
    exit;
}

try {
    $torneo = OpEspecialesHelper::obtenerTorneoObligatorio($torneo_id);
} catch (Throwable $e) {
    header('Location: index.php?page=torneo_gestion&action=index&error=' . urlencode($e->getMessage()));
    exit;
}

if (! OpEspecialesHelper::esCargaEspecial($torneo)) {
    header('Location: index.php?page=torneo_gestion&action=panel&torneo_id=' . $torneo_id . '&error=' . urlencode('Operaciones Especiales solo está disponible con estatus de torneo «Carga especial / simulación» (9).'));
    exit;
}

$pdo = DB::pdo();
$uid = (int) Auth::id();
$modalidad = (int) ($torneo['modalidad'] ?? 0);

/**
 * @return list<int>
 */
function op_especiales_parse_ids_from_post(string $key): array
{
    $raw = (string) ($_POST[$key] ?? '');
    $raw = str_replace([',', ';'], "\n", $raw);
    $out = [];
    foreach (preg_split('/\s+/', trim($raw)) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) {
            $out[] = (int) $p;
        }
    }

    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    CSRF::validate();
    $action = trim((string) ($_POST['op_action'] ?? ''));

    try {
        if ($action === 'aplicar_ff') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $ids = op_especiales_parse_ids_from_post('ids_partiresul_text');
            $pen = (int) ($_POST['penalizacion'] ?? 0);
            $n = OpEspecialesHelper::aplicarForfaitFilas($torneo_id, $ronda, $ids, min(80, max(0, $pen)), $uid);
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = $n > 0 ? "Forfait aplicado en {$n} mesa(s)." : 'No se aplicó ningún cambio (revise selección y mesas completas de 4).';
        } elseif ($action === 'aplicar_tarjetas') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $ids = op_especiales_parse_ids_from_post('ids_tarjeta_text');
            $tarjeta = (int) ($_POST['tipo_tarjeta'] ?? 1);
            $sanc = (int) ($_POST['sancion_pts'] ?? 0);
            $n = OpEspecialesHelper::aplicarTarjetasFilas($torneo_id, $ronda, $ids, $tarjeta, min(80, max(0, $sanc)), $uid);
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = $n > 0 ? "Sanciones/tarjetas aplicadas en {$n} mesa(s)." : 'No se aplicó ningún cambio.';
        } elseif ($action === 'carga_masiva') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $n = OpEspecialesHelper::cargaMasivaResultadosBase($torneo_id, $ronda, $uid);
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = "Carga masiva completada en {$n} mesa(s).";
        } elseif ($action === 'generar_siguiente') {
            RoundManagerHandler::ejecutarGeneracionRonda($torneo_id, [
                'redirect_base' => 'op_especiales',
                'estrategia_ronda2' => trim((string) ($_POST['estrategia_ronda2'] ?? 'separar')),
                'estrategia_asignacion' => trim((string) ($_POST['estrategia_asignacion'] ?? 'secuencial')),
            ]);
            exit;
        } elseif ($action === 'swap') {
            $ronda = (int) ($_POST['ronda'] ?? 0);
            $idA = (int) ($_POST['id_partiresul_a'] ?? 0);
            $idB = (int) ($_POST['id_partiresul_b'] ?? 0);
            OpEspecialesHelper::swapAtletasPorIdsPartiresul($torneo_id, $ronda, $idA, $idB, $modalidad);
            $_SESSION['success'] = 'Intercambio aplicado.';
        } elseif ($action === 'reemplazo_usuario') {
            $idV = (int) ($_POST['id_usuario_viejo'] ?? 0);
            $idN = (int) ($_POST['id_usuario_nuevo'] ?? 0);
            $alc = trim((string) ($_POST['alcance_rondas'] ?? 'todas'));
            $rU = (int) ($_POST['ronda_unica'] ?? 0);
            $rD = (int) ($_POST['ronda_desde'] ?? 0);
            $rH = (int) ($_POST['ronda_hasta'] ?? 0);
            $n = OpEspecialesHelper::reemplazarIdUsuarioPartiresul(
                $torneo_id,
                $idV,
                $idN,
                $alc,
                $rU > 0 ? $rU : null,
                $rD > 0 ? $rD : null,
                $rH > 0 ? $rH : null,
                $modalidad,
                $uid
            );
            OpEspecialesHelper::sincronizarEstadisticasInscritos($torneo_id);
            $_SESSION['success'] = $n > 0
                ? "Reemplazo de id_usuario aplicado en {$n} fila(s) de partiresul."
                : 'No se actualizó ninguna fila.';
        } else {
            $_SESSION['error'] = 'Acción no reconocida.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: index.php?page=op_especiales&torneo_id=' . $torneo_id . '&view=' . urlencode($view));
    exit;
}

$stR = $pdo->prepare('SELECT COALESCE(MAX(partida), 0) FROM partiresul WHERE id_torneo = ?');
$stR->execute([$torneo_id]);
$ultima_ronda = (int) $stR->fetchColumn();
$rondas_opts = range(1, max(1, (int) ($torneo['rondas'] ?? 9)));

$audit = OpEspecialesHelper::reporteAuditoria($torneo_id);

$ronda_lista = (int) ($_GET['ronda'] ?? ($ultima_ronda > 0 ? $ultima_ronda : 1));
$stFilas = $pdo->prepare(
    'SELECT id, partida, mesa, secuencia, id_usuario, resultado1, resultado2, ff, tarjeta, sancion,
            registrado
     FROM partiresul WHERE id_torneo = ? AND partida = ? AND mesa > 0
     ORDER BY mesa ASC, secuencia ASC'
);
$stFilas->execute([$torneo_id, $ronda_lista]);
$filas_ronda = $stFilas->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Op Especiales — ' . htmlspecialchars((string) ($torneo['nombre'] ?? 'Torneo'));
?>
<div class="container-fluid">
  <h1 class="h3 mb-3"><i class="fas fa-flask text-warning me-2"></i>Operaciones Especiales</h1>
  <p class="text-muted">Torneo <strong><?= htmlspecialchars((string) ($torneo['nombre'] ?? '')) ?></strong>
    <span class="badge bg-secondary">estatus 9 · simulación</span>
  </p>

  <?php if (! empty($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars((string) $_SESSION['success']) ?><?php unset($_SESSION['success']); ?></div>
  <?php endif; ?>
  <?php if (! empty($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars((string) $_SESSION['error']) ?><?php unset($_SESSION['error']); ?></div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $view === 'carga' ? 'active' : '' ?>" href="index.php?page=op_especiales&torneo_id=<?= (int) $torneo_id ?>&view=carga">Carga por ronda</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'swap' ? 'active' : '' ?>" href="index.php?page=op_especiales&torneo_id=<?= (int) $torneo_id ?>&view=swap">Swap / reemplazo ID</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $view === 'auditoria' ? 'active' : '' ?>" href="index.php?page=op_especiales&torneo_id=<?= (int) $torneo_id ?>&view=auditoria">Auditoría</a>
    </li>
  </ul>

  <?php if ($view === 'carga'): ?>
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">Forfait (FF) y penalización</div>
          <div class="card-body">
            <form method="post" class="needs-validation">
              <?= CSRF::input() ?>
              <input type="hidden" name="op_action" value="aplicar_ff">
              <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
              <div class="mb-2">
                <label class="form-label">Ronda</label>
                <select name="ronda" class="form-select" required>
                  <?php foreach ($rondas_opts as $r): ?>
                    <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Puntos de penalización (sanción, máx. 80)</label>
                <input type="number" name="penalizacion" class="form-control" min="0" max="80" value="0">
              </div>
              <div class="mb-2">
                <label class="form-label">Filas partiresul (IDs) con FF — una por línea o separadas por coma</label>
                <textarea name="ids_partiresul_text" class="form-control" rows="3" placeholder="ej: 101,102"></textarea>
              </div>
              <p class="small text-muted">Se agrupan por mesa y se aplica el núcleo de registro de resultados (incluye efectividad por FF).</p>
              <button type="submit" class="btn btn-warning">Aplicar FF</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">Tarjetas administrativas</div>
          <div class="card-body">
            <form method="post">
              <?= CSRF::input() ?>
              <input type="hidden" name="op_action" value="aplicar_tarjetas">
              <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
              <div class="mb-2">
                <label class="form-label">Ronda</label>
                <select name="ronda" class="form-select" required>
                  <?php foreach ($rondas_opts as $r): ?>
                    <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Tipo</label>
                <select name="tipo_tarjeta" class="form-select">
                  <option value="1">Amarilla (1)</option>
                  <option value="3">Roja (3)</option>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Puntos de sanción administrativa (máx. 80)</label>
                <input type="number" name="sancion_pts" class="form-control" min="0" max="80" value="0">
              </div>
              <div class="mb-2">
                <label class="form-label">IDs partiresul (una por línea o comas)</label>
                <textarea name="ids_tarjeta_text" class="form-control" rows="3"></textarea>
              </div>
              <button type="submit" class="btn btn-outline-danger">Aplicar tarjeta/sanción</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header">Carga masiva de resultados base</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Se rellenarán todas las mesas completas (4 jugadores) de la ronda con un marcador simulado AC vs BD. ¿Continuar?');">
          <?= CSRF::input() ?>
          <input type="hidden" name="op_action" value="carga_masiva">
          <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
          <div class="col-auto">
            <label class="form-label">Ronda</label>
            <select name="ronda" class="form-select">
              <?php foreach ($rondas_opts as $r): ?>
                <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary">Llenar resultados base</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header">Cerrar ronda y generar emparejamientos (Power Pairing / asignación por modalidad)</div>
      <div class="card-body">
        <form method="post" onsubmit="return confirm('Se generará la siguiente ronda si todas las mesas de la última ronda están registradas. ¿Continuar?');">
          <?= CSRF::input() ?>
          <input type="hidden" name="op_action" value="generar_siguiente">
          <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
          <?php if ($modalidad === 3): ?>
            <div class="mb-2">
              <label class="form-label">Estrategia equipos</label>
              <select name="estrategia_asignacion" class="form-select">
                <option value="secuencial">Secuencial</option>
                <option value="intercalada_13_24">Intercalada 13–24</option>
                <option value="intercalada_14_23">Intercalada 14–23</option>
                <option value="por_rendimiento">Por rendimiento</option>
              </select>
            </div>
          <?php else: ?>
            <div class="mb-2">
              <label class="form-label">Estrategia ronda 2+ (p/parejas)</label>
              <select name="estrategia_ronda2" class="form-select">
                <option value="separar">Separar líderes</option>
              </select>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-success">Generar siguiente ronda</button>
        </form>
        <p class="small text-muted mt-2">Última ronda con datos: <strong><?= (int) $ultima_ronda ?></strong>. Usa la misma lógica que el panel del torneo.</p>
      </div>
    </div>
  <?php elseif ($view === 'swap'): ?>
    <div class="card mb-4">
      <div class="card-header">Intercambiar dos jugadores entre dos mesas (por ID de fila en partiresul)</div>
      <div class="card-body">
        <p class="small text-muted">Usa los IDs numéricos de la tabla <code>partiresul</code> (columna <code>id</code>), no códigos de equipo ni de inscripción.</p>
        <form method="post" class="row g-3">
          <?= CSRF::input() ?>
          <input type="hidden" name="op_action" value="swap">
          <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
          <div class="col-md-3">
            <label class="form-label">Ronda</label>
            <select name="ronda" class="form-select">
              <?php foreach ($rondas_opts as $r): ?>
                <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">ID fila partiresul (jugador A)</label>
            <input type="number" name="id_partiresul_a" class="form-control" required min="1">
          </div>
          <div class="col-md-4">
            <label class="form-label">ID fila partiresul (jugador B)</label>
            <input type="number" name="id_partiresul_b" class="form-control" required min="1">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary">Intercambiar</button>
            <?php if ($modalidad === 3): ?>
              <span class="text-muted small ms-2">Modalidad equipos: no se permitirá duplicar id_equipo en la misma mesa.</span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Reemplazar <code>id_usuario</code> en partiresul</div>
      <div class="card-body">
        <p class="small text-muted mb-3">
          Sustituye al jugador en las filas de resultados por otro usuario. Si el usuario nuevo no está inscrito en el torneo, se crea la inscripción confirmada (como en inscripción en sitio);
          en modalidad <strong>equipos</strong> se copian <code>codigo_equipo</code> y club del jugador sustituido cuando existan.
        </p>
        <form method="post" class="row g-3" id="form-reemplazo-usuario">
          <?= CSRF::input() ?>
          <input type="hidden" name="op_action" value="reemplazo_usuario">
          <input type="hidden" name="torneo_id" value="<?= (int) $torneo_id ?>">
          <div class="col-md-4">
            <label class="form-label">ID usuario sustituido (sale de partiresul)</label>
            <input type="number" name="id_usuario_viejo" class="form-control" required min="1" value="">
          </div>
          <div class="col-md-4">
            <label class="form-label">ID usuario sustituto (entra en partiresul)</label>
            <input type="number" name="id_usuario_nuevo" class="form-control" required min="1" value="">
          </div>
          <div class="col-12">
            <label class="form-label d-block">Alcance de rondas</label>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="alcance_rondas" id="alc_todas" value="todas" checked>
              <label class="form-check-label" for="alc_todas">Todas las rondas donde aparezca el usuario sustituido</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="alcance_rondas" id="alc_una" value="una_ronda">
              <label class="form-check-label" for="alc_una">Una ronda específica</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="alcance_rondas" id="alc_rango" value="rango">
              <label class="form-check-label" for="alc_rango">Rango de rondas (inclusive)</label>
            </div>
          </div>
          <div class="col-md-3" id="wrap-ronda-unica" style="display:none;">
            <label class="form-label">Ronda</label>
            <select name="ronda_unica" class="form-select">
              <?php foreach ($rondas_opts as $r): ?>
                <option value="<?= (int) $r ?>" <?= $r === $ronda_lista ? 'selected' : '' ?>><?= (int) $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3" id="wrap-rango-desde" style="display:none;">
            <label class="form-label">Desde ronda</label>
            <select name="ronda_desde" class="form-select">
              <?php foreach ($rondas_opts as $r): ?>
                <option value="<?= (int) $r ?>"><?= (int) $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3" id="wrap-rango-hasta" style="display:none;">
            <label class="form-label">Hasta ronda</label>
            <select name="ronda_hasta" class="form-select">
              <?php foreach ($rondas_opts as $r): ?>
                <option value="<?= (int) $r ?>"><?= (int) $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-success" onclick="return confirm('¿Confirmar reemplazo de id_usuario en partiresul según el alcance elegido?');">Aplicar reemplazo</button>
          </div>
        </form>
        <script>
        (function () {
          var f = document.getElementById('form-reemplazo-usuario');
          if (!f) return;
          var radios = f.querySelectorAll('input[name="alcance_rondas"]');
          var w1 = document.getElementById('wrap-ronda-unica');
          var wd = document.getElementById('wrap-rango-desde');
          var wh = document.getElementById('wrap-rango-hasta');
          function sync() {
            var v = f.querySelector('input[name="alcance_rondas"]:checked');
            v = v ? v.value : 'todas';
            w1.style.display = v === 'una_ronda' ? '' : 'none';
            wd.style.display = wh.style.display = v === 'rango' ? '' : 'none';
          }
          radios.forEach(function (r) { r.addEventListener('change', sync); });
          sync();
        })();
        </script>
      </div>
    </div>
  <?php else: ?>
    <div class="card mb-3">
      <div class="card-header">Integridad</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tipo</th><th>Ronda</th><th>Mesa</th><th>Detalle</th></tr></thead>
          <tbody>
          <?php foreach ($audit['integridad'] as $row): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($row['tipo'] ?? '')) ?></td>
              <td><?= (int) ($row['partida'] ?? 0) ?></td>
              <td><?= (int) ($row['mesa'] ?? 0) ?></td>
              <td><code><?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE)) ?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($audit['integridad'] === []): ?>
            <tr><td colspan="4" class="text-muted">Sin incidencias de integridad.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Anomalía GDU (ganador con PF ≤ mejor perdedor)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Ronda</th><th>Mesa</th><th>Usuario</th><th>PF</th><th>Max PF perdedores</th></tr></thead>
          <tbody>
          <?php foreach ($audit['gdu'] as $row): ?>
            <tr>
              <td><?= (int) ($row['partida'] ?? 0) ?></td>
              <td><?= (int) ($row['mesa'] ?? 0) ?></td>
              <td><?= (int) ($row['id_usuario'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string) ($row['pf'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($row['max_pf_perdedor_mesa'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($audit['gdu'] === []): ?>
            <tr><td colspan="5" class="text-muted">Sin casos GDU detectados.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Coherencia FF (forfait con marcador de ganador)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>ID</th><th>Ronda</th><th>Mesa</th><th>Usuario</th></tr></thead>
          <tbody>
          <?php foreach ($audit['ff_incoherente'] as $row): ?>
            <tr>
              <td><?= (int) ($row['id'] ?? 0) ?></td>
              <td><?= (int) ($row['partida'] ?? 0) ?></td>
              <td><?= (int) ($row['mesa'] ?? 0) ?></td>
              <td><?= (int) ($row['id_usuario'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($audit['ff_incoherente'] === []): ?>
            <tr><td colspan="4" class="text-muted">Sin incoherencias FF.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($view === 'carga'): ?>
    <div class="card mt-4">
      <div class="card-header">Filas de la ronda <?= (int) $ronda_lista ?> (referencia de IDs)</div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>ID</th><th>Mesa</th><th>Seq</th><th>id_usuario</th><th>R1</th><th>R2</th><th>FF</th><th>Tj</th><th>San</th><th>Reg</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filas_ronda as $f): ?>
              <tr>
                <td><?= (int) $f['id'] ?></td>
                <td><?= (int) $f['mesa'] ?></td>
                <td><?= (int) $f['secuencia'] ?></td>
                <td><?= (int) $f['id_usuario'] ?></td>
                <td><?= htmlspecialchars((string) $f['resultado1']) ?></td>
                <td><?= htmlspecialchars((string) $f['resultado2']) ?></td>
                <td><?= htmlspecialchars((string) $f['ff']) ?></td>
                <td><?= htmlspecialchars((string) $f['tarjeta']) ?></td>
                <td><?= htmlspecialchars((string) $f['sancion']) ?></td>
                <td><?= htmlspecialchars((string) $f['registrado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small">
        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=op_especiales&torneo_id=<?= (int) $torneo_id ?>&view=carga&ronda=<?= (int) $ronda_lista ?>">Refrescar listado</a>
      </div>
    </div>
  <?php endif; ?>

  <p class="mt-4">
    <a href="index.php?page=torneo_gestion&action=panel&torneo_id=<?= (int) $torneo_id ?>" class="btn btn-outline-secondary">Volver al panel del torneo</a>
  </p>
</div>
