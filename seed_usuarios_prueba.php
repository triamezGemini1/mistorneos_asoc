<?php
/**
 * Script CLI: Generación masiva de usuarios de prueba (MisTorneos)
 *
 * Crea X usuarios integrados en una Organización y Club(es) dados, usando datos
 * reales de personas.dbo_persona y el flujo de registro completo del sistema.
 *
 * Uso:
 *   php seed_usuarios_prueba.php --organizacion=1 --clubes=1,2,3 --cantidad=100
 *
 * Parámetros:
 *   --organizacion=ID   ID de la organización (obligatorio)
 *   --clubes=ID1,ID2   Uno o más IDs de clubes pertenecientes a la organización (obligatorio)
 *   --cantidad=N       Número de usuarios a crear (default: 10)
 *
 * Contraseña de todos los usuarios de prueba: npi2025
 *
 * --- Protocolo de comunicación ---
 *
 * QUÉ - Duplicidad de nombres de usuario:
 *   Se genera un usuario base con las 2 primeras letras del nombre y apellido (ej: JuPe).
 *   Si ese username ya existe en la tabla usuarios, se prueba secuencialmente base2, base3, ...
 *   hasta encontrar uno disponible. Así se evita colisión sin alterar el formato legible.
 *
 * POR QUÉ - Flujo de registro existente en lugar de INSERT directo:
 *   Se usa Security::createUser() para que se ejecuten las mismas validaciones, hash
 *   de contraseña (bcrypt), generación de UUID, y escritura en la tabla usuarios con
 *   todos los campos opcionales (cedula, nombre, club_id, etc.). Cualquier trigger,
 *   lógica futura o auditoría que dependa del “registro normal” queda cubierta.
 *
 * PARA QUÉ - Usuarios de prueba 100% funcionales:
 *   Garantizar que los usuarios creados puedan iniciar sesión, aparecer en listados
 *   por club/organización, inscribirse en torneos y ser usados en tests de rendimiento
 *   o carga sin diferencias respecto a usuarios creados por la interfaz.
 */

if (php_sapi_name() !== 'cli') {
    die('Este script solo puede ejecutarse desde la línea de comandos (CLI).' . PHP_EOL);
}

$baseDir = __DIR__;
if (!file_exists($baseDir . '/config/bootstrap.php')) {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/config/bootstrap.php';
require_once $baseDir . '/config/db.php';
require_once $baseDir . '/config/persona_database.php';
require_once $baseDir . '/lib/security.php';

// ---------------------------------------------------------------------------
// Parámetros CLI
// ---------------------------------------------------------------------------
$options = getopt('', ['organizacion:', 'clubes:', 'cantidad::']);
$organizacion_id = isset($options['organizacion']) ? (int)$options['organizacion'] : 0;
$clubes_raw = isset($options['clubes']) ? trim($options['clubes']) : '';
$cantidad = isset($options['cantidad']) ? max(1, (int)$options['cantidad']) : 10;

if ($organizacion_id <= 0 || $clubes_raw === '') {
    echo "Uso: php seed_usuarios_prueba.php --organizacion=ID --clubes=ID1[,ID2,...] [--cantidad=N]" . PHP_EOL;
    echo "  --organizacion  ID de la organización (obligatorio)" . PHP_EOL;
    echo "  --clubes        IDs de clubes separados por coma (obligatorio)" . PHP_EOL;
    echo "  --cantidad      Número de usuarios a crear (default: 10)" . PHP_EOL;
    exit(1);
}

$club_ids = array_values(array_filter(array_map('intval', explode(',', $clubes_raw))));
if (count($club_ids) === 0) {
    echo "Error: Debe indicar al menos un ID de club en --clubes." . PHP_EOL;
    exit(1);
}

// ---------------------------------------------------------------------------
// Validar Organización y Clubes
// ---------------------------------------------------------------------------
$pdo = DB::pdo();
$stmt = $pdo->prepare("SELECT id, nombre FROM organizaciones WHERE id = ? AND estatus = 1");
$stmt->execute([$organizacion_id]);
$organizacion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$organizacion) {
    echo "Error: No existe la organización con ID {$organizacion_id} o está inactiva." . PHP_EOL;
    exit(1);
}

$stmt = $pdo->prepare("SELECT id, nombre, cod_org FROM clubes WHERE id = ? AND estatus = 1");
$clubes_ok = [];
foreach ($club_ids as $cid) {
    $stmt->execute([$cid]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$club) {
        echo "Error: No existe el club con ID {$cid} o está inactivo." . PHP_EOL;
        exit(1);
    }
    if ((int)($club['cod_org'] ?? $club['organizacion_id'] ?? 0) !== $organizacion_id) {
        echo "Error: El club \"{$club['nombre']}\" (ID {$cid}) no pertenece a la organización \"{$organizacion['nombre']}\"." . PHP_EOL;
        exit(1);
    }
    $clubes_ok[] = (int)$club['id'];
}
$club_ids = $clubes_ok;

echo "Organización: {$organizacion['nombre']} (ID: {$organizacion_id})" . PHP_EOL;
echo "Clubes: " . implode(', ', $club_ids) . PHP_EOL;
echo "Cantidad a crear: {$cantidad}" . PHP_EOL;
echo str_repeat('-', 50) . PHP_EOL;

// ---------------------------------------------------------------------------
// Obtener personas: primero BD externa (personas.dbo_persona), si no hay datos → fallback local
// ---------------------------------------------------------------------------
$personaDb = new PersonaDatabase();
$buffer = min(500, max($cantidad + 50, $cantidad * 2));
$personas = $personaDb->getRandomPersonasForSeed($buffer);

if (count($personas) === 0) {
    echo "BD personas no disponible o tabla dbo_persona sin datos válidos. Usando lista local de nombres." . PHP_EOL;
    $nombres = ['Carlos', 'María', 'José', 'Ana', 'Luis', 'Carmen', 'Pedro', 'Laura', 'Juan', 'Patricia', 'Roberto', 'Sandra', 'Miguel', 'Andrea', 'Fernando', 'Ricardo', 'Diana', 'Alejandro', 'Daniel', 'Francisco', 'Manuel', 'Rosa', 'Antonio', 'Jorge', 'Elena', 'Rafael', 'Beatriz', 'Eduardo', 'Claudia', 'Alberto', 'Sergio', 'Adriana', 'Oscar', 'Verónica', 'Victor', 'Gabriela', 'Diego', 'Paola', 'Carolina', 'Felipe', 'Daniela', 'Mauricio', 'Valentina'];
    $apellidos = ['García', 'Rodríguez', 'González', 'Fernández', 'López', 'Martínez', 'Sánchez', 'Pérez', 'Gómez', 'Martín', 'Jiménez', 'Ruiz', 'Hernández', 'Díaz', 'Moreno', 'Álvarez', 'Muñoz', 'Romero', 'Alonso', 'Navarro', 'Torres', 'Ramos', 'Gil', 'Ramírez', 'Serrano', 'Blanco', 'Suárez', 'Molina', 'Morales', 'Ortega', 'Castro', 'Ortiz', 'Rubio', 'Marín', 'Sanz', 'Núñez', 'Medina', 'Garrido', 'Cortés', 'Castillo', 'Prieto', 'Calvo', 'Vidal', 'Lozano'];
    $personas = [];
    $used_cedulas = [];
    for ($i = 0; $i < $buffer; $i++) {
        $nombre1 = $nombres[array_rand($nombres)];
        $apellido1 = $apellidos[array_rand($apellidos)];
        $cedula_num = (string)rand(100000, 99999999);
        while (isset($used_cedulas[$cedula_num])) {
            $cedula_num = (string)rand(100000, 99999999);
        }
        $used_cedulas[$cedula_num] = true;
        $personas[] = [
            'id_usuario' => $cedula_num,
            'nac' => 'V',
            'nombre' => $nombre1 . ' ' . $apellido1,
            'nombre1' => $nombre1,
            'apellido1' => $apellido1,
            'sexo' => ($i % 2 === 0) ? 'M' : 'F',
            'fechnac' => null,
        ];
    }
}

if (count($personas) < $cantidad) {
    echo "Advertencia: Solo se encontraron " . count($personas) . " personas. Se crearán hasta ese número." . PHP_EOL;
    $cantidad = count($personas);
}
if ($cantidad === 0) {
    echo "Error: No se pudo obtener ninguna persona para crear usuarios." . PHP_EOL;
    exit(1);
}

// ---------------------------------------------------------------------------
// Contraseña única para todos los usuarios de prueba (hash igual que el sistema)
// ---------------------------------------------------------------------------
$password_plano = 'npi2025';

/**
 * Genera usuario tipo "JuPe" desde nombre1 y apellido1 (2 primeras letras de cada uno).
 * Normaliza a [a-zA-Z] para cumplir reglas del sistema.
 */
function generarUsuarioDesdeNombre(string $nombre1, string $apellido1): string {
    $n = preg_replace('/[^a-zA-Z]/', '', $nombre1);
    $a = preg_replace('/[^a-zA-Z]/', '', $apellido1);
    $n2 = mb_substr($n, 0, 2);
    $a2 = mb_substr($a, 0, 2);
    $base = $n2 . $a2;
    return strlen($base) >= 3 ? $base : $base . 'X';
}

/**
 * Busca un username disponible: base, base2, base3, ...
 */
function usernameDisponible(PDO $pdo, string $base): string {
    $base = preg_replace('/[^a-zA-Z0-9_.]/', '', $base);
    if (strlen($base) < 3) {
        $base = $base . 'U';
    }
    $candidato = $base;
    $n = 1;
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
    do {
        $stmt->execute([$candidato]);
        if (!$stmt->fetch()) {
            return $candidato;
        }
        $n++;
        $candidato = $base . $n;
    } while ($n < 10000);
    return $base . '_' . uniqid();
}

// ---------------------------------------------------------------------------
// Crear usuarios usando el mismo flujo que el registro manual (Security::createUser)
// ---------------------------------------------------------------------------
$creados = 0;
$errores = 0;
$index_persona = 0;
$num_clubs = count($club_ids);

echo PHP_EOL;

while ($creados < $cantidad && $index_persona < count($personas)) {
    $p = $personas[$index_persona];
    $index_persona++;

    $cedula = trim($p['nac'] ?? 'V') . trim($p['id_usuario'] ?? '');
    if ($cedula === '') {
        continue;
    }

    $base_username = generarUsuarioDesdeNombre($p['nombre1'] ?? '', $p['apellido1'] ?? '');
    $username = usernameDisponible($pdo, $base_username);

    $club_id = $clubes_ok[$creados % $num_clubs];

    $userData = [
        'username' => $username,
        'password' => $password_plano,
        'email' => $username . '@prueba.local',
        'role' => 'usuario',
        'cedula' => $cedula,
        'nombre' => $p['nombre'] ?? $username,
        'celular' => null,
        'fechnac' => !empty($p['fechnac']) ? $p['fechnac'] : null,
        'sexo' => in_array($p['sexo'] ?? 'M', ['M', 'F']) ? $p['sexo'] : 'M',
        'club_id' => $club_id,
        'entidad' => 0,
        'status' => 0,
        '_allow_club_for_usuario' => true,
    ];

    $result = Security::createUser($userData);

    if ($result['success']) {
        $creados++;
        echo "Creando usuario {$creados} de {$cantidad}... [{$username}]" . PHP_EOL;
    } else {
        $msg = implode(', ', $result['errors']);
        if (strpos($msg, 'cédula') !== false) {
            // Cédula ya usada: saltar persona y continuar
            continue;
        }
        $errores++;
        echo "Error usuario [{$username}]: {$msg}" . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
// Resumen final
// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 50) . PHP_EOL;
echo "Se crearon {$creados} usuarios vinculados a la Organización {$organizacion_id} ({$organizacion['nombre']})." . PHP_EOL;
if ($errores > 0) {
    echo "Errores: {$errores}." . PHP_EOL;
}
echo "Contraseña de todos los usuarios de prueba: {$password_plano}" . PHP_EOL;
exit($creados > 0 ? 0 : 1);
