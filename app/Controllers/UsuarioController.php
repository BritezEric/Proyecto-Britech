<?php

namespace App\Controllers;

use App\Core\Paginacion;
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

    /** Admin: listado de usuarios del sistema (sin contraseñas). */
    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $f = [
            'q'      => mb_substr(trim((string) Request::query('q', '')), 0, 100),
            'rol_id' => preg_match('/^[12]$/', (string) Request::query('rol_id', '')) ? Request::query('rol_id') : '',
            'activo' => preg_match('/^[01]$/', (string) Request::query('activo', '')) ? Request::query('activo') : '',
        ];
        $r = (new UsuarioService())->listar($f, $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    /** Admin: cambiar nombre, rol y estado de un usuario (queda en la auditoría). */
    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new UsuarioService())->guardar(
                (int) ($d['id'] ?? 0), (string) ($d['nombre'] ?? ''), (int) ($d['rol_id'] ?? 0),
                (int) ($d['activo'] ?? 0), (int) Session::usuarioId()
            );
            Response::json(['ok' => true, 'mensaje' => 'Usuario actualizado.']);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Admin: desactivar un usuario. */
    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        try {
            (new UsuarioService())->baja((int) ($d['id'] ?? 0), (int) Session::usuarioId());
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
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
