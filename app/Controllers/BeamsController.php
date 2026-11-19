<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\BeamsService;

/**
 * Pusher Beams (push del navegador).
 * - config(): dice si está activo, el id de la instancia (público) y con qué
 *   usuario de Beams tiene que registrarse este navegador (o null si nadie está logueado).
 * - token(): firma el token SOLO para el usuario de la sesión actual.
 */
class BeamsController
{
    public function config(): void
    {
        $beams = new BeamsService();
        Response::json([
            'ok'          => true,
            'activo'      => $beams->activo(),
            'instance_id' => $beams->activo() ? $beams->instancia() : null,
            'user_id'     => self::usuarioActual(),
        ]);
    }

    public function token(): void
    {
        $beams = new BeamsService();
        if (!$beams->activo()) { Response::json(['ok' => false, 'error' => 'Las notificaciones push no están configuradas.'], 503); return; }

        $actual = self::usuarioActual();
        if ($actual === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }
        // El navegador pide token para un user_id: tiene que ser el de su propia sesión.
        if ((string) Request::query('user_id', '') !== $actual) {
            Response::json(['ok' => false, 'error' => 'Usuario inválido.'], 403);
            return;
        }
        Response::json(['token' => $beams->token($actual)]);
    }

    /** u-<id> si hay staff logueado, c-<id> si hay cliente, null si nadie. */
    private static function usuarioActual(): ?string
    {
        if (($uid = Session::usuarioId()) !== null) return BeamsService::idStaff($uid);
        if (($cid = Session::clienteId()) !== null) return BeamsService::idCliente($cid);
        return null;
    }
}
