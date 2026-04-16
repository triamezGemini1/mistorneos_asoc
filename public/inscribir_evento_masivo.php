<?php
/**
 * Inscripción Pública para Eventos Masivos
 * Permite a cualquier usuario inscribirse en eventos masivos desde su dispositivo móvil
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/TournamentScopeHelper.php';

$pdo = DB::pdo();
$torneo_id = isset($_GET['torneo_id']) ? (int)$_GET['torneo_id'] : 0;
$has_cod_org = false;
try {
    $has_cod_org = (bool)$pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $has_cod_org = false;
}
$org_join = $has_cod_org
    ? "LEFT JOIN organizaciones o ON (t.club_responsable = o.id OR t.club_responsable = o.cod_org)"
    : "LEFT JOIN organizaciones o ON t.club_responsable = o.id";

$error = '';
$success = '';
$torneo = null;
$es_hoy_bloqueo = false;

// Obtener información del torneo
if ($torneo_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                o.nombre as club_nombre,
                o.responsable as club_delegado,
                o.telefono as club_telefono,
                COALESCE(o.entidad, 0) as entidad_torneo,
                cb.banco as cuenta_banco,
                cb.numero_cuenta as cuenta_numero,
                cb.tipo_cuenta as cuenta_tipo,
                cb.telefono_afiliado as cuenta_telefono,
                cb.nombre_propietario as cuenta_propietario,
                cb.cedula_propietario as cuenta_cedula
            FROM tournaments t
            {$org_join}
            LEFT JOIN cuentas_bancarias cb ON t.cuenta_id = cb.id
            WHERE t.id = ? AND t.estatus = 1
        ");
        $stmt->execute([$torneo_id]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$torneo) {
            $error = 'Evento no encontrado o no disponible para inscripción pública';
        } elseif (date('Y-m-d', strtotime($torneo['fechator'])) === date('Y-m-d')) {
            $es_hoy_bloqueo = true;
            $error = 'Las inscripciones en línea están deshabilitadas el día del torneo. Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.';
        } elseif (strtotime($torneo['fechator']) < strtotime('today')) {
            // Torneo ya finalizado: redirigir a resultados con mensaje informativo
            $resultados_url = app_base_url() . '/public/evento_resultados.php?torneo_id=' . $torneo_id . '&msg=' . urlencode('Este torneo ha finalizado. Consulta los resultados oficiales aquí.');
            header('Location: ' . $resultados_url);
            exit;
        } elseif ((int)($torneo['permite_inscripcion_linea'] ?? 1) !== 1) {
            $error = 'Este torneo no acepta inscripciones en línea. Contacta al administrador del club para inscribirte en el sitio del evento.';
        }
    } catch (Exception $e) {
        error_log("Error obteniendo torneo: " . $e->getMessage());
        $error = 'Error al cargar la información del evento';
    }
} else {
    $error = 'Debe especificar un evento';
}

// Procesar inscripción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $torneo) {
    CSRF::validate();
    
    // Rechazar si es el día del torneo
    if (date('Y-m-d', strtotime($torneo['fechator'])) === date('Y-m-d')) {
        $es_hoy_bloqueo = true;
        $error = 'Las inscripciones en línea están deshabilitadas el día del torneo. Los interesados deben inscribirse antes o presentarse al sitio del evento para formalizar su participación.';
    }
    // Rechazar si el torneo no permite inscripción en línea
    elseif ((int)($torneo['permite_inscripcion_linea'] ?? 1) !== 1) {
        $error = 'Este torneo no acepta inscripciones en línea. Contacta al administrador del club para inscribirte en el sitio del evento.';
    } else {
    $nacionalidad = trim($_POST['nacionalidad'] ?? 'V');
    // Cédula solo numérica (nunca concatenar con nacionalidad)
    $cedula = preg_replace('/\D/', '', trim($_POST['cedula'] ?? ''));
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $celular = trim($_POST['celular'] ?? '');
    $sexo = trim($_POST['sexo'] ?? '');
    $entidad = (int)($_POST['entidad'] ?? 0);
    
    // Validaciones básicas
    if (empty($cedula) || empty($nombre) || empty($sexo)) {
        $error = 'Los campos marcados con * son requeridos';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Buscar si el usuario ya existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ?");
            $stmt->execute([$cedula]);
            $usuario_existente = $stmt->fetch(PDO::FETCH_ASSOC);
            $es_nuevo_usuario = false;
            $id_usuario = null;
            $id_club_inscripcion = null; // Club de procedencia/afiliación para la inscripción
            if ($usuario_existente) {
                $id_usuario = $usuario_existente['id'];
                
                // Verificar si ya está inscrito
                $stmt = $pdo->prepare("
                    SELECT id FROM inscritos 
                    WHERE torneo_id = ? AND id_usuario = ?
                ");
                $stmt->execute([$torneo_id, $id_usuario]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya estás inscrito en este evento');
                }
                
                // Validación centralizada: es_evento_masivo (0-4), historial, ámbito, club
                $stmt_u = $pdo->prepare("SELECT id, club_id, entidad FROM usuarios WHERE id = ?");
                $stmt_u->execute([$id_usuario]);
                $datos_usuario = $stmt_u->fetch(PDO::FETCH_ASSOC);
                $usuario = $datos_usuario ? [
                    'id' => (int)$datos_usuario['id'],
                    'club_id' => (int)($datos_usuario['club_id'] ?? 0),
                    'entidad' => (int)($datos_usuario['entidad'] ?? 0),
                ] : null;
                // Registrar club de procedencia si el usuario está afiliado
                if (!empty($datos_usuario['club_id']) && (int)$datos_usuario['club_id'] > 0) {
                    $id_club_inscripcion = (int)$datos_usuario['club_id'];
                }
                $validacion = TournamentScopeHelper::canRegisterOnline($torneo, $usuario, $pdo);
                if (!$validacion['can']) {
                    throw new Exception($validacion['message']);
                }
            } else {
                // Usuario nuevo: validar ámbito (entidad del form debe coincidir con torneo)
                $entidad_torneo = (int)($torneo['entidad_torneo'] ?? 0);
                $mismo_ambito = ($entidad_torneo <= 0) || ($entidad > 0 && $entidad === $entidad_torneo);
                if (!$mismo_ambito) {
                    throw new Exception('Este torneo está fuera de tu ámbito. Puedes inscribirte en el sitio del evento el día del torneo.');
                }
                
                // Crear usuario nuevo usando Security::createUser
                $es_nuevo_usuario = true;
                
                // Generar username único
                $username = 'user_' . time() . '_' . rand(1000, 9999);
                $password = uniqid('pwd_', true);
                
                // Obtener sexo desde BD externa si no se proporcionó
                if (empty($sexo)) {
                    if (file_exists(__DIR__ . '/../config/persona_database.php')) {
                        require_once __DIR__ . '/../config/persona_database.php';
                        try {
                            $personaDb = new PersonaDatabase();
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
                            error_log("Error al obtener sexo desde BD persona: " . $e->getMessage());
                        }
                    }
                }
                
                $userData = [
                    'username' => $username,
                    'password' => $password,
                    'email' => $email ?: null,
                    'role' => 'usuario',
                    'cedula' => $cedula,
                    'nombre' => $nombre,
                    'celular' => $celular ?: null,
                    'sexo' => $sexo ?: null,
                    'entidad' => $entidad > 0 ? $entidad : null,
                    'status' => 'approved'
                ];
                
                $result = Security::createUser($userData);
                
                if (!$result['success']) {
                    throw new Exception('Error al crear usuario: ' . implode(', ', $result['errors']));
                }
                
                $id_usuario = $result['user_id'];
            }
            
            // Inscribir en el torneo usando función centralizada
            require_once __DIR__ . '/../lib/InscritosHelper.php';
            
            $id_inscrito = InscritosHelper::insertarInscrito($pdo, [
                'id_usuario' => $id_usuario,
                'torneo_id' => $torneo_id,
                'id_club' => $id_club_inscripcion,
                'estatus' => 0, // pendiente
                'inscrito_por' => null,
                'numero' => 0
            ]);
            if (file_exists(__DIR__ . '/../lib/UserActivationHelper.php')) {
                require_once __DIR__ . '/../lib/UserActivationHelper.php';
                UserActivationHelper::activateUser($pdo, $id_usuario);
            }
            $pdo->commit();
            
            // Enviar notificación por WhatsApp si es nuevo usuario
            if ($es_nuevo_usuario && !empty($torneo['club_telefono'])) {
                try {
                    $telefono = preg_replace('/[^0-9]/', '', $torneo['club_telefono']);
                    if ($telefono && $telefono[0] == '0') {
                        $telefono = substr($telefono, 1);
                    }
                    if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
                        $telefono = '58' . $telefono;
                    }
                    
                    $mensaje = "🔔 *NUEVA INSCRIPCIÓN PÚBLICA*\n\n";
                    $mensaje .= "Se ha registrado un nuevo participante en el evento:\n\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                    $mensaje .= "📋 *INFORMACIÓN DEL PARTICIPANTE*\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                    $mensaje .= "👤 *Nombre:* " . $nombre . "\n";
                    $mensaje .= "🆔 *Cédula:* " . $nacionalidad . $cedula . "\n";
                    if ($celular) {
                        $mensaje .= "📱 *Celular:* " . $celular . "\n";
                    }
                    if ($email) {
                        $mensaje .= "📧 *Email:* " . $email . "\n";
                    }
                    $mensaje .= "⚧️ *Sexo:* " . ($sexo === 'M' ? 'Masculino' : ($sexo === 'F' ? 'Femenino' : 'Otro')) . "\n";
                    if ($entidad > 0) {
                        $stmt_ent = $pdo->prepare("SELECT nombre FROM entidad WHERE id = ?");
                        $stmt_ent->execute([$entidad]);
                        $ent_nombre = $stmt_ent->fetchColumn();
                        if ($ent_nombre) {
                            $mensaje .= "📍 *Entidad:* " . $ent_nombre . "\n";
                        }
                    }
                    $mensaje .= "\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
                    $mensaje .= "🏆 *INFORMACIÓN DEL EVENTO*\n";
                    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
                    $mensaje .= "📅 *Evento:* " . limpiarNombreTorneo($torneo['nombre']) . "\n";
                    $mensaje .= "📆 *Fecha:* " . date('d/m/Y', strtotime($torneo['fechator'])) . "\n";
                    if ($torneo['lugar']) {
                        $mensaje .= "📍 *Lugar:* " . $torneo['lugar'] . "\n";
                    }
                    $mensaje .= "\n";
                    $mensaje .= "⚠️ *Este es un nuevo usuario registrado en el sistema.*\n";
                    $mensaje .= "Revisa la inscripción en el panel de administración.\n";
                    
                    $mensaje_encoded = urlencode($mensaje);
                    $whatsapp_url = "https://wa.me/{$telefono}?text={$mensaje_encoded}";
                    
                    // Guardar URL para mostrar al usuario
                    $_SESSION['whatsapp_notification_url'] = $whatsapp_url;
                    $_SESSION['whatsapp_notification_telefono'] = $telefono;
                } catch (Exception $e) {
                    error_log("Error al generar notificación WhatsApp: " . $e->getMessage());
                }
            }
            
            $success = 'Te has registrado al torneo ' . limpiarNombreTorneo($torneo['nombre']) . '. Debes formalizar tu inscripción haciendo el pago correspondiente a través de la notificación de pagos o al presentarte al evento.';
            
            // Limpiar formulario
            $_POST = [];
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
    }
}

// Obtener entidades para el select
$entidades = [];
try {
    $stmt = $pdo->query("SELECT id, nombre FROM entidad WHERE id > 0 ORDER BY nombre ASC");
    $entidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo entidades: " . $e->getMessage());
}

$csrf_token = CSRF::token();

// Función para limpiar el nombre del torneo (eliminar "masivos" y "Masivos")
function limpiarNombreTorneo($nombre) {
    if (empty($nombre)) return $nombre;
    // Eliminar "masivos", "Masivos", "MASIVOS" y todas las variaciones (case-insensitive)
    $nombre = preg_replace('/\bmasivos?\b/i', '', $nombre);
    // También eliminar "Masivos" específicamente si aparece al inicio o después de espacios
    $nombre = preg_replace('/\s+Masivos\s*/i', ' ', $nombre);
    $nombre = preg_replace('/^Masivos\s+/i', '', $nombre);
    $nombre = preg_replace('/\s+Masivos$/i', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre); // Limpiar espacios múltiples
    return trim($nombre);
}

$torneo_nombre_limpio = $torneo ? limpiarNombreTorneo($torneo['nombre']) : 'Evento';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Inscripción - <?= htmlspecialchars($torneo_nombre_limpio) ?></title>
    
    <!-- Tailwind CSS (compilado localmente para mejor rendimiento) -->
    <link rel="stylesheet" href="assets/dist/output.css">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-600 via-purple-700 to-indigo-800 min-h-screen">
    
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <!-- Header -->
        <div class="text-center mb-8">
            <a href="landing.php#eventos-masivos" class="inline-flex items-center text-white/90 hover:text-white mb-4">
                <i class="fas fa-arrow-left mr-2"></i> Volver a Eventos
            </a>
            <h1 class="text-3xl md:text-4xl font-bold text-white mb-2">
                <i class="fas fa-user-plus mr-3 text-yellow-400"></i>Inscripción Pública
            </h1>
            <?php if ($torneo): ?>
            <p class="text-white/80 text-lg"><?= htmlspecialchars($torneo_nombre_limpio) ?></p>
            <p class="text-white/70 text-sm mt-2">
                <i class="fas fa-calendar mr-1"></i><?= date('d/m/Y', strtotime($torneo['fechator'])) ?>
                <?php if ($torneo['lugar']): ?>
                    <span class="ml-4"><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($torneo['lugar']) ?></span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        
        <!-- Mensajes -->
        <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-3"></i>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
            <?php if ($es_hoy_bloqueo): ?>
            <div class="mt-4">
                <a href="landing.php#eventos-masivos" class="inline-flex items-center bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-all">
                    <i class="fas fa-arrow-left mr-2"></i>Volver a Eventos
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-3"></i>
                <p><?= htmlspecialchars($success) ?></p>
            </div>
            <?php if (isset($_SESSION['whatsapp_notification_url'])): ?>
            <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                <p class="text-sm text-blue-800 mb-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>Nota:</strong> Se ha generado una notificación para el responsable del evento.
                </p>
                <a href="<?= htmlspecialchars($_SESSION['whatsapp_notification_url']) ?>" 
                   class="inline-flex items-center bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600 transition-all">
                    <i class="fab fa-whatsapp mr-2 text-xl"></i>
                    Abrir WhatsApp para enviar notificación
                </a>
            </div>
            <?php 
                unset($_SESSION['whatsapp_notification_url']);
                unset($_SESSION['whatsapp_notification_telefono']);
            endif; ?>
            <div class="mt-4 flex flex-col sm:flex-row gap-3 justify-center">
                <a href="reportar_pago_evento_masivo.php?torneo_id=<?= $torneo_id ?>&cedula=<?= urlencode($_POST['cedula'] ?? '') ?>" 
                   class="inline-flex items-center justify-center bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-all">
                    <i class="fas fa-money-bill-wave mr-2"></i>Reportar Pago
                </a>
                <a href="ver_recibo_pago.php?torneo_id=<?= $torneo_id ?>&cedula=<?= urlencode($_POST['cedula'] ?? '') ?>" 
                   class="inline-flex items-center justify-center bg-purple-500 text-white px-6 py-2 rounded-lg hover:bg-purple-600 transition-all">
                    <i class="fas fa-receipt mr-2"></i>Ver Recibo
                </a>
                <a href="landing.php#eventos-masivos" class="inline-flex items-center justify-center bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600 transition-all">
                    <i class="fas fa-arrow-left mr-2"></i>Volver a Eventos
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($torneo && !$success && !$es_hoy_bloqueo): ?>
        <!-- Condiciones requeridas -->
        <?php 
        $em = (int)($torneo['es_evento_masivo'] ?? 0);
        $req_historial = in_array($em, [2, 3]); // Regional o Local
        ?>
        <div class="bg-blue-50 border-l-4 border-blue-500 rounded-r-lg p-4 mb-6">
            <p class="font-bold text-blue-800 mb-2"><i class="fas fa-info-circle mr-2"></i>Condiciones para inscribirte</p>
            <ul class="text-sm text-blue-900 space-y-1 mb-0">
                <li>Cédula, nombre completo y sexo son obligatorios.</li>
                <li>Si ya estás registrado, verifica que tu cédula coincida con tus datos.</li>
                <?php if ($req_historial): ?>
                <li><strong>Evento <?= $em === 2 ? 'Regional' : 'Local' ?>:</strong> Debes tener historial de participación previa en eventos del sistema.</li>
                <?php endif; ?>
                <li>Tu inscripción será revisada por los organizadores. Mantén tu celular/email actualizado.</li>
            </ul>
        </div>
        
        <!-- Formulario de Inscripción -->
        <div class="bg-white rounded-2xl shadow-2xl p-6 md:p-8">
            <form method="POST" action="" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Nacionalidad -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Nacionalidad <span class="text-red-500">*</span>
                        </label>
                        <select name="nacionalidad" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                            <option value="V" <?= ($_POST['nacionalidad'] ?? 'V') === 'V' ? 'selected' : '' ?>>Venezolano</option>
                            <option value="E" <?= ($_POST['nacionalidad'] ?? '') === 'E' ? 'selected' : '' ?>>Extranjero</option>
                        </select>
                    </div>
                    
                    <!-- Cédula -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Cédula <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="cedula" id="cedula" value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>" required
                               onblur="buscarPersona()"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="Ej: V12345678">
                        <div id="busqueda_resultado" class="mt-2 text-sm"></div>
                    </div>
                </div>
                
                <!-- Nombre -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Nombre Completo <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="nombre" id="nombre" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                           placeholder="Ej: Juan Pérez">
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Email
                        </label>
                        <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="ejemplo@correo.com">
                    </div>
                    
                    <!-- Celular -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Celular
                        </label>
                        <input type="tel" name="celular" id="celular" value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="0412-1234567">
                    </div>
                </div>
                
                <!-- Sexo -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Sexo <span class="text-red-500">*</span>
                    </label>
                    <select name="sexo" id="sexo" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <option value="">Seleccione...</option>
                        <option value="M" <?= ($_POST['sexo'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                        <option value="F" <?= ($_POST['sexo'] ?? '') === 'F' ? 'selected' : '' ?>>Femenino</option>
                        <option value="O" <?= ($_POST['sexo'] ?? '') === 'O' ? 'selected' : '' ?>>Otro</option>
                    </select>
                </div>
                
                <!-- Entidad -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Entidad (Opcional)
                    </label>
                    <select name="entidad" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <option value="0">Seleccione una entidad...</option>
                        <?php foreach ($entidades as $ent): ?>
                        <option value="<?= $ent['id'] ?>" <?= (int)($_POST['entidad'] ?? 0) === (int)$ent['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ent['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Botón de Envío -->
                <div class="pt-4">
                    <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 text-white py-4 rounded-lg font-bold text-lg hover:from-purple-700 hover:to-indigo-700 transition-all shadow-lg hover:shadow-xl transform hover:scale-105">
                        <i class="fas fa-paper-plane mr-2"></i>Enviar Inscripción
                    </button>
                </div>
                
                <p class="text-sm text-gray-500 text-center">
                    <i class="fas fa-info-circle mr-1"></i>
                    Tu inscripción será revisada por los organizadores del evento.
                </p>
                
                <!-- Enlaces para reportar pago o ver recibo -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <p class="text-sm font-semibold text-gray-700 mb-3 text-center">Después de inscribirte:</p>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <a href="reportar_pago_evento_masivo.php?torneo_id=<?= $torneo_id ?>&cedula=" 
                           onclick="this.href += encodeURIComponent(document.querySelector('input[name=cedula]').value); return true;"
                           class="flex-1 inline-flex items-center justify-center bg-blue-500 text-white px-4 py-3 rounded-lg hover:bg-blue-600 transition-all text-center">
                            <i class="fas fa-money-bill-wave mr-2"></i>Reportar Pago
                        </a>
                        <a href="ver_recibo_pago.php?torneo_id=<?= $torneo_id ?>&cedula=" 
                           onclick="this.href += encodeURIComponent(document.querySelector('input[name=cedula]').value); return true;"
                           class="flex-1 inline-flex items-center justify-center bg-purple-500 text-white px-4 py-3 rounded-lg hover:bg-purple-600 transition-all text-center">
                            <i class="fas fa-receipt mr-2"></i>Ver Recibo
                        </a>
                    </div>
                </div>
            </form>
        </div>
        <?php elseif (!$torneo && !$success): ?>
        <div class="bg-white rounded-2xl shadow-2xl p-8 text-center">
            <i class="fas fa-exclamation-triangle text-5xl text-red-500 mb-4"></i>
            <h2 class="text-2xl font-bold text-gray-900 mb-2">Evento no disponible</h2>
            <p class="text-gray-600 mb-6"><?= htmlspecialchars($error ?: 'El evento seleccionado no está disponible para inscripción pública') ?></p>
            <a href="landing.php#eventos-masivos" class="inline-block bg-purple-600 text-white px-6 py-3 rounded-lg hover:bg-purple-700 transition-all">
                <i class="fas fa-arrow-left mr-2"></i>Volver a Eventos
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    const baseUrl = '<?= app_base_url() ?>';
    
    // Búsqueda automática: 1) usuarios primero, 2) si no está registrado -> requiere registro y cargar formulario
    async function buscarPersona() {
        const cedula = document.getElementById('cedula').value.trim();
        const nacionalidad = document.querySelector('select[name="nacionalidad"]').value;
        const resultadoDiv = document.getElementById('busqueda_resultado');
        const torneoId = <?= $torneo_id ?>;
        const submitButton = document.querySelector('button[type="submit"]');
        const form = document.querySelector('form');
        
        if (!cedula || cedula.length < 5) {
            resultadoDiv.innerHTML = '';
            if (submitButton) submitButton.disabled = false;
            if (form) form.style.opacity = '1';
            return;
        }
        
        // Detectar si la cédula viene con nacionalidad pegada (V12345678)
        let cedula_limpia = cedula;
        let nacionalidad_final = nacionalidad;
        const match = cedula.match(/^([VEJP])(\d+)$/i);
        if (match) {
            nacionalidad_final = match[1].toUpperCase();
            cedula_limpia = match[2];
            document.querySelector('select[name="nacionalidad"]').value = nacionalidad_final;
            document.getElementById('cedula').value = cedula_limpia;
        }
        
        resultadoDiv.innerHTML = '<span class="text-blue-600"><i class="fas fa-spinner fa-spin mr-1"></i>Buscando en el sistema...</span>';
        
        try {
            // 1. Buscar PRIMERO en usuarios (verificar_inscripcion hace la búsqueda en usuarios)
            const verificarResponse = await fetch(`${baseUrl}/public/api/verificar_inscripcion.php?cedula=${encodeURIComponent(cedula_limpia)}&nacionalidad=${encodeURIComponent(nacionalidad_final)}&torneo_id=${torneoId}`);
            const verificarResult = await verificarResponse.json();
            
            if (verificarResult.inscrito) {
                resultadoDiv.innerHTML = `<span class="text-red-600 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>${verificarResult.mensaje || 'Ya estás inscrito en este evento'}</span>`;
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (form) form.style.opacity = '0.6';
                return;
            }
            
            // 2. Usuario registrado encontrado -> llenar formulario con sus datos y proceder
            if (verificarResult.usuario_existe && verificarResult.usuario) {
                const u = verificarResult.usuario;
                if (u.nombre) document.getElementById('nombre').value = u.nombre;
                if (u.email) document.getElementById('email').value = u.email;
                if (u.celular) document.getElementById('celular').value = u.celular;
                if (u.sexo) document.getElementById('sexo').value = u.sexo;
                resultadoDiv.innerHTML = '<span class="text-green-600 font-semibold"><i class="fas fa-check-circle mr-1"></i>Usuario registrado. Datos cargados. Puede proceder con la inscripción.</span>';
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
                }
                if (form) form.style.opacity = '1';
                return;
            }
            
            // 3. No está registrado (requiere_registro) -> mensaje + opcional prellenar desde BD persona
            if (verificarResult.requiere_registro || !verificarResult.usuario_existe) {
                resultadoDiv.innerHTML = '<span class="text-amber-700 font-semibold"><i class="fas fa-user-plus mr-1"></i>Requiere registro. Complete el formulario para registrarse y continuar con la inscripción.</span>';
                if (submitButton) submitButton.disabled = false;
                if (form) form.style.opacity = '1';
                
                // Prellenar desde BD persona si está disponible (opcional, ayuda al usuario)
                const responsePersona = await fetch(`${baseUrl}/public/api/search_persona.php?cedula=${encodeURIComponent(cedula_limpia)}&nacionalidad=${encodeURIComponent(nacionalidad_final)}`);
                const resultPersona = await responsePersona.json();
                if (resultPersona.encontrado && resultPersona.persona) {
                    const p = resultPersona.persona;
                    if (p.nombre && !document.getElementById('nombre').value) document.getElementById('nombre').value = p.nombre;
                    if (p.celular && !document.getElementById('celular').value) document.getElementById('celular').value = p.celular || '';
                    if (p.email && !document.getElementById('email').value) document.getElementById('email').value = p.email || '';
                    if (p.sexo && !document.getElementById('sexo').value) document.getElementById('sexo').value = p.sexo || '';
                    resultadoDiv.innerHTML = '<span class="text-amber-700 font-semibold"><i class="fas fa-user-plus mr-1"></i>Requiere registro. Complete los datos faltantes del formulario para registrarse y continuar.</span>';
                }
                return;
            }
            
            // Fallback
            resultadoDiv.innerHTML = '<span class="text-gray-500"><i class="fas fa-info-circle mr-1"></i>Complete los datos del formulario</span>';
            if (submitButton) submitButton.disabled = false;
            if (form) form.style.opacity = '1';
            
        } catch (error) {
            console.error('Error en la búsqueda:', error);
            resultadoDiv.innerHTML = '<span class="text-red-600"><i class="fas fa-times-circle mr-1"></i>Error al buscar</span>';
            if (submitButton) submitButton.disabled = false;
            if (form) form.style.opacity = '1';
        }
    }
    
    </script>
    
</body>
</html>

