<?php
/**
 * Layout maestro — Mobile First + escritorio 13" (scroll solo en main).
 *
 * @var string      $content     HTML del hijo
 * @var string      $title
 * @var string      $assetBase   URL base de public/ (sin barra final)
 * @var array       $menu
 * @var array       $menuMobile
 * @var string|null $dashboardHref
 * @var array|null  $flashMessages
 * @var string|null $userLabel
 */
$title = $title ?? 'Mis Torneos';
$assetBase = rtrim($assetBase ?? '', '/');
$menu = $menu ?? [];
$menuMobile = $menuMobile ?? [];
$dashboardHref = $dashboardHref ?? 'index.php?page=home';
$userLabel = $userLabel ?? '';
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
  <?php require __DIR__ . '/partials/head-meta.php'; ?>
</head>
<body class="h-full overflow-hidden bg-slate-100 font-sans text-slate-800 antialiased">

  <div id="app-shell" class="flex h-full flex-col lg:flex-row">

    <aside
      id="app-sidebar"
      class="hidden lg:flex lg:w-56 lg:shrink-0 lg:flex-col border-r border-primary-800 bg-primary-700 text-white"
      aria-label="Navegación principal"
    >
      <header class="flex h-14 shrink-0 items-center gap-2 border-b border-primary-600 px-3">
        <span class="text-sm font-semibold tracking-tight">Mis Torneos</span>
      </header>
      <nav class="min-h-0 flex-1 overflow-y-auto py-2">
        <?php require __DIR__ . '/partials/sidebar-desktop.php'; ?>
      </nav>
    </aside>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col">

      <header class="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 lg:h-11">
        <button
          type="button"
          id="btn-mobile-menu"
          class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden"
          aria-label="Abrir menú"
          aria-expanded="false"
          aria-controls="mobile-drawer"
        >
          <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
        </button>
        <h1 class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800 lg:text-base">
          <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <?php if ($userLabel !== ''): ?>
        <span class="hidden max-w-[12rem] truncate text-xs text-slate-500 sm:inline">
          <?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php endif; ?>
      </header>

      <div
        id="mobile-drawer"
        class="fixed inset-0 z-40 hidden lg:hidden"
        role="dialog"
        aria-modal="true"
        aria-label="Menú de navegación"
        aria-hidden="true"
      >
        <div class="absolute inset-0 bg-black/40" data-close-drawer tabindex="-1"></div>
        <aside class="absolute left-0 top-0 flex h-full w-72 max-w-[85vw] flex-col bg-primary-700 text-white shadow-xl">
          <header class="flex h-14 items-center border-b border-primary-600 px-3">
            <span class="text-sm font-semibold">Menú</span>
          </header>
          <nav class="flex-1 overflow-y-auto py-2">
            <?php require __DIR__ . '/partials/sidebar-desktop.php'; ?>
          </nav>
        </aside>
      </div>

      <?php require __DIR__ . '/partials/flash-messages.php'; ?>

      <main
        id="app-main"
        class="app-main-13 min-h-0 flex-1 overflow-y-auto overscroll-contain p-3 pb-20 text-sm leading-snug lg:p-4 lg:pb-4"
      >
        <?= $content ?? '' ?>
      </main>

      <nav
        class="fixed bottom-0 left-0 right-0 z-30 flex h-14 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
        aria-label="Navegación inferior"
      >
        <?php require __DIR__ . '/partials/mobile-bottom-nav.php'; ?>
      </nav>
    </div>
  </div>

  <script>
    (function () {
      var btn = document.getElementById('btn-mobile-menu');
      var drawer = document.getElementById('mobile-drawer');
      if (!btn || !drawer) return;

      function openDrawer() {
        drawer.classList.remove('hidden');
        drawer.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
      }

      function closeDrawer() {
        drawer.classList.add('hidden');
        drawer.setAttribute('aria-hidden', 'true');
        btn.setAttribute('aria-expanded', 'false');
      }

      btn.addEventListener('click', openDrawer);
      drawer.querySelector('[data-close-drawer]')?.addEventListener('click', closeDrawer);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDrawer();
      });
    })();
  </script>
</body>
</html>
