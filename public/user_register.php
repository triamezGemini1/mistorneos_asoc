<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/includes/branding_init.php';

/**
 * Obtiene opciones de entidad (ubicación geográfica).
 */
function getEntidadesOptions(): array {
    try {
        $pdo = DB::pdo();
        $columns = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) {
            return [];
        }
        $codeCandidates = ['codigo', 'cod_entidad', 'id', 'code'];
        $nameCandidates = ['nombre', 'descripcion', 'entidad', 'nombre_entidad'];
        $codeCol = null;
        $nameCol = null;
        foreach ($columns as $col) {
            $field = strtolower($col['Field'] ?? $col['field'] ?? '');
            if (!$codeCol && in_array($field, $codeCandidates, true)) {
                $codeCol = $col['Field'] ?? $col['field'];
            }
            if (!$nameCol && in_array($field, $nameCandidates, true)) {
                $nameCol = $col['Field'] ?? $col['field'];
            }
        }
        if (!$codeCol && isset($columns[0]['Field'])) {
            $codeCol = $columns[0]['Field'];
        }
        if (!$nameCol && isset($columns[1]['Field'])) {
            $nameCol = $columns[1]['Field'];
        }
        if (!$codeCol || !$nameCol) {
            return [];
        }
        $stmt = $pdo->query("SELECT {$codeCol} AS codigo, {$nameCol} AS nombre FROM entidad ORDER BY {$nameCol} ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("No se pudo obtener entidades: " . $e->getMessage());
        return [];
    }
}

// Si ya está logueado, redirigir
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$from_invitation = !empty($_GET['from_invitation']) || !empty($_POST['from_invitation']);
$invitation_token = trim($_GET['token'] ?? $_POST['token'] ?? $_SESSION['invitation_token'] ?? '');
$entidad = isset($_POST['entidad']) ? (int)($_POST['entidad']) : 0;
$entidades_options = getEntidadesOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../lib/RateLimiter.php';
    if (!RateLimiter::canSubmit('user_register', 30)) {
        $error = 'Por favor espera 30 segundos antes de intentar registrarte de nuevo.';
    } else {
    CSRF::validate();
    
    $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
    // Cédula solo numérica (nunca concatenar con nacionalidad)
    $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $fechnac = trim($_POST['fechnac'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim((string)($_POST['password'] ?? ''));
    $password_confirm = trim((string)($_POST['password_confirm'] ?? ''));
    
    // Validaciones (por invitación no se exige entidad)
    if ($cedula === '' || empty($nombre) || empty($username) || empty($password)) {
        $error = 'Todos los campos marcados con * son requeridos (cédula solo números)';
    } elseif (!$from_invitation && $entidad <= 0) {
        $error = 'Debe seleccionar una entidad.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } elseif ($password !== $password_confirm) {
        $error = 'Las contraseñas no coinciden';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido';
    } else {
        try {
            $pdo = DB::pdo();
            
            // Usar función centralizada para crear usuario
            // Obtener sexo desde el formulario o desde la BD externa si está disponible
            $sexo = null;
            if (isset($_POST['sexo']) && !empty($_POST['sexo'])) {
                $sexo = strtoupper(trim($_POST['sexo']));
                if (!in_array($sexo, ['M', 'F', 'O'])) {
                    $sexo = null;
                }
            }
            
            // Si no hay sexo en el formulario, intentar obtenerlo desde la BD externa
            if (!$sexo) {
                require_once __DIR__ . '/../config/persona_database.php';
                try {
                    $personaDb = new PersonaDatabase();
                    // Extraer nacionalidad y número de cédula
                    $nacionalidad_busqueda = $nacionalidad;
                    $cedula_numero = $cedula;
                    if (preg_match('/^([VEJP])(\d+)$/i', $cedula, $matches)) {
                        $nacionalidad_busqueda = strtoupper($matches[1]);
                        $cedula_numero = $matches[2];
                    }
                    $result = $personaDb->searchPersonaById($nacionalidad_busqueda, $cedula_numero);
                    if (isset($result['persona']['sexo']) && !empty($result['persona']['sexo'])) {
                        $sexo_raw = strtoupper(trim($result['persona']['sexo']));
                        if ($sexo_raw === 'M' || $sexo_raw === '1' || $sexo_raw === 'MASCULINO') {
                            $sexo = 'M';
                        } elseif ($sexo_raw === 'F' || $sexo_raw === '2' || $sexo_raw === 'FEMENINO') {
                            $sexo = 'F';
                        } else {
                            $sexo = 'O';
                        }
                    }
                } catch (Exception $e) {
                    // Si falla, continuar sin sexo
                    error_log("Error al obtener sexo desde BD persona: " . $e->getMessage());
                }
            }
            
            // Preparar datos para crear usuario (nacionalidad en su propio campo)
            $userData = [
                'username' => $username,
                'password' => $password,
                'email' => $email ?: null,
                'role' => 'usuario',
                'cedula' => $cedula,
                'nacionalidad' => in_array($nacionalidad, ['V', 'E', 'J', 'P'], true) ? strtoupper($nacionalidad) : 'V',
                'nombre' => $nombre,
                'celular' => $celular ?: null,
                'fechnac' => $fechnac ?: null,
                'sexo' => $sexo,
                'entidad' => $entidad,
                'status' => 'approved'
            ];
            
            $result = Security::createUser($userData);
            
            if ($result['success']) {
                RateLimiter::recordSubmit('user_register');
                $new_user_id = isset($result['user_id']) ? (int) $result['user_id'] : 0;
                // Hook post-registro invitación: vincular delegado en directorio_clubes
                if ($from_invitation && $new_user_id > 0) {
                    require_once __DIR__ . '/../lib/InvitationJoinResolver.php';
                    $id_directorio_club = null;
                    if (!empty($_SESSION['invitation_id_directorio_club'])) {
                        $id_directorio_club = (int) $_SESSION['invitation_id_directorio_club'];
                    } elseif ($invitation_token !== '') {
                        $resolved = InvitationJoinResolver::resolve($invitation_token);
                        if ($resolved !== null && !empty($resolved['id_directorio_club'])) {
                            $id_directorio_club = (int) $resolved['id_directorio_club'];
                        }
                    }
                    if ($id_directorio_club > 0) {
                        try {
                            $pdo = DB::pdo();
                            $cols = $pdo->query("SHOW COLUMNS FROM directorio_clubes LIKE 'id_usuario'")->fetchAll();
                            if (!empty($cols)) {
                                $stmt = $pdo->prepare("UPDATE directorio_clubes SET id_usuario = ? WHERE id = ?");
                                $stmt->execute([$new_user_id, $id_directorio_club]);
                            }
                        } catch (Exception $e) {
                            error_log("user_register: error al vincular id_usuario en directorio_clubes: " . $e->getMessage());
                        }
                    }
                    if ($invitation_token !== '') {
                        $base = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : rtrim($GLOBALS['APP_CONFIG']['app']['base_url'] ?? '', '/');
                        $_SESSION['invitation_token'] = $invitation_token;
                        $_SESSION['url_retorno'] = $base . '/invitation/register?token=' . urlencode($invitation_token);
                        unset($_SESSION['invitation_id_directorio_club'], $_SESSION['invitation_join_requires_register']);
                        header('Location: ' . $base . '/auth/login');
                        exit;
                    }
                }
                $success = '¡Registro exitoso! Ya puedes iniciar sesión.' . ($from_invitation ? ' Inicia sesión para acceder al formulario de inscripción de tu invitación.' : '');
                // Limpiar todos los campos del formulario después de registro exitoso
                $_POST = [];
                $cedula = '';
                $nombre = '';
                $email = '';
                $celular = '';
                $fechnac = '';
                $username = '';
                $password = '';
                $password_confirm = '';
                $nacionalidad = 'V';
                $sexo = '';
            } else {
                $error = implode(', ', $result['errors']);
            }
        } catch (Exception $e) {
            $error = 'Error al registrar: ' . $e->getMessage();
        }
    }
    }
}

$base_url = app_base_url();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#48bb78">
    <title><?= htmlspecialchars(Branding::pageTitle('Registro de Jugador')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }
        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .register-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        .register-body {
            padding: 2rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #48bb78;
            box-shadow: 0 0 0 0.2rem rgba(72, 187, 120, 0.25);
        }
        .btn-register {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        .btn-register:hover {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
        }
        .search-status {
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="register-card">
                    <div class="register-header">
                        <i class="fas fa-user-plus"></i>
                        <h3 class="mb-1">Registro de Jugador</h3>
                        <p class="mb-0 opacity-75">Crea tu cuenta para participar en torneos</p>
                    </div>
                    <div class="register-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                <div class="mt-2">
                                    <a href="<?= htmlspecialchars(($base_url ?? '') . '/auth/login') ?>" class="btn btn-success btn-sm">Ir a Iniciar Sesión</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($from_invitation): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-envelope-open-text me-2"></i>
                                Has accedido mediante una invitación. Regístrate para poder inscribir a tus atletas; después inicia sesión y serás llevado al formulario de inscripción.
                            </div>
                            <?php endif; ?>
                            <form method="POST" id="registerForm">
                                <input type="hidden" name="csrf_token" value="<?= CSRF::token() ?>">
                                <?php if ($from_invitation): ?>
                                    <input type="hidden" name="from_invitation" value="1">
                                    <?php if ($invitation_token !== ''): ?><input type="hidden" name="token" value="<?= htmlspecialchars($invitation_token) ?>"><?php endif; ?>
                                <?php endif; ?>
                                <input type="hidden" name="fechnac" id="fechnac" value="">
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nacionalidad *</label>
                                        <select name="nacionalidad" id="nacionalidad" class="form-select" required>
                                            <option value="V" selected>V</option>
                                            <option value="E">E</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8 mb-3">
                                        <label for="cedula" class="form-label">Cédula *</label>
                                        <input type="text" name="cedula" id="cedula" class="form-control" 
                                               value="" 
                                               autocomplete="off" 
                                               onblur="debouncedBuscarPersona()" required 
                                               aria-required="true" aria-describedby="busqueda_resultado">
                                        <div id="busqueda_resultado" class="search-status"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="nombre" class="form-label">Nombre Completo *</label>
                                    <input type="text" name="nombre" id="nombre" class="form-control" 
                                           value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" 
                                           autocomplete="name" required 
                                           aria-required="true" aria-describedby="busqueda_resultado">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="sexo" class="form-label">Sexo *</label>
                                    <select name="sexo" id="sexo" class="form-select" required aria-describedby="sexo-help">
                                        <option value="">-- Seleccionar --</option>
                                        <option value="M" <?= ($_POST['sexo'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                                        <option value="F" <?= ($_POST['sexo'] ?? '') === 'F' ? 'selected' : '' ?>>Femenino</option>
                                        <option value="O" <?= ($_POST['sexo'] ?? '') === 'O' ? 'selected' : '' ?>>Otro</option>
                                    </select>
                                    <div id="sexo-help" class="form-text text-muted">Verifique que coincida con su documento. Se completa automáticamente si se encuentra en el sistema.</div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" name="email" id="email" class="form-control" 
                                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                               autocomplete="email">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="celular" class="form-label">Celular</label>
                                        <input type="tel" name="celular" id="celular" class="form-control" 
                                               value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>"
                                               autocomplete="tel">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Entidad (Ubicación)<?= $from_invitation ? '' : ' *' ?></label>
                                    <select name="entidad" id="entidad" class="form-select"<?= $from_invitation ? '' : ' required' ?>>
                                        <option value="">-- Seleccione<?= $from_invitation ? ' (opcional)' : '' ?> --</option>
                                        <?php if (!empty($entidades_options)): ?>
                                            <?php foreach ($entidades_options as $ent): ?>
                                                <option value="<?= htmlspecialchars($ent['codigo']) ?>" <?= ($entidad == $ent['codigo']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($ent['nombre'] ?? $ent['codigo']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No hay entidades disponibles</option>
                                        <?php endif; ?>
                                    </select>
                                    <div class="form-text text-muted">Se almacenará en usuarios.entidad</div>
                                </div>
                                
                                <hr class="my-4">
                                
                                <div class="mb-3">
                                    <label for="username" class="form-label">Nombre de Usuario *</label>
                                    <input type="text" name="username" id="username" class="form-control" 
                                           value="" autocomplete="username" required aria-required="true">
                                    <small class="text-muted">Este será tu usuario para iniciar sesión</small>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">Contraseña *</label>
                                        <input type="password" name="password" id="password" class="form-control" 
                                               autocomplete="new-password" required aria-required="true"
                                               minlength="6" aria-describedby="password-help">
                                        <small id="password-help" class="text-muted">Mínimo 6 caracteres</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="password_confirm" class="form-label">Confirmar Contraseña *</label>
                                        <input type="password" name="password_confirm" id="password_confirm" class="form-control" 
                                               autocomplete="new-password" required aria-required="true">
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-register btn-primary w-100 mt-3">
                                    <i class="fas fa-user-plus me-2"></i>Crear Mi Cuenta
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted mb-2">¿Ya tienes cuenta?</p>
                            <a href="<?= htmlspecialchars(($base_url ?? '') . '/auth/login') ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-sign-in-alt me-1"></i>Iniciar Sesión
                            </a>
                            <a href="<?= htmlspecialchars(($base_url ?? '') . '/') ?>" class="btn btn-outline-secondary btn-sm ms-2">
                                <i class="fas fa-home me-1"></i>Volver al Inicio
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= htmlspecialchars(rtrim($base_url ?? app_base_url(), '/') . '/assets/form-utils.js') ?>" defer></script>
    <script>
        // Limpiar formulario completamente al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registerForm');
            if (form) {
                // Limpiar todos los campos, especialmente credenciales
                const cedula = document.getElementById('cedula');
                const nombre = document.getElementById('nombre');
                const email = document.getElementById('email');
                const celular = document.getElementById('celular');
                const username = document.getElementById('username');
                const password = document.getElementById('password');
                const passwordConfirm = document.getElementById('password_confirm');
                const nacionalidad = document.getElementById('nacionalidad');
                const fechnac = document.getElementById('fechnac');
                const sexo = document.getElementById('sexo');
                
                if (cedula) cedula.value = '';
                if (nombre) nombre.value = '';
                if (email) email.value = '';
                if (celular) celular.value = '';
                if (username) username.value = '';
                if (password) password.value = '';
                if (passwordConfirm) passwordConfirm.value = '';
                if (nacionalidad) nacionalidad.value = 'V';
                if (fechnac) fechnac.value = '';
                if (sexo) sexo.value = '';
                
                // Limpiar también el resultado de búsqueda
                const busquedaResultado = document.getElementById('busqueda_resultado');
                if (busquedaResultado) busquedaResultado.innerHTML = '';
                if (form && typeof preventDoubleSubmit === 'function') preventDoubleSubmit(form);
                if (typeof initCedulaValidation === 'function') initCedulaValidation('cedula');
                if (typeof initEmailValidation === 'function') initEmailValidation('email');
            }
        });
    const debouncedBuscarPersona = typeof debounce === 'function' ? debounce(buscarPersona, 400) : buscarPersona;
    function buscarPersona() {
        const cedula = document.getElementById('cedula').value.trim();
        const nacionalidad = document.getElementById('nacionalidad').value;
        const resultadoDiv = document.getElementById('busqueda_resultado');
        
        if (!cedula) {
            resultadoDiv.innerHTML = '';
            return;
        }
        
        resultadoDiv.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i>Buscando...</span>';
        
        // Construir URL del API
        const baseUrl = '<?= $base_url ?>';
        const apiUrl = `${baseUrl}/public/api/search_user_persona.php?cedula=${encodeURIComponent(cedula)}&nacionalidad=${encodeURIComponent(nacionalidad)}`;
        
        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Respuesta del API:', data);
                
                if (data && data.success && data.data && data.data.encontrado) {
                    if (data.data.existe_usuario) {
                        resultadoDiv.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>${data.data.mensaje || 'Ya existe un usuario con esta cédula'}</span>`;
                    } else if (data.data.persona) {
                        const persona = data.data.persona;
                        // Cédula solo numérica; nacionalidad en su propio campo (no concatenar)
                        if (persona.cedula !== undefined && persona.cedula !== '') {
                            document.getElementById('cedula').value = String(persona.cedula).replace(/\D/g, '');
                        }
                        if (persona.nacionalidad && ['V','E','J','P'].includes(String(persona.nacionalidad).toUpperCase())) {
                            document.getElementById('nacionalidad').value = String(persona.nacionalidad).toUpperCase();
                        }
                        document.getElementById('nombre').value = persona.nombre || '';
                        document.getElementById('celular').value = persona.celular || '';
                        document.getElementById('email').value = persona.email || '';
                        document.getElementById('fechnac').value = persona.fechnac || '';
                        
                        // Rellenar sexo visible para verificar y corregir si es necesario
                        const sexoSelect = document.getElementById('sexo');
                        if (sexoSelect && persona.sexo) {
                            let sexoValue = String(persona.sexo).toUpperCase();
                            if (sexoValue === 'M' || sexoValue === '1' || sexoValue === 'MASCULINO') {
                                sexoValue = 'M';
                            } else if (sexoValue === 'F' || sexoValue === '2' || sexoValue === 'FEMENINO') {
                                sexoValue = 'F';
                            } else {
                                sexoValue = 'O';
                            }
                            sexoSelect.value = sexoValue;
                        }
                        
                        // Generar username sugerido
                        if (!document.getElementById('username').value) {
                            const nameParts = (persona.nombre || '').toLowerCase().split(' ').filter(p => p.length > 0);
                            if (nameParts.length >= 2) {
                                document.getElementById('username').value = nameParts[0] + '.' + nameParts[nameParts.length - 1];
                            } else if (nameParts.length === 1) {
                                document.getElementById('username').value = nameParts[0];
                            }
                        }
                        
                        resultadoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Datos encontrados y completados</span>';
                    } else {
                        resultadoDiv.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>No encontrado. Complete los datos manualmente.</span>';
                    }
                } else {
                    resultadoDiv.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>No encontrado. Complete los datos manualmente.</span>';
                }
            })
            .catch(error => {
                console.error('Error en búsqueda:', error);
                resultadoDiv.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Error en la búsqueda: ${error.message}</span>`;
            });
    }
    </script>
</body>
</html>
