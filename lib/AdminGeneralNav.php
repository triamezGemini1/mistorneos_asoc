<?php
declare(strict_types=1);

/**
 * Navegación minimalista del administrador general (header).
 * Las rutas legacy del sidebar se conservan en layout.php (ocultas).
 */
final class AdminGeneralNav
{
    public const KEY_INICIO = 'inicio';
    public const KEY_AFILIADOS = 'afiliados';
    public const KEY_SOLICITUDES = 'solicitudes';
    public const KEY_GESTION = 'gestion';
    public const KEY_BIBLIOTECA = 'biblioteca';

    public static function useHeaderNav(string $roleOriginal, string $roleActivo): bool
    {
        if ($roleOriginal !== 'admin_general' || $roleActivo !== 'admin_general') {
            return false;
        }

        if (! class_exists('SegmentConfig', false)) {
            require_once __DIR__ . '/SegmentConfig.php';
        }

        return SegmentConfig::feature('admin_header_nav');
    }

    /**
     * @return list<string>
     */
    public static function pagesForMainItem(string $key): array
    {
        switch ($key) {
            case self::KEY_INICIO:
                return ['home', 'calendario'];
            case self::KEY_AFILIADOS:
                return ['listado_asociaciones', 'asociacion_hub'];
            case self::KEY_SOLICITUDES:
                return ['affiliate_requests'];
            case self::KEY_BIBLIOTECA:
                return ['archivos_web'];
            default:
                return [];
        }
    }

    /**
     * @return list<string>
     */
    public static function gestionPages(): array
    {
        return [
            'calendario',
            'entidades',
            'clubs',
            'organizaciones',
            'torneo_gestion',
            'torneos_estructura',
            'users',
            'admin_clubs',
            'bannerclock',
            'notificaciones_masivas',
            'whatsapp_config',
            'comments',
            'comments_public',
            'admin_atletas_sync',
            'importacion_torneo_externo',
            'torneo_split_ranking',
            'control_admin',
            'estadisticas_torneos',
        ];
    }

    public static function isMainItemActive(string $key, string $currentPage, string $action = ''): bool
    {
        if ($key === self::KEY_GESTION) {
            return self::isGestionActive($currentPage, $action);
        }

        return in_array($currentPage, self::pagesForMainItem($key), true);
    }

    public static function isGestionActive(string $currentPage, string $action = ''): bool
    {
        if (! in_array($currentPage, self::gestionPages(), true)) {
            return false;
        }
        if ($currentPage === 'admin_clubs' && $action === 'invitar') {
            return true;
        }
        if ($currentPage === 'affiliate_requests') {
            return false;
        }

        return true;
    }

    /**
     * @param callable(string, array<string, mixed>=): string $dashboardHref
     *
     * @return list<array<string, mixed>>
     */
    public static function mainNavItems(callable $dashboardHref): array
    {
        if (! class_exists('SegmentConfig', false)) {
            require_once __DIR__ . '/SegmentConfig.php';
        }

        $all = [
            [
                'key' => self::KEY_INICIO,
                'label' => 'Inicio',
                'href' => $dashboardHref('home'),
                'dropdown' => false,
                'feature' => 'menu_inicio',
            ],
            [
                'key' => self::KEY_AFILIADOS,
                'label' => 'Afiliados',
                'href' => $dashboardHref('listado_asociaciones'),
                'dropdown' => false,
                'feature' => 'menu_afiliados',
            ],
            [
                'key' => self::KEY_SOLICITUDES,
                'label' => 'Solicitudes',
                'href' => $dashboardHref('affiliate_requests'),
                'dropdown' => false,
                'feature' => 'menu_solicitudes',
            ],
            [
                'key' => self::KEY_GESTION,
                'label' => 'Gestión',
                'href' => '#',
                'dropdown' => true,
                'feature' => 'menu_gestion',
            ],
            [
                'key' => self::KEY_BIBLIOTECA,
                'label' => 'Biblioteca',
                'href' => $dashboardHref('archivos_web'),
                'dropdown' => false,
                'feature' => 'menu_biblioteca',
            ],
        ];

        $visible = [];
        foreach ($all as $item) {
            $feature = (string) ($item['feature'] ?? '');
            if ($feature === '' || SegmentConfig::feature($feature)) {
                $visible[] = $item;
            }
        }

        return $visible;
    }

    /**
     * @param callable(string, array<string, mixed>=): string $dashboardHref
     * @param callable(string): string $menuUrl
     *
     * @return list<array<string, mixed>>
     */
    public static function gestionDropdownItems(callable $dashboardHref, callable $menuUrl): array
    {
        if (! class_exists('SegmentConfig', false)) {
            require_once __DIR__ . '/SegmentConfig.php';
        }

        $all = [
            ['label' => 'Calendario', 'href' => $dashboardHref('calendario'), 'feature' => 'menu_gestion_calendario'],
            ['label' => 'Entidades', 'href' => $dashboardHref('entidades'), 'divider_before' => true, 'feature' => 'menu_gestion_entidades'],
            ['label' => 'Clubes', 'href' => $dashboardHref('clubs'), 'feature' => 'menu_gestion_clubes'],
            ['label' => 'Gestión de torneos', 'href' => $dashboardHref('torneo_gestion', ['action' => 'index']), 'feature' => 'menu_gestion_torneos'],
            ['label' => 'Usuarios y roles', 'href' => $dashboardHref('users'), 'feature' => 'menu_gestion_usuarios'],
            ['label' => 'Invitar afiliados', 'href' => $dashboardHref('admin_clubs', ['action' => 'invitar']), 'feature' => 'menu_gestion_invitar'],
            ['label' => 'Banner reloj', 'href' => $dashboardHref('bannerclock'), 'divider_before' => true, 'feature' => 'menu_gestion_banner'],
            ['label' => 'Notificaciones masivas', 'href' => $dashboardHref('notificaciones_masivas'), 'feature' => 'menu_gestion_notificaciones'],
            ['label' => 'Mensajes WhatsApp', 'href' => $dashboardHref('whatsapp_config'), 'feature' => 'menu_gestion_whatsapp'],
            ['label' => 'Comentarios (aprobación)', 'href' => $dashboardHref('comments'), 'feature' => 'menu_gestion_comentarios'],
            ['label' => 'Atletas → Usuarios', 'href' => $dashboardHref('admin_atletas_sync'), 'divider_before' => true, 'feature' => 'menu_gestion_integraciones'],
            ['label' => 'Importar torneo externo', 'href' => $dashboardHref('importacion_torneo_externo'), 'feature' => 'menu_gestion_integraciones'],
            ['label' => 'Segmentar torneo (equipos)', 'href' => $dashboardHref('torneo_split_ranking'), 'feature' => 'menu_gestion_integraciones'],
            ['label' => 'Portal público', 'href' => $menuUrl('landing-spa.php'), 'external' => true, 'divider_before' => true, 'feature' => 'menu_gestion_portal'],
        ];

        $visible = [];
        foreach ($all as $item) {
            $feature = (string) ($item['feature'] ?? '');
            if ($feature !== '' && ! SegmentConfig::feature($feature)) {
                continue;
            }
            $visible[] = $item;
        }

        return $visible;
    }
}
