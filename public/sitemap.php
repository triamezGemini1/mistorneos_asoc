<?php
/**
 * Sitemap XML Dinámico
 * Genera un sitemap actualizado con todos los torneos públicos
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$base_url = app_base_url();
$pdo = DB::pdo();

// Obtener torneos públicos
$torneos = [];
try {
    $stmt = $pdo->query("
        SELECT id, nombre, fechator, updated_at 
        FROM tournaments 
        WHERE estatus = 1 
        ORDER BY fechator DESC 
        LIMIT 500
    ");
    $torneos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error generando sitemap: " . $e->getMessage());
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- Páginas Principales -->
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/landing.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/resultados.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/ranking_atletas.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.85</priority>
    </url>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/login.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/register_by_club.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/affiliate_request.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    
    <!-- Torneos -->
    <?php foreach ($torneos as $torneo): ?>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/torneo_detalle.php?torneo_id=<?= $torneo['id'] ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($torneo['updated_at'] ?? $torneo['fechator'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= htmlspecialchars($base_url) ?>/public/evento_resultados.php?torneo_id=<?= $torneo['id'] ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($torneo['updated_at'] ?? $torneo['fechator'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    <?php endforeach; ?>
</urlset>











