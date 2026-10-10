<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Repositories\AuditoriaRepository;

/** Visor de auditoría del panel (quién cambió qué y cuándo). Solo admin. */
class AuditoriaController
{
    public function listar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $repo = new AuditoriaRepository();
        $f = [
            'entidad'    => Request::query('entidad'),
            'accion'     => Request::query('accion'),
            'usuario_id' => Request::query('usuario_id'),
            'desde'      => Request::query('desde'),
            'hasta'      => Request::query('hasta'),
            'q'          => Request::query('q'),
        ];
        $r = $repo->listarPaginado($f, $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage) + [
            'entidades' => $repo->entidades(),
        ]);
    }
}
