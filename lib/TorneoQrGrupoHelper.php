<?php
declare(strict_types=1);

require_once __DIR__ . '/InscritosHelper.php';

/**
 * Tarjeta QR: integrantes de equipo/pareja (codigo_equipo) y detalle por ronda.
 */
final class TorneoQrGrupoHelper
{
    public static function codigoEquipoInscrito(\PDO $pdo, int $torneoId, int $userId): string
    {
        $st = $pdo->prepare('SELECT TRIM(COALESCE(codigo_equipo, \'\')) AS c FROM inscritos WHERE torneo_id = ? AND id_usuario = ? LIMIT 1');
        $st->execute([$torneoId, $userId]);
        $c = $st->fetchColumn();

        return $c !== false ? (string) $c : '';
    }

    /**
     * @return list<array{id_usuario:int, nombre:string}>
     */
    public static function miembrosMismoCodigo(\PDO $pdo, int $torneoId, string $codigo): array
    {
        if ($codigo === '') {
            return [];
        }
        $sql = 'SELECT i.id_usuario, COALESCE(u.nombre, u.username) AS nombre
                FROM inscritos i
                INNER JOIN usuarios u ON u.id = i.id_usuario
                WHERE i.torneo_id = ? AND TRIM(COALESCE(i.codigo_equipo, \'\')) = ?
                AND ' . InscritosHelper::sqlWhereSoloConfirmadoConAlias('i') . '
                ORDER BY i.id_usuario ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$torneoId, $codigo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id_usuario' => (int) ($r['id_usuario'] ?? 0),
                'nombre' => (string) ($r['nombre'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    public static function statsPartiresulRonda(\PDO $pdo, int $torneoId, int $ronda, array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), static fn ($x) => $x > 0));
        if ($userIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "SELECT id_usuario, mesa, resultado1, resultado2, efectividad, registrado, secuencia
                FROM partiresul
                WHERE id_torneo = ? AND partida = ? AND id_usuario IN ($ph)";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$torneoId, $ronda], $userIds));
        $byUser = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int) ($row['id_usuario'] ?? 0);
            if ($uid > 0) {
                $byUser[$uid] = $row;
            }
        }

        return $byUser;
    }

    /**
     * @param list<array<string, mixed>> $rankingEquipos
     * @return array<string, mixed>|null
     */
    public static function filaEquipoEnRanking(array $rankingEquipos, string $codigo): ?array
    {
        if ($codigo === '') {
            return null;
        }
        foreach ($rankingEquipos as $eq) {
            if ((string) ($eq['codigo_equipo'] ?? '') === $codigo) {
                return $eq;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rankingEquipos
     */
    public static function renderTarjetaHtml(
        \PDO $pdo,
        int $torneoId,
        int $viewerId,
        int $modalidad,
        int $ronda,
        bool $torneoIniciado,
        array $rankingEquipos,
        string $miCodigoEquipo
    ): string {
        if (!in_array($modalidad, [2, 3, 4], true)) {
            return '';
        }

        $esEquipos = ($modalidad === 3);
        $titulo = $esEquipos ? 'Tu equipo' : 'Tu pareja / grupo';
        $codigo = self::codigoEquipoInscrito($pdo, $torneoId, $viewerId);

        if ($codigo === '') {
            return '<div class="qr-grupo-card qr-grupo-card--warn">'
                . '<p class="qr-grupo-tit"><i class="fas fa-users"></i> ' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p class="muted">No hay <strong>código de equipo/pareja</strong> en tu inscripción. Cuando el organizador lo asigne, verás aquí a tus compañeros y el detalle por ronda.</p>'
                . '</div>';
        }

        $miembros = self::miembrosMismoCodigo($pdo, $torneoId, $codigo);
        if ($miembros === []) {
            return '';
        }

        $ids = array_column($miembros, 'id_usuario');
        $statsPorRonda = $torneoIniciado ? self::statsPartiresulRonda($pdo, $torneoId, $ronda, $ids) : [];

        $html = '<div class="qr-grupo-card">';
        $html .= '<p class="qr-grupo-tit"><i class="fas fa-users"></i> ' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $html .= ' <span class="qr-grupo-cod">[' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . ']</span></p>';
        $html .= '<ul class="qr-grupo-miembros">';
        foreach ($miembros as $m) {
            $uid = (int) $m['id_usuario'];
            $yo = ($uid === $viewerId);
            $html .= '<li class="' . ($yo ? 'qr-grupo-yo' : '') . '"><i class="fas fa-user" aria-hidden="true"></i> '
                . htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8')
                . ($yo ? ' <strong>(tú)</strong>' : '')
                . '</li>';
        }
        $html .= '</ul>';

        if (!$torneoIniciado) {
            $html .= '<p class="muted" style="margin:10px 0 0">Al iniciar el torneo verás aquí, por cada ronda, puntos y totales del grupo.</p>';
            $html .= '</div>';

            return $html;
        }

        $html .= '<p class="qr-grupo-ronda-h"><i class="fas fa-calendar-check"></i> Ronda ' . (int) $ronda . ' — detalle</p>';
        $html .= '<div class="tab-wrap"><table class="data-tab qr-grupo-tab">';
        $html .= '<thead><tr><th>Jugador</th><th>Mesa</th><th>Pts</th><th>Ef.</th></tr></thead><tbody>';

        $sumPts = 0;
        $sumEf = 0;
        $nConDato = 0;

        foreach ($miembros as $m) {
            $uid = (int) $m['id_usuario'];
            $st = $statsPorRonda[$uid] ?? null;
            $nombreCorto = mb_strlen($m['nombre']) > 22 ? mb_substr($m['nombre'], 0, 20) . '…' : $m['nombre'];
            if ($st === null) {
                $html .= '<tr class="' . ($uid === $viewerId ? 'tab-yo' : '') . '">'
                    . '<td>' . htmlspecialchars($nombreCorto, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td colspan="3" class="muted">Sin partida registrada esta ronda</td></tr>';
                continue;
            }
            $mesa = (int) ($st['mesa'] ?? 0);
            $r1 = (int) ($st['resultado1'] ?? 0);
            $ef = (int) ($st['efectividad'] ?? 0);
            if ($mesa === 0) {
                $html .= '<tr class="' . ($uid === $viewerId ? 'tab-yo' : '') . '">'
                    . '<td>' . htmlspecialchars($nombreCorto, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>BYE</td><td>—</td><td>—</td></tr>';
                continue;
            }
            $sumPts += $r1;
            $sumEf += $ef;
            ++$nConDato;
            $html .= '<tr class="' . ($uid === $viewerId ? 'tab-yo' : '') . '">'
                . '<td>' . htmlspecialchars($nombreCorto, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . $mesa . '</td>'
                . '<td>' . $r1 . '</td>'
                . '<td>' . $ef . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';

        if ($esEquipos) {
            $filaEq = self::filaEquipoEnRanking($rankingEquipos, $codigo);
            $posEq = $filaEq !== null ? (int) ($filaEq['posicion'] ?? 0) : 0;
            $ptsEq = $filaEq !== null ? (int) ($filaEq['puntos'] ?? 0) : 0;
            $ganEq = $filaEq !== null ? (int) ($filaEq['ganados'] ?? 0) : 0;
            $perEq = $filaEq !== null ? (int) ($filaEq['perdidos'] ?? 0) : 0;
            $nomEq = $filaEq !== null ? (string) ($filaEq['nombre_equipo'] ?? $codigo) : $codigo;

            $html .= '<div class="qr-grupo-totales">';
            $html .= '<p class="qr-grupo-totales__k">Totales del equipo <strong>' . htmlspecialchars($nomEq, ENT_QUOTES, 'UTF-8') . '</strong></p>';
            $html .= '<div class="stat-grid">';
            $html .= '<div class="stat-box yo"><span class="k">Pos. equipo</span><div class="v">' . ($posEq > 0 ? $posEq . 'º' : '—') . '</div></div>';
            $html .= '<div class="stat-box"><span class="k">Pts equipo</span><div class="v">' . $ptsEq . '</div></div>';
            $html .= '<div class="stat-box"><span class="k">G / P</span><div class="v">' . $ganEq . ' / ' . $perEq . '</div></div>';
            $html .= '<div class="stat-box"><span class="k">Suma pts ronda ' . (int) $ronda . '</span><div class="v">' . $sumPts . '</div></div>';
            $html .= '</div>';
            $html .= '<p class="muted" style="margin-top:8px">La suma de puntos de la ronda es la suma del resultado propio (Pts) de cada jugador en esta ronda. La posición y puntos del equipo son los del ranking general.</p>';
            $html .= '</div>';
        } else {
            $html .= '<div class="qr-grupo-totales">';
            $html .= '<p class="qr-grupo-totales__k">Totales del grupo en esta ronda</p>';
            $html .= '<div class="stat-grid">';
            $html .= '<div class="stat-box"><span class="k">Suma Pts</span><div class="v">' . $sumPts . '</div></div>';
            $html .= '<div class="stat-box"><span class="k">Media Ef.</span><div class="v">' . ($nConDato > 0 ? (int) round($sumEf / $nConDato) : 0) . '%</div></div>';
            $html .= '</div>';
            $html .= '<p class="muted" style="margin-top:8px">En la pestaña <strong>General</strong> ves la clasificación individual de jugadores.</p>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
