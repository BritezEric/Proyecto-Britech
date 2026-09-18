<?php

namespace App\Controllers;

use App\Core\Paginacion;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Repositories\ReclamoRepository;
use App\Services\ReclamoService;

/**
 * Reclamos: el cliente los abre/sigue desde la tienda (sobre sus pedidos);
 * el staff (admin o vendedor) los gestiona desde el panel.
 */
class ReclamoController
{
    // ===== Cliente (tienda) =====

    /** POST /api/tienda/reclamos — el cliente abre un reclamo sobre un pedido. */
    public function crear(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }
        $in = Request::json();
        try {
            $r = (new ReclamoService())->abrir(
                (int) $c['id'],
                (int) ($in['pedido_id'] ?? 0),
                (string) ($in['asunto'] ?? ''),
                (string) ($in['descripcion'] ?? '')
            );
            Response::json(['ok' => true, 'reclamo' => $r], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** GET /api/tienda/reclamos — mis reclamos. */
    public function mis(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }
        Response::json(['ok' => true, 'reclamos' => (new ReclamoRepository())->porCliente((int) $c['id'])]);
    }

    /** GET /api/tienda/reclamos/detalle?id= — un reclamo propio + mensajes. */
    public function detalle(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }
        $repo = new ReclamoRepository();
        $r = $repo->buscarPorId((int) Request::query('id', '0'));
        if ($r === null || (int) $r['cliente_id'] !== (int) $c['id']) {
            Response::json(['ok' => false, 'error' => 'Reclamo no encontrado.'], 404); return;
        }
        Response::json(['ok' => true, 'reclamo' => $r, 'mensajes' => $repo->mensajesDe((int) $r['id'])]);
    }

    /** POST /api/tienda/reclamos/mensaje — el cliente responde su reclamo. */
    public function mensaje(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }
        $in = Request::json();
        try {
            (new ReclamoService())->mensajeCliente((int) ($in['reclamo_id'] ?? 0), (int) $c['id'], (string) ($in['mensaje'] ?? ''));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ===== Staff (panel) =====

    /** GET /api/admin/reclamos — listado (paginado, filtro estado). Staff. */
    public function adminListar(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $r = (new ReclamoRepository())->listarPaginado(Request::query('estado'), $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    /** GET /api/admin/reclamos/detalle?id= — reclamo + mensajes. Staff. */
    public function adminDetalle(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $repo = new ReclamoRepository();
        $r = $repo->buscarPorId((int) Request::query('id', '0'));
        if ($r === null) { Response::json(['ok' => false, 'error' => 'Reclamo no encontrado.'], 404); return; }
        Response::json(['ok' => true, 'reclamo' => $r, 'mensajes' => $repo->mensajesDe((int) $r['id'])]);
    }

    /** POST /api/admin/reclamos/mensaje — el staff responde. */
    public function adminMensaje(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $in = Request::json();
        try {
            (new ReclamoService())->mensajeStaff((int) ($in['reclamo_id'] ?? 0), (int) Session::usuarioId(), (string) ($in['mensaje'] ?? ''));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/admin/reclamos/estado — cambia el estado. */
    public function adminEstado(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $in = Request::json();
        try {
            (new ReclamoService())->cambiarEstado((int) ($in['reclamo_id'] ?? 0), (string) ($in['estado'] ?? ''));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
