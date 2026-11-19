<?php

namespace App\Services;

/**
 * Notificaciones push del navegador con Pusher Beams (sin librerías: cURL + JWT).
 *
 * Cada persona es un "usuario autenticado" de Beams:
 *   u-<id>  → staff (tabla usuario: admin / vendedor)
 *   c-<id>  → cliente de la tienda
 * El navegador se registra con ese id SOLO si el servidor le firma un token
 * (ver BeamsController::token), así nadie puede suscribirse a avisos ajenos.
 *
 * Si BEAMS_INSTANCE_ID o BEAMS_SECRET_KEY están vacíos, todo queda apagado (no-op).
 * Un push nunca frena la acción real: los errores se tragan.
 */
class BeamsService
{
    private string $instancia;
    private string $secreto;
    private string $appUrl;

    public function __construct()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $this->instancia = (string) ($cfg['beams']['instance_id'] ?? '');
        $this->secreto   = (string) ($cfg['beams']['secret_key'] ?? '');
        $this->appUrl    = rtrim((string) ($cfg['app']['url'] ?? ''), '/');
    }

    public function activo(): bool
    {
        return $this->instancia !== '' && $this->secreto !== '';
    }

    public function instancia(): string
    {
        return $this->instancia;
    }

    public static function idStaff(int $usuarioId): string   { return 'u-' . $usuarioId; }
    public static function idCliente(int $clienteId): string { return 'c-' . $clienteId; }

    /** Token (JWT HS256) que Beams pide para asociar este navegador al usuario. */
    public function token(string $userId): string
    {
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $cabecera = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $datos = $b64(json_encode([
            'sub' => $userId,
            'exp' => time() + 86400,
            'iss' => "https://{$this->instancia}.pushnotifications.pusher.com",
        ]));
        $firma = $b64(hash_hmac('sha256', "$cabecera.$datos", $this->secreto, true));
        return "$cabecera.$datos.$firma";
    }

    /** Aviso push a un usuario staff. $ir = sección del panel (ej. 'pedidos'). */
    public function avisarStaff(int $usuarioId, string $titulo, string $tipo = '', ?string $ir = null): void
    {
        $this->encolar(self::idStaff($usuarioId), self::etiqueta($tipo), $titulo,
            $this->appUrl . '/admin.html' . ($ir ? '#' . $ir : ''));
    }

    /** Aviso push a un cliente de la tienda. */
    public function avisarCliente(int $clienteId, string $titulo, string $tipo = ''): void
    {
        $this->encolar(self::idCliente($clienteId), self::etiqueta($tipo, 'Britech'), $titulo,
            $this->appUrl . '/tienda.html');
    }

    /** Pushes pendientes del pedido HTTP actual, agrupados por mensaje: clave → [mensaje, usuarios]. */
    private static array $cola = [];

    /**
     * Los push no se mandan en el momento: se juntan y salen al final del pedido,
     * una sola llamada por mensaje para todos sus destinatarios (avisar a todo el
     * staff = 1 llamada, no una por persona). Así una venta no espera a Pusher.
     */
    private function encolar(string $usuario, string $titulo, string $cuerpo, string $link): void
    {
        if (!$this->activo()) return;
        $clave = md5("$titulo\n$cuerpo\n$link");
        if (!self::$cola) {
            register_shutdown_function(function (): void {
                // En PHP-FPM (Docker) primero se entrega la respuesta y después se notifica.
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                $this->enviarCola();
            });
        }
        self::$cola[$clave] ??= ['msg' => [$titulo, $cuerpo, $link], 'usuarios' => []];
        self::$cola[$clave]['usuarios'][$usuario] = true;
    }

    private function enviarCola(): void
    {
        $cola = self::$cola;
        self::$cola = [];
        foreach ($cola as $item) {
            // Beams acepta hasta 1000 usuarios por publicación.
            foreach (array_chunk(array_keys($item['usuarios']), 1000) as $grupo) {
                $this->publicar($grupo, ...$item['msg']);
            }
        }
    }

    /** POST a la API de Beams. Devuelve el código HTTP (0 si no se pudo conectar). */
    public function publicar(array $usuarios, string $titulo, string $cuerpo, ?string $link = null): int
    {
        if (!$this->activo() || !$usuarios) return 0;
        $noti = ['title' => mb_substr($titulo, 0, 80), 'body' => mb_substr($cuerpo, 0, 300), 'icon' => $this->appUrl . '/assets/img/britech-mark.png'];
        if ($link) $noti['deep_link'] = $link;

        try {
            $url = "https://{$this->instancia}.pushnotifications.pusher.com/publish_api/v1/instances/{$this->instancia}/publishes/users";
            $ch = curl_init($url);
            $opts = [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 4,      // un push lento no puede trabar la venta
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->secreto],
                CURLOPT_POSTFIELDS     => json_encode(['users' => array_values($usuarios), 'web' => ['notification' => $noti]]),
            ];
            $ca = getenv('CURL_CA_BUNDLE') ?: null;
            if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
                $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            }
            if ($ca) $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca;
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $codigo;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Título corto del push según el tipo de aviso. */
    private static function etiqueta(string $tipo, string $def = 'Britech · Aviso'): string
    {
        $mapa = [
            'pedido'          => 'Britech · Nuevo pedido',
            'comprobante'     => 'Britech · Pago',
            'pago_aprobado'   => 'Britech · Pago acreditado',
            'stock_bajo'      => 'Britech · Stock bajo',
            'solicitud'       => 'Britech · Solicitud mayorista',
            'reclamo'         => 'Britech · Reclamo',
            'envio_asignado'  => 'Britech · Envío',
            'envio_entregado' => 'Britech · Entregado',
        ];
        return $mapa[$tipo] ?? $def;
    }
}
