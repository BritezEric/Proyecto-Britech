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
        Response::json(['ok' => true,
            // Si Mercado Pago está configurado, el checkout cobra online (y no
            // hace falta mostrar alias/CBU ni subir comprobante a mano).
            'mp' => (new \App\Services\MercadoPagoService())->configurado(),
            'pago' => [
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
        Response::json(['ok' => true, 'config' => (new ConfigRepository())->todos(), 'local' => self::localEfectivo()]);
    }

    /**
     * Ubicación del negocio efectiva: la que cargó el admin (DB) y, si no hay,
     * la del .env. Es el punto de retiro del cadete.
     */
    public static function localEfectivo(): array
    {
        $c   = (new ConfigRepository())->todos();
        $cfg = (require dirname(__DIR__, 2) . '/config/config.php')['mapas'];
        return [
            'lat' => isset($c['local_lat']) && $c['local_lat'] !== '' ? (float) $c['local_lat'] : (float) $cfg['local_lat'],
            'lng' => isset($c['local_lng']) && $c['local_lng'] !== '' ? (float) $c['local_lng'] : (float) $cfg['local_lng'],
            'dir' => !empty($c['local_dir']) ? (string) $c['local_dir'] : (string) $cfg['local_dir'],
        ];
    }

    /** Admin: guardar la ubicación del negocio (pin del mapa). Body: {lat, lng, dir}. */
    public function guardarLocal(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $d = Request::json();
        $lat = isset($d['lat']) ? (float) $d['lat'] : null;
        $lng = isset($d['lng']) ? (float) $d['lng'] : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Response::json(['ok' => false, 'error' => 'Coordenadas inválidas.'], 422); return;
        }
        (new ConfigRepository())->guardar([
            'local_lat' => (string) $lat,
            'local_lng' => (string) $lng,
            'local_dir' => trim((string) ($d['dir'] ?? '')),
        ]);
        Response::json(['ok' => true, 'mensaje' => 'Ubicación del negocio guardada.']);
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
