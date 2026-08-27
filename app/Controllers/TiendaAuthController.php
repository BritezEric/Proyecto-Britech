<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\RateLimit;
use App\Core\ValidacionException;
use App\Services\TiendaAuthService;
use App\Services\MayoristaService;

/** Registro / login / logout de los CLIENTES de la tienda online. */
class TiendaAuthController
{
    public function registro(): void
    {
        // Limita el registro para evitar spam de correos.
        if (RateLimit::excedido('registro', 8, 60)) {
            Response::json(['ok' => false, 'error' => 'Demasiados registros desde tu conexión. Probá más tarde.'], 429);
            return;
        }
        RateLimit::registrar('registro');

        $d = Request::json();
        try {
            // Crea la cuenta (sin verificar) y manda el correo de activación.
            (new TiendaAuthService())->registrar($d['nombre'] ?? '', $d['email'] ?? '', $d['telefono'] ?? null);
            Response::json(['ok' => true, 'mensaje' => 'Te enviamos un correo para activar tu cuenta y elegir tu contraseña.'], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo enviar el correo. Revisá la dirección e intentá de nuevo.'], 500);
        }
    }

    /** Activa la cuenta con el token del correo: define la contraseña e inicia sesión. */
    public function activar(): void
    {
        $d = Request::json();
        try {
            $cliente = (new TiendaAuthService())->activar($d['token'] ?? '', $d['password'] ?? '');
            Session::loginCliente($cliente['id'], $cliente);   // login automático tras activar
            Response::json(['ok' => true, 'cliente' => $cliente]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Reenvía el correo de activación. */
    public function reenviar(): void
    {
        (new TiendaAuthService())->reenviar(Request::json()['email'] ?? '');
        // Siempre ok: no revelamos si el email existe.
        Response::json(['ok' => true, 'mensaje' => 'Si el email está registrado y sin activar, te reenviamos el correo.']);
    }

    /** "Olvidé mi contraseña": manda el link de reset si corresponde. */
    public function olvide(): void
    {
        (new TiendaAuthService())->olvide(Request::json()['email'] ?? '');
        Response::json(['ok' => true, 'mensaje' => 'Si el email está registrado, te enviamos un correo para cambiar la contraseña.']);
    }

    /** Cambia la contraseña con el token del correo e inicia sesión. */
    public function restablecer(): void
    {
        $d = Request::json();
        try {
            $cliente = (new TiendaAuthService())->restablecer($d['token'] ?? '', $d['password'] ?? '');
            Session::loginCliente($cliente['id'], $cliente);
            Response::json(['ok' => true, 'cliente' => $cliente]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function login(): void
    {
        if (RateLimit::excedido('login-tienda')) {
            Response::json(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.'], 429);
            return;
        }
        $d = Request::json();
        try {
            $cliente = (new TiendaAuthService())->login($d['email'] ?? '', $d['password'] ?? '');
            Session::loginCliente($cliente['id'], $cliente);
            Response::json(['ok' => true, 'cliente' => $cliente]);
        } catch (ValidacionException $e) {
            RateLimit::registrar('login-tienda');
            Response::json(['ok' => false, 'error' => $e->getMessage()], 401);
        }
    }

    public function logout(): void
    {
        Session::logoutCliente();
        Response::json(['ok' => true]);
    }

    public function yo(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }

        // Estado mayorista fresco desde la BD + modo de navegación de la sesión.
        $estado = (new MayoristaService())->estadoCliente((int) $c['id']);
        $c['mayorista_aprobado'] = $estado['mayorista_aprobado'];
        $c['solicitud_estado']   = $estado['solicitud_estado'];

        // Datos completos del perfil (para el checkout y la pantalla de perfil).
        $full = (new \App\Repositories\ClienteRepository())->buscarCompleto((int) $c['id']);
        foreach (['documento', 'direccion', 'localidad', 'provincia', 'cp', 'creado_en'] as $k) {
            $c[$k] = $full[$k] ?? null;
        }
        // Si perdió el acceso, forzamos modo minorista.
        if (!$estado['mayorista_aprobado']) { Session::setClienteModo('minorista'); }
        $c['modo'] = Session::clienteModo();

        Response::json(['ok' => true, 'cliente' => $c]);
    }

    /** El cliente edita su propio perfil (datos de contacto + dirección). */
    public function actualizarPerfil(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }

        $d = Request::json();
        $g = fn($k) => trim((string) ($d[$k] ?? '')) ?: null;
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2) {
            Response::json(['ok' => false, 'error' => 'Poné tu nombre (mínimo 2 letras).'], 422); return;
        }
        (new \App\Repositories\ClienteRepository())->actualizarPerfil((int) $c['id'], [
            'nombre'    => $nombre,
            'documento' => $g('documento'),
            'telefono'  => $g('telefono'),
            'direccion' => $g('direccion'),
            'localidad' => $g('localidad'),
            'provincia' => $g('provincia'),
            'cp'        => $g('cp'),
        ]);
        Response::json(['ok' => true]);
    }

    /** Cambia el modo de navegación (minorista/mayorista). Mayorista requiere aprobación. */
    public function modo(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'No logueado'], 401); return; }

        $modo = Request::json()['modo'] ?? 'minorista';
        if ($modo === 'mayorista' && !(new MayoristaService())->estadoCliente((int) $c['id'])['mayorista_aprobado']) {
            Response::json(['ok' => false, 'error' => 'Todavía no tenés acceso mayorista aprobado.'], 403);
            return;
        }
        Session::setClienteModo($modo);
        Response::json(['ok' => true, 'modo' => Session::clienteModo()]);
    }
}
