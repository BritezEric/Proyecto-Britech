<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\ProveedorService;

/** ABM de Proveedores (panel admin). Solo admin. */
class ProveedorController
{
    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        [$page, $perPage, $offset] = Paginacion::desde();
        $q      = Request::query('q');
        $activo = Request::query('activo');

        $r = (new ProveedorService())->listar(
            $q,
            $activo === null || $activo === '' ? null : (int) $activo,
            $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $id = (new ProveedorService())->guardar(Request::json());
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el proveedor.'], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            (new ProveedorService())->baja((int) (Request::json()['id'] ?? 0));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
