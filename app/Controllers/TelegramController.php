<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UsuarioRepository;
use App\Services\TelegramService;

/**
 * Vinculación y recepción de Telegram.
 * - vincular(): el usuario staff pide su link de conexión (código + bot).
 * - webhook(): Telegram envía acá los mensajes del bot (en el server, con setWebhook).
 *   En local, el mismo procesamiento lo corre tools/telegram-sync.php por polling.
 */
class TelegramController
{
    /** Genera/recupera el código del usuario y devuelve el link para abrir el bot. */
    public function vincular(): void
    {
        $uid = Session::usuarioId();
        if ($uid === null) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }

        $tg = new TelegramService();
        if (!$tg->activo() || $tg->botUsuario() === '') {
            Response::json(['ok' => false, 'error' => 'El bot de Telegram no está configurado todavía.'], 200);
            return;
        }

        $codigo = strtoupper(bin2hex(random_bytes(4)));   // 8 hex
        (new UsuarioRepository())->fijarTelegramCodigo($uid, $codigo);

        $bot = $tg->botUsuario();
        Response::json([
            'ok'     => true,
            'codigo' => $codigo,
            'bot'    => $bot,
            'url'    => "https://t.me/{$bot}?start={$codigo}",
        ]);
    }

    /** Dice si el usuario actual ya está vinculado. */
    public function estado(): void
    {
        $uid = Session::usuarioId();
        if ($uid === null) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $vinculado = (new UsuarioRepository())->telegramChatId($uid) !== null;
        Response::json(['ok' => true, 'vinculado' => $vinculado]);
    }

    /** Webhook público de Telegram (en el server). Procesa un update y responde 200. */
    public function webhook(): void
    {
        try { (new TelegramService())->procesarUpdate(Request::json() ?: []); }
        catch (\Throwable $e) { /* nunca devolvemos error a Telegram, reintentaría en loop */ }
        Response::json(['ok' => true]);
    }
}
