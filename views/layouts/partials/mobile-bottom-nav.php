<?php
/**
 * @var array<int, array{label: string, href: string, icon?: string, active?: bool}>|null $menuMobile
 */
$menuMobile = $menuMobile ?? [];
?>
<div class="flex h-full w-full items-stretch justify-around">
  <?php foreach ($menuMobile as $item): ?>
    <?php
    $isActive = !empty($item['active']);
    $href = (string) ($item['href'] ?? '#');
    $label = (string) ($item['label'] ?? '');
    $icon = (string) ($item['icon'] ?? '○');
    ?>
    <a
      href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
      class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[10px] font-medium
             <?= $isActive ? 'text-primary-600' : 'text-slate-500 hover:text-primary-500' ?>"
      <?= $isActive ? 'aria-current="page"' : '' ?>
    >
      <span class="text-lg leading-none" aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="truncate max-w-[4.5rem]"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
  <?php endforeach; ?>
</div>
