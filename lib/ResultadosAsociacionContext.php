<?php

declare(strict_types=1);

require_once __DIR__ . '/OrganizacionDashboardStats.php';

/**
 * Contexto público para filtrar resultados/reportes por asociación (organizacion_id).
 */
final class ResultadosAsociacionContext
{
    public int $organizacionId = 0;

    public string $organizacionNombre = '';

    public string $entidadNombre = '';

    /** @var array<string, mixed>|null */
    public ?array $organizacion = null;

    private string $whereSql = '';

    /** @var list<mixed> */
    private array $whereParams = [];

    public static function fromGet(PDO $pdo): self
    {
        $orgId = isset($_GET['organizacion_id']) ? max(0, (int) $_GET['organizacion_id']) : 0;

        return self::fromOrganizacionId($pdo, $orgId);
    }

    public static function fromOrganizacionId(PDO $pdo, int $organizacionId): self
    {
        $ctx = new self();
        $ctx->organizacionId = max(0, $organizacionId);
        if ($ctx->organizacionId <= 0) {
            return $ctx;
        }

        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasCodOrg = false;
        }

        $stmt = $pdo->prepare('SELECT * FROM organizaciones WHERE id = ? AND estatus = 1 LIMIT 1');
        $stmt->execute([$ctx->organizacionId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        if (! is_array($org)) {
            $ctx->organizacionId = 0;

            return $ctx;
        }

        $ctx->organizacion = $org;
        $ctx->organizacionNombre = trim((string) ($org['nombre'] ?? ''));
        $entidadId = (int) ($org['entidad'] ?? 0);
        if ($entidadId > 0) {
            try {
                $stEnt = $pdo->prepare('SELECT nombre FROM entidad WHERE id = ? LIMIT 1');
                $stEnt->execute([$entidadId]);
                $ctx->entidadNombre = trim((string) ($stEnt->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $ctx->entidadNombre = '';
            }
        }
        [$ctx->whereSql, $ctx->whereParams] = OrganizacionDashboardStats::tournamentWhereSqlAndParamsForOrganizacion(
            $pdo,
            $org,
            $hasCodOrg,
            't',
            false
        );

        return $ctx;
    }

    /**
     * Contexto desde GET; si no hay organizacion_id, infiere la asociación del torneo.
     *
     * @param array<string, mixed>|null $torneoRow
     */
    public static function fromGetOrTorneo(PDO $pdo, ?array $torneoRow = null): self
    {
        $ctx = self::fromGet($pdo);
        if ($ctx->organizacionId > 0 || $torneoRow === null) {
            return $ctx;
        }

        $resolved = self::resolveOrganizacionIdForTorneo($pdo, $torneoRow);
        if ($resolved <= 0) {
            return $ctx;
        }

        return self::fromOrganizacionId($pdo, $resolved);
    }

    /**
     * @param array<string, mixed> $torneoRow
     */
    public static function resolveOrganizacionIdForTorneo(PDO $pdo, array $torneoRow): int
    {
        $fromJoin = (int) ($torneoRow['organizacion_id_resuelta'] ?? 0);
        if ($fromJoin > 0) {
            return $fromJoin;
        }

        $clubResp = (int) ($torneoRow['club_responsable'] ?? 0);
        $codOrgTorneo = (int) ($torneoRow['cod_org'] ?? 0);
        $refs = array_values(array_unique(array_filter([$clubResp, $codOrgTorneo], static fn (int $v): bool => $v > 0)));
        if ($refs === []) {
            return 0;
        }

        $hasCodOrg = false;
        try {
            $hasCodOrg = (bool) $pdo->query("SHOW COLUMNS FROM organizaciones LIKE 'cod_org'")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasCodOrg = false;
        }

        if ($hasCodOrg) {
            $ph = implode(',', array_fill(0, count($refs), '?'));
            $stmt = $pdo->prepare("
                SELECT id FROM organizaciones
                WHERE estatus = 1 AND (id IN ({$ph}) OR cod_org IN ({$ph}))
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute(array_merge($refs, $refs));

            return (int) ($stmt->fetchColumn() ?: 0);
        }

        $stmt = $pdo->prepare('SELECT id FROM organizaciones WHERE estatus = 1 AND id = ? LIMIT 1');
        $stmt->execute([$refs[0]]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function tieneAsociacionActiva(): bool
    {
        return $this->organizacionId > 0;
    }

    public function labelListadoEventos(): string
    {
        return $this->organizacionId > 0 ? 'Torneos realizados' : 'Volver a eventos';
    }

    public function isScoped(): bool
    {
        return $this->organizacionId > 0 && $this->whereSql !== '';
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function tournamentWhere(): array
    {
        if (! $this->isScoped()) {
            return ['', []];
        }

        return [$this->whereSql, $this->whereParams];
    }

    public function torneoPertenece(PDO $pdo, int $torneoId): bool
    {
        if (! $this->isScoped() || $torneoId <= 0) {
            return true;
        }

        $sql = "SELECT COUNT(*) FROM tournaments t WHERE t.id = ? AND ({$this->whereSql})";
        $params = array_merge([$torneoId], $this->whereParams);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return array<string, scalar|null>
     */
    public function withParams(array $params): array
    {
        if ($this->organizacionId > 0) {
            $params['organizacion_id'] = $this->organizacionId;
        }

        return $params;
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function buildQs(array $params): string
    {
        $qs = http_build_query($this->withParams($params));

        return $qs !== '' ? '?' . $qs : '';
    }

    public function publicBase(): string
    {
        return class_exists('AppHelpers', false)
            ? rtrim(AppHelpers::getPublicUrl(), '/') . '/'
            : '';
    }

    public function urlEventos(): string
    {
        return $this->publicBase() . ltrim($this->urlEventosRelative(), '/');
    }

    public function urlEventosRelative(): string
    {
        if ($this->organizacionId > 0) {
            return 'resultados.php?organizacion_id=' . $this->organizacionId;
        }

        return 'resultados.php';
    }

    public function urlHubAfiliadoRelative(): string
    {
        if ($this->organizacionId > 0) {
            return 'landing-afiliados.php#/a/' . $this->organizacionId;
        }

        return 'landing-spa.php#asociaciones-afiliadas';
    }

    public function urlHubAfiliado(): string
    {
        return $this->publicBase() . ltrim($this->urlHubAfiliadoRelative(), '/');
    }

    public function urlEventoResultadosRelative(int $torneoId, array $extra = []): string
    {
        return 'evento_resultados.php' . $this->buildQs(array_merge(['torneo_id' => $torneoId], $extra));
    }

    public function urlTorneoDetalleRelative(int $torneoId): string
    {
        return 'torneo_detalle.php' . $this->buildQs(['torneo_id' => $torneoId]);
    }

    public function urlEventoResultados(int $torneoId, array $extra = []): string
    {
        return $this->publicBase() . ltrim($this->urlEventoResultadosRelative($torneoId, $extra), '/');
    }
}
