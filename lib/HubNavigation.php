<?php
declare(strict_types=1);

/**
 * URLs canónicas del Hub de Asociación (sustituye asociacion_panel cuando el feature está activo).
 */
final class HubNavigation
{
    public static function isEnabled(): bool
    {
        if (! class_exists('SegmentConfig', false)) {
            return false;
        }

        return SegmentConfig::feature('hub_asociacion');
    }

    /**
     * URL del hub para una organización.
     *
     * @param array<string, mixed> $params
     */
    public static function hubUrl(int $orgId, array $params = []): string
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        $params['org_id'] = $orgId;
        if (! isset($params['tab'])) {
            $params['tab'] = 'torneos';
        }

        if (isset($params['error']) && trim((string) $params['error']) !== '') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['error'] = (string) $params['error'];
            }
            unset($params['error']);
        }

        return AppHelpers::dashboard('asociacion_hub', $params);
    }

    /**
     * org_id del usuario actual (PK organizaciones).
     */
    public static function resolveOrgIdForCurrentUser(): int
    {
        if (! class_exists('Auth', false)) {
            require_once __DIR__ . '/../config/auth.php';
        }

        $orgId = (int) (Auth::getUserOrganizacionId() ?? 0);
        if ($orgId > 0) {
            return $orgId;
        }

        if (! class_exists('DB', false)) {
            require_once __DIR__ . '/../config/db.php';
        }
        if (! class_exists('AsociacionAdminHelper', false)) {
            require_once __DIR__ . '/AsociacionAdminHelper.php';
        }

        $user = Auth::user();
        if (! is_array($user)) {
            return 0;
        }

        $club = AsociacionAdminHelper::clubOperativo(
            DB::pdo(),
            (int) Auth::id(),
            (string) ($user['role'] ?? '')
        );
        if (! is_array($club)) {
            return 0;
        }

        $fromClub = (int) ($club['organizacion_id'] ?? 0);
        if ($fromClub > 0) {
            return $fromClub;
        }

        return 0;
    }

    public static function homeUrlForCurrentUser(): ?string
    {
        if (! self::isEnabled()) {
            return null;
        }
        $orgId = self::resolveOrgIdForCurrentUser();
        if ($orgId <= 0) {
            return null;
        }

        return self::hubUrl($orgId, ['tab' => 'torneos']);
    }

    /**
     * Panel operativo o hub según configuración del segmento.
     *
     * @param array<string, mixed> $params
     */
    public static function panelOrHubUrl(array $params = []): string
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        if (self::isEnabled()) {
            $orgId = self::resolveOrgIdForCurrentUser();
            if ($orgId > 0) {
                return self::hubUrl($orgId, $params);
            }
        }

        return AppHelpers::dashboard('asociacion_panel', $params);
    }
}
