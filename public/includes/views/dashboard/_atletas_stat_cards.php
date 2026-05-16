<?php
$s = $stats ?? [];
?>
<div class="col-12 mb-2 mt-2">
    <h5 class="text-muted mb-0"><i class="fas fa-running me-2"></i>Atletas</h5>
</div>
<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
    <div class="stat-card success">
        <div class="d-flex flex-column align-items-start w-100">
            <h3 class="mb-1"><?= number_format((int)($s['atletas_activos'] ?? 0)) ?></h3>
            <p class="mb-0"><i class="fas fa-user-check me-1"></i>Activos</p>
        </div>
    </div>
</div>
<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
    <div class="stat-card secondary">
        <div class="d-flex flex-column align-items-start w-100">
            <h3 class="mb-1"><?= number_format((int)($s['atletas_inactivos'] ?? 0)) ?></h3>
            <p class="mb-0"><i class="fas fa-user-slash me-1"></i>Inactivos</p>
        </div>
    </div>
</div>
<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
    <div class="stat-card danger">
        <div class="d-flex flex-column align-items-start w-100">
            <h3 class="mb-1"><?= number_format((int)($s['hombres_activos'] ?? 0)) ?></h3>
            <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres (activos)</p>
        </div>
    </div>
</div>
<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
    <div class="stat-card purple">
        <div class="d-flex flex-column align-items-start w-100">
            <h3 class="mb-1"><?= number_format((int)($s['mujeres_activos'] ?? 0)) ?></h3>
            <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres (activas)</p>
        </div>
    </div>
</div>
<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
    <div class="stat-card dark">
        <div class="d-flex flex-column align-items-start w-100">
            <h3 class="mb-1"><?= number_format((int)($s['hombres_inactivos'] ?? 0)) ?></h3>
            <p class="mb-0"><i class="fas fa-mars me-1"></i>Hombres (inactivos)</p>
        </div>
    </div>
</div>
<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 col-12">
    <div class="stat-card warning">
        <div class="d-flex flex-column align-items-start w-100">
            <h3 class="mb-1"><?= number_format((int)($s['mujeres_inactivos'] ?? 0)) ?></h3>
            <p class="mb-0"><i class="fas fa-venus me-1"></i>Mujeres (inactivas)</p>
        </div>
    </div>
</div>
