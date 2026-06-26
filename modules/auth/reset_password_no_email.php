<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/app_helpers.php';

$cedula = trim($_POST['cedula'] ?? '');
$contacto = trim($_POST['contacto'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($cedula === '' || $contacto === '') {
            throw new Exception('Ingresa tu cédula y tu teléfono o correo.');
        }
        if (strlen($new_password) < 8) {
            throw new Exception('La contraseña debe tener al menos 8 caracteres.');
        }
        if ($new_password !== $confirm_password) {
            throw new Exception('Las contraseñas no coinciden.');
        }

        $pdo = DB::pdo();

        // Detectar columnas disponibles para contacto
        $cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
        $hasEmail = false; $hasCel = false; $hasTel = false;
        foreach ($cols as $c) {
            $f = strtolower($c['Field'] ?? $c['field'] ?? '');
            if ($f === 'email') $hasEmail = true;
            if ($f === 'celular') $hasCel = true;
            if ($f === 'telefono') $hasTel = true;
        }

        $contactConds = [];
        $params = [$cedula];
        if ($hasEmail) { $contactConds[] = "email = ?"; $params[] = $contacto; }
        if ($hasCel)   { $contactConds[] = "celular = ?"; $params[] = $contacto; }
        if ($hasTel)   { $contactConds[] = "telefono = ?"; $params[] = $contacto; }

        if (empty($contactConds)) {
            throw new Exception('No hay columnas de contacto configuradas (email/celular/telefono). Contacta al administrador.');
        }

        $sql = "SELECT id FROM usuarios WHERE cedula = ? AND (" . implode(' OR ', $contactConds) . ") LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('No se encontró coincidencia. Verifica los datos o contacta al administrador.');
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE usuarios SET password_hash = ?, recovery_token = NULL WHERE id = ?");
        $upd->execute([$hash, (int)$user['id']]);

        // Redirigir al login con indicador de éxito
        $redirectUrl = AppHelpers::url('login.php?reset=1');
        header("Location: $redirectUrl");
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log("reset_password_no_email error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#1a365d">
  <title><?= htmlspecialchars(class_exists('Branding', false) ? Branding::pageTitle('Restablecer contraseña') : 'Restablecer contraseña - La Estación del Dominó') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      padding: 1rem;
    }
    .auth-card {
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
      overflow: hidden;
    }
    .auth-header {
      background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
      color: white;
      padding: 2rem;
      text-align: center;
    }
    .auth-header i {
      font-size: 3rem;
      margin-bottom: 1rem;
      color: #48bb78;
    }
    .card-body {
      padding: 2rem;
    }
    @media (max-width: 576px) {
      .card-body { padding: 1.5rem; }
      .auth-header { padding: 1.5rem; }
      .auth-header i { font-size: 2.5rem; }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-5 col-lg-4">
        <div class="card auth-card border-0">
          <div class="auth-header">
            <?php 
            $logo_url = AppHelpers::getAppLogo();
            ?>
            <?php $auth_brand = class_exists('Branding', false) ? Branding::siteName() : 'La Estación del Dominó'; ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($auth_brand) ?>" style="height: 60px; margin-bottom: 1rem;">
            <h4 class="mb-1"><?= htmlspecialchars($auth_brand) ?></h4>
            <p class="mb-0 opacity-75">Restablecer contraseña (sin correo)</p>
          </div>
          <div class="card-body">
            <?php if ($error): ?>
              <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
              </div>
            <?php endif; ?>
            <?php if ($success): ?>
              <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
              </div>
            <?php endif; ?>

            <p class="text-muted mb-4">Ingresa tu cédula, tu teléfono o correo registrado y define una nueva contraseña.</p>
            <form method="post">
              <div class="mb-3">
                <label class="form-label fw-semibold">Cédula</label>
                <input type="text" name="cedula" class="form-control form-control-lg" required value="<?= htmlspecialchars($cedula) ?>">
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Teléfono o correo registrado</label>
                <input type="text" name="contacto" class="form-control form-control-lg" required value="<?= htmlspecialchars($contacto) ?>">
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Nueva contraseña</label>
                <input type="password" name="new_password" class="form-control form-control-lg" required minlength="8">
              </div>
              <div class="mb-4">
                <label class="form-label fw-semibold">Confirmar contraseña</label>
                <input type="password" name="confirm_password" class="form-control form-control-lg" required minlength="8">
              </div>
              <button type="submit" class="btn btn-primary btn-lg w-100">
                <i class="fas fa-key me-1"></i>Actualizar contraseña
              </button>
            </form>
            <div class="d-flex justify-content-between align-items-center mt-3">
              <a class="small text-decoration-none" href="<?= htmlspecialchars((function_exists('AppHelpers') ? AppHelpers::url('login.php') : '/public/login.php')) ?>">
                <i class="fas fa-arrow-left me-1"></i>Volver al login
              </a>
              <a class="small text-decoration-none" href="<?= htmlspecialchars((function_exists('AppHelpers') ? AppHelpers::url('recover_user.php') : '/public/recover_user.php')) ?>">
                <i class="fas fa-user-circle me-1"></i>Olvidé mi usuario
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

