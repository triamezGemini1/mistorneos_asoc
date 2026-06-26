<?php
declare(strict_types=1);

/**
 * Punto de entrada único para recalcular estadísticas desde partiresul y puntos de ranking (ptosrnk)
 * cada vez que se consultan resultados públicos o el ranking acumulado de atletas.
 *
 * POLÍTICA DE NEGOCIO (implementación detallada en {@see recalcularPosiciones()} de modules/torneo_gestion.php):
 *
 * 1) Tabla `clasiranking`: por `tipo_torneo` (1=individual, 2=parejas/parejas fijas, 3=equipos) define
 *    filas por número de clasificación con: puntos por posición, puntos por partida ganada, puntos de asistencia/participación.
 *
 * 2) Límite de filas de tabla aplicables por modalidad (variable `$limitePosiciones` en código):
 *    - Individual: 30
 *    - Parejas (2) y parejas fijas (4): 20
 *    - Equipos (3): 10
 *
 * 3) Hasta ese límite (clasificación 1..N dentro del límite):
 *    ptosrnk = puntos_posicion_tabla + (partidas_ganadas × puntos_por_partida_ganada_fila) + puntos_asistencia_fila
 *    - Individual: la clasificación es la posición del jugador en el torneo (orden por ganados/efectividad/puntos).
 *    - Parejas / equipos: la componente "puntos_posicion" de la tabla es la de la UNIDAD (misma fila para todos
 *      los integrantes según `clasiequi` / posición del equipo o pareja); las partidas ganadas aplican como
 *      rendimiento (agregado por unidad en modalidades 2 y 4; individuales en modalidad 3 equipos).
 *
 * 4) Por encima del límite (últimos puestos): no se aplican puntos_posicion de tabla; se usan la tarifa de
 *    partidas ganadas y asistencia definida para "fuera de tabla" (misma referencia que la última fila dentro
 *    del límite o respaldo desde `clasiranking`).
 *
 * 5) Ingreso de resultados: el formulario solo escribe `partiresul`. Al cerrar la ronda (mesas pendientes = 0)
 *    se invoca {@see actualizarClasificacionDesdePartiresul()} (partiresul → inscritos + posiciones).
 * 6) Consulta de reportes/posiciones en pantalla: puede forzar el mismo proceso al abrir clasificación.
 */
final class RankingTorneoRecalc
{
    /** @var array<int, true> Evita repetir el recálculo del mismo torneo en una sola petición HTTP. */
    private static array $recalculadoEnEstaPeticion = [];

    /**
     * Actualiza estadísticas de partidas y recalcula ranking del torneo (misma lógica que la gestión interna).
     * Requiere definir TORNEO_GESTION_SKIP_AUTH y TORNEO_GESTION_SKIP_ROUTER antes de cargar torneo_gestion.php.
     */
    public static function actualizarEstadisticasYRanking(int $torneo_id): void
    {
        $torneo_id = (int) $torneo_id;
        if ($torneo_id <= 0) {
            return;
        }
        if (isset(self::$recalculadoEnEstaPeticion[$torneo_id])) {
            return;
        }
        self::$recalculadoEnEstaPeticion[$torneo_id] = true;
        $path = dirname(__DIR__) . '/modules/torneo_gestion.php';
        if (! is_readable($path)) {
            return;
        }
        if (! function_exists('actualizarEstadisticasInscritos')) {
            if (! defined('TORNEO_GESTION_SKIP_AUTH')) {
                define('TORNEO_GESTION_SKIP_AUTH', true);
            }
            if (! defined('TORNEO_GESTION_SKIP_ROUTER')) {
                define('TORNEO_GESTION_SKIP_ROUTER', true);
            }
            require_once $path;
        }
        if (! function_exists('actualizarEstadisticasInscritos')) {
            return;
        }
        try {
            if (function_exists('actualizarClasificacionDesdePartiresul')) {
                actualizarClasificacionDesdePartiresul($torneo_id);
            } else {
                actualizarEstadisticasInscritos($torneo_id);
            }
        } catch (Throwable $e) {
            error_log('RankingTorneoRecalc::actualizarEstadisticasYRanking: ' . $e->getMessage());
        }
    }
}
