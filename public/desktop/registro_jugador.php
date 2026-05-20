<?php
/**
 * Registro de jugador en base de datos local (SQLite) — app desktop / Offline-First.
 * Usa tablas maestras (entidad, organizaciones, clubes) y session_context.json para valores por defecto.
 * Guarda IDs (club_id, entidad) para consistencia al subir a MySQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/db_local.php';
require_once __DIR__ . '/../../lib/FvdConfig.php';

function uuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Cargar maestros desde SQLite
$entidades = [];
$clubes = [];
$context = [];
$fvd_org_id = class_exists('FvdConfig') ? FvdConfig::organizacionId() : 1;
try {
    $pdo = DB_Local::pdo();
    $entidades = $pdo->query("SELECT codigo, nombre FROM entidad ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $clubes = $pdo->query("SELECT id, nombre, organizacion_id, entidad FROM clubes ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // tablas pueden no existir aún
}
$contextFile = __DIR__ . '/session_context.json';
if (is_readable($contextFile)) {
    $context = json_decode((string)file_get_contents($contextFile), true) ?: [];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
    $nacionalidad = in_array($_POST['nacionalidad'] ?? '', ['V', 'E', 'J', 'P']) ? $_POST['nacionalidad'] : 'V';
    $sexo = in_array($_POST['sexo'] ?? '', ['M', 'F', 'O']) ? $_POST['sexo'] : 'M';
    $fechnac = trim($_POST['fechnac'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '') ?: null;
    $username = trim($_POST['username'] ?? '');
    $club_id = (int)($_POST['club_id'] ?? 0);
    $entidad = (int)($_POST['entidad'] ?? 0);

    if ($nombre === '' || $cedula === '' || $username === '') {
        $error = 'Nombre, cédula y usuario son obligatorios.';
    } elseif ($entidad === 0 && count($entidades) > 0) {
        $error = 'Seleccione una entidad.';
    } elseif ($club_id === 0 && count($clubes) > 0) {
        $error = 'Seleccione un club.';
    } else {
        try {
            $pdo = DB_Local::pdo();
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ? OR username = ?");
            $stmt->execute([$cedula, $username]);
            if ($stmt->fetch()) {
                $error = 'Ya existe un jugador con esa cédula o nombre de usuario.';
            } else {
                $uuid = uuidV4();
                $last_updated = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (uuid, nombre, cedula, nacionalidad, sexo, fechnac, email, username, club_id, entidad, status, role, last_updated, sync_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'usuario', ?, 0)
                ");
                $stmt->execute([$uuid, $nombre, $cedula, $nacionalidad, $sexo, $fechnac, $email, $username, $club_id, $entidad, $last_updated]);
                $success = 'Jugador registrado correctamente en la base local. Se sincronizará cuando haya conexión.';
                $_POST = [];
            }
        } catch (Throwable $e) {
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$default_entidad = (int)($_POST['entidad'] ?? $context['entidad_id'] ?? 0);
$default_club = (int)($_POST['club_id'] ?? $context['club_id'] ?? 0);
$default_org = $fvd_org_id;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de jugador (Desktop)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .desktop-alert-zone { min-height: 3.25rem; margin-bottom: 1rem; }
        .desktop-alert-zone .alert { margin-bottom: 0; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Registro de jugador</h5>
                        <small>Base local (offline)</small>
                    </div>
                    <div class="card-body">
                        <div class="desktop-alert-zone">
                            <?php if ($success): ?>
                                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <?php endif; ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" action="" id="formRegistro">
                            <div class="mb-3">
                                <label class="form-label">Nombre *</label>
                                <input type="text" name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cédula *</label>
                                <input type="text" name="cedula" class="form-control" required placeholder="Solo números" value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nacionalidad</label>
                                    <select name="nacionalidad" class="form-select">
                                        <option value="V" <?= ($_POST['nacionalidad'] ?? '') === 'V' ? 'selected' : '' ?>>V</option>
                                        <option value="E" <?= ($_POST['nacionalidad'] ?? '') === 'E' ? 'selected' : '' ?>>E</option>
                                        <option value="J" <?= ($_POST['nacionalidad'] ?? '') === 'J' ? 'selected' : '' ?>>J</option>
                                        <option value="P" <?= ($_POST['nacionalidad'] ?? '') === 'P' ? 'selected' : '' ?>>P</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Sexo</label>
                                    <select name="sexo" class="form-select">
                                        <option value="M" <?= ($_POST['sexo'] ?? 'M') === 'M' ? 'selected' : '' ?>>M</option>
                                        <option value="F" <?= ($_POST['sexo'] ?? '') === 'F' ? 'selected' : '' ?>>F</option>
                                        <option value="O" <?= ($_POST['sexo'] ?? '') === 'O' ? 'selected' : '' ?>>O</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Fecha de nacimiento</label>
                                <input type="date" name="fechnac" class="form-control" value="<?= htmlspecialchars($_POST['fechnac'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Usuario *</label>
                                <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Entidad *</label>
                                <select name="entidad" id="selEntidad" class="form-select">
                                    <option value="0">-- Seleccione entidad --</option>
                                    <?php foreach ($entidades as $e): ?>
                                        <option value="<?= (int)$e['codigo'] ?>" <?= (int)$e['codigo'] === $default_entidad ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="organizacion_id" value="<?= (int)$fvd_org_id ?>">
                            <div class="mb-3">
                                <label class="form-label">Club *</label>
                                <select name="club_id" id="selClub" class="form-select">
                                    <option value="0">-- Seleccione club --</option>
                                    <?php foreach ($clubes as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" data-organizacion="<?= (int)($c['organizacion_id'] ?? 0) ?>" data-entidad="<?= (int)($c['entidad'] ?? 0) ?>" <?= (int)$c['id'] === $default_club ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <hr>
                            <button type="submit" class="btn btn-primary">Guardar jugador</button>
                            <a href="import_from_web.php" class="btn btn-outline-secondary ms-2">Sincronizar desde web</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var selEntidad = document.getElementById('selEntidad');
        var selClub = document.getElementById('selClub');

        function filterClubesByEntidad() {
            var entidad = parseInt(selEntidad.value, 10) || 0;
            var opts = selClub.querySelectorAll('option');
            opts.forEach(function (opt) {
                if (opt.value === '' || opt.value === '0') {
                    opt.style.display = '';
                    return;
                }
                var e = parseInt(opt.getAttribute('data-entidad'), 10) || 0;
                opt.style.display = (entidad === 0 || e === entidad) ? '' : 'none';
            });
        }
        selEntidad.addEventListener('change', filterClubesByEntidad);
        filterClubesByEntidad();
    })();
    </script>
</body>
</html>
