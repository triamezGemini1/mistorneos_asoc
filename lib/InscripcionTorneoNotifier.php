<?php

declare(strict_types=1);

require_once __DIR__ . '/NotificationManager.php';
require_once __DIR__ . '/app_helpers.php';

/**
 * Notificación al atleta tras inscripción (web + Telegram), alineada con landing/tournament_register.
 */
final class InscripcionTorneoNotifier
{
  public static function notificarTrasInscripcion(
    PDO $pdo,
    int $idUsuario,
    int $torneoId,
    int $idClub,
    int $idInscrito = 0
  ): void {
    if ($idUsuario <= 0 || $torneoId <= 0) {
      return;
    }

    $stU = $pdo->prepare('
      SELECT id, nombre, username, celular, telegram_chat_id
      FROM usuarios WHERE id = ? LIMIT 1
    ');
    $stU->execute([$idUsuario]);
    $usuario = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
      return;
    }

    $orgJoin = function_exists('torneoOrgJoinExpr')
      ? torneoOrgJoinExpr('t', 'o')
      : 'LEFT JOIN organizaciones o ON o.id = t.club_responsable';
    $stT = $pdo->prepare("
      SELECT t.*, o.nombre AS organizacion_nombre, o.telefono AS org_telefono,
             o.responsable AS delegado, c.nombre AS club_nombre
      FROM tournaments t
      {$orgJoin}
      LEFT JOIN clubes c ON c.id = ?
      WHERE t.id = ?
      LIMIT 1
    ");
    $stT->execute([$idClub > 0 ? $idClub : null, $torneoId]);
    $torneo = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
      return;
    }

    $nombre = trim((string) ($usuario['nombre'] ?? $usuario['username'] ?? 'Atleta'));
    $costo = (float) ($torneo['costo'] ?? 0);
    $paymentId = 0;
    $urlPago = '#';

    if ($costo > 0) {
      try {
        $stP = $pdo->prepare("
          INSERT INTO payments (torneo_id, club_id, amount, method, status, created_at)
          VALUES (?, ?, ?, 'pendiente', 'pendiente', NOW())
        ");
        $stP->execute([$torneoId, $idClub > 0 ? $idClub : null, $costo]);
        $paymentId = (int) $pdo->lastInsertId();
        if ($paymentId > 0) {
          $base = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
          $urlPago = $base . '/report_payment.php?payment_id=' . $paymentId;
        }
      } catch (Throwable $e) {
        error_log('InscripcionTorneoNotifier payments: ' . $e->getMessage());
      }
    }

    $fechaTor = !empty($torneo['fechator']) ? date('d/m/Y', strtotime((string) $torneo['fechator'])) : '—';
    $lugar = trim((string) ($torneo['lugar'] ?? ''));
    $orgNombre = trim((string) ($torneo['organizacion_nombre'] ?? 'FVD'));
    $clubNombre = trim((string) ($torneo['club_nombre'] ?? ''));

    $mensaje = "✅ *INSCRIPCIÓN EXITOSA*\n\n";
    $mensaje .= 'Hola *' . $nombre . "*\n\n";
    $mensaje .= "Tu inscripción en el torneo ha sido registrada exitosamente.\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "📋 *DETALLES DE INSCRIPCIÓN*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= '🏆 *Torneo:* ' . (string) ($torneo['nombre'] ?? '') . "\n";
    $mensaje .= '📅 *Fecha:* ' . $fechaTor . "\n";
    if ($lugar !== '') {
      $mensaje .= '📍 *Lugar:* ' . $lugar . "\n";
    }
    if ($clubNombre !== '') {
      $mensaje .= '🏢 *Asociación:* ' . $clubNombre . "\n";
    }
    if ($costo > 0) {
      $mensaje .= '💰 *Costo:* $' . number_format($costo, 2) . "\n\n";
      $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
      $mensaje .= "💳 *INFORMACIÓN DE PAGO*\n";
      $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
      $mensaje .= 'Para completar tu inscripción, realiza el pago de *$' . number_format($costo, 2) . "*\n\n";
      if (!empty($torneo['org_telefono'])) {
        $mensaje .= '📞 Teléfono: ' . (string) $torneo['org_telefono'] . "\n";
      }
      if (!empty($torneo['delegado'])) {
        $mensaje .= 'Delegado: ' . (string) $torneo['delegado'] . "\n";
      }
      $mensaje .= "\n🔗 *Reportar pago:*\n" . $urlPago . "\n\n";
    }
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "¡Gracias por participar! 🎲\n\n";
    $mensaje .= '_' . $orgNombre . '_';

    $urlDestino = $urlPago !== '#' ? $urlPago : (
      class_exists('AppHelpers')
        ? AppHelpers::url('index.php', ['page' => 'user_notificaciones'])
        : '#'
    );

    $datosJson = [
      'tipo' => 'inscripcion_torneo_confirmada',
      'nombre' => $nombre,
      'usuario_id' => $idUsuario,
      'torneo' => (string) ($torneo['nombre'] ?? ''),
      'fecha_torneo' => $fechaTor,
      'lugar_torneo' => $lugar,
      'asociacion' => $clubNombre,
      'costo' => $costo > 0 ? number_format($costo, 2) : '',
      'organizacion_nombre' => $orgNombre,
      'url_pago' => $urlPago,
      'inscrito_id' => $idInscrito,
    ];

    $nm = new NotificationManager($pdo);
    $nm->programarMasivoPersonalizado([[
      'id' => $idUsuario,
      'telegram_chat_id' => trim((string) ($usuario['telegram_chat_id'] ?? '')) ?: null,
      'mensaje' => $mensaje,
      'url_destino' => $urlDestino,
      'datos_json' => $datosJson,
    ]]);
  }

  /**
   * Notifica al atleta que su pago fue validado (web push + tarjeta).
   */
  public static function notificarPagoValidado(
    PDO $pdo,
    int $idUsuario,
    int $torneoId,
    int $idClub,
    int $idInscrito = 0
  ): void {
    if ($idUsuario <= 0 || $torneoId <= 0) {
      return;
    }

    $stU = $pdo->prepare('SELECT id, nombre, username, telegram_chat_id FROM usuarios WHERE id = ? LIMIT 1');
    $stU->execute([$idUsuario]);
    $usuario = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
      return;
    }

    $orgJoin = function_exists('torneoOrgJoinExpr')
      ? torneoOrgJoinExpr('t', 'o')
      : 'LEFT JOIN organizaciones o ON o.id = t.club_responsable';
    $stT = $pdo->prepare("
      SELECT t.nombre, t.fechator, t.lugar, t.costo, o.nombre AS organizacion_nombre, c.nombre AS club_nombre
      FROM tournaments t
      {$orgJoin}
      LEFT JOIN clubes c ON c.id = ?
      WHERE t.id = ?
      LIMIT 1
    ");
    $stT->execute([$idClub > 0 ? $idClub : null, $torneoId]);
    $torneo = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
      return;
    }

    $nombre = trim((string) ($usuario['nombre'] ?? $usuario['username'] ?? 'Atleta'));
    $torneoNombre = trim((string) ($torneo['nombre'] ?? 'Torneo'));
    $fechaTor = !empty($torneo['fechator']) ? date('d/m/Y', strtotime((string) $torneo['fechator'])) : '—';
    $lugar = trim((string) ($torneo['lugar'] ?? ''));
    $clubNombre = trim((string) ($torneo['club_nombre'] ?? ''));
    $orgNombre = trim((string) ($torneo['organizacion_nombre'] ?? 'FVD'));
    $costo = (float) ($torneo['costo'] ?? 0);

    $mensaje = "✅ *PAGO VALIDADO*\n\n";
    $mensaje .= 'Hola *' . $nombre . "*\n\n";
    $mensaje .= "Su pago de inscripción fue confirmado por la administración.\n\n";
    $mensaje .= '🏆 *Torneo:* ' . $torneoNombre . "\n";
    $mensaje .= '📅 *Fecha:* ' . $fechaTor . "\n";
    if ($lugar !== '') {
      $mensaje .= '📍 *Lugar:* ' . $lugar . "\n";
    }
    if ($clubNombre !== '') {
      $mensaje .= '🏢 *Asociación:* ' . $clubNombre . "\n";
    }
    if ($costo > 0) {
      $mensaje .= '💰 *Monto:* $' . number_format($costo, 2) . "\n";
    }
    $mensaje .= "\nYa está confirmado para participar.\n\n_" . $orgNombre . '_';

    $urlDestino = class_exists('AppHelpers')
      ? AppHelpers::url('index.php', ['page' => 'user_notificaciones'])
      : '#';

    $datosJson = [
      'tipo' => 'inscripcion_pago_validado',
      'nombre' => $nombre,
      'usuario_id' => $idUsuario,
      'torneo' => $torneoNombre,
      'fecha_torneo' => $fechaTor,
      'lugar_torneo' => $lugar,
      'asociacion' => $clubNombre,
      'monto' => $costo > 0 ? number_format($costo, 2) : '',
      'organizacion_nombre' => $orgNombre,
      'inscrito_id' => $idInscrito,
    ];

    $nm = new NotificationManager($pdo);
    $nm->programarMasivoPersonalizado([[
      'id' => $idUsuario,
      'telegram_chat_id' => trim((string) ($usuario['telegram_chat_id'] ?? '')) ?: null,
      'mensaje' => $mensaje,
      'url_destino' => $urlDestino,
      'datos_json' => $datosJson,
    ]]);
  }

  /**
   * Datos para recordatorio de pago (tarjeta web + WhatsApp).
   *
   * @return array{mensaje:string,mensaje_plano:string,datos_json:array<string,mixed>,url_destino:string}|null
   */
  public static function construirDatosRecordatorioPago(
    PDO $pdo,
    int $idUsuario,
    int $torneoId,
    int $idClub,
    int $idInscrito = 0
  ): ?array {
    if ($idUsuario <= 0 || $torneoId <= 0) {
      return null;
    }

    $stU = $pdo->prepare('SELECT id, nombre, username FROM usuarios WHERE id = ? LIMIT 1');
    $stU->execute([$idUsuario]);
    $usuario = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
      return null;
    }

    $orgJoin = function_exists('torneoOrgJoinExpr')
      ? torneoOrgJoinExpr('t', 'o')
      : 'LEFT JOIN organizaciones o ON o.id = t.club_responsable';
    $stT = $pdo->prepare("
      SELECT t.nombre, t.fechator, t.lugar, t.costo, o.nombre AS organizacion_nombre, c.nombre AS club_nombre
      FROM tournaments t
      {$orgJoin}
      LEFT JOIN clubes c ON c.id = ?
      WHERE t.id = ?
      LIMIT 1
    ");
    $stT->execute([$idClub > 0 ? $idClub : null, $torneoId]);
    $torneo = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
      return null;
    }

    $nombre = trim((string) ($usuario['nombre'] ?? $usuario['username'] ?? 'Atleta'));
    $torneoNombre = trim((string) ($torneo['nombre'] ?? 'Torneo'));
    $fechaTor = !empty($torneo['fechator']) ? date('d/m/Y', strtotime((string) $torneo['fechator'])) : '—';
    $fechaLimite = self::fechaLimitePago($torneo['fechator'] ?? null);
    $lugar = trim((string) ($torneo['lugar'] ?? ''));
    $clubNombre = trim((string) ($torneo['club_nombre'] ?? ''));
    $orgNombre = trim((string) ($torneo['organizacion_nombre'] ?? 'FVD'));
    $costo = (float) ($torneo['costo'] ?? 0);

    $mensaje = "⏰ *RECORDATORIO DE PAGO*\n\n";
    $mensaje .= 'Hola *' . $nombre . "*\n\n";
    $mensaje .= "Tiene pendiente el pago de su inscripción.\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n";
    $mensaje .= "📋 *TARJETA DE INSCRIPCIÓN*\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= '🏆 *Torneo:* ' . $torneoNombre . "\n";
    $mensaje .= '📅 *Fecha del evento:* ' . $fechaTor . "\n";
    if ($lugar !== '') {
      $mensaje .= '📍 *Lugar:* ' . $lugar . "\n";
    }
    if ($clubNombre !== '') {
      $mensaje .= '🏢 *Asociación:* ' . $clubNombre . "\n";
    }
    if ($costo > 0) {
      $mensaje .= '💰 *Monto pendiente:* $' . number_format($costo, 2) . "\n";
    }
    $mensaje .= "\n⚠️ *Fecha límite de pago:* " . $fechaLimite . "\n";
    $mensaje .= "Por favor realice su pago antes de esa fecha para mantener su cupo.\n\n";
    $mensaje .= '_' . $orgNombre . '_';

    $mensajePlano = preg_replace('/\*([^*]+)\*/', '$1', $mensaje);

    $base = class_exists('AppHelpers') ? rtrim(AppHelpers::getPublicUrl(), '/') : '';
    $urlDestino = $base !== '' ? $base . '/report_payment.php' : '#';

    $datosJson = [
      'tipo' => 'inscripcion_recordatorio_pago',
      'nombre' => $nombre,
      'usuario_id' => $idUsuario,
      'torneo' => $torneoNombre,
      'fecha_torneo' => $fechaTor,
      'fecha_limite_pago' => $fechaLimite,
      'lugar_torneo' => $lugar,
      'asociacion' => $clubNombre,
      'costo' => $costo > 0 ? number_format($costo, 2) : '',
      'organizacion_nombre' => $orgNombre,
      'inscrito_id' => $idInscrito,
      'nota' => 'Recuerde cancelar antes del ' . $fechaLimite,
    ];

    return [
      'mensaje' => $mensaje,
      'mensaje_plano' => $mensajePlano,
      'datos_json' => $datosJson,
      'url_destino' => $urlDestino,
    ];
  }

  /**
   * Recordatorio de pago: web push + Telegram (+ mensaje para WhatsApp).
   */
  public static function notificarRecordatorioPago(
    PDO $pdo,
    int $idUsuario,
    int $torneoId,
    int $idClub,
    int $idInscrito = 0
  ): void {
    $stU = $pdo->prepare('SELECT id, telegram_chat_id FROM usuarios WHERE id = ? LIMIT 1');
    $stU->execute([$idUsuario]);
    $usuario = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
      return;
    }

    $payload = self::construirDatosRecordatorioPago($pdo, $idUsuario, $torneoId, $idClub, $idInscrito);
    if ($payload === null) {
      return;
    }

    $nm = new NotificationManager($pdo);
    $nm->programarMasivoPersonalizado([[
      'id' => $idUsuario,
      'telegram_chat_id' => trim((string) ($usuario['telegram_chat_id'] ?? '')) ?: null,
      'mensaje' => $payload['mensaje'],
      'url_destino' => $payload['url_destino'],
      'datos_json' => $payload['datos_json'],
    ]]);
  }

  private static function fechaLimitePago(?string $fechator): string
  {
    if ($fechator === null || $fechator === '') {
      return 'consultar con la organización';
    }
    try {
      $dt = new DateTime($fechator);
      $dt->modify('-3 days');

      return $dt->format('d/m/Y');
    } catch (Throwable $e) {
      return date('d/m/Y', strtotime($fechator));
    }
  }
}
