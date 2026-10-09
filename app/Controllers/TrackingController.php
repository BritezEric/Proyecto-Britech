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
