<?php

declare(strict_types=1);

/**
 * TournamentPhaseHelper - Determina la fase activa del torneo para mostrar solo herramientas relevantes.
 * Workflow: Registro → Preparación → Ejecución → Cierre
 */
class TournamentPhaseHelper
{
    public const FASE_REGISTRO = 'registro';
    public const FASE_PREPARACION = 'preparacion';
    public const FASE_EJECUCION = 'ejecucion';
    public const FASE_CIERRE = 'cierre';

    /**
     * Determina la fase activa según el estado del torneo.
     *
     * @param array $torneo Datos del torneo (id, finalizado, rondas, locked, etc.)
     * @param PDO $pdo Conexión BD
     * @return string FASE_REGISTRO|FASE_PREPARACION|FASE_EJECUCION|FASE_CIERRE
     */
    public static function getFaseActiva(array $torneo, PDO $pdo): string
    {
        $torneo_id = (int)($torneo['id'] ?? 0);
        $finalizado = isset($torneo['finalizado']) && (int)$torneo['finalizado'] === 1;
        $locked = isset($torneo['locked']) && (int)$torneo['locked'] === 1;
        $rondas_programadas = (int)($torneo['rondas'] ?? 0);

        if ($finalizado || $locked) {
            return self::FASE_CIERRE;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT MAX(CAST(partida AS UNSIGNED)) as ultima_ronda,
                       COUNT(DISTINCT partida) as total_rondas
                FROM partiresul WHERE id_torneo = ?
            ");
            $stmt->execute([$torneo_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $ultima_ronda = (int)($row['ultima_ronda'] ?? 0);
            $total_rondas = (int)($row['total_rondas'] ?? 0);
        } catch (Exception $e) {
            return self::FASE_REGISTRO;
        }

        // Sin rondas generadas → Registro
        if ($total_rondas === 0) {
            return self::FASE_REGISTRO;
        }

        // Hay rondas generadas: verificar si todas están completas
        $todas_completas = false;
        if ($rondas_programadas > 0 && $total_rondas >= $rondas_programadas) {
            $stmt = $pdo->prepare("
                SELECT partida,
                       COUNT(*) as total,
                       SUM(CASE WHEN registrado = 1 THEN 1 ELSE 0 END) as registradas
                FROM partiresul WHERE id_torneo = ?
                GROUP BY partida
            ");
            $stmt->execute([$torneo_id]);
            $todas_completas = true;
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ((int)$r['total'] > 0 && (int)$r['registradas'] < (int)$r['total']) {
                    $todas_completas = false;
                    break;
                }
            }
        }

        if ($todas_completas) {
            return self::FASE_CIERRE;
        }

        // Hay rondas generadas pero no todas completas
        // Si no hay resultados registrados → Preparación
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM partiresul WHERE id_torneo = ? AND registrado = 1
        ");
        $stmt->execute([$torneo_id]);
        $con_resultados = (int)$stmt->fetchColumn();

        return $con_resultados > 0 ? self::FASE_EJECUCION : self::FASE_PREPARACION;
    }

    /**
     * Herramientas permitidas por fase.
     */
    public static function getHerramientasPorFase(): array
    {
        return [
            self::FASE_REGISTRO => [
                'revisar_inscripciones',
                'inscribir_sitio',
                'invitar_whatsapp',
                'activar_participantes',
            ],
            self::FASE_PREPARACION => [
                'activar_participantes',
                'generar_rondas',
                'eliminar_ronda',
                'tabla_asignacion',
                'hojas_anotacion',
                'mostrar_resultados',
            ],
            self::FASE_EJECUCION => [
                'tabla_asignacion',
                'hojas_anotacion',
                'ingreso_resultados',
                'mostrar_resultados',
            ],
            self::FASE_CIERRE => [
                'mostrar_resultados',
                'ingreso_resultados', // admin_general puede corregir
                'galeria_fotos',
                'generar_qr_general',
                'generar_qr_personal',
            ],
        ];
    }

    /**
     * Herramientas que siempre se muestran (independientes de fase).
     */
    public static function getHerramientasGlobales(): array
    {
        return ['activar_participantes', 'mostrar_resultados', 'galeria_fotos', 'generar_qr', 'generar_qr_general', 'generar_qr_personal'];
    }

    /**
     * Verifica si una herramienta debe mostrarse en la fase actual.
     */
    public static function mostrarHerramienta(string $action, string $fase): bool
    {
        $globales = self::getHerramientasGlobales();
        if (in_array($action, $globales, true)) {
            return true;
        }
        $por_fase = self::getHerramientasPorFase();
        $permisos = $por_fase[$fase] ?? [];
        return in_array($action, $permisos, true);
    }

    /**
     * Etiqueta amigable de la fase.
     */
    public static function getEtiquetaFase(string $fase): string
    {
        $etiquetas = [
            self::FASE_REGISTRO => 'Registro (Inscripciones)',
            self::FASE_PREPARACION => 'Preparación (Rondas)',
            self::FASE_EJECUCION => 'Ejecución (Resultados)',
            self::FASE_CIERRE => 'Cierre',
        ];
        return $etiquetas[$fase] ?? $fase;
    }
}
