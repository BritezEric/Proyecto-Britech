<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\PedidoRepository;
use App\Repositories\EnvioRepository;
use App\Repositories\NotificacionRepository;
use App\Services\MercadoPagoService;

/**
 * Pago online con Mercado Pago (Checkout Pro).
 *  - iniciar():  el cliente crea la preferencia y recibe el link de pago (init_point).
 *  - confirmar(): cuando MP devuelve al cliente a la tienda (back_url), el navegador
 *                 avisa el payment_id y re-consultamos el pago a MP para confirmarlo.
 *  - webhook():   notificación server-to-server de MP (camino de producción).
 * confirmar() y webhook() terminan en el mismo procesar(): la fuente de verdad es
 * siempre la consulta a MP con nuestro access token, nunca el navegador.
 */
class PagoController
{
    /** Cliente: arma la preferencia de pago del pedido y devuelve el init_point. */
    public function iniciar(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }

        $mp = new MercadoPagoService();
        if (!$mp->configurado()) {
            Response::json(['ok' => false, 'error' => 'El pago online no está disponible.'], 400); return;
        }

        $repo = new PedidoRepository();
        $pedidoId = (int) (Request::json()['pedido_id'] ?? 0);
        $pedido = $repo->buscarPorId($pedidoId);
        if ($pedido === null || (int) $pedido['cliente_id'] !== (int) $c['id']) {
            Response::json(['ok' => false, 'error' => 'El pedido no existe.'], 404); return;
        }
        if ($pedido['estado_pago'] === 'pagado') {
            Response::json(['ok' => false, 'error' => 'Este pedido ya está pagado.'], 422); return;
        }

        // El monto lo calcula el backend: total de productos + costo de envío.
        $envio = (new EnvioRepository())->dePedido($pedidoId);
        $monto = round((float) $pedido['total'] + (float) ($envio['costo'] ?? 0), 2);

        try {
            $link = $mp->crearPreferencia($pedidoId, (string) $pedido['numero'], $monto, $c['email'] ?? null);
            Response::json(['ok' => true, 'init_point' => $link]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo iniciar el pago con Mercado Pago.'], 502);
        }
    }

    /** Retorno del cliente desde MP: confirma el pago por su payment_id. */
    public function confirmar(): void
    {
        $paymentId = trim((string) (Request::query('payment_id') ?? (Request::json()['payment_id'] ?? '')));
        if ($paymentId === '') { Response::json(['ok' => false, 'error' => 'Falta el pago.'], 422); return; }
        $estado = $this->procesar($paymentId);
        Response::json(['ok' => true, 'estado_pago' => $estado]);
    }

    /** Webhook de MP (público). Siempre responde 200 para que MP no reintente en loop. */
    public function webhook(): void
    {
        // MP manda el id del pago en ?data.id / ?id (query) o en el body {data:{id}}.
        $id = Request::query('data.id') ?? Request::query('id');
        $tipo = Request::query('type') ?? Request::query('topic');
        if ($id === null) {
            $body = Request::json();
            $id = $body['data']['id'] ?? $body['id'] ?? null;
            $tipo = $tipo ?? ($body['type'] ?? $body['topic'] ?? null);
        }
        // Solo nos interesan las notificaciones de pagos.
        if ($id !== null && ($tipo === null || $tipo === 'payment')) {
            try { $this->procesar((string) $id); } catch (\Throwable $e) { /* no reintentar */ }
        }
        Response::json(['ok' => true]);
    }

    /**
     * Consulta el pago a MP y actualiza el estado_pago del pedido. Idempotente.
     * Devuelve el estado_pago resultante.
     */
    private function procesar(string $paymentId): string
    {
        $mp = new MercadoPagoService();
        if (!$mp->configurado()) { return 'pendiente'; }

        $pago = $mp->consultarPago($paymentId);
        if ($pago === null) { return 'pendiente'; }

        $pedidoId = (int) $pago['external_reference'];
        $repo = new PedidoRepository();
        $pedido = $repo->buscarPorId($pedidoId);
        if ($pedido === null) { return 'pendiente'; }

        if ($pago['status'] === 'approved') {
            if ($pedido['estado_pago'] !== 'pagado') {
                $repo->pagarConMercadoPago($pedidoId, $paymentId);
                $notif = new NotificacionRepository();
                $notif->crear('comprobante', "Pago aprobado (Mercado Pago) · pedido {$pedido['numero']}", 'pedidos', $pedidoId, 'exito');
                $notif->crearCliente((int) $pedido['cliente_id'], 'pago_aprobado', "¡Pago acreditado! Pedido {$pedido['numero']}", 'mis-pedidos', $pedidoId, 'exito');
                \App\Services\AvisoService::email((int) $pedido['cliente_id'], "Pago acreditado · pedido {$pedido['numero']}",
                    "<p>¡Recibimos tu pago del pedido <strong>{$pedido['numero']}</strong>! Ya lo estamos preparando.</p>");
            }
            return 'pagado';
        }
        if (in_array($pago['status'], ['rejected', 'cancelled'], true)) {
            $repo->cambiarEstadoPago($pedidoId, 'rechazado');
            return 'rechazado';
        }
        // pending / in_process / authorized: queda a la espera de acreditación.
        if ($pedido['estado_pago'] !== 'pagado') {
            $repo->cambiarEstadoPago($pedidoId, 'en_revision');
        }
        return 'en_revision';
    }
}
