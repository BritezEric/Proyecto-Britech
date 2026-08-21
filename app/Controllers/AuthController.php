<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Services\AuthService;

class AuthController
{
    public function login(): void
    {
        $datos = Request::json();
        try {
            $usuario = (new AuthService())->login($datos['email'] ?? '', $datos['password'] ?? '');
            Response::json(['ok' => true, 'usuario' => $usuario]);
        } catch (ValidacionException $e) {
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
}
