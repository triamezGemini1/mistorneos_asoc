<?php
declare(strict_types=1);

/** Perfil MisTorneos Eventos — torneos sin capa club obligatoria. */
return [
    'product' => [
        'name' => 'MisTorneos Eventos',
        'short_name' => 'MisTorneos',
        'tagline' => 'Organización de eventos y torneos',
        'segment_label' => 'Eventos',
    ],
    'hierarchy' => [
        'mode' => 'evento_plano',
        'eje' => 'evento',
        'tipo_org_default' => 1,
    ],
    'labels' => [
        'organizacion' => 'Organizador',
        'organizaciones' => 'Organizadores',
        'club' => 'Sede',
        'clubes' => 'Sedes',
        'admin_sectorial' => 'Organizador de evento',
    ],
    'features' => [
        'admin_header_nav' => false,
        'hub_asociacion' => false,
    ],
];
