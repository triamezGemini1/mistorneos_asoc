<?php
declare(strict_types=1);

/**
 * Contexto de usuario para el Hub de Asociación.
 * Encapsula rol, organización y pertenencia territorial.
 */
final class AsociacionAuthUser
{
    public int $organizacion_id;

    private bool $superAdmin;
    private bool $asocAdmin;
    private bool $memberOfOrganizacion;

    public function __construct(
        int $organizacionId,
        bool $superAdmin,
        bool $asocAdmin,
        bool $memberOfOrganizacion
    ) {
        $this->organizacion_id = $organizacionId;
        $this->superAdmin = $superAdmin;
        $this->asocAdmin = $asocAdmin;
        $this->memberOfOrganizacion = $memberOfOrganizacion;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function isAsocAdmin(): bool
    {
        return $this->asocAdmin;
    }

    /**
     * Usuario pertenece a la asociación objetivo (PK organizaciones.id).
     */
    public function belongsToOrganizacion(int $orgId): bool
    {
        if ($orgId <= 0) {
            return false;
        }
        if ($this->superAdmin) {
            return true;
        }

        return $this->memberOfOrganizacion || $this->organizacion_id === $orgId;
    }

    /**
     * Construye el contexto desde la sesión Auth (array) y la org objetivo del hub.
     */
    public static function fromAuthSession(?array $sessionUser, int $targetOrgId = 0): ?self
    {
        if ($sessionUser === null || $sessionUser === []) {
            return null;
        }

        if (! class_exists('Auth', false)) {
            require_once __DIR__ . '/../config/auth.php';
        }

        if (! class_exists('TenantContext', false)) {
            require_once __DIR__ . '/Tenant/TenantContext.php';
        }
        $tenant = TenantContext::fromSession();

        $role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
        $super = $role === 'admin_general';
        $orgId = $tenant !== null && $tenant->organizacion_id > 0
            ? $tenant->organizacion_id
            : (int) (Auth::getUserOrganizacionId() ?? 0);

        $asocAdmin = in_array($role, ['admin_club', 'admin_torneo', 'operador'], true);
        if (! $asocAdmin) {
            $uid = (int) ($sessionUser['id'] ?? 0);
            if ($uid > 0) {
                if (! class_exists('AsociacionAdminHelper', false)) {
                    require_once __DIR__ . '/AsociacionAdminHelper.php';
                }
                $asocAdmin = AsociacionAdminHelper::usuarioEsDelegadoAsociacion(DB::pdo(), $uid);
            }
        }

        $member = $targetOrgId > 0 && Auth::userIsInOrganizacion($targetOrgId);

        return new self($orgId, $super, $asocAdmin, $member);
    }
}

/**
 * Gestiona el control de acceso a los Hubs de Asociación.
 */
final class AsociacionAuth
{
    public const PUBLICO = 0;
    public const AFILIADO = 1;
    public const ADMIN_ASOC = 2;
    public const SUPER_ADMIN = 3;

    /**
     * @param int $requiredLevel Nivel mínimo requerido.
     * @param int $org_id ID de la asociación objetivo (organizaciones.id).
     * @param object|null $user Contexto de usuario (AsociacionAuthUser).
     */
    public static function checkAccess(int $requiredLevel, int $org_id, ?object $user): bool
    {
        if ($org_id <= 0) {
            return false;
        }

        // 1. SuperAdmin tiene acceso total (acceso universal)
        if ($user instanceof AsociacionAuthUser && $user->isSuperAdmin()) {
            return true;
        }

        // 2. Acceso público
        if ($requiredLevel === self::PUBLICO) {
            return true;
        }

        // 3. Si no hay usuario, denegar acceso a áreas privadas
        if (! $user instanceof AsociacionAuthUser) {
            return false;
        }

        // 4. Lógica de asociación (usuario debe pertenecer a la org)
        if (! $user->belongsToOrganizacion($org_id)) {
            return false;
        }

        if ($requiredLevel === self::AFILIADO) {
            return true;
        }

        if ($requiredLevel === self::ADMIN_ASOC && $user->isAsocAdmin()) {
            return true;
        }

        if ($requiredLevel === self::SUPER_ADMIN && $user->isSuperAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Helper para controladores: sesión Auth → contexto del hub.
     */
    public static function userFromSession(?array $sessionUser, int $targetOrgId = 0): ?AsociacionAuthUser
    {
        return AsociacionAuthUser::fromAuthSession($sessionUser, $targetOrgId);
    }
}
