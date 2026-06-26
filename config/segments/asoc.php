<?php
declare(strict_types=1);

/**
 * Perfil MisTorneos ASOC — administración sectorial por asociaciones.
 */
$orgRaiz = 0;
if (class_exists('Env', false)) {
    $orgRaiz = (int) Env::get('ORG_RAIZ_ID', 0);
}

return [
    'product' => [
        'name' => 'La Estación del Dominó',
        'short_name' => 'La Estación del Dominó',
        'tagline' => 'Sistema integral para la gestión de torneos de dominó',
        'segment_label' => 'ASOC',
    ],
    'instance' => [
        'organizacion_raiz_id' => $orgRaiz > 0 ? $orgRaiz : 1,
        'entidad_nacional_id' => 999,
    ],
    'hierarchy' => [
        'mode' => 'sectorial',
        'eje' => 'organizacion',
        'tipo_org_default' => 0,
    ],
    'labels' => [
        'organizacion' => 'Asociación',
        'organizaciones' => 'Asociaciones',
        'club' => 'Club',
        'clubes' => 'Clubes',
        'admin_sectorial' => 'Administrador de Asociación',
    ],
    'features' => [
        'admin_header_nav' => true,
        'menu_inicio' => true,
        'menu_afiliados' => true,
        'menu_solicitudes' => true,
        'menu_gestion' => true,
        'menu_biblioteca' => true,
        'menu_gestion_calendario' => true,
        'menu_gestion_torneos' => true,
        'menu_gestion_usuarios' => true,
        'hub_asociacion' => true,
        'landing_afiliados_hub' => true,
    ],
    'branding' => [
        'theme_css' => 'assets/css/estacion-hub.css',
        'logo' => 'assets/logo.png',
        'primary_color' => '#1a5276',
        'theme_color' => '#1a5276',
        'meta_description' => 'La Estación del Dominó — Plataforma para torneos de dominó en Venezuela. Consulta asociaciones, resultados y documentos oficiales.',
        'meta_keywords' => 'dominó, torneos dominó, La Estación del Dominó, asociaciones, resultados, Venezuela',
        'landing_meta_title' => 'La Estación del Dominó - Sistema de Gestión de Torneos de Dominó en Venezuela',
        'og_title' => 'La Estación del Dominó - Sistema de Gestión de Torneos',
        'og_description' => 'Plataforma integral para torneos de dominó: asociaciones afiliadas, resultados y documentos oficiales.',
        'contact_email' => 'info@laestaciondeldomino.com',
    ],
];
