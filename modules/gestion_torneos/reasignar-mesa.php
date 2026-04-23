<?php
/**
 * Vista: Reasignar Mesa
 * Permite intercambiar posiciones de jugadores en una mesa
 */
$script_actual = basename($_SERVER['PHP_SELF'] ?? '');
$use_standalone = in_array($script_actual, ['admin_torneo.php', 'panel_torneo.php']);
$base_url = $use_standalone ? $script_actual : 'index.php?page=torneo_gestion';
$esParejasFijas = ((int)($torneo['modalidad'] ?? 0) === 4);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reasignar Mesa <?php echo $mesaActual ?? 0; ?> - <?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .mesa-item {
            transition: all 0.3s;
            padding: 8px 12px !important;
            cursor: pointer;
            text-decoration: none;
        }
        .mesa-item:hover {
            transform: translateX(5px);
            text-decoration: none;
        }
        .mesa-activa {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            font-weight: bold;
        }
        .opcion-reasignacion {
            transition: all 0.3s;
            border: 2px solid #e5e7eb;
            padding: 15px;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 10px;
        }
        .opcion-reasignacion:hover {
            border-color: #007bff;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .opcion-reasignacion input[type="radio"]:checked + .opcion-content {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        .pareja-a {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .pareja-b {
            background-color: #d1fae5;
            color: #065f46;
        }
        @media (max-width: 768px) {
            .sidebar-mesas {
                max-height: 300px;
                overflow-y: auto;
            }
        }
    </style>
</head>
<body>
    <?php if (!$use_standalone): ?>
        <?php require_once __DIR__ . '/../../public/includes/layout.php'; ?>
    <?php else: ?>
        <?php require_once __DIR__ . '/../../public/includes/admin_torneo_layout.php'; ?>
    <?php endif; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Panel Lateral - Lista de Mesas -->
            <div class="col-md-3 col-lg-2 bg-white shadow-sm p-0 sidebar-mesas">
                <div class="bg-primary text-white p-3">
                    <h5 class="mb-0">
                        <i class="fas fa-exchange-alt mr-2"></i>Reasignar Mesa
                    </h5>
                    <small class="d-block mt-1"><?php echo htmlspecialchars($torneo['nombre'] ?? 'Torneo'); ?></small>
                </div>

                <!-- Selector de Ronda -->
                <div class="p-3 border-bottom bg-light">
                    <label class="font-weight-bold small mb-2 d-block">
                        <i class="fas fa-list-ol mr-1"></i>Ronda:
                    </label>
                    <select id="selector-ronda" 
                            onchange="cambiarRonda(<?php echo $torneo['id']; ?>, this.value)"
                            class="form-control form-control-sm">
                        <?php foreach ($todasLasRondas ?? [] as $r): ?>
                            <option value="<?php echo $r['ronda']; ?>" <?php echo ($r['ronda'] ?? 0) == ($ronda ?? 0) ? 'selected' : ''; ?>>
                                Ronda <?php echo $r['ronda']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Lista de Mesas -->
                <div class="p-3">
                    <h6 class="font-weight-bold small mb-2">
                        <i class="fas fa-table mr-1"></i>Mesas (Ronda <?php echo $ronda ?? 0; ?>)
                    </h6>
                    <div class="list-group list-group-flush">
                        <?php foreach ($todasLasMesas ?? [] as $m): ?>
                            <?php 
                                $esActiva = ($m['numero'] ?? 0) == ($mesaActual ?? 0);
                                $claseMesa = $esActiva ? 'mesa-activa' : 'list-group-item-action';
                            ?>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=reasignar_mesa&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $m['numero']; ?>"
                               class="list-group-item list-group-item-action <?php echo $claseMesa; ?> mesa-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="font-weight-bold">Mesa <?php echo $m['numero']; ?></span>
                                    <?php if ($esActiva): ?>
                                        <i class="fas fa-arrow-right"></i>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Navegación -->
                <div class="p-3 border-top bg-light">
                    <div class="btn-group btn-group-sm w-100 mb-2" role="group">
                        <?php if ($mesaAnterior ?? null): ?>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=reasignar_mesa&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $mesaAnterior; ?>"
                               class="btn btn-secondary">
                                <i class="fas fa-arrow-left mr-1"></i>Anterior
                            </a>
                        <?php endif; ?>
                        <?php if ($mesaSiguiente ?? null): ?>
                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=reasignar_mesa&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $mesaSiguiente; ?>"
                               class="btn btn-secondary">
                                Siguiente<i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $mesaActual; ?>"
                       class="btn btn-primary btn-sm btn-block">
                        <i class="fas fa-keyboard mr-1"></i>Ver Resultados
                    </a>
                </div>
            </div>

            <!-- Contenido Principal -->
            <div class="col-md-9 col-lg-10">
                <div class="container-fluid py-4">
                    <!-- Encabezado -->
                    <div class="card shadow mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-2">
                                        <i class="fas fa-exchange-alt text-primary mr-2"></i>
                                        Reasignar Mesa <?php echo $mesaActual ?? 0; ?>
                                    </h4>
                                    <p class="text-muted mb-0">
                                        Torneo: <strong><?php echo htmlspecialchars($torneo['nombre'] ?? 'N/A'); ?></strong> | 
                                        Ronda: <strong><?php echo $ronda ?? 0; ?></strong>
                                    </p>
                                </div>
                                <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=panel&torneo_id=<?php echo $torneo['id']; ?>" 
                                   class="btn btn-secondary">
                                    <i class="fas fa-arrow-left mr-2"></i>Volver al Panel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mensajes -->
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i>
                            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($jugadores) || count($jugadores) != 4): ?>
                        <div class="card shadow">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-exclamation-triangle text-muted" style="font-size: 4rem;"></i>
                                <p class="text-muted mt-3">La mesa debe tener exactamente 4 jugadores para reasignar</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Formulario de Reasignación -->
                        <form method="POST" 
                              action="<?php echo $base_url; ?>"
                              class="card shadow"
                              onsubmit="event.preventDefault(); reasignarMesaConfirmar(event);">
                            <div class="card-body">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRF::token(), ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="ejecutar_reasignacion">
                                <input type="hidden" name="torneo_id" value="<?php echo $torneo['id']; ?>">
                                <input type="hidden" name="ronda" value="<?php echo $ronda; ?>">
                                <input type="hidden" name="mesa" value="<?php echo $mesaActual; ?>">

                                <div class="row">
                                    <!-- Columna Izquierda: Configuración Actual -->
                                    <div class="col-lg-6 mb-4">
                                        <h5 class="mb-3">
                                            <i class="fas fa-users mr-2"></i>Configuración Actual - Mesa <?php echo $mesaActual; ?>
                                        </h5>
                                        
                                        <!-- Pareja A -->
                                        <div class="card border-primary mb-3">
                                            <div class="card-header bg-primary text-white">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-user-friends mr-2"></i>Pareja A
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <?php foreach ($jugadores as $jugador): ?>
                                                    <?php if ($jugador['secuencia'] <= 2): ?>
                                                        <div class="bg-light rounded p-3 mb-2">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <small class="text-primary font-weight-bold">Posición <?php echo $jugador['secuencia']; ?></small>
                                                                    <div class="font-weight-bold mt-1">
                                                                        <?php echo htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A'); ?>
                                                                    </div>
                                                                </div>
                                                                <span class="badge badge-primary badge-pill" style="font-size: 1.2rem;">
                                                                    <?php echo $jugador['secuencia']; ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <!-- Pareja B -->
                                        <div class="card border-success">
                                            <div class="card-header bg-success text-white">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-user-friends mr-2"></i>Pareja B
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <?php foreach ($jugadores as $jugador): ?>
                                                    <?php if ($jugador['secuencia'] > 2): ?>
                                                        <div class="bg-light rounded p-3 mb-2">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <small class="text-success font-weight-bold">Posición <?php echo $jugador['secuencia']; ?></small>
                                                                    <div class="font-weight-bold mt-1">
                                                                        <?php echo htmlspecialchars($jugador['nombre_completo'] ?? $jugador['nombre'] ?? 'N/A'); ?>
                                                                    </div>
                                                                </div>
                                                                <span class="badge badge-success badge-pill" style="font-size: 1.2rem;">
                                                                    <?php echo $jugador['secuencia']; ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Columna Derecha: Opciones de Reasignación -->
                                    <div class="col-lg-6">
                                        <h5 class="mb-3">
                                            <i class="fas fa-list-check mr-2"></i>Opciones de Reasignación
                                        </h5>
                                        <?php if ($esParejasFijas): ?>
                                            <div class="alert alert-warning py-2">
                                                <i class="fas fa-ban mr-1"></i>
                                                La reasignación de mesa no está disponible para modalidad <strong>Parejas Fijas</strong>.
                                            </div>
                                        <?php endif; ?>

                                        <div class="form-group">
                                            <?php if (!$esParejasFijas): ?>
                                                <!-- Opción 1 -->
                                                <label class="opcion-reasignacion d-block mb-2">
                                                    <input type="radio" name="opcion_reasignacion" value="1" required class="mr-2">
                                                    <span class="font-weight-bold">Opción 1: Intercambiar Posición 1 con 3</span>
                                                    <small class="d-block text-muted">El jugador en posición 1 intercambia con el jugador en posición 3</small>
                                                </label>

                                                <!-- Opción 2 -->
                                                <label class="opcion-reasignacion d-block mb-2">
                                                    <input type="radio" name="opcion_reasignacion" value="2" required class="mr-2">
                                                    <span class="font-weight-bold">Opción 2: Intercambiar Posición 1 con 4</span>
                                                    <small class="d-block text-muted">El jugador en posición 1 intercambia con el jugador en posición 4</small>
                                                </label>

                                                <!-- Opción 3 -->
                                                <label class="opcion-reasignacion d-block mb-2">
                                                    <input type="radio" name="opcion_reasignacion" value="3" required class="mr-2">
                                                    <span class="font-weight-bold">Opción 3: Intercambiar Posición 2 con 3</span>
                                                    <small class="d-block text-muted">El jugador en posición 2 intercambia con el jugador en posición 3</small>
                                                </label>

                                                <!-- Opción 4 -->
                                                <label class="opcion-reasignacion d-block mb-2">
                                                    <input type="radio" name="opcion_reasignacion" value="4" required class="mr-2">
                                                    <span class="font-weight-bold">Opción 4: Intercambiar Posición 2 con 4</span>
                                                    <small class="d-block text-muted">El jugador en posición 2 intercambia con el jugador en posición 4</small>
                                                </label>
                                            <?php endif; ?>

                                        </div>

                                        <!-- Botones -->
                                        <div class="mt-4">
                                            <button type="submit" class="btn btn-primary btn-lg btn-block" <?php echo $esParejasFijas ? 'disabled' : ''; ?>>
                                                <i class="fas fa-check mr-2"></i>Ejecutar Reasignación
                                            </button>
                                            <a href="<?php echo $base_url . ($use_standalone ? '?' : '&'); ?>action=registrar_resultados&torneo_id=<?php echo $torneo['id']; ?>&ronda=<?php echo $ronda; ?>&mesa=<?php echo $mesaActual; ?>"
                                               class="btn btn-secondary btn-block mt-2">
                                                <i class="fas fa-arrow-left mr-2"></i>Volver a Resultados
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
    <script>
        function cambiarRonda(torneoId, nuevaRonda) {
            var mesaNum = <?php echo (int)($mesaActual ?? $mesa ?? 1); ?>;
            window.location.href = '<?php echo $base_url . ($use_standalone ? "?" : "&"); ?>action=reasignar_mesa&torneo_id=' + torneoId + '&ronda=' + nuevaRonda + '&mesa=' + mesaNum;
        }
        
        async function reasignarMesaConfirmar(event) {
            const result = await Swal.fire({
                title: '¿Reasignar mesa?',
                text: '¿Está seguro de reasignar esta mesa? Esta acción cambiará las posiciones de los jugadores.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, reasignar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d'
            });
            
            if (result.isConfirmed) {
                event.target.submit();
            }
        }
    </script>
</body>
</html>


