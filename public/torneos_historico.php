<?php
/**
 * Vista de Torneos Realizados (Histórico)
 * Muestra tarjetas con torneos finalizados, organización responsable y podio (1°, 2°, 3°).
 * Filtros: Año y Tipo de Evento (Nacional, Regional, Local).
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/app_helpers.php';
require_once __DIR__ . '/../lib/LandingDataService.php';
require_once __DIR__ . '/../lib/UrlHelper.php';
require_once __DIR__ . '/includes/branding_init.php';

$pdo = DB::pdo();
$base_url = app_base_url();
$landingService = new LandingDataService($pdo);

// Filtros
$anio_filtro = isset($_GET['anio']) ? (int)$_GET['anio'] : null;
$tipo_filtro = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? (int)$_GET['tipo'] : null;

// Obtener eventos realizados con filtros
$eventos = $landingService->getEventosRealizados(100, $anio_filtro, $tipo_filtro);

// Enriquecer con podio
foreach ($eventos as &$ev) {
    $ev['podio'] = $landingService->getPodioPorTorneo((int)$ev['id']);
}
unset($ev);

// Años disponibles para filtro
$anios_disponibles = [];
foreach ($eventos as $ev) {
    $y = (int)date('Y', strtotime($ev['fechator']));
    if (!in_array($y, $anios_disponibles)) {
        $anios_disponibles[] = $y;
    }
}
if ($anio_filtro && !in_array($anio_filtro, $anios_disponibles)) {
    $anios_disponibles[] = $anio_filtro;
    rsort($anios_disponibles);
} else {
    rsort($anios_disponibles);
}

$tipos_evento = [
    '' => 'Todos los tipos',
    '1' => 'Nacional',
    '2' => 'Regional',
    '3' => 'Local',
    '4' => 'Privado',
];

$modalidades = [1 => 'Individual', 2 => 'Parejas', 3 => 'Equipos'];
$clases = [1 => 'Torneo', 2 => 'Campeonato'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(Branding::pageTitle('Torneos Realizados')) ?></title>
    <meta name="description" content="Histórico de torneos de dominó realizados. Consulta resultados y podios.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .main-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 1400px;
        }
        .header-card {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .torneo-card {
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s;
            height: 100%;
        }
        .torneo-card:hover {
            border-color: #1a365d;
            box-shadow: 0 8px 25px rgba(26,54,93,0.2);
            transform: translateY(-4px);
        }
        .torneo-card-header {
            background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%);
            color: white;
            padding: 1rem 1.25rem;
        }
        .podio-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .podio-item:last-child { border-bottom: none; }
        .podio-pos {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .podio-1 { background: linear-gradient(135deg, #ffd700, #ffb700); color: #1a365d; }
        .podio-2 { background: linear-gradient(135deg, #c0c0c0, #a0a0a0); color: #1a365d; }
        .podio-3 { background: linear-gradient(135deg, #cd7f32, #b87333); color: white; }
        .podio-medal { font-size: 1.5rem; margin-right: 0.5rem; }
        .podio-1 .podio-medal { filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3)); }
        .filter-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .btn-resultados {
            background: linear-gradient(135deg, #059669, #047857);
            border: none;
            color: white;
            font-weight: 600;
        }
        .btn-resultados:hover {
            background: linear-gradient(135deg, #047857, #065f46);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="main-card mx-auto">
            <div class="header-card">
                <h1 class="mb-2">
                    <i class="fas fa-trophy me-3"></i>Torneos Realizados
                </h1>
                <p class="mb-0 opacity-90">Histórico de eventos finalizados. Consulta resultados y podios.</p>
            </div>

            <div class="p-4">
                <!-- Filtros: búsqueda instantánea (auto-submit al cambiar) -->
                <div class="filter-card">
                    <form method="GET" action="" id="filtros-form" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="fas fa-calendar-alt me-1"></i>Año</label>
                            <select name="anio" id="filtro-anio" class="form-select">
                                <option value="">Todos los años</option>
                                <?php foreach ($anios_disponibles as $a): ?>
                                    <option value="<?= $a ?>" <?= $anio_filtro === $a ? 'selected' : '' ?>><?= $a ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="fas fa-tag me-1"></i>Tipo de Evento</label>
                            <select name="tipo" id="filtro-tipo" class="form-select">
                                <?php foreach ($tipos_evento as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>" <?= ($tipo_filtro === null && $k === '') || ($tipo_filtro !== null && (int)$k === $tipo_filtro) ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filtrar</button>
                            <a href="torneos_historico.php" class="btn btn-outline-secondary ms-2">Limpiar</a>
                        </div>
                    </form>
                </div>

                <?php require __DIR__ . '/../modules/shared/views/torneos_historico.php'; ?>

                <div class="mt-4 text-center">
                    <a href="landing.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Volver al Inicio
                    </a>
                    <a href="resultados.php" class="btn btn-primary ms-2">
                        <i class="fas fa-list me-2"></i>Ver Todos los Resultados
                    </a>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('filtro-anio').addEventListener('change', function() {
        document.getElementById('filtros-form').submit();
    });
    document.getElementById('filtro-tipo').addEventListener('change', function() {
        document.getElementById('filtros-form').submit();
    });
    </script>
</body>
</html>
