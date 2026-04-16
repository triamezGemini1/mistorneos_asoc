<?php
declare(strict_types=1);

/**
 * Lectura resiliente de la tabla clasiranking para el cálculo de ptosrnk.
 *
 * Evita dos fallos frecuentes:
 * 1) SELECT que incluye puntos_asistencia cuando la columna aún no existe (migración pendiente).
 * 2) Fila exacta inexistente para una clasificación: se usa la mejor fila con clasificacion <= K
 *    (misma lógica que “tabla por lugares” escalonada).
 */
final class ClasirankingRankingHelper
{
    private static ?bool $tienePuntosAsistencia = null;

    public static function tieneColumnaPuntosAsistencia(PDO $pdo): bool
    {
        if (self::$tienePuntosAsistencia !== null) {
            return self::$tienePuntosAsistencia;
        }
        try {
            $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $st = $pdo->query("PRAGMA table_info(clasiranking)");
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($rows as $r) {
                    if (isset($r['name']) && strtolower((string) $r['name']) === 'puntos_asistencia') {
                        self::$tienePuntosAsistencia = true;

                        return true;
                    }
                }
                self::$tienePuntosAsistencia = false;

                return false;
            }
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clasiranking' AND COLUMN_NAME = 'puntos_asistencia'
            ");
            self::$tienePuntosAsistencia = $stmt && ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            self::$tienePuntosAsistencia = false;
        }

        return self::$tienePuntosAsistencia;
    }

    /**
     * @return array{puntos_posicion: int, puntos_por_partida_ganada: int, puntos_asistencia: int}|null
     */
    public static function obtenerFilaParaClasificacion(
        PDO $pdo,
        int $tipoTorneo,
        int $clasificacion,
        int $limiteTabla
    ): ?array {
        if ($clasificacion < 1) {
            return null;
        }
        $tienePa = self::tieneColumnaPuntosAsistencia($pdo);
        $selBase = 'puntos_posicion, puntos_por_partida_ganada';
        $selAsist = $tienePa ? ', COALESCE(puntos_asistencia, 1) AS puntos_asistencia' : '';

        $normalizar = static function (?array $row) use ($tienePa): ?array {
            if ($row === null || $row === []) {
                return null;
            }
            $pp = (int) ($row['puntos_posicion'] ?? 0);
            $ppp = (int) ($row['puntos_por_partida_ganada'] ?? 0);
            $pa = $tienePa ? max(1, (int) ($row['puntos_asistencia'] ?? 1)) : 1;

            return ['puntos_posicion' => $pp, 'puntos_por_partida_ganada' => $ppp, 'puntos_asistencia' => $pa];
        };

        $ejecutar = static function (string $sql, array $params) use ($pdo, $normalizar): ?array {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                return $normalizar($row === false ? null : $row);
            } catch (Throwable $e) {
                return null;
            }
        };

        // 1) Fila exacta (lugar K en la tabla de puntos)
        $sql1 = "SELECT {$selBase}{$selAsist} FROM clasiranking WHERE tipo_torneo = ? AND clasificacion = ? LIMIT 1";
        $fila = $ejecutar($sql1, [$tipoTorneo, $clasificacion]);
        if ($fila !== null) {
            return $fila;
        }

        // 2) Mejor fila definida sin superar el lugar del jugador (cubre huecos en la tabla)
        $c = min($clasificacion, $limiteTabla);
        $sql2 = "SELECT {$selBase}{$selAsist} FROM clasiranking WHERE tipo_torneo = ? AND clasificacion <= ? ORDER BY clasificacion DESC LIMIT 1";
        $fila = $ejecutar($sql2, [$tipoTorneo, $c]);
        if ($fila !== null) {
            return $fila;
        }

        // 3) Primera fila disponible del tipo (p. ej. solo cargaron datos desde un lugar intermedio)
        $sql3 = "SELECT {$selBase}{$selAsist} FROM clasiranking WHERE tipo_torneo = ? ORDER BY clasificacion ASC LIMIT 1";

        return $ejecutar($sql3, [$tipoTorneo]);
    }
}
