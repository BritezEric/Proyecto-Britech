<?php
/**
 * Vinculación de Telegram en LOCAL (sin webhook público).
 * Lee los mensajes nuevos del bot por polling y los procesa (igual que el webhook):
 * así, cuando un usuario manda "/start CODIGO" al bot, queda vinculado.
 *
 * Uso:  php tools/telegram-sync.php          (una pasada)
 *       php tools/telegram-sync.php --loop    (se queda escuchando cada 2s)
 *
 * En el SERVER no hace falta: ahí se usa setWebhook apuntando a /api/telegram/webhook.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\TelegramService;

$tg = new TelegramService();
if (!$tg->activo()) {
    fwrite(STDERR, "TG_TOKEN no configurado en .env. Nada que hacer.\n");
    exit(1);
}

$offsetFile = sys_get_temp_dir() . '/britech_tg_offset';
$offset = (int) (@file_get_contents($offsetFile) ?: 0);
$loop = in_array('--loop', $argv, true);

echo "Escuchando el bot" . ($loop ? " (loop, Ctrl+C para cortar)" : "") . "...\n";

do {
    $updates = $tg->getUpdates($offset);
    foreach ($updates as $u) {
        $id = $tg->procesarUpdate($u);
        if ($id !== null) { $offset = $id + 1; file_put_contents($offsetFile, (string) $offset); }
        $txt = $u['message']['text'] ?? '';
        if ($txt !== '') echo "  recibido: " . $txt . "\n";
    }
    if ($loop) { sleep(2); }
} while ($loop);

echo "Listo.\n";
