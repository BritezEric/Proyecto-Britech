<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\EmpresaEnvioService;
use App\Repositories\EmpresaEnvioRepository;

/** ABM de empresas de envío (admin) + lista pública para el checkout. */
class EmpresaEnvioController
{
    /** GET /api/tienda/envios — empresas activas para elegir en el checkout. Público. */
    public function opciones(): void
    {
        Response::json(['ok' => true, 'empresas' => (new EmpresaEnvioRepository())->activas()]);
    }

    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $activo = Request::query('activo');
        $r = (new EmpresaEnvioService())->listar(
            Request::query('q'),
            $activo === null || $activo === '' ? null : (int) $activo,
            $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $id = (new EmpresaEnvioService())->guardar(Request::json());
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar la empresa de envío.'], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            (new EmpresaEnvioService())->baja((int) (Request::json()['id'] ?? 0));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
