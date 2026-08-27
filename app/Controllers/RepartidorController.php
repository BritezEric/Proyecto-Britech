<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\RepartidorService;
use App\Repositories\BarrioRepository;

/** ABM de repartidores (admin) + detalle de paga. Lista pública de barrios para el checkout. */
class RepartidorController
{
    /** GET /api/tienda/barrios — barrios activos para el checkout Moto Express. Público. */
    public function barrios(): void
    {
        Response::json(['ok' => true, 'barrios' => (new BarrioRepository())->activos()]);
    }

    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $activo = Request::query('activo');
        $r = (new RepartidorService())->listar(
            Request::query('q'),
            $activo === null || $activo === '' ? null : (int) $activo,
            $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function detalle(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $r = (new RepartidorService())->detalle((int) Request::query('id', '0'), Request::query('fecha'));
            Response::json(['ok' => true] + $r);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** GET /api/admin/envios/sin-asignar — envíos de moto activos sin repartidor. */
    public function sinAsignar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        Response::json(['ok' => true, 'envios' => (new RepartidorService())->sinAsignar()]);
    }

    /** POST /api/admin/envios/derivar — asigna/desasigna un envío a un repartidor. */
    public function derivar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            $rep = ((int) ($d['repartidor_id'] ?? 0)) ?: null;
            (new RepartidorService())->derivar((int) ($d['envio_id'] ?? 0), $rep);
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/admin/envios/estado — cambia el estado de un envío (salida/entregado). */
    public function estado(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new RepartidorService())->cambiarEstadoEnvio((int) ($d['envio_id'] ?? 0), $d['estado'] ?? '');
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $id = (new RepartidorService())->guardar(Request::json());
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el repartidor.'], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            (new RepartidorService())->baja((int) (Request::json()['id'] ?? 0));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
