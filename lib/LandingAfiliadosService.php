<?php

declare(strict_types=1);

require_once __DIR__ . '/LandingDataService.php';
require_once __DIR__ . '/OrganizacionDashboardStats.php';
require_once __DIR__ . '/OrganizacionService.php';
require_once __DIR__ . '/ClubService.php';
require_once __DIR__ . '/AfiliadoService.php';
require_once __DIR__ . '/AsociacionAuth.php';
require_once __DIR__ . '/LandingAfiliadosAccess.php';
require_once __DIR__ . '/ClubNavigation.php';
require_once __DIR__ . '/ClubHelper.php';

/**
 * Datos públicos de asociaciones afiliadas para la landing ASOC.
 */
final class LandingAfiliadosService
{
    private PDO $pdo;

    private LandingDataService $landing;

    private bool $hasCodOrg;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->landing = new LandingDataService($pdo);
        $this->hasCodOrg = $this->detectCodOrg();
    }

    private function detectCodOrg(): bool
    {
        try {
            return (bool) $this->pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAfiliadosActivos(bool $soloPublico = false): array
    {
        $rows = OrganizacionService::getAllAfiliadas();
        $out = [];
        foreach ($rows as $row) {
            if ((int) ($row['estatus'] ?? 0) !== 1) {
                continue;
            }
            $orgId = (int) ($row['id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }
            if ($soloPublico) {
                $out[] = $this->mapAfiliadoPublico($row);
                continue;
            }
            $snap = OrganizacionDashboardStats::snapshot($this->pdo, $row, $this->hasCodOrg);
            $out[] = $this->mapAfiliadoResumen($row, $snap['stats'] ?? []);
        }

        return $out;
    }

    /**
     * Hub según nivel de acceso: público (invitado) o administración.
     *
     * @return array<string, mixed>|null
     */
    public function getAfiliadoHub(int $orgId): ?array
    {
        $ctx = LandingAfiliadosAccess::context($orgId);
        if (LandingAfiliadosAccess::esInvitado($ctx)) {
            return $this->getAfiliadoHubPublico($orgId, $ctx);
        }
        if (! LandingAfiliadosAccess::esAdmin($ctx)) {
            return null;
        }

        $org = $this->loadOrganizacionPublica($orgId);
        if ($org === null) {
            return null;
        }

        $snap = OrganizacionDashboardStats::snapshot($this->pdo, $org, $this->hasCodOrg);
        $torneos = $this->clasificarTorneos($org);

        return [
            'modo' => 'admin',
            'access' => $ctx,
            'afiliado' => $this->mapAfiliadoDetalle($org, $snap['stats'] ?? []),
            'torneos_resumen' => [
                'realizados' => count($torneos['realizados']),
                'en_proceso' => count($torneos['en_proceso']),
                'por_realizar' => count($torneos['por_realizar']),
            ],
            'urls' => $this->urlsForAfiliado($org),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAfiliadoHubPublico(int $orgId, ?array $ctx = null): ?array
    {
        $org = $this->loadOrganizacionPublica($orgId);
        if ($org === null) {
            return null;
        }

        $ctx ??= LandingAfiliadosAccess::context($orgId);
        $torneos = $this->clasificarTorneos($org);
        $base = class_exists('AppHelpers', false) ? rtrim(AppHelpers::getPublicUrl(), '/') . '/' : '';

        $realizados = [];
        foreach ($torneos['realizados'] as $t) {
            $torneoId = (int) ($t['id'] ?? 0);
            if ($torneoId <= 0) {
                continue;
            }
            $realizados[] = [
                'id' => $torneoId,
                'nombre' => (string) ($t['nombre'] ?? ''),
                'fechator' => (string) ($t['fechator'] ?? ''),
                'lugar' => (string) ($t['lugar'] ?? ''),
                'total_inscritos' => (int) ($t['total_inscritos'] ?? 0),
                'costo' => $t['costo'] ?? null,
                'descripcion' => trim((string) ($t['descripcion'] ?? '')),
                'afiche_url' => $t['afiche_url'] ?? null,
                'logo_url' => $t['logo_url'] ?? null,
                'urls' => [
                    'resultados' => $base . 'evento_resultados.php?torneo_id=' . $torneoId . '&organizacion_id=' . $orgId,
                ],
            ];
        }

        return [
            'modo' => 'publico',
            'access' => $ctx,
            'afiliado' => $this->mapAfiliadoPublico($org),
            'torneos_realizados' => $realizados,
            'urls' => [
                'ranking' => $base . 'ranking_atletas.php?organizacion_id=' . $orgId,
                'ranking_estado' => $base . 'ranking_atletas.php?organizacion_id=' . $orgId,
                'eventos' => $base . 'resultados.php?organizacion_id=' . $orgId,
                'login' => $ctx['urls']['login'] ?? ($base . 'login.php'),
                'landing' => $base . 'landing-spa.php#asociaciones-afiliadas',
            ],
        ];
    }

    /**
     * @return array{realizados: list<array<string,mixed>>, en_proceso: list<array<string,mixed>>}|null
     */
    public function getTorneosPublicos(int $orgId): ?array
    {
        $org = $this->loadOrganizacionPublica($orgId);
        if ($org === null) {
            return null;
        }
        $torneos = $this->clasificarTorneos($org);

        return [
            'realizados' => $torneos['realizados'],
            'en_proceso' => $torneos['en_proceso'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTorneoDetalle(int $orgId, int $torneoId, string $baseUrl): ?array
    {
        if ($orgId <= 0 || $torneoId <= 0) {
            return null;
        }
        $org = $this->loadOrganizacionPublica($orgId);
        if ($org === null) {
            return null;
        }

        [$where, $params] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
            $this->pdo,
            $org,
            $this->hasCodOrg,
            't',
            false
        );

        $sql = "
            SELECT t.*,
                   (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus IS NULL OR estatus != 'retirado')) AS total_inscritos
            FROM tournaments t
            WHERE t.id = ? AND t.estatus = 1 AND ({$where})
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$torneoId], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row) || $row === []) {
            return null;
        }
        $row['organizacion_nombre'] = (string) ($org['nombre'] ?? '');
        $row['organizacion_logo'] = $org['logo'] ?? null;
        $row['organizacion_responsable'] = (string) ($org['responsable'] ?? '');
        $row['organizacion_telefono'] = (string) ($org['telefono'] ?? '');
        $row['organizacion_email'] = (string) ($org['email'] ?? '');
        $row['organizacion_direccion'] = (string) ($org['direccion'] ?? '');

        return $this->enriquecerTorneo($row, $baseUrl);
    }

    /**
     * @return array{clubes: list<array<string,mixed>>}|null
     */
    public function getClubesPublicos(int $orgId): ?array
    {
        $org = $this->loadOrganizacionPublica($orgId);
        if ($org === null) {
            return null;
        }

        $base = class_exists('AppHelpers', false) ? rtrim(AppHelpers::getPublicUrl(), '/') . '/' : '';
        $clubes = [];
        foreach (ClubService::getByOrg($orgId) as $club) {
            $clubId = (int) ($club['id'] ?? 0);
            if ($clubId <= 0) {
                continue;
            }
            $clubes[] = [
                'id' => $clubId,
                'nombre' => (string) ($club['nombre'] ?? ''),
                'delegado' => (string) ($club['delegado'] ?? ''),
                'total_afiliados' => (int) ($club['total_afiliados'] ?? 0),
                'urls' => [
                    'detalle' => $base . 'landing-afiliados.php#/a/' . $orgId . '/club/' . $clubId,
                    'afiliacion' => $base . 'register_by_club.php?club_id=' . $clubId,
                ],
            ];
        }

        return ['clubes' => $clubes];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getClubDetalle(int $orgId, int $clubId): ?array
    {
        if ($orgId <= 0 || $clubId <= 0) {
            return null;
        }
        $org = $this->loadOrganizacionPublica($orgId);
        if ($org === null) {
            return null;
        }

        $clubRow = null;
        foreach (ClubService::getByOrg($orgId) as $club) {
            if ((int) ($club['id'] ?? 0) === $clubId) {
                $clubRow = $club;
                break;
            }
        }
        if ($clubRow === null) {
            return null;
        }

        $base = class_exists('AppHelpers', false) ? rtrim(AppHelpers::getPublicUrl(), '/') . '/' : '';
        $puedeGestionar = $this->puedeGestionarClub($orgId, $clubId);
        $afiliados = [];
        foreach (AfiliadoService::getByOrg($orgId) as $af) {
            if (ClubHelper::resolveAfiliadoClubId($af) !== $clubId) {
                continue;
            }
            $userId = (int) ($af['id'] ?? 0);
            $afiliados[] = [
                'id' => $userId,
                'nombre' => (string) ($af['nombre'] ?? ''),
                'estatus' => (string) ($af['estatus'] ?? ''),
                'estatus_label' => (string) ($af['estatus_label'] ?? ''),
                'urls' => $this->urlsAfiliadoClub($base, $orgId, $clubId, $userId, $puedeGestionar),
            ];
        }

        return [
            'club' => [
                'id' => $clubId,
                'nombre' => (string) ($clubRow['nombre'] ?? ''),
                'delegado' => (string) ($clubRow['delegado'] ?? ''),
                'total_afiliados' => (int) ($clubRow['total_afiliados'] ?? 0),
            ],
            'afiliados' => $afiliados,
            'puede_gestionar' => $puedeGestionar,
            'urls' => [
                'afiliacion' => $base . 'register_by_club.php?club_id=' . $clubId,
                'clubes' => $base . 'landing-afiliados.php#/a/' . $orgId . '/clubes',
                'hub' => $base . 'landing-afiliados.php#/a/' . $orgId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAfiliadoEnClub(int $orgId, int $clubId, int $userId): ?array
    {
        if ($orgId <= 0 || $clubId <= 0 || $userId <= 0) {
            return null;
        }
        if ($this->loadOrganizacionPublica($orgId) === null || ! $this->clubPerteneceAOrg($orgId, $clubId)) {
            return null;
        }

        $clubRow = null;
        foreach (ClubService::getByOrg($orgId) as $club) {
            if ((int) ($club['id'] ?? 0) === $clubId) {
                $clubRow = $club;
                break;
            }
        }
        if ($clubRow === null) {
            return null;
        }

        $afRow = ClubHelper::fetchAfiliadoInClub($this->pdo, $clubRow, $clubId, $userId);
        if ($afRow === null) {
            return null;
        }

        $af = AfiliadoService::getByIdInOrg($orgId, $userId);
        if ($af === null) {
            return null;
        }

        $base = class_exists('AppHelpers', false) ? rtrim(AppHelpers::getPublicUrl(), '/') . '/' : '';
        $puedeGestionar = $this->puedeGestionarClub($orgId, $clubId);

        return [
            'afiliado' => $af,
            'puede_gestionar' => $puedeGestionar,
            'urls' => array_merge(
                $this->urlsAfiliadoClub($base, $orgId, $clubId, $userId, $puedeGestionar),
                [
                    'club' => $base . 'landing-afiliados.php#/a/' . $orgId . '/club/' . $clubId,
                    'ficha_pdf' => $base . 'afiliado_ficha_pdf.php?' . http_build_query([
                        'org_id' => $orgId,
                        'user_id' => $userId,
                    ]),
                ]
            ),
        ];
    }

    /**
     * @return array{success: bool, estatus?: string, estatus_label?: string, error?: string}
     */
    public function toggleAfiliadoStatus(int $orgId, int $clubId, int $userId): array
    {
        if (! $this->puedeGestionarClub($orgId, $clubId)) {
            return ['success' => false, 'error' => 'No tiene permisos para gestionar afiliados de este club'];
        }

        $af = $this->getAfiliadoEnClub($orgId, $clubId, $userId);
        if ($af === null) {
            return ['success' => false, 'error' => 'Afiliado no encontrado en este club'];
        }

        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser) && (int) ($sessionUser['id'] ?? 0) === $userId) {
            return ['success' => false, 'error' => 'No puede cambiar su propio estatus'];
        }

        try {
            $stmt = $this->pdo->prepare('UPDATE usuarios SET status = IF(status = 0, 1, 0), updated_at = NOW() WHERE id = ? AND role = ?');
            $stmt->execute([$userId, 'usuario']);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'No se pudo actualizar el estatus'];
            }

            $actualizado = AfiliadoService::getByIdInOrg($orgId, $userId);

            return [
                'success' => true,
                'estatus' => (string) ($actualizado['estatus'] ?? ''),
                'estatus_label' => (string) ($actualizado['estatus_label'] ?? ''),
            ];
        } catch (Throwable $e) {
            error_log('LandingAfiliadosService::toggleAfiliadoStatus: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Error al actualizar el estatus'];
        }
    }

    public function puedeGestionarClub(int $orgId, int $clubId): bool
    {
        return LandingAfiliadosAccess::esAdmin(LandingAfiliadosAccess::context($orgId))
            && $this->clubPerteneceAOrg($orgId, $clubId);
    }

    private function clubPerteneceAOrg(int $orgId, int $clubId): bool
    {
        foreach (ClubService::getByOrg($orgId) as $club) {
            if ((int) ($club['id'] ?? 0) === $clubId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function urlsAfiliadoClub(string $base, int $orgId, int $clubId, int $userId, bool $puedeGestionar): array
    {
        $urls = [
            'ver' => $base . 'landing-afiliados.php#/a/' . $orgId . '/club/' . $clubId . '/u/' . $userId,
        ];
        if ($puedeGestionar) {
            $urls['editar'] = ClubNavigation::afiliadoFormUrl($clubId, $userId, ['from' => 'asociacion_hub', 'hub_org_id' => $orgId, 'hub_tab' => 'afiliados']);
            $urls['admin_ver'] = $base . 'index.php?page=clubs&action=afiliado_detail&club_id=' . $clubId . '&user_id=' . $userId . '&from=asociacion_hub&hub_org_id=' . $orgId . '&hub_tab=afiliados';
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadOrganizacionPublica(int $orgId): ?array
    {
        if ($orgId <= 0) {
            return null;
        }
        $sqlSoloAsoc = OrganizacionDashboardStats::sqlWhereSoloAsociaciones($this->pdo, 'o');
        $cols = ['o.id', 'o.nombre', 'o.entidad', 'o.estatus', 'o.logo', 'o.responsable', 'o.telefono', 'o.email', 'o.direccion'];
        if ($this->hasCodOrg) {
            $cols[] = 'o.cod_org';
        }
        try {
            if ((bool) $this->pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'tipo_org'")->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = 'o.tipo_org';
            }
        } catch (Throwable $ignored) {
        }
        $cols[] = 'e.nombre AS entidad_nombre';
        $sql = 'SELECT ' . implode(', ', $cols) . '
                FROM organizaciones o
                LEFT JOIN entidad e ON e.id = o.entidad
                WHERE o.id = ? AND o.estatus = 1 AND (' . $sqlSoloAsoc . ')
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapAfiliadoPublico(array $row): array
    {
        $logo = trim((string) ($row['logo'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'entidad' => (int) ($row['entidad'] ?? 0),
            'entidad_nombre' => (string) ($row['entidad_nombre'] ?? ''),
            'logo_url' => $logo !== '' && class_exists('AppHelpers', false) ? AppHelpers::imageUrl($logo) : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stats
     *
     * @return array<string, mixed>
     */
    private function mapAfiliadoResumen(array $row, array $stats): array
    {
        $logo = trim((string) ($row['logo'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'entidad' => (int) ($row['entidad'] ?? 0),
            'entidad_nombre' => (string) ($row['entidad_nombre'] ?? ''),
            'logo_url' => $logo !== '' && class_exists('AppHelpers', false) ? AppHelpers::imageUrl($logo) : null,
            'clubes' => (int) ($stats['clubes'] ?? 0),
            'afiliados' => (int) ($stats['afiliados'] ?? 0),
            'torneos' => (int) ($stats['torneos'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stats
     *
     * @return array<string, mixed>
     */
    private function mapAfiliadoDetalle(array $row, array $stats): array
    {
        $base = $this->mapAfiliadoResumen($row, $stats);
        $base['responsable'] = (string) ($row['responsable'] ?? '');
        $base['telefono'] = (string) ($row['telefono'] ?? '');
        $base['email'] = (string) ($row['email'] ?? '');
        $base['direccion'] = (string) ($row['direccion'] ?? '');
        $base['torneos_activos'] = (int) ($stats['torneos_activos'] ?? 0);
        $base['inscripciones'] = (int) ($stats['inscripciones'] ?? 0);

        return $base;
    }

    /**
     * @param array<string, mixed> $org
     *
     * @return array{realizados: list<array<string,mixed>>, en_proceso: list<array<string,mixed>>, por_realizar: list<array<string,mixed>>}
     */
    private function clasificarTorneos(array $org): array
    {
        [$where, $params] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
            $this->pdo,
            $org,
            $this->hasCodOrg,
            't',
            false
        );

        $wherePublicar = '';
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM tournaments LIKE 'publicar_landing'")->fetchAll();
            if (! empty($cols)) {
                $wherePublicar = ' AND (t.publicar_landing = 1 OR t.publicar_landing IS NULL)';
            }
        } catch (Throwable $ignored) {
        }

        $sql = "
            SELECT t.*,
                   (SELECT COUNT(*) FROM inscritos WHERE torneo_id = t.id AND (estatus IS NULL OR estatus != 'retirado')) AS total_inscritos
            FROM tournaments t
            WHERE t.estatus = 1 AND ({$where}) {$wherePublicar}
            ORDER BY t.fechator DESC
            LIMIT 200
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $baseUrl = class_exists('AppHelpers', false) ? AppHelpers::getPublicUrl() . '/' : '';
        $realizados = [];
        $enProceso = [];
        $porRealizar = [];
        $hoy = date('Y-m-d');

        foreach ($rows as $row) {
            $row['organizacion_nombre'] = (string) ($org['nombre'] ?? '');
            $row['organizacion_logo'] = $org['logo'] ?? null;
            $item = $this->enriquecerTorneo($row, $baseUrl);
            $fecha = substr((string) ($row['fechator'] ?? ''), 0, 10);
            if ($fecha === '' || $fecha < $hoy) {
                $realizados[] = $item;
            } elseif ($fecha === $hoy) {
                $enProceso[] = $item;
            } else {
                $porRealizar[] = $item;
            }
        }

        return [
            'realizados' => $realizados,
            'en_proceso' => $enProceso,
            'por_realizar' => $porRealizar,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function enriquecerTorneo(array $row, string $baseUrl): array
    {
        $logo = trim((string) ($row['organizacion_logo'] ?? ''));
        $afiche = trim((string) ($row['afiche'] ?? ''));
        $row['logo_url'] = $logo !== '' && class_exists('AppHelpers', false)
            ? AppHelpers::imageUrl($logo)
            : null;
        $row['afiche_url'] = $afiche !== '' ? rtrim($baseUrl, '/') . '/view_tournament_file.php?file=' . urlencode(basename($afiche)) : null;
        $row['estado_torneo'] = $this->estadoTorneoLabel((string) ($row['fechator'] ?? ''));
        $row['total_inscritos'] = (int) ($row['total_inscritos'] ?? 0);

        return $row;
    }

    private function estadoTorneoLabel(string $fechator): string
    {
        $fecha = substr($fechator, 0, 10);
        $hoy = date('Y-m-d');
        if ($fecha === '' || $fecha < $hoy) {
            return 'realizado';
        }
        if ($fecha === $hoy) {
            return 'en_proceso';
        }

        return 'por_realizar';
    }

    /**
     * @param array<string, mixed> $org
     *
     * @return array<string, string>
     */
    private function urlsForAfiliado(array $org): array
    {
        $base = class_exists('AppHelpers', false) ? rtrim(AppHelpers::getPublicUrl(), '/') . '/' : '';
        $orgId = (int) ($org['id'] ?? 0);
        $entidad = (int) ($org['entidad'] ?? 0);

        return [
            'ranking' => $base . 'ranking_atletas.php?organizacion_id=' . $orgId,
            'eventos' => $base . 'resultados.php?organizacion_id=' . $orgId,
            'landing' => $base . 'landing-spa.php#asociaciones-afiliadas',
            'login' => $base . 'login.php',
            'clubes' => $base . 'landing-afiliados.php#/a/' . $orgId . '/clubes',
            'afiliacion' => $base . 'affiliate_request.php',
        ];
    }
}
