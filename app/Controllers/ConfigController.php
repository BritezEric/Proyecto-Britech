<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ConfigRepository;

/** Config de la tienda: datos de transferencia editables desde el panel. */
class ConfigController
{
    private const CLAVES_PAGO = ['pago_alias', 'pago_titular', 'pago_cbu', 'pago_banco'];

    /** Público: datos de transferencia que el cliente ve en el checkout. */
    public function pagoInfo(): void
    {
        $c = (new ConfigRepository())->todos();
        Response::json(['ok' => true, 'pago' => [
            'alias'   => $c['pago_alias']   ?? '',
            'titular' => $c['pago_titular'] ?? '',
            'cbu'     => $c['pago_cbu']     ?? '',
            'banco'   => $c['pago_banco']   ?? '',
        ]]);
    }

    /** Admin: toda la config (para el formulario de ajustes). */
    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        Response::json(['ok' => true, 'config' => (new ConfigRepository())->todos()]);
    }

    /** Admin: guardar los datos de transferencia. */
    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        $kv = [];
        foreach (self::CLAVES_PAGO as $k) {
            if (array_key_exists($k, $d)) { $kv[$k] = trim((string) $d[$k]); }
        }
        (new ConfigRepository())->guardar($kv);
        Response::json(['ok' => true, 'mensaje' => 'Datos de pago guardados.']);
    }
}
