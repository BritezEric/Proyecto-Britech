<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\PedidoService;
use App\Repositories\PedidoRepository;
use App\Repositories\EnvioRepository;

/**
 * Pedidos de la tienda.
 *  - crear()/mis(): del CLIENTE logueado (sesión de cliente).
 *  - admin*(): gestión desde el panel (solo admin/staff).
 */
class PedidoController
{
    // ---- Cliente ----

    public function crear(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión para comprar.'], 401); return; }

        // El pedido se cobra con el mismo precio que el cliente VE (según su modo
        // de navegación). 'mayorista' solo puede estar activo si fue aprobado.
        $lista = Session::clienteModo() === 'mayorista' ? 2 : 1;

        $d = Request::json();
        try {
            $r = (new PedidoService())->crear(
                (int) $c['id'], $lista,
                $d['items'] ?? [], $d['observacion'] ?? null,
                $d['envio'] ?? [], $d['metodo_pago'] ?? 'transferencia'
            );
            Response::json(['ok' => true, 'pedido' => $r], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo registrar el pedido.'], 500);
        }
    }

    public function mis(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }
        Response::json(['ok' => true, 'pedidos' => (new PedidoRepository())->deCliente((int) $c['id'])]);
    }

    /** El cliente sube el comprobante de transferencia de un pedido propio. */
    public function subirComprobante(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }

        $repo = new PedidoRepository();
        $pedidoId = (int) ($_POST['pedido_id'] ?? 0);
        $pedido = $repo->buscarPorId($pedidoId);
        if ($pedido === null || (int) $pedido['cliente_id'] !== (int) $c['id']) {
            Response::json(['ok' => false, 'error' => 'El pedido no existe.'], 404); return;
        }

        $f = $_FILES['comprobante'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            Response::json(['ok' => false, 'error' => 'No se recibió el comprobante.'], 422); return;
        }
        if ($f['size'] > 6 * 1024 * 1024) {
            Response::json(['ok' => false, 'error' => 'El archivo supera los 6 MB.'], 422); return;
        }

        // Aceptamos imágenes (screenshot) o PDF. El tipo se valida por contenido.
        $ext = null;
        $img = @getimagesize($f['tmp_name']);
        $porImg = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ($img && isset($porImg[$img['mime'] ?? ''])) {
            $ext = $porImg[$img['mime']];
        } elseif (str_starts_with((string) @file_get_contents($f['tmp_name'], false, null, 0, 5), '%PDF-')) {
            $ext = 'pdf';
        }
        if ($ext === null) {
            Response::json(['ok' => false, 'error' => 'Subí una imagen (JPG/PNG/WEBP) o un PDF.'], 422); return;
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/comprobantes';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre)) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el comprobante.'], 500); return;
        }

        $repo->guardarComprobante($pedidoId, '/uploads/comprobantes/' . $nombre);
        Response::json(['ok' => true, 'estado_pago' => 'en_revision']);
    }

    // ---- Admin ----

    public function adminListar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $r = (new PedidoRepository())->listarPaginado(
            Request::query('q'), Request::query('estado'), $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function adminDetalle(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $id = (int) Request::query('id', '0');
        $p = (new PedidoRepository())->buscarPorId($id);
        Response::json([
            'ok'    => true,
            'items' => (new PedidoRepository())->detalle($id),
            'envio' => (new EnvioRepository())->dePedido($id),
            'pago'  => $p ? [
                'estado_pago'     => $p['estado_pago'],
                'metodo_pago'     => $p['metodo_pago'],
                'comprobante_url' => $p['comprobante_url'],
            ] : null,
        ]);
    }

    public function adminEstado(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new PedidoService())->cambiarEstado((int) ($d['id'] ?? 0), $d['estado'] ?? '');
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Aprueba o rechaza el pago de un pedido (revisión del comprobante). Solo admin. */
    public function adminEstadoPago(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        $estado = $d['estado_pago'] ?? '';
        if (!in_array($estado, ['pendiente', 'en_revision', 'pagado', 'rechazado'], true)) {
            Response::json(['ok' => false, 'error' => 'Estado de pago inválido.'], 422); return;
        }
        $repo = new PedidoRepository();
        if ($repo->buscarPorId((int) ($d['pedido_id'] ?? 0)) === null) {
            Response::json(['ok' => false, 'error' => 'El pedido no existe.'], 404); return;
        }
        $repo->cambiarEstadoPago((int) $d['pedido_id'], $estado);
        Response::json(['ok' => true]);
    }

    /** Actualiza el estado/seguimiento del envío de un pedido. Solo admin. */
    public function adminEnvio(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new PedidoService())->actualizarEnvio(
                (int) ($d['pedido_id'] ?? 0), $d['estado'] ?? '', $d['tracking'] ?? null,
                is_array($d['datos'] ?? null) ? $d['datos'] : []
            );
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
