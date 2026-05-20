<?php
/**
 * Dashboard Asociación — vista aislada (solo $data).
 *
 * @var string $contextType
 * @var string|null $contextRole
 * @var string $orgNombre
 * @var int $clubesCount
 * @var int $atletasCount
 * @var int $torneosActivos
 * @var list<array{id: int, nombre: string, delegado: string, estatus: string}> $clubes
 */
$orgNombre = $orgNombre ?? 'Asociación';
$clubesCount = (int) ($clubesCount ?? 0);
$atletasCount = (int) ($atletasCount ?? 0);
$torneosActivos = (int) ($torneosActivos ?? 0);
$clubes = $clubes ?? [];
?>
<section class="mx-auto max-w-6xl space-y-3">
  <header class="app-card-dense">
    <p class="text-[10px] font-semibold uppercase tracking-wider text-primary-600">Asociación</p>
    <h2 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($orgNombre, ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="mt-1 text-xs text-slate-500">Clubes afiliados y atletas del territorio</p>
  </header>

  <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
    <article class="app-card-dense !p-2.5">
      <p class="text-[10px] font-medium uppercase text-slate-500">Clubes</p>
      <p class="mt-0.5 text-xl font-bold tabular-nums text-primary-700">
        <?= $clubesCount ?>
      </p>
    </article>
    <article class="app-card-dense !p-2.5">
      <p class="text-[10px] font-medium uppercase text-slate-500">Atletas</p>
      <p class="mt-0.5 text-xl font-bold tabular-nums text-primary-700">
        <?= $atletasCount ?>
      </p>
    </article>
    <article class="app-card-dense !p-2.5">
      <p class="text-[10px] font-medium uppercase text-slate-500">Torneos activos</p>
      <p class="mt-0.5 text-xl font-bold tabular-nums text-primary-700">
        <?= $torneosActivos ?>
      </p>
    </article>
  </div>

  <article class="app-card-dense overflow-hidden !p-0">
    <div class="border-b border-slate-200 px-3 py-2">
      <h3 class="text-sm font-semibold text-slate-800">Clubes afiliados</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="app-table-dense w-full text-left">
        <thead class="bg-slate-50 text-[10px] font-semibold uppercase text-slate-500">
          <tr>
            <th scope="col" class="w-10 px-2 py-1.5">ID</th>
            <th scope="col" class="px-2 py-1.5">Club</th>
            <th scope="col" class="px-2 py-1.5">Delegado</th>
            <th scope="col" class="px-2 py-1.5">Estatus</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($clubes === []): ?>
          <tr>
            <td colspan="4" class="px-2 py-3 text-center text-xs text-slate-400">
              Sin datos
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($clubes as $club): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-2 py-1 font-mono text-xs"><?= (int) ($club['id'] ?? 0) ?></td>
            <td class="px-2 py-1"><?= htmlspecialchars((string) ($club['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="px-2 py-1 text-slate-600"><?= htmlspecialchars((string) ($club['delegado'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="px-2 py-1">
              <span class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800">
                <?= htmlspecialchars((string) ($club['estatus'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
