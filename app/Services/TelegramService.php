<?php

namespace App\Services;

use App\Repositories\UsuarioRepository;

/**
 * Bot de Telegram para avisos INTERNOS del sistema al staff (vendedor/admin).
 * Telegram no banea bots (canal oficial), así que acá mandamos texto libre: es
 * el canal del equipo. WhatsApp queda para el cliente.
 *
 * Un aviso nunca frena la acción real: si algo falla, se traga y devuelve false.
 * Si TG_TOKEN no está configurado, queda desactivado (no-op).
 *
 * Vinculación: cada usuario abre el bot y manda "/start CODIGO"; procesarUpdate()
 * guarda su chat_id. Después, avisar($usuarioId, ...) le llega directo.
 */
class TelegramService
{
    private string $token;
    private string $bot;

    public function __construct()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $tg = $cfg['telegram'] ?? [];
        $this->token = (string) ($tg['token'] ?? '');
        $this->bot   = (string) ($tg['bot'] ?? '');
    }

    public function activo(): bool
    {
        return $this->token !== '';
    }

    public function botUsuario(): string
    {
        return $this->bot;
    }

    /** Avisa a un usuario staff por su id (si está vinculado a Telegram). No-op si no. */
    public function avisar(int $usuarioId, string $texto): void
    {
        if (!$this->activo()) return;
        try {
            $chatId = (new UsuarioRepository())->telegramChatId($usuarioId);
            if ($chatId === null) return;
            $this->enviar($chatId, $texto);
        } catch (\Throwable $e) { /* el aviso es secundario */ }
    }

    /** Manda un mensaje a un chat. parse_mode HTML (podés usar <b>…</b>). */
    public function enviar(string $chatId, string $texto): bool
    {
        if (!$this->activo() || $chatId === '') return false;
        $r = $this->api('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $texto,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        return $r !== null && ($r['ok'] ?? false) === true;
    }

    /** Lee actualizaciones (polling). offset = id del último update + 1. */
    public function getUpdates(int $offset = 0): array
    {
        $r = $this->api('getUpdates', ['offset' => $offset, 'timeout' => 0]);
        return ($r && ($r['ok'] ?? false)) ? ($r['result'] ?? []) : [];
    }

    /**
     * Procesa un update entrante (lo mismo sirve para webhook o para polling).
     * Si es "/start CODIGO" de un código válido, vincula el chat y responde OK.
     * Devuelve el update_id procesado (para avanzar el offset del polling), o null.
     */
    public function procesarUpdate(array $update): ?int
    {
        $msg = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$msg) return $update['update_id'] ?? null;

        $chatId = (string) ($msg['chat']['id'] ?? '');
        $texto  = trim((string) ($msg['text'] ?? ''));

        // Aceptamos el código de dos formas: "/start CODIGO" (deep link) o el código
        // pegado solo (8 hex). Así anda aunque el usuario toque START sin el link.
        $codigo = null;
        if (preg_match('/^\/start\s+([A-Za-z0-9]{4,32})/', $texto, $m)) { $codigo = $m[1]; }
        elseif (preg_match('/^[A-Fa-f0-9]{8}$/', $texto)) { $codigo = $texto; }

        if ($chatId !== '' && $codigo !== null) {
            $usr = (new UsuarioRepository())->porTelegramCodigo($codigo);
            if ($usr) {
                (new UsuarioRepository())->vincularTelegram((int) $usr['id'], $chatId);
                $this->enviar($chatId, '<b>Britech</b>: listo ' . htmlspecialchars((string) $usr['nombre'])
                    . ', vas a recibir los avisos del sistema por acá.');
            } else {
                $this->enviar($chatId, '<b>Britech</b>: ese código no es válido o ya venció. Generá uno nuevo desde el panel (Conectar Telegram).');
            }
        } elseif ($chatId !== '' && str_starts_with($texto, '/start')) {
            $this->enviar($chatId, '<b>Britech</b>: pegá acá el código que te muestra el panel en <b>Conectar Telegram</b> (8 caracteres) y listo.');
        }

        return $update['update_id'] ?? null;
    }

    /** Llamada cruda a la Bot API. Devuelve el JSON decodificado o null si falló. */
    private function api(string $metodo, array $params): ?array
    {
        $url = "https://api.telegram.org/bot{$this->token}/{$metodo}";
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
        ];
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) { $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca; }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) { error_log('[Telegram] curl falló en ' . $metodo); return null; }
        $json = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300) {
            error_log('[Telegram] ' . $metodo . ' HTTP ' . $code . ' ' . substr((string) $raw, 0, 300));
        }
        return is_array($json) ? $json : null;
    }
}
