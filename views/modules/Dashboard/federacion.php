<?php
/**
 * Dashboard Federación — vista aislada (solo $data).
 *
 * @var string $contextType
 * @var string|null $contextRole
 * @var list<array{label: string, value: string, hint: string}> $metrics
 * @var list<array{pos: int, nombre: string, puntos: int|string}> $rankings
 */
$metrics = $metrics ?? [];
$rankings = $rankings ?? [];
?>
<section class="mx-auto max-w-6xl space-y-3">
  <header class="app-card-dense flex flex-wrap items-center justify-between gap-2">
    <div>
      <p class="text-[10px] font-semibold uppercase tracking-wider text-primary-600">Federación</p>
      <h2 class="text-base font-semibold text-slate-900">Panel nacional</h2>
    </div>
    <span class="rounded-full bg-primary-50 px-2 py-0.5 text-[10px] font-medium text-primary-700">
      Rol: <?= htmlspecialchars((string) ($contextRole ?? '—'), ENT_QUOTES, 'UTF-8') ?>
    </span>
  </header>

  <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
    <?php foreach ($metrics as $metric): ?>
    <article class="app-card-dense !p-2.5">
      <p class="text-[10px] font-medium uppercase text-slate-500">
        <?= htmlspecialchars((string) ($metric['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
      </p>
      <p class="mt-0.5 text-xl font-bold tabular-nums text-primary-700">
        <?= htmlspecialchars((string) ($metric['value'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
      </p>
      <p class="mt-0.5 text-[10px] text-slate-400">
        <?= htmlspecialchars((string) ($metric['hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
      </p>
    </article>
    <?php endforeach; ?>
  </div>

  <article class="app-card-dense overflow-hidden !p-0">
    <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
      <h3 class="text-sm font-semibold text-slate-800">Rankings nacionales</h3>
      <span class="text-[10px] text-slate-400">Datos en vivo</span>
    </div>
    <div class="overflow-x-auto">
      <table class="app-table-dense w-full text-left">
        <thead class="bg-slate-50 text-[10px] font-semibold uppercase text-slate-500">
          <tr>
            <th scope="col" class="w-10 px-2 py-1.5">#</th>
            <th scope="col" class="px-2 py-1.5">Atleta / Club</th>
            <th scope="col" class="px-2 py-1.5 text-right">Puntos</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($rankings === []): ?>
          <tr>
            <td colspan="3" class="px-2 py-3 text-center text-xs text-slate-400">
              Sin datos
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($rankings as $row): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-2 py-1 font-mono text-xs text-slate-500"><?= (int) ($row['pos'] ?? 0) ?></td>
            <td class="px-2 py-1"><?= htmlspecialchars((string) ($row['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="px-2 py-1 text-right tabular-nums"><?= htmlspecialchars((string) ($row['puntos'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
