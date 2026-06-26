<?php
declare(strict_types=1);

/**
 * Procesamiento POST — edición de datos de la asociación (hub).
 * Invocado desde modules/asociacion_hub.php antes del layout.
 */

if (! defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/AsociacionAuth.php';
require_once __DIR__ . '/../lib/OrganizacionService.php';
require_once __DIR__ . '/../lib/AsociacionHubNavigation.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

$org_id = filter_input(INPUT_POST, 'org_id', FILTER_VALIDATE_INT);
if (! is_int($org_id) || $org_id <= 0) {
    $org_id = filter_input(INPUT_GET, 'org_id', FILTER_VALIDATE_INT);
}

if (! is_int($org_id) || $org_id <= 0) {
    $_SESSION['error'] = 'Asociación no válida.';
    header('Location: ' . AsociacionHubNavigation::getOrigin(0)['origin_url']);
    exit;
}

$sessionUser = Auth::user();
$authUser = AsociacionAuth::userFromSession($sessionUser, $org_id);

if (! AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $org_id, $authUser)) {
    $_SESSION['error'] = 'No tiene permiso para editar esta asociación.';
    header('Location: ' . AppHelpers::dashboard('asociacion_hub', [
        'org_id' => $org_id,
        'tab' => 'info',
    ]));
    exit;
}

try {
    CSRF::validate();
} catch (Throwable $e) {
    $_SESSION['error'] = 'Token de seguridad inválido. Intente de nuevo.';
    header('Location: ' . AppHelpers::dashboard('asociacion_hub', [
        'org_id' => $org_id,
        'tab' => 'info',
    ]));
    exit;
}

$asociacion = OrganizacionService::getById($org_id);
if ($asociacion === null) {
    $_SESSION['error'] = 'Asociación no encontrada.';
    header('Location: ' . AsociacionHubNavigation::getOrigin($org_id)['origin_url']);
    exit;
}

$redirectHub = static function () use ($org_id): void {
    header('Location: ' . AppHelpers::dashboard('asociacion_hub', [
        'org_id' => $org_id,
        'tab' => 'info',
    ]));
    exit;
};

$nombre = trim((string) ($_POST['nombre'] ?? ''));
if ($nombre === '') {
    $_SESSION['error'] = 'El nombre de la asociación es obligatorio.';
    $redirectHub();
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'El correo electrónico no es válido.';
    $redirectHub();
}

$data = [
    'nombre' => $nombre,
    'email' => $email,
    'telefono' => trim((string) ($_POST['telefono'] ?? '')),
    'direccion' => trim((string) ($_POST['direccion'] ?? '')),
];

$logoFile = $_FILES['logo'] ?? null;
if (is_array($logoFile)) {
    $uploadErr = (int) ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadErr === UPLOAD_ERR_OK) {
        try {
            $data['logo'] = OrganizacionService::saveLogoFromUpload($org_id, $logoFile);
        } catch (Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
            $redirectHub();
        }
    } elseif ($uploadErr !== UPLOAD_ERR_NO_FILE) {
        $_SESSION['error'] = 'Error al subir el archivo de logo.';
        $redirectHub();
    }
}
if (! isset($data['logo'])) {
    $logoUrl = trim((string) ($_POST['logo_url'] ?? ''));
    if ($logoUrl !== '') {
        $data['logo'] = $logoUrl;
    }
}

$ok = OrganizacionService::update($org_id, $data);

if ($ok) {
    $_SESSION['success_msg'] = 'Datos de la asociación actualizados correctamente.';
} else {
    $_SESSION['error'] = 'No se pudo actualizar la asociación.';
}

header('Location: ' . AppHelpers::dashboard('asociacion_hub', [
    'org_id' => $org_id,
    'tab' => 'info',
]));
exit;
