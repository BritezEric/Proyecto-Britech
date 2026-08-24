<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\RateLimit;
use App\Core\ValidacionException;
use App\Services\AuthService;

class AuthController
{
    public function login(): void
    {
        if (RateLimit::excedido('login-staff')) {
            Response::json(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.'], 429);
            return;
        }
        $datos = Request::json();
        try {
            $usuario = (new AuthService())->login($datos['email'] ?? '', $datos['password'] ?? '');
            Response::json(['ok' => true, 'usuario' => $usuario]);
        } catch (ValidacionException $e) {
            RateLimit::registrar('login-staff');   // solo contamos los fallidos
            Response::json(['ok' => false, 'error' => $e->getMessage()], 401);
        }
    }

    public function logout(): void
    {
        (new AuthService())->logout();
        Response::json(['ok' => true]);
    }

    /** Devuelve el usuario logueado (o 401 si no hay sesión). */
    public function yo(): void
    {
        $usuario = Session::usuario();
        if ($usuario === null) {
            Response::json(['ok' => false], 401);
            return;
        }
        Response::json(['ok' => true, 'usuario' => $usuario]);
    }

    /** "Olvidé mi contraseña": manda el correo si el email existe. */
    public function olvide(): void
    {
        $datos = Request::json();
        (new AuthService())->solicitarReset($datos['email'] ?? '');
        // Siempre respondemos ok: no revelamos si el email existe.
        Response::json(['ok' => true]);
    }

    /** Restablecer contraseña con el token del correo. */
    public function restablecer(): void
    {
        $datos = Request::json();
        try {
            (new AuthService())->restablecer($datos['token'] ?? '', $datos['password'] ?? '');
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
