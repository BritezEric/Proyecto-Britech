<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\TrackingRepository;
use App\Repositories\PedidoRepository;

/**
 * Seguimiento de envíos en tiempo real (estilo Uber).
 *  - posicion(): el repartidor (celular/PWA o TrackingTag) emite su GPS. Público,
 *    autenticado por el token del repartidor (no por sesión).
 *  - adminMapa(): todas las motos en curso, para el mapa del panel (staff).
 *  - seguimiento(): la moto del pedido de un cliente, para su mapa (tienda).
 * Actualización en vivo por polling (el front consulta cada pocos segundos); en
 * producción se puede subir a SSE/WebSocket.
 */
class TrackingController
{
    private const VELOCIDAD_KMH = 25.0;   // velocidad promedio de la moto para el ETA

    /** El emisor (repartidor) manda su posición. Body: {token, lat, lng}. */
    public function posicion(): void
    {
        $d = Request::json();
        $token = trim((string) ($d['token'] ?? ''));
        $lat = isset($d['lat']) ? (float) $d['lat'] : null;
        $lng = isset($d['lng']) ? (float) $d['lng'] : null;

        if ($token === '' || $lat === null || $lng === null
            || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Response::json(['ok' => false, 'error' => 'Datos de posición inválidos.'], 422); return;
        }
        $repo = new TrackingRepository();
        $rep = $repo->repartidorPorToken($token);
        if ($rep === null) { Response::json(['ok' => false, 'error' => 'Token inválido.'], 401); return; }

        $repo->guardarPosicion((int) $rep['id'], $lat, $lng);
        Response::json(['ok' => true]);
    }

    /** Público: lista de repartidores activos para el login de su app (nombre + si tiene PIN). */
    public function listaCadetes(): void
    {
        $reps = (new \App\Repositories\RepartidorRepository())->paraLogin();
        $out = array_map(fn($r) => [
            'id' => (int) $r['id'], 'nombre' => $r['nombre'], 'tiene_pin' => (bool) $r['tiene_pin'],
        ], $reps);
        Response::json(['ok' => true, 'repartidores' => $out]);
    }

    /** Público: login del cadete. Body: {id, pin}. Devuelve el token para su app. */
    public function loginCadete(): void
    {
        $d = Request::json();
        $id  = (int) ($d['id'] ?? 0);
        $pin = trim((string) ($d['pin'] ?? ''));

        $r = (new \App\Repositories\RepartidorRepository())->pinYToken($id);
        if ($r === null) { Response::json(['ok' => false, 'error' => 'Repartidor inválido.'], 401); return; }

        // Si el admin le cargó un PIN, es obligatorio y tiene que coincidir.
        if ($r['pin'] !== null && ($pin === '' || !password_verify($pin, $r['pin']))) {
            Response::json(['ok' => false, 'error' => 'PIN incorrecto.'], 401); return;
        }
        Response::json(['ok' => true, 'token' => $r['token'], 'nombre' => $r['nombre']]);
    }

    /** Mapa del admin: todas las motos en curso con su posición y ETA. */
    public function adminMapa(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $envios = (new TrackingRepository())->enviosEnCurso();
        foreach ($envios as &$e) { $e['eta_min'] = $this->eta($e); }
        Response::json(['ok' => true, 'envios' => $envios]);
    }

    /** Seguimiento del cliente: la moto de SU pedido + estado + ETA + rastro. */
    public function seguimiento(): void
    {
        $c = Session::cliente();
        if ($c === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión.'], 401); return; }
        $pedidoId = (int) Request::query('pedido_id', '0');

        $pedido = (new PedidoRepository())->buscarPorId($pedidoId);
        if ($pedido === null || (int) $pedido['cliente_id'] !== (int) $c['id']) {
            Response::json(['ok' => false, 'error' => 'El pedido no existe.'], 404); return;
        }
        $repo = new TrackingRepository();
        $env = $repo->seguimientoPorPedido($pedidoId);
        if ($env === null) { Response::json(['ok' => false, 'error' => 'El pedido no tiene envío.'], 404); return; }

        $env['eta_min'] = $this->eta($env);
        Response::json(['ok' => true, 'envio' => $env]);
    }

    /**
     * Seguimiento PÚBLICO por token (link sin login para el cliente).
     * Query: ?t=<token>. Token inválido → 404. No expone datos personales.
     */
    public function seguimientoPublico(): void
    {
        $token = trim((string) Request::query('t', ''));
        if ($token === '') { Response::json(['ok' => false, 'error' => 'Falta el token.'], 404); return; }

        $env = (new TrackingRepository())->seguimientoPorToken($token);
        if ($env === null) { Response::json(['ok' => false, 'error' => 'Seguimiento no encontrado.'], 404); return; }

        $env['eta_min'] = $this->eta($env);
        Response::json(['ok' => true, 'envio' => $env]);
    }

    /**
     * Ruta por calles (estilo Uber) del envío público: desde la moto hasta el
     * destino. Query: ?t=<token>. Devuelve la polyline + duración (ETA de manejo).
     * Se consulta con menos frecuencia que la posición (cuida la cuota del motor).
     */
    public function rutaPublica(): void
    {
        $token = trim((string) Request::query('t', ''));
        if ($token === '') { Response::json(['ok' => false, 'error' => 'Falta el token.'], 404); return; }

        $e = (new TrackingRepository())->seguimientoPorToken($token);
        if ($e === null) { Response::json(['ok' => false, 'error' => 'No encontrado.'], 404); return; }
        if ($e['ult_lat'] === null || $e['dest_lat'] === null) {
            Response::json(['ok' => true, 'ruta' => null]); return;   // sin moto o sin destino todavía
        }

        $r = (new \App\Services\RoutingService())->ruta(
            (float) $e['ult_lat'], (float) $e['ult_lng'], (float) $e['dest_lat'], (float) $e['dest_lng']
        );
        Response::json([
            'ok'   => true,
            'ruta' => $r ? ['coords' => $r['coords'], 'eta_min' => (int) ceil($r['dur_s'] / 60)] : null,
        ]);
    }

    /**
     * Vista del cadete: su envío activo con punto de retiro (local), destino y la
     * ruta hacia el objetivo actual. Query: ?token=<token del repartidor>.
     */
    public function miEnvio(): void
    {
        $token = trim((string) Request::query('token', ''));
        $repo = new TrackingRepository();
        $rep = $token !== '' ? $repo->repartidorPorToken($token) : null;
        if ($rep === null) { Response::json(['ok' => false, 'error' => 'Token inválido.'], 401); return; }

        $e = $repo->envioActivoDeRepartidor((int) $rep['id']);
        if ($e === null) { Response::json(['ok' => true, 'envio' => null]); return; }

        $pickup = ConfigController::localEfectivo();   // ubicación del negocio: panel (DB) o .env
        $dropDir = trim(($e['direccion'] ?? '') . ' ' . ($e['numero'] ?? ''))
            . ($e['localidad'] ? ', ' . $e['localidad'] : '');
        $dropoff = [
            'lat' => $e['dest_lat'] !== null ? (float) $e['dest_lat'] : null,
            'lng' => $e['dest_lng'] !== null ? (float) $e['dest_lng'] : null,
            'dir' => trim($dropDir) ?: 'Dirección del cliente',
        ];
        // En camino → va al cliente (dropoff); antes → va a retirar al local (pickup).
        $target = $e['estado'] === 'en_camino' ? $dropoff : $pickup;

        $ruta = null;
        if ($e['ult_lat'] !== null && $target['lat'] !== null) {
            $r = (new \App\Services\RoutingService())->ruta(
                (float) $e['ult_lat'], (float) $e['ult_lng'], (float) $target['lat'], (float) $target['lng']
            );
            if ($r) $ruta = ['coords' => $r['coords'], 'eta_min' => (int) ceil($r['dur_s'] / 60)];
        }

        Response::json(['ok' => true, 'envio' => [
            'envio_id'     => (int) $e['envio_id'],
            'numero'       => $e['numero'],
            'estado'       => $e['estado'],
            'destinatario' => $e['destinatario'] ?? null,
            'barrio'       => $e['barrio'] ?? null,
            'costo'        => isset($e['envio_costo']) ? (float) $e['envio_costo'] : null,
            'pickup'       => $pickup,
            'dropoff'      => $dropoff,
            'moto'         => [   // última posición del propio cadete (para verse aunque no haya ruta)
                'lat' => $e['ult_lat'] !== null ? (float) $e['ult_lat'] : null,
                'lng' => $e['ult_lng'] !== null ? (float) $e['ult_lng'] : null,
            ],
            'target'       => $e['estado'] === 'en_camino' ? 'dropoff' : 'pickup',
            'ruta'         => $ruta,
        ]]);
    }

    /**
     * El cadete cambia el estado de SU envío (aceptar/retiré/entregué).
     * Body: {token, envio_id, estado}. Valida que el envío sea de ese repartidor.
     */
    public function estadoCadete(): void
    {
        $d = Request::json();
        $token = trim((string) ($d['token'] ?? ''));
        $envioId = (int) ($d['envio_id'] ?? 0);
        $estado = (string) ($d['estado'] ?? '');

        $rep = $token !== '' ? (new TrackingRepository())->repartidorPorToken($token) : null;
        if ($rep === null) { Response::json(['ok' => false, 'error' => 'Token inválido.'], 401); return; }
        if (!in_array($estado, ['despachado', 'en_camino', 'entregado'], true)) {
            Response::json(['ok' => false, 'error' => 'Estado inválido.'], 422); return;
        }
        $env = (new \App\Repositories\EnvioRepository())->buscarPorId($envioId);
        if ($env === null || (int) $env['repartidor_id'] !== (int) $rep['id']) {
            Response::json(['ok' => false, 'error' => 'Ese envío no es tuyo.'], 403); return;
        }
        try {
            (new \App\Services\RepartidorService())->cambiarEstadoEnvio($envioId, $estado);
            Response::json(['ok' => true]);
        } catch (\App\Core\ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Admin fija el destino del envío a mano (pin en el mapa). Body: {envio_id, lat, lng}. */
    public function fijarDestino(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $d = Request::json();
        $envioId = (int) ($d['envio_id'] ?? 0);
        $lat = isset($d['lat']) ? (float) $d['lat'] : null;
        $lng = isset($d['lng']) ? (float) $d['lng'] : null;
        if ($envioId <= 0 || $lat === null || $lng === null
            || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Response::json(['ok' => false, 'error' => 'Datos inválidos.'], 422); return;
        }
        (new \App\Repositories\EnvioRepository())->fijarDestinoPorId($envioId, $lat, $lng);
        Response::json(['ok' => true]);
    }

    /** ETA en minutos hasta el destino (null si falta la posición o el destino). */
    private function eta(array $e): ?int
    {
        if ($e['ult_lat'] === null || $e['dest_lat'] === null) return null;
        $km = $this->haversineKm(
            (float) $e['ult_lat'], (float) $e['ult_lng'], (float) $e['dest_lat'], (float) $e['dest_lng']
        );
        return (int) ceil($km / self::VELOCIDAD_KMH * 60);
    }

    /** Distancia en km entre dos coordenadas (fórmula de Haversine). */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
