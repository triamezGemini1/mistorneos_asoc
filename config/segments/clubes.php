<?php
declare(strict_types=1);

/** Perfil MisTorneos Clubes — eje en clubes. */
return [
    'product' => [
        'name' => 'MisTorneos Clubes',
        'short_name' => 'MisTorneos',
        'tagline' => 'Gestión de clubes y torneos',
        'segment_label' => 'Clubes',
    ],
    'hierarchy' => [
        'mode' => 'club_centric',
        'eje' => 'club',
        'tipo_org_default' => 1,
    ],
    'labels' => [
        'organizacion' => 'Club',
        'organizaciones' => 'Clubes',
        'club' => 'Club',
        'clubes' => 'Clubes',
        'admin_sectorial' => 'Administrador de Club',
    ],
    'features' => [
        'admin_header_nav' => false,
        'hub_asociacion' => false,
    ],
];
