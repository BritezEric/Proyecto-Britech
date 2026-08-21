<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envío de correos con PHPMailer + SMTP.
 * Las credenciales salen de la config (que las lee del .env), nunca del código.
 */
class Mailer
{
    /**
     * @throws Exception si el envío falla (el que llama decide qué hacer).
     */
    public static function enviar(string $paraEmail, string $paraNombre, string $asunto, string $htmlCuerpo): void
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $m = $config['mail'];

        $mail = new PHPMailer(true); // true = lanza excepciones ante errores
        $mail->isSMTP();
        $mail->Host       = $m['host'];
        $mail->Port       = (int) $m['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $m['user'];
        $mail->Password   = $m['password'];
        $mail->SMTPSecure = ($m['secure'] === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($m['from_email'], $m['from_nombre']);
        $mail->addAddress($paraEmail, $paraNombre);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $htmlCuerpo;

        $mail->send();
    }
}
