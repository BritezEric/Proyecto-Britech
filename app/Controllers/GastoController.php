<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\GastoService;

/**
 * ABM de Gastos (finanzas del negocio). Solo admin.
 * El listado incluye la SUMA de los gastos filtrados (para ver el total gastado).
 */
class GastoController
{
    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        [$page, $perPage, $offset] = Paginacion::desde();
        $filtros = [];
        foreach (['activo', 'proveedor_id'] as $k) {
            $v = Request::query($k);
            $filtros[$k] = ($v === null || $v === '') ? null : (int) $v;
        }
        $r = (new GastoService())->listar(Request::query('q'), $filtros, $perPage, $offset);
        $resp = Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage);
        $resp['suma'] = $r['suma'];   // total de lo listado (para el resumen)
        Response::json($resp);
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $id = (new GastoService())->guardar(Request::json(), (int) Session::usuarioId());
            Response::json(['ok' => true, 'id' => $id, 'mensaje' => 'Gasto guardado.']);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el gasto.'], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            (new GastoService())->baja((int) (Request::json()['id'] ?? 0));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
