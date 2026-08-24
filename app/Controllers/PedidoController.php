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
                $d['envio'] ?? []
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
        Response::json([
            'ok'    => true,
            'items' => (new PedidoRepository())->detalle($id),
            'envio' => (new EnvioRepository())->dePedido($id),
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

    /** Actualiza el estado/seguimiento del envío de un pedido. Solo admin. */
    public function adminEnvio(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new PedidoService())->actualizarEnvio(
                (int) ($d['pedido_id'] ?? 0), $d['estado'] ?? '', $d['tracking'] ?? null,
                $d['direccion'] ?? null, $d['localidad'] ?? null
            );
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
