<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\MayoristaService;
use App\Repositories\SolicitudMayoristaRepository;

/**
 * Acceso mayorista B2B.
 *  - solicitar(): el CLIENTE pide acceso mayorista.
 *  - admin*(): el admin ve y resuelve las solicitudes.
 */
class MayoristaController
{
    public function solicitar(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }
        try {
            (new MayoristaService())->solicitar((int) $c['id'], Request::json()['mensaje'] ?? null);
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ---- Admin ----

    public function adminListar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $r = (new SolicitudMayoristaRepository())->listarPaginado(
            Request::query('q'), Request::query('estado'), $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function adminResolver(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new MayoristaService())->resolver((int) ($d['id'] ?? 0), $d['estado'] ?? '', (int) Session::usuarioId());
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
