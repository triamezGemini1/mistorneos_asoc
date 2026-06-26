<?php
declare(strict_types=1);

/**
 * Contexto institucional del tenant (organización + rol) para nuevos flujos.
 */
final class TenantContext
{
    /** @var int */
    public $organizacion_id = 0;

    /** @var int */
    public $cod_org = 0;

    /** @var int */
    public $entidad = 0;

    /** @var int */
    public $tipo_org = 0;

    /** @var string */
    public $rol_efectivo = '';

    /** @var string */
    public $segment_key = 'asoc';

    /**
     * @return self|null null si no hay sesión.
     */
    public static function fromSession(): ?self
    {
        if (! class_exists('Auth', false)) {
            require_once __DIR__ . '/../../config/auth.php';
        }

        $user = Auth::user();
        if (! is_array($user) || $user === []) {
            return null;
        }

        $ctx = new self();
        $ctx->rol_efectivo = strtolower(trim((string) ($user['role'] ?? '')));
        $ctx->organizacion_id = (int) (Auth::getUserOrganizacionId() ?? 0);
        $ctx->entidad = (int) ($user['entidad'] ?? 0);

        if (class_exists('SegmentConfig', false)) {
            $ctx->segment_key = SegmentConfig::segmentKey();
            if ($ctx->tipo_org === 0) {
                $ctx->tipo_org = (int) SegmentConfig::get('hierarchy.tipo_org_default', 0);
            }
        }

        if ($ctx->organizacion_id > 0) {
            if (! class_exists('OrganizacionService', false)) {
                require_once __DIR__ . '/../OrganizacionService.php';
            }
            $org = OrganizacionService::getById($ctx->organizacion_id);
            if ($org !== null) {
                $ctx->entidad = (int) ($org->entidad ?? $ctx->entidad);
                if (isset($org->tipo_org)) {
                    $ctx->tipo_org = (int) $org->tipo_org;
                }
                if (isset($org->cod_org)) {
                    $ctx->cod_org = (int) $org->cod_org;
                }
            }
        }

        if ($ctx->cod_org <= 0 && class_exists('Auth', false)) {
            $ctx->cod_org = (int) (Auth::getUserOrganizacionCodOrg() ?? 0);
        }

        return $ctx;
    }

    public function isSectorialMode(): bool
    {
        if (! class_exists('SegmentConfig', false)) {
            return true;
        }

        return SegmentConfig::hierarchyMode() === 'sectorial';
    }
}
