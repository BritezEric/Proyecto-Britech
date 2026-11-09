<?php

namespace App\Services;

/**
 * Geocodificación de direcciones con Nominatim (OpenStreetMap), gratis.
 * Convierte "calle + altura + localidad" en coordenadas (lat/lng) para ubicar
 * el destino del envío en el mapa y calcular el ETA (estilo Uber).
 *
 * Uso responsable (política de Nominatim): pocas consultas (una por pedido),
 * User-Agent propio, 1 resultado, sesgado a Argentina. Nunca frena el pedido:
 * si falla o no encuentra, devuelve null y el admin puede corregir el pin.
 */
class GeocoderService
{
    private const URL = 'https://nominatim.openstreetmap.org/search';
    private const UA  = 'BritechPOS/1.0 (seguimiento de envios)';

    private string $viewbox;

    public function __construct()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $this->viewbox = (string) ($cfg['mapas']['geo_viewbox'] ?? '');
    }

    /**
     * Geocodifica una dirección. Devuelve ['lat'=>float,'lng'=>float] o null.
     * $partes: calle, numero, localidad, provincia (los que haya).
     */
    public function geocodificar(array $partes): ?array
    {
        $q = $this->armarConsulta($partes);
        if ($q === '') return null;

        $params = [
            'q'              => $q,
            'format'         => 'json',
            'limit'          => 1,
            'countrycodes'   => 'ar',
            'accept-language' => 'es',
        ];
        // Acotar a Formosa capital: evita matches de calles homónimas lejanas.
        if ($this->viewbox !== '') { $params['viewbox'] = $this->viewbox; $params['bounded'] = 1; }
        $url = self::URL . '?' . http_build_query($params);

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::UA, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ];
        // Bundle TLS: el curl.cainfo de muchos PHP de Windows está roto (cURL 77).
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) { $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca; }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) return null;
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon'])) return null;

        $lat = (float) $data[0]['lat'];
        $lng = (float) $data[0]['lon'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function armarConsulta(array $p): string
    {
        $calle     = trim((string) ($p['direccion'] ?? ''));
        $numero    = trim((string) ($p['numero'] ?? ''));
        $localidad = trim((string) ($p['localidad'] ?? ''));
        $provincia = trim((string) ($p['provincia'] ?? ''));
        if ($calle === '' && $localidad === '') return '';

        $calleNum = trim($calle . ' ' . $numero);
        $partes = array_filter([$calleNum, $localidad, $provincia, 'Argentina']);
        return implode(', ', $partes);
    }
}
