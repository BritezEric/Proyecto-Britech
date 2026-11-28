<?php

namespace App\Services;

/**
 * Tiempo real (WebSockets): lo que el backend PHP necesita para hablar con el
 * servidor WebSocket (bin/websocket.php).
 *
 *  - ticket() / validarTicket(): pase firmado (HMAC-SHA256 con WS_SECRET) que el navegador
 *    presenta al conectarse. Dice QUIÉN es (u-staff, c-cliente, r-repartidor) y su rol,
 *    y vence a los 60 s. El servidor WebSocket no lee la sesión de PHP: confía solo en el ticket.
 *  - emitir(): manda un evento a un público ("staff", "admin", "u:5", "c:12", "r:3").
 *    Los eventos se juntan y salen al final del pedido HTTP por el puerto interno
 *    (solo 127.0.0.1). Si el servidor WebSocket no está levantado, no pasa nada:
 *    la operación real nunca se frena por un aviso en tiempo real.
 *
 * Quién recibe qué lo decide SIEMPRE el backend al emitir: el navegador no puede
 * suscribirse a grupos por su cuenta.
 */
class TiempoReal
{
    private const VIGENCIA_TICKET = 60;   // segundos

    /** Eventos pendientes de este pedido HTTP (clave → mensaje), sin duplicados. */
    private static array $cola = [];
    private static ?array $cfg = null;

    public static function config(): array
    {
        if (self::$cfg === null) {
            $c = require dirname(__DIR__, 2) . '/config/config.php';
            self::$cfg = $c['tiempo_real'] ?? [];
        }
        return self::$cfg;
    }

    public static function activo(): bool
    {
        return (string) (self::config()['secret'] ?? '') !== '';
    }

    // ---------- Tickets ----------

    /** Ticket para un sujeto: 'u:5' (staff), 'c:12' (cliente) o 'r:3' (repartidor). */
    public static function ticket(string $sujeto, string $rol): string
    {
        $datos = self::b64(json_encode(['sub' => $sujeto, 'rol' => $rol, 'exp' => time() + self::VIGENCIA_TICKET]));
        return $datos . '.' . self::b64(hash_hmac('sha256', $datos, (string) self::config()['secret'], true));
    }

    /** Devuelve ['sub' => …, 'rol' => …] si el ticket es auténtico y no venció; si no, null. */
    public static function validarTicket(string $ticket): ?array
    {
        $secreto = (string) (self::config()['secret'] ?? '');
        if ($secreto === '' || substr_count($ticket, '.') !== 1) return null;
        [$datos, $firma] = explode('.', $ticket);
        $esperada = self::b64(hash_hmac('sha256', $datos, $secreto, true));
        if (!hash_equals($esperada, $firma)) return null;
        $d = json_decode(self::desb64($datos), true);
        if (!is_array($d) || !isset($d['sub'], $d['rol'], $d['exp']) || (int) $d['exp'] < time()) return null;
        if (!preg_match('/^[ucr]:\d+$/', (string) $d['sub'])) return null;
        return ['sub' => (string) $d['sub'], 'rol' => (string) $d['rol']];
    }

    /** URL pública del WebSocket (WS_URL o ws://<host>:<puerto>). */
    public static function url(string $host): string
    {
        $c = self::config();
        if (!empty($c['url'])) return (string) $c['url'];
        $soloHost = preg_replace('/:\d+$/', '', $host) ?: '127.0.0.1';
        return 'ws://' . $soloHost . ':' . (int) ($c['puerto'] ?? 8090);
    }

    // ---------- Emitir eventos ----------

    /**
     * Encola un evento para un público. $para: lista de grupos ("staff", "admin",
     * "u:5", "c:12", "r:3"). $datos: solo lo que ese público puede ver.
     */
    public static function emitir(array $para, string $evento, array $datos = []): void
    {
        if (!self::activo()) return;
        $para = array_values(array_unique(array_filter(array_map('strval', $para), fn($g) => preg_match('/^(staff|admin|[ucr]:\d+)$/', $g))));
        if (!$para) return;
        $msg = ['para' => $para, 'evento' => $evento, 'datos' => $datos];
        $clave = md5(json_encode($msg));
        if (!self::$cola) register_shutdown_function([self::class, 'enviarCola']);
        self::$cola[$clave] = $msg;
    }

    /**
     * Aviso liviano de "algo cambió en tal módulo" para que las pantallas abiertas
     * se refresquen solas. Solo viaja el módulo, la acción y el id (nunca detalles).
     */
    public static function cambio(string $modulo, string $accion, ?int $id, ?int $actorId = null): void
    {
        // Inicios y cierres de sesión no cambian datos de pantalla: no se refresca nada por eso
        // (los intentos sospechosos ya generan una "alerta" para el admin).
        if ($modulo === 'sesion') return;
        // Pedidos, envíos y stock los ve todo el staff; ventas, cajas y el resto, el admin
        // (más el usuario que hizo la operación, para su "Mi panel").
        $publico = in_array($modulo, ['pedido', 'envio', 'stock', 'producto'], true) ? ['staff'] : ['admin'];
        if ($actorId) $publico[] = 'u:' . $actorId;
        self::emitir($publico, 'cambio', ['modulo' => $modulo, 'accion' => $accion, 'id' => $id]);
    }

    /** Se ejecuta al final del pedido HTTP: manda todos los eventos en una sola conexión. */
    public static function enviarCola(): void
    {
        $cola = self::$cola;
        self::$cola = [];
        if (!$cola) return;
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();   // la respuesta sale primero
        $secreto = (string) self::config()['secret'];
        $lineas = '';
        foreach ($cola as $m) $lineas .= json_encode(['secreto' => $secreto] + $m, JSON_UNESCAPED_UNICODE) . "\n";
        self::hablarInterno($lineas, false);
    }

    /** Consulta al servidor quiénes están conectados (para el panel admin). null si no responde. */
    public static function estado(): ?array
    {
        if (!self::activo()) return null;
        $r = self::hablarInterno(json_encode(['secreto' => self::config()['secret'], 'cmd' => 'estado']) . "\n", true);
        $d = $r === null ? null : json_decode($r, true);
        return is_array($d) ? $d : null;
    }

    /** Escribe en el puerto interno (127.0.0.1). Con $leer, espera una línea de respuesta. */
    private static function hablarInterno(string $lineas, bool $leer): ?string
    {
        try {
            $puerto = (int) (self::config()['puerto_interno'] ?? 8091);
            $s = @stream_socket_client("tcp://127.0.0.1:$puerto", $errno, $errstr, 0.5);
            if (!$s) return null;                       // servidor WebSocket apagado: se ignora
            stream_set_timeout($s, 1);
            fwrite($s, $lineas);
            $resp = $leer ? (string) fgets($s, 1 << 20) : null;
            fclose($s);
            return $resp;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function desb64(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/')); }
}
