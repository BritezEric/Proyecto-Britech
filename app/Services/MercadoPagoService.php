<?php

namespace App\Services;

/**
 * Cliente mínimo de Mercado Pago (Checkout Pro) vía la API REST.
 * Solo dos operaciones: crear la preferencia de pago y consultar un pago.
 * No usamos el SDK: son dos endpoints y evitamos una dependencia más.
 *
 * Flujo: el backend crea una PREFERENCIA (con el monto que él mismo calcula) y
 * manda al cliente al init_point de MP. MP cobra y avisa por webhook y por la
 * back_url de retorno; en ambos casos re-consultamos el pago a MP con nuestro
 * access token y recién ahí marcamos el pedido como pagado (nunca confiamos en
 * lo que llega del navegador).
 */
class MercadoPagoService
{
    private const BASE = 'https://api.mercadopago.com';

    private string $token;
    private string $appUrl;

    public function __construct()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $this->token  = $cfg['mercadopago']['access_token'] ?? '';
        $this->appUrl = rtrim($cfg['app']['url'] ?? '', '/');
    }

    public function configurado(): bool
    {
        return $this->token !== '';
    }

    /** Crea una preferencia de Checkout Pro. Devuelve el init_point (URL de pago). */
    public function crearPreferencia(int $pedidoId, string $numero, float $monto, ?string $emailCliente): string
    {
        $body = [
            'items' => [[
                'title'       => "Pedido {$numero} - Britech",
                'quantity'    => 1,
                'currency_id' => 'ARS',
                'unit_price'  => round($monto, 2),
            ]],
            'external_reference' => (string) $pedidoId,
            'back_urls' => [
                'success' => $this->appUrl . '/tienda.html?pago=retorno',
                'pending' => $this->appUrl . '/tienda.html?pago=retorno',
                'failure' => $this->appUrl . '/tienda.html?pago=retorno',
            ],
            'notification_url' => $this->appUrl . '/api/tienda/pago/webhook',
        ];
        if ($emailCliente) {
            $body['payer'] = ['email' => $emailCliente];
        }

        $r = $this->request('POST', '/checkout/preferences', $body);
        if (!isset($r['init_point'])) {
            throw new \RuntimeException('Mercado Pago no devolvió el link de pago.');
        }
        return (string) $r['init_point'];
    }

    /**
     * Consulta un pago por su id. Devuelve [status, external_reference, amount]
     * o null si no existe / no se pudo leer.
     */
    public function consultarPago(string $paymentId): ?array
    {
        $r = $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
        if (!isset($r['id'])) {
            return null;
        }
        return [
            'status'             => (string) ($r['status'] ?? ''),
            'external_reference' => (string) ($r['external_reference'] ?? ''),
            'amount'             => (float) ($r['transaction_amount'] ?? 0),
        ];
    }

    /** Hace la llamada HTTP y devuelve el JSON decodificado (array). */
    private function request(string $metodo, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('No se pudo conectar con Mercado Pago: ' . $err);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        // 404 al consultar un pago inexistente no es excepción: lo maneja quien llama.
        if ($code >= 400 && $metodo !== 'GET') {
            $msg = $data['message'] ?? ('Error ' . $code . ' de Mercado Pago');
            throw new \RuntimeException($msg);
        }
        return $data;
    }
}
