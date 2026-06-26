<?php

declare(strict_types=1);

require_once __DIR__ . '/AsociacionAuth.php';

/**
 * Control de acceso al portal landing de asociaciones afiliadas.
 *
 * Invitado: ranking + resultados de torneos realizados.
 * Usuario (rol usuario): solo su perfil.
 * Admin asociación: hub completo (torneos, clubes, afiliados).
 */
final class LandingAfiliadosAccess
{
    public const TIPO_INVITADO = 'invitado';

    public const TIPO_USUARIO = 'usuario';

    public const TIPO_ADMIN = 'admin';

    /** @var list<string> */
    private const ADMIN_ROLES = ['admin_general', 'admin_club', 'admin_torneo', 'operador'];

    /**
     * @return array<string, mixed>
     */
    public static function context(int $orgId = 0): array
    {
        $base = class_exists('AppHelpers', false) ? rtrim(AppHelpers::getPublicUrl(), '/') . '/' : '';
        $session = $_SESSION['user'] ?? null;

        if (! is_array($session) || (int) ($session['id'] ?? 0) <= 0) {
            return self::invitadoContext($base, $orgId);
        }

        $role = strtolower(trim((string) ($session['role'] ?? '')));
        if ($role === 'usuario') {
            return self::usuarioContext($base, $session);
        }

        $authUser = AsociacionAuthUser::fromAuthSession($session, $orgId);
        $esAdminGlobal = $role === 'admin_general';
        $esAdminAsoc = $esAdminGlobal
            || ($authUser instanceof AsociacionAuthUser
                && AsociacionAuth::checkAccess(AsociacionAuth::ADMIN_ASOC, $orgId, $authUser));

        return [
            'tipo' => self::TIPO_ADMIN,
            'logueado' => true,
            'es_admin' => $esAdminAsoc,
            'es_usuario' => false,
            'user_id' => (int) ($session['id'] ?? 0),
            'role' => $role,
            'urls' => [
                'perfil' => $base . 'user_portal.php',
                'login' => self::loginUrl($base, $orgId),
                'admin' => $base . 'index.php',
            ],
        ];
    }

    public static function esInvitado(array $ctx): bool
    {
        return ($ctx['tipo'] ?? '') === self::TIPO_INVITADO;
    }

    public static function esUsuario(array $ctx): bool
    {
        return ($ctx['tipo'] ?? '') === self::TIPO_USUARIO;
    }

    public static function esAdmin(array $ctx): bool
    {
        return ($ctx['tipo'] ?? '') === self::TIPO_ADMIN && ! empty($ctx['es_admin']);
    }

    public static function puedeVerHubPublico(array $ctx): bool
    {
        return self::esInvitado($ctx) || self::esAdmin($ctx);
    }

    public static function puedeVerHubAdmin(array $ctx): bool
    {
        return self::esAdmin($ctx);
    }

    /**
     * @return array<string, mixed>|null Respuesta de error lista para JSON, o null si permite continuar.
     */
    public static function guard(string $action, int $orgId = 0): ?array
    {
        $ctx = self::context($orgId);

        if (self::esUsuario($ctx)) {
            return self::deny('Los afiliados autenticados solo pueden acceder a su perfil.', $ctx['urls']['perfil'] ?? null, 'usuario_solo_perfil');
        }

        $soloAdmin = ['torneos', 'club', 'clubes', 'afiliado', 'torneo', 'toggle_afiliado'];
        if (in_array($action, $soloAdmin, true) && ! self::esAdmin($ctx)) {
            return self::deny('Esta sección requiere acceso de administrador.', $ctx['urls']['login'] ?? null, 'requiere_admin');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function invitadoContext(string $base, int $orgId): array
    {
        return [
            'tipo' => self::TIPO_INVITADO,
            'logueado' => false,
            'es_admin' => false,
            'es_usuario' => false,
            'user_id' => 0,
            'role' => '',
            'urls' => [
                'perfil' => $base . 'user_portal.php',
                'login' => self::loginUrl($base, $orgId),
                'admin' => $base . 'index.php',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private static function usuarioContext(string $base, array $session): array
    {
        return [
            'tipo' => self::TIPO_USUARIO,
            'logueado' => true,
            'es_admin' => false,
            'es_usuario' => true,
            'user_id' => (int) ($session['id'] ?? 0),
            'role' => 'usuario',
            'urls' => [
                'perfil' => $base . 'user_portal.php',
                'login' => self::loginUrl($base, 0),
                'admin' => $base . 'index.php',
            ],
        ];
    }

    private static function loginUrl(string $base, int $orgId): string
    {
        $return = 'landing-afiliados.php';
        if ($orgId > 0) {
            $return .= '#/a/' . $orgId;
        }

        return $base . 'login.php?return_url=' . rawurlencode($return);
    }

    /**
     * @return array<string, mixed>
     */
    private static function deny(string $message, ?string $redirect, string $code): array
    {
        $out = [
            'success' => false,
            'error' => $message,
            'code' => $code,
        ];
        if ($redirect !== null && $redirect !== '') {
            $out['redirect'] = $redirect;
        }

        return $out;
    }
}
