<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Services\UsuarioService;

class UsuarioController
{
    /** Crear usuario: SOLO admin. */
    public function crear(): void
    {
        $u = Session::usuario();
        if ($u === null || ($u['rol'] ?? '') !== 'admin') {
            Response::json(['ok' => false, 'error' => 'Solo el administrador puede crear usuarios.'], 403);
            return;
        }

        $d = Request::json();
        try {
            $usuario = (new UsuarioService())->crear($d['nombre'] ?? '', $d['email'] ?? '', (int) ($d['rol_id'] ?? 2));
            Response::json(['ok' => true, 'usuario' => $usuario], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo crear el usuario (revisá el correo).'], 500);
        }
    }

    /** Verificar cuenta + definir contraseña (con el token del email). Pública. */
    public function verificar(): void
    {
        $d = Request::json();
        try {
            (new UsuarioService())->verificar($d['token'] ?? '', $d['password'] ?? '');
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
