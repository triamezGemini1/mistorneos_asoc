<?php
/**
 * Script para crear 120 usuarios de la asociación de dominó del estado Miranda
 * a partir de personas aleatorias de la base de datos externa.
 * 
 * - Distribuye usuarios proporcionalmente en los clubes de Miranda
 * - Entidad: Miranda
 * - Email: iniciales + username @miranda.local
 * - Contraseña: pru12345
 * - Usa la funcionalidad de registro (Security::createUser)
 * 
 * Uso: php scripts/generar_usuarios_miranda_desde_personas.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/persona_database.php';
require_once __DIR__ . '/../lib/Security.php';

$TOTAL_USUARIOS = 120;
$PASSWORD = 'pru12345';
$DOMINIO_EMAIL = '@miranda.local';

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Generador de Usuarios Miranda desde BD Personas\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Obtener código de entidad Miranda
$pdo = DB::pdo();
$entidad_miranda = null;
$entidad_nombre = 'Miranda';

try {
    $cols = $pdo->query("SHOW COLUMNS FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
    $codeCol = null;
    $nameCol = null;
    foreach ($cols as $c) {
        $f = strtolower($c['Field'] ?? '');
        if (!$codeCol && in_array($f, ['codigo', 'cod_entidad', 'id', 'code'], true)) $codeCol = $f;
        if (!$nameCol && in_array($f, ['nombre', 'descripcion', 'entidad', 'nombre_entidad'], true)) $nameCol = $f;
    }
    if ($codeCol && $nameCol) {
        $stmt = $pdo->prepare("SELECT {$codeCol} AS codigo FROM entidad WHERE {$nameCol} LIKE ? OR {$nameCol} LIKE ? LIMIT 1");
        $stmt->execute(['%Miranda%', '%miranda%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $entidad_miranda = $row ? (int)$row['codigo'] : null;
    }
} catch (Exception $e) {
    echo "❌ Error al buscar entidad Miranda: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$entidad_miranda) {
    echo "❌ No se encontró la entidad Miranda en la tabla entidad.\n";
    echo "   Verifique que exista un registro con nombre 'Miranda' o similar.\n";
    exit(1);
}
echo "✅ Entidad Miranda encontrada (código: $entidad_miranda)\n";

// 2. Obtener clubes de Miranda (gestionados por admin_club con entidad Miranda)
$clubes_miranda = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre
        FROM clubes c
        INNER JOIN usuarios u ON c.admin_club_id = u.id
        WHERE u.entidad = ? AND u.role = 'admin_club' AND c.estatus = 1
        ORDER BY c.nombre ASC
    ");
    $stmt->execute([$entidad_miranda]);
    $clubes_miranda = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clubes_miranda = [];
}
if (empty($clubes_miranda)) {
    // Fallback: clubes por admin_club_id o club_id de admin
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.nombre FROM clubes c
            WHERE (c.admin_club_id IN (SELECT id FROM usuarios WHERE role='admin_club' AND entidad=? AND status = 0)
               OR c.id IN (SELECT club_id FROM usuarios WHERE role='admin_club' AND entidad=? AND status = 0))
            AND c.estatus = 1
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$entidad_miranda, $entidad_miranda]);
        $clubes_miranda = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $clubes_miranda = [];
    }
}
if (empty($clubes_miranda)) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.nombre FROM clubes c
            INNER JOIN organizaciones o ON c.cod_org = o.id AND o.estatus = 1
            INNER JOIN usuarios u ON o.admin_user_id = u.id AND u.role = 'admin_club'
            WHERE u.entidad = ? AND c.estatus = 1
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$entidad_miranda]);
        $clubes_miranda = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e3) {
        $clubes_miranda = [];
    }
}

if (empty($clubes_miranda)) {
    echo "❌ No se encontraron clubes de Miranda.\n";
    echo "   Asegúrese de que existan clubes con admin_club_id asignado y entidad = Miranda.\n";
    exit(1);
}
echo "✅ Clubes de Miranda: " . count($clubes_miranda) . "\n";
foreach ($clubes_miranda as $c) {
    echo "   - {$c['nombre']} (ID: {$c['id']})\n";
}
echo "\n";

// 3. Obtener personas aleatorias de la BD externa
$personaDb = new PersonaDatabase();
$personas = $personaDb->getRandomPersonas($TOTAL_USUARIOS * 2); // Pedir más por si hay duplicados

if (empty($personas)) {
    echo "❌ No se pudieron obtener personas de la base de datos externa.\n";
    echo "   Verifique la conexión a la BD 'personas' y que la tabla exista.\n";
    exit(1);
}
echo "✅ Personas obtenidas de BD externa: " . count($personas) . "\n";

// 4. Filtrar personas que ya existen en usuarios (por cédula)
$cedulas_existentes = [];
$stmt_check = $pdo->prepare("SELECT cedula FROM usuarios WHERE cedula = ?");
foreach ($personas as $p) {
    $cedula = ($p['nac'] ?? 'V') . ($p['id_usuario'] ?? '');
    $stmt_check->execute([$cedula]);
    if ($stmt_check->fetch()) {
        $cedulas_existentes[$cedula] = true;
    }
}
$personas = array_filter($personas, function ($p) use ($cedulas_existentes) {
    $cedula = ($p['nac'] ?? 'V') . ($p['id_usuario'] ?? '');
    return !isset($cedulas_existentes[$cedula]);
});
$personas = array_values($personas);

if (count($personas) < $TOTAL_USUARIOS) {
    echo "⚠️  Solo hay " . count($personas) . " personas no registradas (se solicitaban $TOTAL_USUARIOS)\n";
    $TOTAL_USUARIOS = count($personas);
} else {
    $personas = array_slice($personas, 0, $TOTAL_USUARIOS);
}
echo "✅ Personas a registrar: $TOTAL_USUARIOS\n\n";

// 5. Distribución proporcional (igual) entre clubes
$num_clubes = count($clubes_miranda);
$por_club = (int)floor($TOTAL_USUARIOS / $num_clubes);
$resto = $TOTAL_USUARIOS % $num_clubes;
$asignacion = [];
for ($i = 0; $i < $num_clubes; $i++) {
    $asignacion[$i] = $por_club + ($i < $resto ? 1 : 0);
}
echo "📊 Distribución por club: ";
foreach ($clubes_miranda as $i => $c) {
    echo "{$c['nombre']}=" . ($asignacion[$i] ?? 0) . " ";
}
echo "\n\n";

// 6. Generar username e email desde nombre
function generarUsername($nombre, $cedula, $usados) {
    $nombre = preg_replace('/\s+/', ' ', trim($nombre));
    $partes = explode(' ', $nombre);
    $iniciales = '';
    foreach ($partes as $p) {
        if (strlen($p) > 0) {
            $iniciales .= mb_substr($p, 0, 1);
        }
    }
    $iniciales = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $iniciales));
    $iniciales = preg_replace('/[^a-z]/', '', $iniciales);
    if (strlen($iniciales) < 2) $iniciales = 'usr';
    $sufijo = substr(preg_replace('/\D/', '', $cedula), -4) ?: rand(1000, 9999);
    $base = $iniciales . $sufijo;
    $username = $base;
    $contador = 0;
    while (isset($usados[$username]) && $contador < 100) {
        $username = $base . ($contador > 0 ? $contador : '');
        $contador++;
    }
    return $username;
}

// 7. Crear usuarios usando Security::createUser
$generados = 0;
$errores = 0;
$usernames_usados = [];
$club_idx = 0;
$personas_por_club = 0;
$limite_club = $asignacion[0] ?? 0;

echo "🔄 Creando usuarios...\n\n";

foreach ($personas as $i => $persona) {
    if ($personas_por_club >= $limite_club) {
        $club_idx++;
        $personas_por_club = 0;
        $limite_club = $asignacion[$club_idx] ?? 0;
    }
    $club_id = (int)($clubes_miranda[$club_idx]['id'] ?? 0);
    $personas_por_club++;

    $cedula = ($persona['nac'] ?? 'V') . ($persona['id_usuario'] ?? '');
    $nombre = $persona['nombre'] ?? 'Sin nombre';
    $username = generarUsername($nombre, $cedula, $usernames_usados);
    $usernames_usados[$username] = true;

    $iniciales = '';
    $partes = explode(' ', preg_replace('/\s+/', ' ', trim($nombre)));
    foreach ($partes as $p) {
        if (strlen($p) > 0) $iniciales .= mb_substr($p, 0, 1);
    }
    $iniciales = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $iniciales));
    $iniciales = preg_replace('/[^a-z]/', '', $iniciales);
    if (strlen($iniciales) < 2) $iniciales = 'us';
    $email = $iniciales . $username . $DOMINIO_EMAIL;

    $userData = [
        'username' => $username,
        'password' => $PASSWORD,
        'email' => $email,
        'role' => 'usuario',
        'cedula' => $cedula,
        'nombre' => $nombre,
        'celular' => null,
        'fechnac' => $persona['fechnac'] ?? null,
        'sexo' => in_array($persona['sexo'] ?? 'M', ['M', 'F']) ? $persona['sexo'] : 'M',
        'club_id' => $club_id,
        'entidad' => $entidad_miranda,
        'status' => 0,
        '_allow_club_for_usuario' => true
    ];

    $result = Security::createUser($userData);

    if ($result['success']) {
        $generados++;
        $club_nombre = $clubes_miranda[$club_idx]['nombre'] ?? 'N/A';
        echo "  ✅ [$generados/$TOTAL_USUARIOS] $nombre | $username | $club_nombre\n";
    } else {
        $errores++;
        echo "  ❌ Error: $nombre - " . implode(', ', $result['errors']) . "\n";
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Proceso completado\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Usuarios creados: $generados\n";
echo "  Errores: $errores\n";
echo "\n";
echo "  📋 Credenciales:\n";
echo "     - Usuario: [iniciales]+[sufijo] (ej: jcpr5678)\n";
echo "     - Contraseña: $PASSWORD\n";
echo "     - Email: [iniciales][username]$DOMINIO_EMAIL\n";
echo "     - Entidad: Miranda ($entidad_nombre)\n";
echo "     - Clubes: distribuidos proporcionalmente\n";
echo "\n";
