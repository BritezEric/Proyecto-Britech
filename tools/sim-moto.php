<?php
/**
 * Simulador de moto para el seguimiento de envíos (sin hardware).
 * Trae la ruta REAL por las calles (OSRM) y mueve la moto a lo largo de ella,
 * haciendo POST al mismo endpoint que usará el celular del repartidor.
 *
 * Uso:
 *   php tools/sim-moto.php <token_repartidor> [pasos] [segundos]
 */

$token  = $argv[1] ?? '';
$pasos  = (int) ($argv[2] ?? 60);
$espera = (float) ($argv[3] ?? 2);
if ($token === '') { fwrite(STDERR, "Falta el token del repartidor.\n"); exit(1); }

$url = getenv('BRITECH_URL') ?: 'http://127.0.0.1:8080';

// Origen (salida) → destino (el local Britech, Hipólito Yrigoyen 3563).
$origen  = ['lat' => -26.1775, 'lng' => -58.2010];
$destino = ['lat' => -26.1861644, 'lng' => -58.1661652];

// Ruta real por calles (OSRM). Si falla, cae a una línea recta.
$ruta = rutaOsrm($origen, $destino) ?: lineaRecta($origen, $destino, 40);
$ruta = remuestrear($ruta, $pasos);   // dejamos ~$pasos puntos parejos

echo "Simulando moto (token " . substr($token, 0, 8) . "…) por " . count($ruta) . " puntos de la ruta\n";

foreach ($ruta as $i => $p) {
    $payload = json_encode(['token' => $token, 'lat' => round($p['lat'], 7), 'lng' => round($p['lng'], 7)]);
    $ch = curl_init("$url/api/repartidor/posicion");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    printf("[%2d/%d] %.6f, %.6f → HTTP %d %s\n", $i + 1, count($ruta), $p['lat'], $p['lng'], $code, trim((string) $resp));
    if ($code !== 200) { fwrite(STDERR, "Se detuvo: respuesta $code\n"); exit(1); }
    if ($i < count($ruta) - 1) usleep((int) ($espera * 1_000_000));
}
echo "Listo: la moto llegó al destino.\n";

/** @return array<array{lat:float,lng:float}>|null */
function rutaOsrm(array $o, array $d): ?array
{
    $u = "https://router.project-osrm.org/route/v1/driving/{$o['lng']},{$o['lat']};{$d['lng']},{$d['lat']}?overview=full&geometries=geojson";
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'BritechPOS/1.0', CURLOPT_TIMEOUT => 15]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return null;
    $coords = json_decode((string) $raw, true)['routes'][0]['geometry']['coordinates'] ?? null;
    if (!$coords) return null;
    return array_map(fn($c) => ['lat' => (float) $c[1], 'lng' => (float) $c[0]], $coords);
}

function lineaRecta(array $o, array $d, int $n): array
{
    $pts = [];
    for ($i = 0; $i <= $n; $i++) {
        $t = $i / $n;
        $pts[] = ['lat' => $o['lat'] + ($d['lat'] - $o['lat']) * $t, 'lng' => $o['lng'] + ($d['lng'] - $o['lng']) * $t];
    }
    return $pts;
}

/** Deja ~$n puntos parejos de la ruta (para no emitir cientos). */
function remuestrear(array $pts, int $n): array
{
    $total = count($pts);
    if ($total <= $n || $n <= 1) return $pts;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $pts[(int) round($i * ($total - 1) / ($n - 1))];
    }
    return $out;
}
