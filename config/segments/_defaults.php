<?php
declare(strict_types=1);

/**
 * Valores por defecto de perfil de segmento (todos los despliegues MisTorneos).
 */
return [
    'product' => [
        'name' => 'MisTorneos',
        'short_name' => 'MisTorneos',
        'tagline' => 'Gestión de torneos',
        'segment_label' => '',
    ],
    'instance' => [
        'organizacion_raiz_id' => 0,
        'entidad_nacional_id' => 0,
    ],
    'hierarchy' => [
        'mode' => 'sectorial',
        'eje' => 'organizacion',
        'tipo_org_default' => 0,
    ],
    'labels' => [
        'organizacion' => 'Organización',
        'organizaciones' => 'Organizaciones',
        'club' => 'Club',
        'clubes' => 'Clubes',
        'admin_sectorial' => 'Administrador sectorial',
    ],
    'features' => [
        'admin_header_nav' => false,
        'menu_inicio' => true,
        'menu_afiliados' => true,
        'menu_solicitudes' => true,
        'menu_gestion' => true,
        'menu_biblioteca' => true,
        'menu_gestion_calendario' => true,
        'menu_gestion_entidades' => false,
        'menu_gestion_clubes' => false,
        'menu_gestion_torneos' => true,
        'menu_gestion_usuarios' => true,
        'menu_gestion_invitar' => false,
        'menu_gestion_banner' => false,
        'menu_gestion_notificaciones' => false,
        'menu_gestion_whatsapp' => false,
        'menu_gestion_comentarios' => false,
        'menu_gestion_integraciones' => false,
        'menu_gestion_portal' => false,
        'hub_asociacion' => false,
        'landing_afiliados_hub' => false,
    ],
    'branding' => [
        'logo' => null,
        'favicon' => null,
        'theme_css' => null,
        'primary_color' => '#1a5276',
        'theme_color' => '#1a365d',
        'meta_description' => 'Plataforma integral para la gestión de torneos de dominó. Participa en eventos, consulta resultados e inscríbete en torneos.',
        'meta_keywords' => 'dominó, torneos dominó, torneos, campeonatos, clubes dominó, resultados dominó, inscripciones torneos',
        'landing_meta_title' => null,
        'og_title' => null,
        'og_description' => null,
        'contact_email' => 'info@laestaciondeldomino.com',
    ],
];
