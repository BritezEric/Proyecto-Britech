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

    /**
     * Avisa a un usuario staff por su id (si está vinculado a Telegram). No-op si no.
     * Antepone una etiqueta de categoría según $tipo para que se entienda de un vistazo.
     */
    public function avisar(int $usuarioId, string $texto, string $tipo = '', string $nivel = 'info'): void
    {
        if (!$this->activo()) return;
        try {
            $chatId = (new UsuarioRepository())->telegramChatId($usuarioId);
            if ($chatId === null) return;
            $this->enviar($chatId, '<b>' . self::etiqueta($tipo, $nivel) . "</b>\n" . htmlspecialchars($texto));
        } catch (\Throwable $e) { /* el aviso es secundario */ }
    }

    /** Etiqueta de categoría (ícono + nombre) que encabeza el aviso según su tipo. */
    private static function etiqueta(string $tipo, string $nivel = 'info'): string
    {
        static $map = [
            'pedido_nuevo'        => '🛒 Pedido nuevo',
            'pedido_confirmado'   => '🛒 Pedido',
            'comprobante'         => '💳 Pago',
            'pago_aprobado'       => '💳 Pago',
            'venta_nueva'         => '🧾 Venta',
            'venta_anulada'       => '🧾 Venta anulada',
            'stock_bajo'          => '⚠️ Stock bajo',
            'caja_cierre'         => '💰 Caja',
            'caja_cierre_detalle' => '💰 Caja',
            'caja_resumen'        => '💰 Resumen de caja',
            'compra_creada'       => '🏷️ Compra',
            'compra_recibida'     => '🏷️ Compra',
            'compra_anulada'      => '🏷️ Compra',
            'envio_asignado'      => '📦 Envío',
            'envio_entregado'     => '📦 Entregado',
            'reclamo'             => '📣 Reclamo',
            'solicitud'           => '👤 Mayorista',
        ];
        if (isset($map[$tipo])) return $map[$tipo];
        return $nivel === 'alerta' ? '⚠️ Sistema' : '🔔 Sistema';
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
        if ($chatId === '') return $update['update_id'] ?? null;

        // Nota de voz / audio: lo transcribimos y seguimos como si fuera texto.
        $esAudio = false;
        if ($texto === '' && (isset($msg['voice']) || isset($msg['audio']))) {
            $esAudio = true;
            $fileId = $msg['voice']['file_id'] ?? $msg['audio']['file_id'] ?? '';
            $texto  = $fileId !== '' ? $this->transcribirAudio($fileId) : '';
            if ($texto === '') {
                $this->enviar($chatId, '<b>Britech</b>: no pude entender el audio, probá de nuevo.');
                return $update['update_id'] ?? null;
            }
        }

        // 1) Vinculación: "/start CODIGO" (deep link) o el código pegado solo (8 hex).
        $codigo = null;
        if (preg_match('/^\/start\s+([A-Za-z0-9]{4,32})/', $texto, $m)) { $codigo = $m[1]; }
        elseif (preg_match('/^[A-Fa-f0-9]{8}$/', $texto)) { $codigo = $texto; }

        if ($codigo !== null) {
            $usr = (new UsuarioRepository())->porTelegramCodigo($codigo);
            if ($usr) {
                (new UsuarioRepository())->vincularTelegram((int) $usr['id'], $chatId);
                $this->enviar($chatId, '<b>Britech</b>: listo ' . htmlspecialchars((string) $usr['nombre'])
                    . ', vas a recibir los avisos del sistema por acá. También podés preguntarme por texto o audio.');
            } else {
                $this->enviar($chatId, '<b>Britech</b>: ese código no es válido o ya venció. Generá uno nuevo desde el panel (Conectar Telegram).');
            }
            return $update['update_id'] ?? null;
        }

        // 2) Asistente: SOLO para staff ya vinculado (el cerebro puede operar el sistema).
        $staff = (new UsuarioRepository())->porTelegramChatId($chatId);
        if ($staff === null) {
            $this->enviar($chatId, '<b>Britech</b>: pegá acá el código que te muestra el panel en <b>Conectar Telegram</b> (8 caracteres) para vincularte.');
            return $update['update_id'] ?? null;
        }

        if ($texto !== '' && !str_starts_with($texto, '/')) {
            if ($esAudio) $this->enviar($chatId, '🎤 <i>' . htmlspecialchars($texto) . '</i>');
            $this->responderAsistente($chatId, $texto);
        } elseif (str_starts_with($texto, '/start')) {
            $this->enviar($chatId, '<b>Britech</b>: ya estás conectado. Preguntame lo que quieras por texto o audio.');
        }

        return $update['update_id'] ?? null;
    }

    /** Pregunta al cerebro (n8n/IA) y responde en el chat, con memoria por chat. */
    private function responderAsistente(string $chatId, string $texto): void
    {
        $this->api('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        $hist = $this->historialCargar($chatId);
        $r = (new VozService())->interpretar($texto, $hist);
        $this->historialGuardar($chatId, $r['historial'] ?? []);
        $this->enviar($chatId, htmlspecialchars((string) ($r['respuesta'] ?? 'No entendí, probá de nuevo.')));
    }

    /** Descarga la nota de voz de Telegram y la transcribe a texto. '' si falla. */
    private function transcribirAudio(string $fileId): string
    {
        $f = $this->api('getFile', ['file_id' => $fileId]);
        $path = $f['result']['file_path'] ?? '';
        if ($path === '') return '';
        $bytes = $this->descargar("https://api.telegram.org/file/bot{$this->token}/{$path}");
        if ($bytes === '') return '';
        // Telegram manda las notas de voz en OGG/Opus; el mime deja que Gemini lo entienda.
        return (new VozService())->transcribir(base64_encode($bytes), 'audio/ogg');
    }

    /** GET crudo (para bajar el archivo de audio). Devuelve los bytes o ''. */
    private function descargar(string $url): string
    {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) { $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca; }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($raw !== false && $code >= 200 && $code < 300) ? (string) $raw : '';
    }

    private function historialPath(string $chatId): string
    {
        return sys_get_temp_dir() . '/britech_tg_hist_' . preg_replace('/\D/', '', $chatId) . '.json';
    }

    private function historialCargar(string $chatId): array
    {
        $j = @file_get_contents($this->historialPath($chatId));
        $h = $j !== false ? json_decode($j, true) : null;
        return is_array($h) ? $h : [];
    }

    private function historialGuardar(string $chatId, array $historial): void
    {
        // Guardamos solo los últimos turnos para no crecer sin límite.
        if (count($historial) > 20) $historial = array_slice($historial, -20);
        @file_put_contents($this->historialPath($chatId), json_encode($historial, JSON_UNESCAPED_UNICODE));
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
