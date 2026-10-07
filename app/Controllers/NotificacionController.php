<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\NotificacionRepository;

/**
 * Bandeja de notificaciones. Staff (panel) y cliente (tienda) tienen su propia
 * bandeja: cada uno ve y marca leídas SOLO las suyas.
 */
class NotificacionController
{
    // ---- Staff (panel) ----
    public function listar(): void
    {
        $uid = Session::usuarioId();
        if ($uid === null) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $repo = new NotificacionRepository();
        Response::json(['ok' => true, 'no_leidas' => $repo->contarUsuario($uid), 'items' => $repo->paraUsuario($uid)]);
    }

    public function leer(): void
    {
        $uid = Session::usuarioId();
        if ($uid === null) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        (new NotificacionRepository())->marcarLeidaUsuario((int) (Request::json()['id'] ?? 0), $uid);
        Response::json(['ok' => true]);
    }

    public function leerTodas(): void
    {
        $uid = Session::usuarioId();
        if ($uid === null) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        (new NotificacionRepository())->marcarTodasUsuario($uid);
        Response::json(['ok' => true]);
    }

    // ---- Cliente (tienda) ----
    public function listarCliente(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }
        $repo = new NotificacionRepository();
        Response::json(['ok' => true, 'no_leidas' => $repo->contarCliente((int) $c['id']), 'items' => $repo->paraCliente((int) $c['id'])]);
    }

    public function leerCliente(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }
        (new NotificacionRepository())->marcarLeidaCliente((int) (Request::json()['id'] ?? 0), (int) $c['id']);
        Response::json(['ok' => true]);
    }

    public function leerTodasCliente(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }
        (new NotificacionRepository())->marcarTodasCliente((int) $c['id']);
        Response::json(['ok' => true]);
    }
}
