<?php
/**
 * Menú de Anclaje Rápido - Navegación lateral flotante con iconos
 * Permite saltar entre secciones sin scroll manual
 */
$side_nav_sections = [
    ['id' => 'registro', 'icon' => 'fa-user-plus', 'label' => 'Registro'],
    ['id' => 'calendario', 'icon' => 'fa-calendar-alt', 'label' => 'Calendario'],
    ['id' => 'servicios', 'icon' => 'fa-cogs', 'label' => 'Servicios'],
    ['id' => 'precios', 'icon' => 'fa-tag', 'label' => 'Precios'],
    ['id' => 'galeria', 'icon' => 'fa-images', 'label' => 'Galería'],
    ['id' => 'faq', 'icon' => 'fa-question-circle', 'label' => 'FAQ'],
    ['id' => 'comentarios', 'icon' => 'fa-comments', 'label' => 'Comentarios'],
    ['id' => 'contacto', 'icon' => 'fa-envelope', 'label' => 'Contacto'],
];
?>
<nav id="side-nav" class="fixed right-3 top-1/2 -translate-y-1/2 flex flex-col gap-2 rounded-2xl p-2 shadow-2xl border border-[#38a169]" style="background-color: #48bb78; z-index: 9999;" aria-label="Anclaje rápido">
    <?php foreach ($side_nav_sections as $item): ?>
    <a href="#<?= htmlspecialchars($item['id']) ?>" 
       class="side-nav-link flex items-center justify-center w-9 h-9 rounded-full bg-white/25 hover:bg-white text-white hover:text-primary-700 shadow-md hover:shadow-lg transition-all duration-200 group"
       title="<?= htmlspecialchars($item['label']) ?>">
        <i class="fas <?= htmlspecialchars($item['icon']) ?> text-sm"></i>
        <span class="absolute right-full mr-2 px-2 py-1 text-xs font-medium text-white bg-gray-900 rounded opacity-0 group-hover:opacity-100 pointer-events-none whitespace-nowrap transition-opacity duration-200">
            <?= htmlspecialchars($item['label']) ?>
        </span>
    </a>
    <?php endforeach; ?>
</nav>
