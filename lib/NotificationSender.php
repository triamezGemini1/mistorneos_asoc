<?php
/**
 * Clase unificada para envío de notificaciones
 * Soporta: WhatsApp (enlace wa.me), Email (PHPMailer), Telegram (Bot API)
 */
require_once __DIR__ . '/TelegramBot.php';
if (!class_exists('Env')) {
    require_once __DIR__ . '/Env.php';
    Env::load(__DIR__ . '/../.env');
}

class NotificationSender {
    
    /** Obtiene variable de entorno (Env o $_ENV) */
    private static function env(string $key, $default = '') {
        if (class_exists('Env')) {
            return Env::get($key, $default);
        }
        return $_ENV[$key] ?? $default;
    }

    /** Nombre remitente: MAIL_FROM_NAME en .env o marca del segmento. */
    private static function mailFromName(): string {
        $fromEnv = trim((string) self::env('MAIL_FROM_NAME', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        if (! class_exists('Branding', false)) {
            $brandingFile = __DIR__ . '/Branding.php';
            if (is_file($brandingFile)) {
                if (! class_exists('SegmentConfig', false)) {
                    require_once __DIR__ . '/SegmentConfig.php';
                }
                require_once $brandingFile;
                if (class_exists('SegmentConfig', false)) {
                    SegmentConfig::boot();
                }
            }
        }

        return class_exists('Branding', false) ? Branding::mailFromName() : 'La Estación del Dominó';
    }
    
    /**
     * Genera URL de WhatsApp wa.me con mensaje prellenado
     * @param string $telefono Número con código país (ej: 584241234567)
     * @param string $mensaje Texto del mensaje
     * @return string URL completa
     */
    public static function whatsappLink(string $telefono, string $mensaje): string {
        $telefono = preg_replace('/[^0-9]/', '', $telefono);
        if ($telefono && $telefono[0] == '0') $telefono = substr($telefono, 1);
        if ($telefono && strlen($telefono) == 10 && !str_starts_with($telefono, '58')) {
            $telefono = '58' . $telefono;
        }
        $encoded = urlencode($mensaje);
        return "https://wa.me/{$telefono}?text={$encoded}";
    }
    
    /**
     * Envía email mediante PHPMailer
     * @param string $email Destinatario
     * @param string $asunto Asunto del correo
     * @param string $mensaje Cuerpo (HTML o texto)
     * @param string $nombre_destinatario Nombre para personalizar
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public static function sendEmail(string $email, string $asunto, string $mensaje, string $nombre_destinatario = ''): array {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido'];
        }
        
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return ['ok' => false, 'error' => 'PHPMailer no disponible'];
        }
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = self::env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth = true;
            $mail->Username = self::env('MAIL_USERNAME', '');
            $mail->Password = self::env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)self::env('MAIL_PORT', 587);
            
            $mail->setFrom(self::env('MAIL_FROM_ADDRESS', self::env('MAIL_FROM', 'noreply@mistorneos.com')), self::mailFromName());
            $mail->addAddress($email, $nombre_destinatario ?: '');
            $mail->Subject = $asunto;
            $mail->isHTML(true);
            $mail->Body = nl2br(htmlspecialchars($mensaje));
            
            $mail->send();
            return ['ok' => true, 'error' => null];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Envía email HTML (cuerpo ya formateado en HTML)
     * @param string $email Destinatario
     * @param string $asunto Asunto
     * @param string $html_body Cuerpo HTML
     * @param string $nombre_destinatario Nombre para personalizar
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public static function sendEmailHtml(string $email, string $asunto, string $html_body, string $nombre_destinatario = ''): array {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido'];
        }
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return ['ok' => false, 'error' => 'PHPMailer no disponible'];
        }
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = self::env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth = true;
            $mail->Username = self::env('MAIL_USERNAME', '');
            $mail->Password = self::env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)self::env('MAIL_PORT', 587);
            $mail->setFrom(self::env('MAIL_FROM_ADDRESS', self::env('MAIL_FROM', 'noreply@mistorneos.com')), self::mailFromName());
            $mail->addAddress($email, $nombre_destinatario ?: '');
            $mail->Subject = $asunto;
            $mail->isHTML(true);
            $mail->Body = $html_body;
            $mail->send();
            return ['ok' => true, 'error' => null];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Envía mensaje por Telegram Bot API
     * @param string $chat_id Chat ID del destinatario
     * @param string $mensaje Texto a enviar
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public static function sendTelegram(string $chat_id, string $mensaje): array {
        $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        return TelegramBot::sendMessage($token, $chat_id, $mensaje);
    }
    
    /**
     * Reemplaza variables en el mensaje: {nombre}, {torneo}, {club}, etc.
     */
    public static function replaceVariables(string $mensaje, array $vars): string {
        foreach ($vars as $key => $value) {
            $mensaje = str_replace('{' . $key . '}', (string)$value, $mensaje);
        }
        return $mensaje;
    }
}
