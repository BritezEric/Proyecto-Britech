<?php
/**
 * Configura el webhook de Telegram en el SERVER (así los /start llegan solos,
 * sin polling, para cualquier usuario).
 *
 *   php tools/telegram-webhook.php set https://TU-DOMINIO/api/telegram/webhook
 *   php tools/telegram-webhook.php info
 *   php tools/telegram-webhook.php delete     (vuelve a modo polling/local)
 *
 * La URL tiene que ser HTTPS y pública (ej. el dominio fijo de ngrok del server).
 */

require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/config.php';
$token = (string) ($cfg['telegram']['token'] ?? '');
if ($token === '') { fwrite(STDERR, "TG_TOKEN no configurado en .env.\n"); exit(1); }

$accion = $argv[1] ?? 'info';
$metodo = ['set' => 'setWebhook', 'delete' => 'deleteWebhook', 'info' => 'getWebhookInfo'][$accion] ?? null;
if ($metodo === null) { fwrite(STDERR, "Acción inválida. Usá: set <url> | info | delete\n"); exit(1); }

$params = [];
if ($accion === 'set') {
    $url = $argv[2] ?? '';
    if (!preg_match('#^https://#', $url)) { fwrite(STDERR, "La URL del webhook tiene que ser HTTPS.\n"); exit(1); }
    $params['url'] = $url;
}

$ch = curl_init("https://api.telegram.org/bot{$token}/{$metodo}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($params),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_CAINFO         => \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
]);
$r = curl_exec($ch);
curl_close($ch);
echo $r . "\n";
