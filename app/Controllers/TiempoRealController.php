<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\TrackingRepository;
use App\Services\TiempoReal;

/**
 * Tiempo real: entrega el ticket para conectarse al servidor WebSocket.
 *
 * El navegador no puede elegir quién es: la identidad sale de la SESIÓN (staff o
 * cliente) o del token del repartidor, y el servidor la firma con WS_SECRET.
 */
class TiempoRealController
{
    /** GET /api/tiempo-real/ticket  (repartidor: ?token=<su token>) */
    public function ticket(): void
    {
        if (!TiempoReal::activo()) { Response::json(['ok' => true, 'activo' => false]); return; }

        $uid = Session::usuarioId();
        $cid = Session::clienteId();
        $token = trim((string) Request::query('token', ''));
        if ($uid !== null) {
            $sujeto = 'u:' . $uid;
            $rol = Session::esAdmin() ? 'admin' : 'vendedor';
        } elseif ($cid !== null) {
            $sujeto = 'c:' . $cid;
            $rol = 'cliente';
        } elseif ($token !== '' && ($rep = (new TrackingRepository())->repartidorPorToken($token)) !== null) {
            $sujeto = 'r:' . $rep['id'];
            $rol = 'repartidor';
        } else {
            Response::json(['ok' => false, 'error' => 'Iniciá sesión para recibir actualizaciones en vivo.'], 401);
            return;
        }
        header('Cache-Control: no-store');
        Response::json([
            'ok' => true, 'activo' => true,
            'url' => TiempoReal::url((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1')),
            'ticket' => TiempoReal::ticket($sujeto, $rol),
        ]);
    }

    /** GET /api/admin/tiempo-real/estado — quiénes están conectados ahora (solo admin). */
    public function estado(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $e = TiempoReal::estado();
        Response::json(['ok' => true, 'activo' => TiempoReal::activo(), 'en_linea' => $e !== null] + ($e ?? ['conexiones' => 0, 'lista' => []]));
    }
}
