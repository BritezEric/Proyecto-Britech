<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\NotificacionRepository;

/** Bandeja de notificaciones del panel admin. */
class NotificacionController
{
    public function listar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $repo = new NotificacionRepository();
        Response::json([
            'ok'         => true,
            'no_leidas'  => $repo->contarNoLeidas(),
            'items'      => $repo->ultimas(20),
        ]);
    }

    public function leer(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        (new NotificacionRepository())->marcarLeida((int) (Request::json()['id'] ?? 0));
        Response::json(['ok' => true]);
    }

    public function leerTodas(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        (new NotificacionRepository())->marcarTodasLeidas();
        Response::json(['ok' => true]);
    }
}
