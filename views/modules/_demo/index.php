<?php
/**
 * Vista demo Fase 0 — solo usa variables inyectadas en $data (sin $_SESSION ni globals).
 *
 * @var string $mensaje
 * @var string $timestamp
 * @var array<int, array{id: int, nombre: string, estado: string, inscritos: int}> $items
 * @var bool   $showGlobalsWarning
 * @var string $homeHref
 */
$mensaje = $mensaje ?? '';
$timestamp = $timestamp ?? '';
$items = $items ?? [];
$showGlobalsWarning = $showGlobalsWarning ?? true;
$homeHref = $homeHref ?? 'index.php?page=home';
?>
<section class="mx-auto max-w-5xl space-y-4">
  <header class="app-card-dense">
    <p class="text-xs font-medium uppercase tracking-wide text-primary-600">Fase 0 — Piloto</p>
    <h2 class="mt-1 text-lg font-semibold text-slate-900">Motor de vistas aislado</h2>
    <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="mt-1 text-xs text-slate-400">Generado: <?= htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') ?></p>
  </header>

  <?php if ($showGlobalsWarning): ?>
  <aside class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
    Esta plantilla <strong>no</strong> accede a <code class="text-xs">$_SESSION</code>, <code class="text-xs">$user</code> ni otras variables globales.
    Los datos visibles llegan únicamente desde el controlador vía <code class="text-xs">$data</code>.
  </aside>
  <?php endif; ?>

  <article class="app-card-dense overflow-hidden p-0">
    <div class="border-b border-slate-200 px-3 py-2">
      <h3 class="text-sm font-semibold text-slate-800">Torneos de demostración</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="app-table-dense w-full text-left">
        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
          <tr>
            <th scope="col" class="px-2 py-2">ID</th>
            <th scope="col" class="px-2 py-2">Nombre</th>
            <th scope="col" class="px-2 py-2">Estado</th>
            <th scope="col" class="px-2 py-2 text-right">Inscritos</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($items === []): ?>
          <tr>
            <td colspan="4" class="px-2 py-4 text-center text-slate-500">Sin registros en $data['items']</td>
          </tr>
          <?php else: ?>
          <?php foreach ($items as $row): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-2 py-1.5 font-mono text-xs text-slate-600"><?= (int) ($row['id'] ?? 0) ?></td>
            <td class="px-2 py-1.5"><?= htmlspecialchars((string) ($row['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="px-2 py-1.5">
              <span class="inline-flex rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                <?= htmlspecialchars((string) ($row['estado'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>
            <td class="px-2 py-1.5 text-right tabular-nums"><?= (int) ($row['inscritos'] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>

  <footer class="text-center text-xs text-slate-400">
    <a href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>" class="text-primary-600 hover:underline">← Volver al dashboard legacy</a>
  </footer>
</section>
