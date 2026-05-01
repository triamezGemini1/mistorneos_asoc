<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/mn_require_mesa_repository.php';
mn_require_mesa_repository();
require_once __DIR__ . '/MesaAsignacionRoundsTrait.php';
require_once __DIR__ . '/MesaAsignacionQueueTrait.php';
require_once __DIR__ . '/MesaAsignacionConflictos1Trait.php';
require_once __DIR__ . '/MesaAsignacionConflictos2Trait.php';
require_once __DIR__ . '/MesaAsignacionClubInterclubTrait.php';
require_once __DIR__ . '/MesaAsignacionLimiteClubMesaTrait.php';

/**
 * Algoritmo de asignación de jugadores a mesas (sin HTML; persistencia vía MesaRepository).
 */
class MesaAsignacionAlgorithm
{
    use MesaAsignacionRoundsTrait;
    use MesaAsignacionQueueTrait;
    use MesaAsignacionConflictos1Trait;
    use MesaAsignacionConflictos2Trait;
    use MesaAsignacionClubInterclubTrait;
    use MesaAsignacionLimiteClubMesaTrait;

    public const JUGADORES_POR_MESA = 4;

    protected MesaRepository $repo;

    /** Id del admin que genera la ronda (prioridad sobre sesión en partiresul.registrado_por). */
    private ?int $registradoPorUsuarioId = null;

    public function __construct(MesaRepository $repo)
    {
        $this->repo = $repo;
    }

    public function setRegistradoPorUsuarioId(?int $id): void
    {
        $this->registradoPorUsuarioId = ($id !== null && $id > 0) ? $id : null;
    }

    /**
     * @return array<int, array<int, true>>
     */
    private function obtenerMatrizCompañerosParaRonda(int $torneoId, int $hastaRonda): array
    {
        $matriz = $this->repo->obtenerMatrizCompañerosDesdeHistorial($torneoId, $hastaRonda);
        if ($matriz !== []) {
            return $matriz;
        }
        $parejas = $this->repo->obtenerParejasRondasAnteriores($torneoId, $hastaRonda);

        return MesaAsignacionMatriz::crearMatrizCompañeros($parejas);
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function reordenarParaLimitarBye(array $ranking, array $conteoBye, int $numBye, int $numMesas): array
    {
        $posicionKey = 0;
        $conClasif = [];
        foreach ($ranking as $j) {
            $id = (int) ($j['id_usuario'] ?? 0);
            $byeCount = $conteoBye[$id] ?? 0;
            $pos = (int) ($j['posicion'] ?? $posicionKey);
            $conClasif[] = ['bye_count' => $byeCount, 'posicion' => $pos, 'id_usuario' => $id, 'jugador' => $j];
            $posicionKey++;
        }
        usort($conClasif, function ($a, $b) {
            if ($a['bye_count'] !== $b['bye_count']) {
                return $a['bye_count'] - $b['bye_count'];
            }

            return $b['posicion'] - $a['posicion'];
        });
        $ordenados = array_column($conClasif, 'jugador');
        $totalParaMesas = $numMesas * self::JUGADORES_POR_MESA;
        $jugadoresBye = array_slice($ordenados, 0, $numBye);
        $resto = array_slice($ordenados, $numBye, $totalParaMesas);
        usort($resto, function ($a, $b) {
            $pa = (int) ($a['posicion'] ?? 0);
            $pb = (int) ($b['posicion'] ?? 0);

            return $pa - $pb;
        });

        return [$resto, $jugadoresBye];
    }
}
