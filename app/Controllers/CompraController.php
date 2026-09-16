<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Paginacion;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Repositories\CompraRepository;
use App\Services\CompraService;

/**
 * Compras: órdenes de compra a proveedores y recepción de mercadería.
 * Todo el módulo es solo-admin.
 */
class CompraController
{
    private function noAdmin(): bool
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return true; }
        return false;
    }

    /** GET /api/admin/compras — lista de órdenes (paginada, filtro por estado). */
    public function listar(): void
    {
        if ($this->noAdmin()) return;
        [$page, $perPage, $offset] = Paginacion::desde();
        $r = (new CompraRepository())->listarPaginado(Request::query('estado'), $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    /** GET /api/admin/compras/detalle?id= — cabecera + líneas de una orden. */
    public function detalle(): void
    {
        if ($this->noAdmin()) return;
        $id = (int) Request::query('id', '0');
        $repo = new CompraRepository();
        $orden = $repo->buscarPorId($id);
        if ($orden === null) { Response::json(['ok' => false, 'error' => 'La orden no existe.'], 404); return; }
        Response::json(['ok' => true, 'orden' => $orden, 'items' => $repo->detalleDe($id)]);
    }

    /** GET /api/admin/compras/nueva — proveedores activos (para el alta manual). */
    public function datosNueva(): void
    {
        if ($this->noAdmin()) return;
        $proveedores = Database::conexion()
            ->query("SELECT id, nombre, telefono FROM proveedor WHERE activo = 1 ORDER BY nombre")
            ->fetchAll();
        Response::json(['ok' => true, 'proveedores' => $proveedores]);
    }

    /** POST /api/admin/compras — crea una orden de compra. */
    public function crear(): void
    {
        if ($this->noAdmin()) return;
        try {
            $r = (new CompraService())->crear(Request::json(), (int) Session::usuarioId());
            Response::json(['ok' => true, 'orden' => $r], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[Compras] crear: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo crear la orden.'], 500);
        }
    }

    /** POST /api/admin/compras/recibir — registra la recepción (total o parcial). */
    public function recibir(): void
    {
        if ($this->noAdmin()) return;
        $in = Request::json();
        $ordenId = (int) ($in['orden_id'] ?? 0);
        $recepciones = is_array($in['recepciones'] ?? null) ? $in['recepciones'] : [];
        try {
            $r = (new CompraService())->recibir($ordenId, $recepciones, (int) Session::usuarioId());
            Response::json(['ok' => true, 'orden' => $r]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[Compras] recibir: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo registrar la recepción.'], 500);
        }
    }

    /** POST /api/admin/compras/anular — anula una orden no recibida. */
    public function anular(): void
    {
        if ($this->noAdmin()) return;
        $ordenId = (int) (Request::json()['orden_id'] ?? 0);
        try {
            $r = (new CompraService())->anular($ordenId);
            Response::json(['ok' => true, 'orden' => $r]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[Compras] anular: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo anular la orden.'], 500);
        }
    }
}
