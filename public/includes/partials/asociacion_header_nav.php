<?php
declare(strict_types=1);

/**
 * Menú horizontal minimalista — administrador de asociación.
 *
 * @var callable(string, array<string, mixed>=): string $dashboard_href
 * @var string $current_page
 * @var string $layout_nav_action
 */

if (! isset($dashboard_href, $current_page)) {
    return;
}

require_once __DIR__ . '/../../../lib/AsociacionAdminNav.php';

$nav_action = $layout_nav_action ?? trim((string) ($_GET['action'] ?? ''));
$org_id_nav = AsociacionAdminNav::resolveOrgId();
$nav_items = AsociacionAdminNav::mainNavItems($dashboard_href, $org_id_nav);
?>
<nav class="admin-general-header-nav navbar-nav flex-row align-items-center gap-1 ms-2 ms-lg-3" aria-label="Menú principal">
  <?php foreach ($nav_items as $item): ?>
    <?php
    $key = (string) ($item['key'] ?? '');
    $active = AsociacionAdminNav::isItemActive($key, $current_page, $nav_action);
    ?>
    <div class="nav-item">
      <a
        class="nav-link admin-general-nav-link<?= $active ? ' active' : '' ?>"
        href="<?= htmlspecialchars((string) $item['href']) ?>"
      >
        <?= htmlspecialchars((string) $item['label']) ?>
      </a>
    </div>
  <?php endforeach; ?>
</nav>
