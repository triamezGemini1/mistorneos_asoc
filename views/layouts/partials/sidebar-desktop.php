<?php
/**
 * @var array<int, array{label: string, href: string, icon?: string, active?: bool}>|null $menu
 * @var string|null $dashboardHref
 */
$menu = $menu ?? [];
$dashboardHref = $dashboardHref ?? 'index.php?page=home';
?>
<ul class="space-y-0.5 px-2" role="list">
  <?php foreach ($menu as $item): ?>
    <?php
    $isActive = !empty($item['active']);
    $href = (string) ($item['href'] ?? '#');
    $label = (string) ($item['label'] ?? '');
    $icon = (string) ($item['icon'] ?? '•');
    ?>
    <li>
      <a
        href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
        class="flex min-h-[44px] items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors
               <?= $isActive
                   ? 'bg-primary-600 text-white'
                   : 'text-primary-100 hover:bg-primary-600/80 hover:text-white' ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <span class="w-5 shrink-0 text-center text-xs opacity-90" aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="truncate"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
<?php if ($menu === []): ?>
  <p class="px-3 py-2 text-xs text-primary-200">Sin ítems de menú configurados.</p>
<?php endif; ?>
