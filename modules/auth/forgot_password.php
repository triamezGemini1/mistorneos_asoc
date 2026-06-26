<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';

$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success = null;
$error = null;

/**
 * Crea e inicializa un PHPMailer a partir de la configuración local.
 * Retorna null si no está disponible.
 */
function crearMailer(): ?PHPMailer {
    if (!class_exists(PHPMailer::class)) {
        return null;
    }

    $config_path = __DIR__ . '/../../config/email.php';
    $email_cfg = file_exists($config_path) ? require $config_path : [];
    $smtp = $email_cfg['smtp'] ?? [];

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    if (!empty($smtp)) {
        $mail->isSMTP();
        $mail->Host = $smtp['host'] ?? 'localhost';
        $mail->Port = $smtp['port'] ?? 587;
        $mail->SMTPAuth = !empty($smtp['username']);
        if ($mail->SMTPAuth) {
            $mail->Username   = 'viajacontrino@gmail.com';                
            $mail->Password   = 'zgwymqdmepplznrh'; // Tu Contraseña de Aplicación (sin espacios)

            $mail->Username = $smtp['viajacontrino@gmail.com'] ?? '';
            $mail->Password = $smtp['zgwymqdmepplznrh'] ?? '';
            $encryption = $smtp['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPSecure = $encryption === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
        }
    } else {
        $mail->isMail();
    }

    $fromEmail = $smtp['from_email'] ?? 'noreply@mistorneos.com';
    $fromName = $smtp['from_name'] ?? 'Sistema de Inscripciones - Mistorneos';
    $mail->setFrom($fromEmail, $fromName);
    $mail->addReplyTo($fromEmail, $fromName);

    return $mail;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        $error = 'Email inválido';
    } else {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $user_id = (int) $user['id'];
                $pdo->prepare("UPDATE usuarios SET recovery_token = ? WHERE id = ?")->execute([$token, $user_id]);
                $base = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
                if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'public') !== false ? dirname($_SERVER['SCRIPT_NAME']) : '/public');
                }
                $reset_link = rtrim($base, '/') . '/reset_password.php?token=' . urlencode($token);

                $mailer = crearMailer();
                if ($mailer) {
                    $mailer->addAddress($email);
                    $mailer->Subject = 'Recuperación de contraseña';
                    $mailer->Body = "Haz clic en el enlace para restablecer tu contraseña: $reset_link";
                    $mailer->send();
                    $success = 'Enlace enviado a tu email';
                } else {
                    // Fallback con mail() si PHPMailer no está disponible
                    $headers = [
                        'MIME-Version: 1.0',
                        'Content-type: text/plain; charset=UTF-8',
                        'From: Sistema de Inscripciones - Mistorneos <noreply@mistorneos.com>',
                    ];
                    $sent = mail($email, 'Recuperación de contraseña', "Haz clic en el enlace para restablecer tu contraseña: $reset_link", implode("\r\n", $headers));
                    if ($sent) {
                        $success = 'Enlace enviado a tu email';
                    } else {
                        $error = 'No se pudo enviar el correo. Intenta más tarde.';
                    }
                }
            } else {
                $error = 'Email no encontrado';
            }
        } catch (Exception $e) {
            $error = 'No se pudo enviar el correo. Intenta más tarde.';
            error_log('forgot_password mail error: ' . $e->getMessage());
        } catch (Throwable $t) {
            $error = 'Ocurrió un error al procesar la solicitud.';
            error_log('forgot_password error: ' . $t->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#1a365d">
  <title><?= htmlspecialchars(class_exists('Branding', false) ? Branding::pageTitle('Recuperar contraseña') : 'Recuperar contraseña - La Estación del Dominó') ?></title>
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
            require_once __DIR__ . '/../../lib/app_helpers.php';
            $logo_url = AppHelpers::getAppLogo();
            ?>
            <?php $auth_brand = class_exists('Branding', false) ? Branding::siteName() : 'La Estación del Dominó'; ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($auth_brand) ?>" style="height: 60px; margin-bottom: 1rem;">
            <h4 class="mb-1"><?= htmlspecialchars($auth_brand) ?></h4>
            <p class="mb-0 opacity-75">Recuperar contraseña</p>
          </div>
          <div class="card-body">
            <?php if (isset($error)): ?>
              <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
              </div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
              <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= $success ?>
              </div>
            <?php endif; ?>

            <p class="text-muted mb-4">Ingresa tu correo y te enviaremos un enlace para restablecer tu contraseña.</p>
            <form method="post">
              <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" class="form-control form-control-lg" required>
              </div>
              <button type="submit" class="btn btn-primary btn-lg w-100">
                <i class="fas fa-paper-plane me-1"></i>Enviar enlace
              </button>
            </form>
            <?php
            $entry_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $login_href = $entry_dir . '/login.php';
            $recover_user_href = $entry_dir . '/recover_user.php';
            $landing_href = $entry_dir . '/landing.php';
            ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
              <a class="small text-decoration-none" href="<?= htmlspecialchars($login_href) ?>">
                <i class="fas fa-arrow-left me-1"></i>Volver al login
              </a>
              <a class="small text-decoration-none" href="<?= htmlspecialchars($recover_user_href) ?>">
                <i class="fas fa-user-circle me-1"></i>Olvidé mi usuario
              </a>
            </div>
            <div class="text-center mt-3">
              <a href="<?= htmlspecialchars($landing_href) ?>" class="text-muted text-decoration-none small">
                <i class="fas fa-home me-1"></i>Volver al inicio
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
