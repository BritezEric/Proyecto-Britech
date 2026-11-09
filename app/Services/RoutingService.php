<?php

namespace App\Services;

/**
 * Ruteo por calles para el seguimiento de envíos (estilo Uber): calcula la ruta
 * real (no la línea recta) entre dos puntos y su duración, para dibujarla en el
 * mapa y mostrar un ETA de manejo.
 *
 * Usa OpenRouteService si hay API key (config mapas.ors_key); si no, cae al
 * servidor demo de OSRM (sin key, solo para demo). Nunca rompe: si falla,
 * devuelve null y el mapa muestra la línea recta como respaldo.
 */
class RoutingService
{
    private string $orsKey;

    public function __construct()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $this->orsKey = (string) ($cfg['mapas']['ors_key'] ?? '');
    }

    /**
     * Ruta entre origen y destino.
     * @return array{coords: array<array{0: float, 1: float}>, dist_m: float, dur_s: int}|null
     *         coords = lista de [lat, lng] para dibujar la polyline.
     */
    public function ruta(float $oLat, float $oLng, float $dLat, float $dLng): ?array
    {
        return $this->orsKey !== ''
            ? $this->viaOrs($oLat, $oLng, $dLat, $dLng)
            : $this->viaOsrm($oLat, $oLng, $dLat, $dLng);
    }

    /** OpenRouteService (con key). GeoJSON. */
    private function viaOrs(float $oLat, float $oLng, float $dLat, float $dLng): ?array
    {
        $body = json_encode(['coordinates' => [[$oLng, $oLat], [$dLng, $dLat]]]);
        $raw = $this->http(
            'https://api.openrouteservice.org/v2/directions/driving-car/geojson',
            $body,
            ['Content-Type: application/json', 'Authorization: ' . $this->orsKey]
        );
        if ($raw === null) return null;
        $d = json_decode($raw, true);
        $feat = $d['features'][0] ?? null;
        if (!$feat || !isset($feat['geometry']['coordinates'])) return null;
        $sum = $feat['properties']['summary'] ?? [];
        return [
            'coords' => array_map(fn($c) => [(float) $c[1], (float) $c[0]], $feat['geometry']['coordinates']),
            'dist_m' => (float) ($sum['distance'] ?? 0),
            'dur_s'  => (int) round((float) ($sum['duration'] ?? 0)),
        ];
    }

    /** OSRM demo (sin key). */
    private function viaOsrm(float $oLat, float $oLng, float $dLat, float $dLng): ?array
    {
        $url = "https://router.project-osrm.org/route/v1/driving/"
            . "$oLng,$oLat;$dLng,$dLat?overview=full&geometries=geojson";
        $raw = $this->http($url, null, []);
        if ($raw === null) return null;
        $d = json_decode($raw, true);
        $route = $d['routes'][0] ?? null;
        if (!$route || !isset($route['geometry']['coordinates'])) return null;
        return [
            'coords' => array_map(fn($c) => [(float) $c[1], (float) $c[0]], $route['geometry']['coordinates']),
            'dist_m' => (float) ($route['distance'] ?? 0),
            'dur_s'  => (int) round((float) ($route['duration'] ?? 0)),
        ];
    }

    /** GET (body null) o POST (body no null) con TLS robusto. Devuelve el cuerpo o null. */
    private function http(string $url, ?string $body, array $headers): ?string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers ?: ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'BritechPOS/1.0 (seguimiento de envios)',  // OSRM demo rechaza sin UA (403)
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
        ];
        if ($body !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body; }
        // Bundle TLS (curl.cainfo roto en muchos PHP de Windows → cURL 77).
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) { $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca; }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($raw !== false && $code >= 200 && $code < 300) ? (string) $raw : null;
    }
}
