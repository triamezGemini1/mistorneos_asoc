<?php
/**
 * Mensajes flash opcionales pasados explícitamente en $layoutData (sin leer $_SESSION aquí).
 *
 * @var array<int, array{type: string, message: string}>|null $flashMessages
 */
$flashMessages = $flashMessages ?? [];
if ($flashMessages === []) {
    return;
}
$typeClasses = [
    'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
    'error'   => 'border-red-200 bg-red-50 text-red-900',
    'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
    'info'    => 'border-sky-200 bg-sky-50 text-sky-900',
];
?>
<div class="pointer-events-none fixed left-0 right-0 top-14 z-50 flex flex-col gap-2 px-3 lg:top-12 lg:left-56" aria-live="polite">
  <?php foreach ($flashMessages as $flash): ?>
    <?php
    $type = (string) ($flash['type'] ?? 'info');
    $message = (string) ($flash['message'] ?? '');
    $classes = $typeClasses[$type] ?? $typeClasses['info'];
    ?>
    <div class="pointer-events-auto rounded-lg border px-3 py-2 text-sm shadow-sm <?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>" role="alert">
      <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endforeach; ?>
</div>
