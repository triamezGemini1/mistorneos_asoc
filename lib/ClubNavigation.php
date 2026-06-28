<?php
declare(strict_types=1);

require_once __DIR__ . '/AsociacionHubNavigation.php';

/**
 * URLs de retorno y contexto de navegación para gestión de clubes y afiliados.
 */
final class ClubNavigation
{
    /**
     * @param array<string, mixed> $req
     * @return array<string, scalar>
     */
    public static function contextFromRequest(array $req): array
    {
        $out = [];
        $from = trim((string) ($req['from'] ?? ''));
        if ($from !== '') {
            $out['from'] = $from;
        }
        if (!empty($req['admin_id'])) {
            $out['admin_id'] = (int) $req['admin_id'];
        }
        $hubOrgId = (int) ($req['hub_org_id'] ?? 0);
        if ($hubOrgId > 0) {
            $out['hub_org_id'] = $hubOrgId;
            $out['hub_tab'] = (string) ($req['hub_tab'] ?? 'clubes');
        }
        if ($from === 'asociacion_hub' && isset($req['hub_estado'])) {
            $out['hub_estado'] = (string) $req['hub_estado'];
        }

        return $out;
    }

    /**
     * Parámetros hub para enlaces desde clubes_asociados.
     *
     * @param array<string, mixed>|null $req
     * @return array<string, scalar>
     */
    public static function hubParams(?array $req = null): array
    {
        $req = $req ?? $_GET;
        if (!AsociacionHubNavigation::isHubContext($req)) {
            return [];
        }
        $orgId = (int) ($req['hub_org_id'] ?? 0);
        if ($orgId <= 0) {
            return [];
        }

        return AsociacionHubNavigation::outboundParams($orgId, (string) ($req['hub_tab'] ?? 'clubes'));
    }

    public static function clubesAsociadosUrl(?array $req = null, array $extra = []): string
    {
        return self::clubesListUrl($req, $extra);
    }

    /**
     * URL canónica del listado de clubes (hub ASOC o inicio).
     *
     * @param array<string, mixed>|null $req
     */
    public static function clubesListUrl(?array $req = null, array $extra = []): string
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }
        if (! class_exists('HubNavigation', false)) {
            require_once __DIR__ . '/HubNavigation.php';
        }

        $req = $req ?? [];
        $orgId = (int) ($req['hub_org_id'] ?? $req['org_id'] ?? 0);
        if ($orgId <= 0 && class_exists('Auth', false)) {
            $orgId = (int) (Auth::getUserOrganizacionId() ?? 0);
        }

        if ($orgId > 0 && HubNavigation::isEnabled()) {
            $params = array_merge(['org_id' => $orgId, 'tab' => 'clubes'], $extra);
            unset($params['page'], $params['hub_org_id'], $params['hub_tab'], $params['from']);

            return AppHelpers::dashboard('asociacion_hub', $params);
        }

        return AppHelpers::dashboard('home');
    }

    /** Endpoint interno POST (crear/editar/eliminar club). No usar en enlaces de navegación. */
    public static function clubesAsociadosPostUrl(): string
    {
        if (! class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        return AppHelpers::dashboard('clubes_asociados');
    }

    /**
     * @param array<string, mixed> $req
     */
    public static function detailUrl(int $clubId, array $req, ?string $genero = null): string
    {
        $params = array_merge(
            ['page' => 'clubs', 'action' => 'detail', 'id' => $clubId],
            self::contextFromRequest($req)
        );
        if ($genero === 'M' || $genero === 'F') {
            $params['genero'] = $genero;
        }

        return 'index.php?' . http_build_query($params);
    }

    /**
     * @param array<string, mixed> $req
     */
    public static function returnUrlFromRequest(array $req): string
    {
        $from = (string) ($req['from'] ?? '');
        if ($from === 'admin_clubs' && !empty($req['admin_id'])) {
            return 'index.php?page=admin_clubs&view=detail&admin_id=' . (int) $req['admin_id'];
        }
        if ($from === 'home') {
            return class_exists('AppHelpers', false)
                ? AppHelpers::dashboard('home')
                : 'index.php?page=home';
        }
        if ($from === 'clubes_asociados' || $from === 'clubes') {
            return self::clubesListUrl($req);
        }
        if ($from === 'clubs') {
            $clubId = (int) ($req['club_id'] ?? $req['id'] ?? 0);
            if ($clubId > 0) {
                $back = $req;
                $back['from'] = 'clubes_asociados';

                return self::detailUrl($clubId, $back);
            }

            return self::clubesListUrl($req);
        }
        if ($from === 'asociacion_hub') {
            return self::clubesListUrl($req);
        }
        if ($from === 'torneo_gestion') {
            return 'index.php?page=torneo_gestion&action=index';
        }

        return class_exists('AppHelpers', false)
            ? AppHelpers::dashboard('home')
            : 'index.php?page=home';
    }

    public static function returnLabelFromRequest(array $req): string
    {
        $from = (string) ($req['from'] ?? '');
        if ($from === 'asociacion_hub') {
            return 'Volver al listado';
        }
        if ($from === 'clubes_asociados' || $from === 'clubes') {
            return 'Volver al listado';
        }
        if ($from === 'admin_clubs') {
            return 'Volver al administrador';
        }
        if ($from === 'home') {
            return 'Volver al inicio';
        }
        if ($from === 'clubs') {
            return 'Volver al club';
        }

        return 'Volver';
    }

    /**
     * Redirige accesos obsoletos a clubs/invitation_link.
     *
     * @param array<string, mixed> $req
     */
    public static function redirectLegacyInvitationLink(array $req): never
    {
        $clubId = (int) ($req['club_id'] ?? 0);
        if ($clubId > 0) {
            $back = $req;
            if (($back['from'] ?? '') === 'asociacion_hub') {
                $back['from'] = 'clubes_asociados';
            }
            $target = self::detailUrl($clubId, $back);
        } else {
            $target = self::returnUrlFromRequest($req);
        }

        header('Location: ' . $target);
        exit;
    }

    /**
     * URL de retorno para notificaciones masivas (admin club).
     *
     * @param array<string, mixed> $req
     */
    public static function notificacionesReturnUrl(array $req): string
    {
        $from = (string) ($req['from'] ?? '');
        if ($from === 'torneo_gestion' || (int) ($req['torneo_id'] ?? 0) > 0) {
            return 'index.php?page=torneo_gestion&action=index';
        }
        if ($from !== '') {
            return self::returnUrlFromRequest($req);
        }

        return '';
    }

    /**
     * Formulario público de afiliación (alta o edición de afiliado).
     *
     * @param array<string, mixed> $req Contexto de navegación (from, hub_*, genero).
     */
    public static function afiliadoFormUrl(int $clubId, ?int $userId, array $req = []): string
    {
        if (!class_exists('AppHelpers', false)) {
            require_once __DIR__ . '/app_helpers.php';
        }

        $back = $req;
        if (!isset($back['from']) || (string) $back['from'] === '') {
            $back['from'] = 'clubes_asociados';
        }

        $params = ['club_id' => $clubId];
        if (AsociacionHubNavigation::isHubContext($back)) {
            $returnUrl = AsociacionHubNavigation::returnUrlFromRequest($back);
            if (is_string($returnUrl) && $returnUrl !== '') {
                $params['return_url'] = $returnUrl;
            } else {
                $params['return_url'] = self::detailUrl($clubId, $back, self::generoFromRequest($req));
            }
        } else {
            $params['return_url'] = self::detailUrl($clubId, $back, self::generoFromRequest($req));
        }
        if ($userId !== null && $userId > 0) {
            $params['user_id'] = $userId;
        }

        return AppHelpers::url('register_by_club.php', $params);
    }

    /**
     * @param array<string, mixed> $req
     */
    public static function generoFromRequest(array $req): ?string
    {
        $g = strtoupper(trim((string) ($req['genero'] ?? $req['return_genero'] ?? '')));

        return ($g === 'M' || $g === 'F') ? $g : null;
    }

    /**
     * Valida URL interna de retorno (solo rutas del mismo sitio).
     */
    public static function safeReturnUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            $current = $_SERVER['HTTP_HOST'] ?? '';
            if ($host === null || $current === '' || strcasecmp((string) $host, (string) $current) !== 0) {
                return null;
            }
        }
        if (str_contains($url, 'logout.php') || str_contains($url, 'login.php')) {
            return null;
        }

        return $url;
    }
}
