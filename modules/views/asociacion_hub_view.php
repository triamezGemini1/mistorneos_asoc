<?php
/**
 * Shell del Hub de Asociación (fragmento para layout principal).
 *
 * @var array<string, mixed> $viewData
 */
declare(strict_types=1);

$orgId = (int) ($viewData['org_id'] ?? 0);
$tabActiva = (string) ($viewData['tab'] ?? 'info');
$nombre = htmlspecialchars((string) ($viewData['nombre_asociacion'] ?? ''), ENT_QUOTES, 'UTF-8');
$tabs = is_array($viewData['tabs'] ?? null) ? $viewData['tabs'] : [];
$tabsVisibles = is_array($viewData['tabs_visibles'] ?? null) ? $viewData['tabs_visibles'] : [];
$tabFile = (string) ($viewData['tab_file'] ?? '');

$hubHref = static function (string $tabId) use ($orgId, $dashboard_href): string {
    $params = ['org_id' => $orgId, 'tab' => $tabId];
    if (is_callable($dashboard_href ?? null)) {
        return $dashboard_href('asociacion_hub', $params);
    }

    return 'index.php?page=asociacion_hub&' . http_build_query($params);
};

$listadoHref = (string) ($viewData['hub_origin_url'] ?? '');
$listadoLabel = (string) ($viewData['hub_origin_label'] ?? 'Origen');
if ($listadoHref === '') {
    $listadoHref = is_callable($dashboard_href ?? null)
        ? $dashboard_href('listado_asociaciones')
        : 'index.php?page=listado_asociaciones';
    $listadoLabel = 'Asociaciones Afiliadas';
}
?>
<div class="container-fluid py-4">
    <div class="estacion-hub-header">
        <div class="row align-items-center">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-2">
                        <li class="breadcrumb-item">
                            <a href="<?= htmlspecialchars($listadoHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($listadoLabel, ENT_QUOTES, 'UTF-8') ?></a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page"><?= $nombre ?></li>
                    </ol>
                </nav>
                <h1 class="h3 mb-0">
                    <i class="fas fa-building me-2"></i><?= $nombre ?>
                </h1>
                <p class="estacion-hub-subtitle small mt-1">Organización #<?= $orgId ?></p>
            </div>
            <div class="col-auto">
                <a href="<?= htmlspecialchars($listadoHref, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Volver a <?= htmlspecialchars($listadoLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>
    </div>

    <?php if (! empty($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string) $_SESSION['success_msg'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>

    <?php if (! empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string) $_SESSION['error'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (! empty($_SESSION['warning'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string) $_SESSION['warning'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['warning']); ?>
    <?php endif; ?>

    <?php if ($tabsVisibles !== []): ?>
    <div class="estacion-hub-tabs-shell">
    <ul class="nav nav-tabs flex-nowrap overflow-auto" role="tablist">
        <?php foreach ($tabsVisibles as $tabId):
            $label = (string) ($tabs[$tabId]['label'] ?? ucfirst($tabId));
            $activa = $tabId === $tabActiva;
        ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $activa ? 'active' : '' ?>"
               href="<?= htmlspecialchars($hubHref($tabId), ENT_QUOTES, 'UTF-8') ?>"
               role="tab"
               <?= $activa ? 'aria-selected="true"' : 'aria-selected="false"' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content">
        <?php
        if ($tabFile !== '' && is_file($tabFile)) {
            include $tabFile;
        } else {
            echo '<div class="alert alert-warning mb-0">Contenido de la pestaña no disponible.</div>';
        }
        ?>
    </div>
    </div>
    <?php else: ?>
    <div class="tab-content">
        <?php
        if ($tabFile !== '' && is_file($tabFile)) {
            include $tabFile;
        }
        ?>
    </div>
    <?php endif; ?>
</div>
