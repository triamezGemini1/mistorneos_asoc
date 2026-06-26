<?php
/**
 * API Landing - Datos para la SPA de la landing page
 * GET: Retorna todos los datos necesarios para renderizar la landing
 * Usa LandingAfiliadosService y datos estáticos del sitio.
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../lib/app_helpers.php';
require_once __DIR__ . '/../../lib/UrlHelper.php';
require_once __DIR__ . '/../../lib/LandingAfiliadosService.php';
require_once __DIR__ . '/../../lib/LandingAfiliadosAccess.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// URL base absoluta (misma que la web) para que logos e imágenes carguen en cualquier entorno
$baseUrl = rtrim(class_exists('AppHelpers') ? AppHelpers::getPublicUrl() : (rtrim(app_base_url(), '/') . '/public'), '/') . '/';
$entidadParam = isset($_GET['entidad']) ? (int)$_GET['entidad'] : 0;

/** TTL caché landing (segundos): TTFB bajo en calientes */
const LANDING_DATA_CACHE_TTL = 90;

/**
 * Clave de caché estable por entidad + usuario (la respuesta depende de ambos).
 */
function landingDataCacheKey(int $entidadParam): string
{
    $uid = 0;
    try {
        $uid = (int)(Auth::id() ?: 0);
    } catch (Throwable $e) {
        $uid = 0;
    }
    $role = '';
    try {
        $u = Auth::user();
        $role = (string)($u['role'] ?? '');
    } catch (Throwable $e) {
    }

    return 'landing_data_v3_' . hash('sha256', json_encode([$entidadParam, $uid, $role]));
}

function landingDataCacheGet(string $key): ?array
{
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch($key);
        if (is_array($v) && isset($v['exp'], $v['payload']) && $v['exp'] >= time()) {
            return $v['payload'];
        }
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    if (!is_readable($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta) || !isset($meta['exp'], $meta['payload']) || $meta['exp'] < time()) {
        return null;
    }

    return $meta['payload'];
}

function landingDataCacheSet(string $key, array $payload): void
{
    $wrapped = ['exp' => time() + LANDING_DATA_CACHE_TTL, 'payload' => $payload];
    if (function_exists('apcu_store')) {
        @apcu_store($key, $wrapped, LANDING_DATA_CACHE_TTL + 5);
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    @file_put_contents($file, json_encode($wrapped), LOCK_EX);
}

function landingDocumentDisplayTitle(string $baseName): string
{
    $slug = strtolower($baseName);
    $slug = str_replace(['__', ' '], '_', $slug);
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? $slug;

    if (str_contains($slug, 'circuito') && str_contains($slug, '2026')) {
        return 'Circuito 2026';
    }
    if (str_contains($slug, 'reglamento') && str_contains($slug, '2024')) {
        return 'Reglamento 2024';
    }
    if (str_contains($slug, 'invitaci') && (str_contains($slug, 'mundial') || str_contains($slug, 'esp'))) {
        return 'Mundial España 2026';
    }
    if (str_contains($slug, 'clasificacion') && str_contains($slug, 'final')) {
        return 'Clasificación final';
    }

    $human = trim(str_replace(['_', '-'], ' ', $baseName));
    $human = preg_replace('/\s+/', ' ', $human) ?? $human;

    return $human !== '' ? ucwords($human) : $baseName;
}

function landingDocumentEntry(string $pathRel, string $filename, string $tipo = 'oficial'): array
{
    $baseName = pathinfo($filename, PATHINFO_FILENAME);

    return [
        'titulo' => landingDocumentDisplayTitle($baseName),
        'path' => $pathRel,
        'archivo' => $filename,
        'tipo' => $tipo,
    ];
}

try {
    $pdo = DB::pdo();
    $user = Auth::user();
    $skipCache = isset($_GET['nocache']) && $_GET['nocache'] === '1';

    if (!$skipCache) {
        $cacheKey = landingDataCacheKey($entidadParam);
        $cachedPayload = landingDataCacheGet($cacheKey);
        if (is_array($cachedPayload)) {
            $cachedPayload['csrf_token'] = CSRF::token();
            $cachedPayload['user'] = $user ? [
                'id' => Auth::id() ?: null,
                'nombre' => $user['nombre'] ?? $user['username'] ?? '',
                'username' => $user['username'] ?? '',
            ] : null;
            header('X-Landing-Cache: HIT');
            echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Comentarios aprobados
    $comentarios = [];
    try {
        $comentarios = $pdo->query("
            SELECT c.*, u.username as usuario_username, u.nombre as usuario_nombre
            FROM comentariossugerencias c
            LEFT JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.estatus = 'aprobado'
            ORDER BY c.fecha_creacion DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Logos de clientes: desde clubes + upload/logos (fallback) + upload/logos_clientes/
    // Incluir 'url' absoluta para que el frontend muestre la imagen sin depender de baseUrl
    $logos_clientes = [];
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, logo FROM clubes WHERE logo IS NOT NULL AND logo != '' AND (estatus = 1 OR estatus = '1') ORDER BY nombre ASC");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = trim((string)($row['logo'] ?? ''));
            if ($path !== '') {
                $url = class_exists('AppHelpers') ? AppHelpers::imageUrl($path) : ($baseUrl . 'view_image.php?path=' . rawurlencode($path));
                $logos_clientes[] = ['nombre' => $row['nombre'] ?? 'Club', 'path' => $path, 'url' => $url];
            }
        }
    } catch (Exception $e) {}
    if (empty($logos_clientes)) {
        $upload_logos_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'logos';
        $extensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
        if (is_dir($upload_logos_dir)) {
            foreach (new DirectoryIterator($upload_logos_dir) as $f) {
                if ($f->isDot() || !$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $path = 'upload/logos/' . $f->getFilename();
                    $url = class_exists('AppHelpers') ? AppHelpers::imageUrl($path) : ($baseUrl . 'view_image.php?path=' . rawurlencode($path));
                    $logos_clientes[] = ['nombre' => pathinfo($f->getFilename(), PATHINFO_FILENAME), 'path' => $path, 'url' => $url];
                }
            }
        }
    }
    $logos_clientes_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'logos_clientes';
    if (is_dir($logos_clientes_dir)) {
        foreach (new DirectoryIterator($logos_clientes_dir) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                $path = 'upload/logos_clientes/' . $f->getFilename();
                $url = class_exists('AppHelpers') ? AppHelpers::imageUrl($path) : ($baseUrl . 'view_image.php?path=' . rawurlencode($path));
                $logos_clientes[] = ['nombre' => pathinfo($f->getFilename(), PATHINFO_FILENAME), 'path' => $path, 'url' => $url];
            }
        }
    }

    // Documentos oficiales de dominó (upload/documentos_oficiales/)
    $documentos_oficiales = [];
    $doc_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'documentos_oficiales';
    $doc_extensions = ['pdf', 'doc', 'docx'];
    if (is_dir($doc_dir)) {
        foreach (new DirectoryIterator($doc_dir) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, $doc_extensions, true)) {
                $path_rel = 'upload/documentos_oficiales/' . $f->getFilename();
                $documentos_oficiales[] = landingDocumentEntry($path_rel, $f->getFilename(), 'oficial');
            }
        }
    }

    $invitaciones_fvd = [];
    $inv_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'invitaciones_fvd';
    $inv_extensions = ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'];
    if (is_dir($inv_dir)) {
        foreach (new DirectoryIterator($inv_dir) as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, $inv_extensions, true)) {
                $path_rel = 'upload/invitaciones_fvd/' . $f->getFilename();
                $invitaciones_fvd[] = landingDocumentEntry($path_rel, $f->getFilename(), 'invitacion');
            }
        }
    }

    $documentos_descarga = array_values(array_merge($documentos_oficiales, $invitaciones_fvd));

    $csrf_token = CSRF::token();

    $landingAccess = LandingAfiliadosAccess::context();
    $afiliados = [];
    if (class_exists('SegmentConfig', false) && SegmentConfig::feature('landing_afiliados_hub')) {
        try {
            $afiliadosService = new LandingAfiliadosService($pdo);
            // Tarjetas del landing: resumen público (logo, entidad, contadores) sin datos de contacto.
            $afiliados = $afiliadosService->listAfiliadosActivos(false);
        } catch (Throwable $e) {
            error_log('landing_data afiliados: ' . $e->getMessage());
        }
    }

    $response = [
        'success' => true,
        'base_url' => $baseUrl,
        'show_afiliados_hub' => class_exists('SegmentConfig', false) && SegmentConfig::feature('landing_afiliados_hub'),
        'landing_access' => [
            'tipo' => $landingAccess['tipo'] ?? 'invitado',
            'es_admin' => ! empty($landingAccess['es_admin']),
        ],
        'user' => $user ? [
            'id' => Auth::id() ?: null,
            'nombre' => $user['nombre'] ?? $user['username'] ?? '',
            'username' => $user['username'] ?? '',
        ] : null,
        'csrf_token' => $csrf_token,
        'comentarios' => $comentarios,
        'logos_clientes' => $logos_clientes,
        'documentos_oficiales' => $documentos_oficiales,
        'invitaciones_fvd' => $invitaciones_fvd,
        'documentos_descarga' => $documentos_descarga,
        'afiliados' => $afiliados,
    ];

    if (!$skipCache) {
        $toStore = $response;
        $toStore['csrf_token'] = '';
        $toStore['user'] = null;
        landingDataCacheSet(landingDataCacheKey($entidadParam), $toStore);
    }

    header('X-Landing-Cache: MISS');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("API landing_data: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar los datos',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
