<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\VentaService;
use App\Repositories\VentaRepository;
use App\Core\ValidacionException;

/**
 * Controlador de ventas. Recibe el pedido de confirmación (JSON), llama al
 * servicio y devuelve el resultado. No lleva lógica ni SQL.
 */
class VentaController
{
    public function crear(): void
    {
        $datos = Request::json();

        try {
            // El vendedor es el usuario logueado (la ruta está protegida).
            $resultado = (new VentaService())->registrar($datos, (int) Session::usuarioId());
            Response::json(['ok' => true, 'venta' => $resultado], 201);
        } catch (ValidacionException $e) {
            // Errores de validación (stock, pago, cliente…): 422.
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Cualquier otra falla: 500 (no exponemos el detalle interno).
            Response::json(['ok' => false, 'error' => 'No se pudo registrar la venta.'], 500);
        }
    }

    /** Lista de ventas recientes (para la pantalla de anulación). */
    public function listar(): void
    {
        Response::json(['ok' => true, 'ventas' => (new VentaRepository())->listarRecientes()]);
    }

    /** Genera el ticket de una venta en PDF (para descargar/imprimir). Requiere login. */
    public function ticket(): void
    {
        $data = (new VentaRepository())->paraTicket((int) Request::query('id', '0'));
        if ($data === null) { http_response_code(404); echo 'Venta no encontrada'; return; }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper([0, 0, 226.77, 650]);   // ~80 mm de ancho (ticket térmico)
        $dompdf->loadHtml($this->htmlTicket($data), 'UTF-8');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="ticket-' . $data['venta']['numero'] . '.pdf"');
        echo $dompdf->output();
    }

    private function htmlTicket(array $d): string
    {
        $v = $d['venta'];
        $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $fecha = date('d/m/Y H:i', strtotime($v['creado_en']));

        $items = '';
        foreach ($d['items'] as $it) {
            $items .= "<tr><td>{$it['cantidad']} x " . $esc($it['nombre']) . "</td>"
                    . "<td class='r'>{$fmt($it['subtotal'])}</td></tr>";
        }
        $pagos = '';
        foreach ($d['pagos'] as $p) {
            $pagos .= "<div class='row'><span>" . $esc($p['nombre']) . "</span><span>{$fmt($p['monto'])}</span></div>";
        }
        $anulada = $v['estado'] === 'anulada' ? "<div class='anul'>*** ANULADA ***</div>" : '';
        $desc = (float) $v['descuento'] > 0
            ? "<div class='row'><span>Descuento</span><span>-{$fmt($v['descuento'])}</span></div>" : '';
        // El envío no es una línea de detalle: es la diferencia entre el total y (subtotal - descuento).
        $envioMonto = round((float) $v['total'] - ((float) $v['subtotal'] - (float) $v['descuento']), 2);
        $envio = $envioMonto > 0.001
            ? "<div class='row'><span>Envio</span><span>{$fmt($envioMonto)}</span></div>" : '';

        return "<html><head><meta charset='utf-8'><style>
            * { font-family: 'DejaVu Sans', sans-serif; }
            body { font-size: 10px; color: #000; margin: 0; padding: 6px 8px; }
            .c { text-align: center; }
            .marca { font-size: 15px; font-weight: bold; letter-spacing: 1px; }
            .sep { border-top: 1px dashed #000; margin: 6px 0; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 1px 0; vertical-align: top; }
            .r { text-align: right; white-space: nowrap; }
            .row { display: block; overflow: hidden; }
            .row span:first-child { float: left; }
            .row span:last-child { float: right; }
            .total { font-size: 13px; font-weight: bold; }
            .anul { text-align: center; color: #c00; font-weight: bold; margin: 4px 0; }
          </style></head><body>
            <div class='c marca'>BRITECH</div>
            <div class='c'>Punto de Venta</div>
            {$anulada}
            <div class='sep'></div>
            <div>Ticket: {$esc($v['numero'])}</div>
            <div>Fecha: {$fecha}</div>
            <div>Cliente: {$esc($v['cliente'])}</div>
            <div>Vendedor: {$esc($v['vendedor'])}</div>
            <div class='sep'></div>
            <table>{$items}</table>
            <div class='sep'></div>
            <div class='row'><span>Subtotal</span><span>{$fmt($v['subtotal'])}</span></div>
            {$desc}
            {$envio}
            <div class='row total'><span>TOTAL</span><span>{$fmt($v['total'])}</span></div>
            <div class='sep'></div>
            {$pagos}
            <div class='sep'></div>
            <div class='c'>¡Gracias por su compra!</div>
          </body></html>";
    }

    /** Anular una venta: SOLO admin. */
    public function anular(): void
    {
        $u = Session::usuario();
        if ($u === null || ($u['rol'] ?? '') !== 'admin') {
            Response::json(['ok' => false, 'error' => 'Solo el administrador puede anular ventas.'], 403);
            return;
        }

        $d = Request::json();
        try {
            $resultado = (new VentaService())->anular(
                (int) ($d['venta_id'] ?? 0),
                $d['motivo'] ?? '',
                (int) Session::usuarioId()
            );
            Response::json(['ok' => true, 'venta' => $resultado]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo anular la venta.'], 500);
        }
    }
}
