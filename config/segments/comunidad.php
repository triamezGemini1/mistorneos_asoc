<?php
declare(strict_types=1);

/** Perfil MisTorneos Comunidad — portal e inscripción. */
return [
    'product' => [
        'name' => 'MisTorneos Comunidad',
        'short_name' => 'MisTorneos',
        'tagline' => 'Comunidad de jugadores y eventos',
        'segment_label' => 'Comunidad',
    ],
    'hierarchy' => [
        'mode' => 'comunidad',
        'eje' => 'organizacion',
        'tipo_org_default' => 0,
    ],
    'labels' => [
        'organizacion' => 'Comunidad',
        'organizaciones' => 'Comunidades',
        'club' => 'Grupo',
        'clubes' => 'Grupos',
        'admin_sectorial' => 'Moderador',
    ],
    'features' => [
        'admin_header_nav' => false,
        'hub_asociacion' => false,
        'menu_gestion_torneos' => false,
    ],
];
