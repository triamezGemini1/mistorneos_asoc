<?php
/**
 * Dashboard Club — vista aislada (solo $data).
 *
 * @var string $contextType
 * @var string|null $contextRole
 * @var string $clubNombre
 * @var string $orgNombre
 * @var list<array{label: string, href: string, disabled: bool}> $quickActions
 * @var int $mesasPendientes
 * @var list<array{id: int, nombre: string, fechator: string|null}> $torneosActivos
 * @var list<array{mesa: int, ronda: int, estado: string, torneo_nombre?: string}> $mesas
 */
$clubNombre = $clubNombre ?? 'Club';
$orgNombre = $orgNombre ?? '';
$quickActions = $quickActions ?? [];
$mesasPendientes = (int) ($mesasPendientes ?? 0);
$torneosActivos = $torneosActivos ?? [];
$mesas = $mesas ?? [];
?>
<section class="mx-auto max-w-6xl space-y-3">
  <header class="app-card-dense">
    <p class="text-[10px] font-semibold uppercase tracking-wider text-primary-600">Club</p>
    <h2 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($clubNombre, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($orgNombre !== '' && $orgNombre !== $clubNombre): ?>
    <p class="mt-0.5 text-xs text-slate-500"><?= htmlspecialchars($orgNombre, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </header>

  <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
    <?php foreach ($quickActions as $action): ?>
    <?php $disabled = !empty($action['disabled']); ?>
    <a
      href="<?= $disabled ? '#' : htmlspecialchars((string) ($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
      class="app-card-dense flex min-h-[48px] items-center justify-center text-center text-sm font-medium transition-colors
             <?= $disabled
                 ? 'cursor-not-allowed border-dashed text-slate-400 opacity-70'
                 : 'text-primary-700 hover:border-primary-300 hover:bg-primary-50' ?>"
      <?= $disabled ? 'aria-disabled="true" tabindex="-1"' : '' ?>
    >
      <?= htmlspecialchars((string) ($action['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endforeach; ?>
  </div>

  <article class="app-card-dense overflow-hidden !p-0">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
      <h3 class="text-sm font-semibold text-slate-800">Mesas del día</h3>
      <span class="text-[10px] text-slate-500">
        Pendientes:
        <strong class="text-slate-800"><?= $mesasPendientes ?></strong>
      </span>
    </div>
    <div class="overflow-x-auto">
      <table class="app-table-dense w-full text-left">
        <thead class="bg-slate-50 text-[10px] font-semibold uppercase text-slate-500">
          <tr>
            <th scope="col" class="w-14 px-2 py-1.5">Mesa</th>
            <th scope="col" class="w-14 px-2 py-1.5">Ronda</th>
            <th scope="col" class="px-2 py-1.5">Estado</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($mesas === []): ?>
          <tr>
            <td colspan="3" class="px-2 py-3 text-center text-xs text-slate-400">
              Sin datos
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($mesas as $mesa): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-2 py-1 font-mono text-xs"><?= (int) ($mesa['mesa'] ?? 0) ?></td>
            <td class="px-2 py-1 font-mono text-xs"><?= (int) ($mesa['ronda'] ?? 0) ?></td>
            <td class="px-2 py-1">
              <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-700">
                <?= htmlspecialchars((string) ($mesa['estado'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </span>
              <?php if (!empty($mesa['torneo_nombre'])): ?>
              <span class="ml-1 text-[10px] text-slate-400">
                <?= htmlspecialchars((string) $mesa['torneo_nombre'], ENT_QUOTES, 'UTF-8') ?>
              </span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
