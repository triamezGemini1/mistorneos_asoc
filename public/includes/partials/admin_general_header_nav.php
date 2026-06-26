<?php
declare(strict_types=1);

/**
 * Menú horizontal minimalista — administrador general.
 *
 * @var callable(string, array<string, mixed>=): string $dashboard_href
 * @var callable(string): string $menu_url
 * @var string $current_page
 * @var string $layout_nav_action
 * @var int $solicitudes_pendientes
 */

if (! isset($dashboard_href, $menu_url, $current_page)) {
    return;
}

require_once __DIR__ . '/../../../lib/AdminGeneralNav.php';

$nav_action = $layout_nav_action ?? trim((string) ($_GET['action'] ?? ''));
$nav_items = AdminGeneralNav::mainNavItems($dashboard_href);
$gestion_items = AdminGeneralNav::gestionDropdownItems($dashboard_href, $menu_url);
$solicitudes_badge = (int) ($solicitudes_pendientes ?? 0);
?>
<nav class="admin-general-header-nav navbar-nav flex-row align-items-center gap-1 ms-2 ms-lg-3" aria-label="Menú principal">
  <?php foreach ($nav_items as $item): ?>
    <?php
    $key = (string) ($item['key'] ?? '');
    $active = AdminGeneralNav::isMainItemActive($key, $current_page, $nav_action);
    $is_dropdown = ! empty($item['dropdown']);
    ?>
    <?php if ($is_dropdown): ?>
      <div class="nav-item dropdown admin-general-nav-dropdown">
        <a
          class="nav-link dropdown-toggle admin-general-nav-link<?= $active ? ' active' : '' ?>"
          href="#"
          role="button"
          data-bs-toggle="dropdown"
          aria-expanded="false"
          id="admin-nav-<?= htmlspecialchars($key) ?>"
        >
          <?= htmlspecialchars((string) $item['label']) ?>
        </a>
        <ul class="dropdown-menu shadow-sm" aria-labelledby="admin-nav-<?= htmlspecialchars($key) ?>">
          <?php foreach ($gestion_items as $sub): ?>
            <?php if (! empty($sub['divider_before'])): ?>
              <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li>
              <a
                class="dropdown-item"
                href="<?= htmlspecialchars((string) $sub['href']) ?>"
                <?php if (! empty($sub['external'])): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>
              >
                <?= htmlspecialchars((string) $sub['label']) ?>
                <?php if (! empty($sub['external'])): ?>
                  <i class="fas fa-external-link-alt ms-1 small text-muted" aria-hidden="true"></i>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="nav-item">
        <a
          class="nav-link admin-general-nav-link<?= $active ? ' active' : '' ?>"
          href="<?= htmlspecialchars((string) $item['href']) ?>"
        >
          <?= htmlspecialchars((string) $item['label']) ?>
          <?php if ($key === AdminGeneralNav::KEY_SOLICITUDES && $solicitudes_badge > 0): ?>
            <span class="badge rounded-pill bg-danger ms-1"><?= $solicitudes_badge ?></span>
          <?php endif; ?>
        </a>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
