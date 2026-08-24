<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Services\ClienteService;
use App\Services\TiendaAuthService;
use App\Repositories\ClienteRepository;

/**
 * Controlador de clientes.
 *  - index(): dropdown de clientes activos para el POS.
 *  - admin()/guardar()/baja(): ABM del panel admin (solo admin).
 */
class ClienteController
{
    public function index(): void
    {
        $repo = new ClienteRepository();
        Response::json(['datos' => $repo->listar()]);
    }

    /** Listado paginado del ABM (búsqueda + filtros). Solo admin. */
    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        [$page, $perPage, $offset] = Paginacion::desde();
        $q      = Request::query('q');
        $activo = Request::query('activo');
        $lista  = Request::query('lista_precio_id');

        $r = (new ClienteService())->listar(
            $q,
            $activo === null || $activo === '' ? null : (int) $activo,
            $lista === null || $lista === '' ? null : (int) $lista,
            $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $in = Request::json();
            $esNuevo = empty($in['id']);
            $id = (new ClienteService())->guardar($in);

            $mensaje = 'Cliente guardado.';
            // Si es un cliente NUEVO con email, le mandamos el correo de activación
            // (para que elija su contraseña y pueda entrar a la tienda), igual que
            // en el registro. El fallo del correo no debe romper el alta.
            if ($esNuevo) {
                try {
                    $enviado = (new TiendaAuthService())->invitarActivacion($id);
                    $mensaje = $enviado
                        ? 'Cliente creado. Le enviamos un correo para que active su cuenta y elija su contraseña.'
                        : 'Cliente creado.';
                } catch (\Throwable $e) {
                    $mensaje = 'Cliente creado, pero no se pudo enviar el correo de activación.';
                }
            }
            Response::json(['ok' => true, 'id' => $id, 'mensaje' => $mensaje]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el cliente.'], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            (new ClienteService())->baja((int) (Request::json()['id'] ?? 0));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
