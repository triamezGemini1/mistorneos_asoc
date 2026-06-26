<?php
declare(strict_types=1);

/** Perfil MisTorneos Academias. */
return [
    'product' => [
        'name' => 'MisTorneos Academias',
        'short_name' => 'MisTorneos',
        'tagline' => 'Gestión de academias y formación',
        'segment_label' => 'Academias',
    ],
    'hierarchy' => [
        'mode' => 'sectorial',
        'eje' => 'organizacion',
        'tipo_org_default' => 0,
    ],
    'labels' => [
        'organizacion' => 'Academia',
        'organizaciones' => 'Academias',
        'club' => 'Academia',
        'clubes' => 'Academias',
        'admin_sectorial' => 'Administrador de Academia',
    ],
    'features' => [
        'admin_header_nav' => false,
        'hub_asociacion' => true,
    ],
];
