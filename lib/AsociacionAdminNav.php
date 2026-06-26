<?php
declare(strict_types=1);

/**
 * Navegación minimalista del administrador de asociación (header).
 */
final class AsociacionAdminNav
{
    public const KEY_INICIO = 'inicio';
    public const KEY_CLUBES = 'clubes';
    public const KEY_TORNEOS = 'torneos';

    public static function useHeaderNav(string $roleOriginal, string $roleActivo): bool
    {
        if ($roleOriginal !== 'admin_club' || $roleActivo !== 'admin_club') {
            return false;
        }

        if (! class_exists('SegmentConfig', false)) {
            require_once __DIR__ . '/SegmentConfig.php';
        }

        return SegmentConfig::feature('admin_header_nav')
            && SegmentConfig::feature('hub_asociacion');
    }

    public static function resolveOrgId(): int
    {
        if (! class_exists('HubNavigation', false)) {
            require_once __DIR__ . '/HubNavigation.php';
        }

        return HubNavigation::resolveOrgIdForCurrentUser();
    }

    /**
     * @return list<string>
     */
    public static function pagesForItem(string $key): array
    {
        switch ($key) {
            case self::KEY_INICIO:
                return ['home', 'mi_organizacion', 'calendario'];
            case self::KEY_CLUBES:
                return ['clubs', 'clubes_asociados', 'directorio_clubes'];
            case self::KEY_TORNEOS:
                return self::torneosPages();
            default:
                return [];
        }
    }

    /**
     * @return list<string>
     */
    public static function torneosPages(): array
    {
        return [
            'torneos_estructura',
            'torneo_gestion',
            'gestion_torneos',
            'tournaments',
            'tournament_admin',
            'invitations',
            'registrants',
            'player_invitations',
            'tournaments/invitation_link',
            'estadisticas_torneos',
        ];
    }

    public static function isItemActive(string $key, string $currentPage, string $action = '', ?array $request = null): bool
    {
        $request = $request ?? $_GET;

        if ($currentPage === 'asociacion_hub') {
            $tab = strtolower(trim((string) ($request['tab'] ?? 'info')));
            if ($key === self::KEY_INICIO && $tab === 'info') {
                return true;
            }
            if ($key === self::KEY_CLUBES && $tab === 'clubes') {
                return true;
            }
            if ($key === self::KEY_TORNEOS && $tab === 'torneos') {
                return true;
            }

            return false;
        }

        if ($currentPage === 'organizaciones') {
            return $key === self::KEY_CLUBES;
        }

        if ($currentPage === 'tournaments' && class_exists('AsociacionHubNavigation', false) === false) {
            require_once __DIR__ . '/AsociacionHubNavigation.php';
        }
        if ($currentPage === 'tournaments' && class_exists('AsociacionHubNavigation', false)
            && AsociacionHubNavigation::isHubContext($request)) {
            return $key === self::KEY_TORNEOS;
        }

        return in_array($currentPage, self::pagesForItem($key), true);
    }

    /**
     * @param callable(string, array<string, mixed>=): string $dashboardHref
     *
     * @return list<array<string, mixed>>
     */
    public static function mainNavItems(callable $dashboardHref, int $orgId): array
    {
        if ($orgId > 0 && class_exists('AsociacionHubNavigation', false) === false) {
            require_once __DIR__ . '/AsociacionHubNavigation.php';
        }

        $clubesHref = $orgId > 0
            ? AsociacionHubNavigation::hubUrl($orgId, 'clubes')
            : $dashboardHref('clubs');
        $torneosHref = $orgId > 0
            ? AsociacionHubNavigation::torneosListUrl($orgId, 'en_proceso')
            : $dashboardHref('torneos_estructura', ['context' => 'asociaciones']);

        return [
            [
                'key' => self::KEY_INICIO,
                'label' => 'Inicio',
                'href' => $dashboardHref('home'),
            ],
            [
                'key' => self::KEY_CLUBES,
                'label' => 'Clubes',
                'href' => $clubesHref,
            ],
            [
                'key' => self::KEY_TORNEOS,
                'label' => 'Torneos',
                'href' => $torneosHref,
            ],
        ];
    }
}
