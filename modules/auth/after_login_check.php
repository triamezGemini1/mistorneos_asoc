<?php
// La sesión ya está iniciada por bootstrap; no llamar session_start() de nuevo
if (!empty($_SESSION['user']) && isset($_SESSION['user']['id'])) {
  try {
    $pdo = DB::pdo();
    // Verificar si la columna must_change_password existe antes de usarla
    $checkColumn = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'must_change_password'");
    if ($checkColumn->rowCount() > 0) {
      $q = $pdo->prepare("SELECT must_change_password FROM usuarios WHERE id=:id");
      $q->execute([':id' => $_SESSION['user']['id']]);
      $m = $q->fetch();
      if ($m && isset($m['must_change_password']) && (int)$m['must_change_password'] === 1) {
        $url = class_exists('AppHelpers') ? AppHelpers::url('index.php', ['page' => 'users/change_password', 'force' => 1]) : (rtrim(FvdConfig::resolvePublicUrl(), '/') . '/index.php?page=users/change_password&force=1');
        header('Location: ' . $url, true, 302);
        exit;
      }
    }
  } catch (Exception $e) {
    // Si hay error, continuar sin verificar must_change_password
    error_log("after_login_check: " . $e->getMessage());
  }
}
