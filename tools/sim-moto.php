<?php
/**
 * Simulador de moto para el seguimiento de envíos (sin hardware).
 * Emite posiciones GPS que se mueven de un punto a otro por Formosa, haciendo
 * POST al mismo endpoint que usará el celular del repartidor o el TrackingTag.
 *
 * Uso:
 *   php tools/sim-moto.php <token_repartidor> [pasos] [segundos]
 * Ejemplo:
 *   php tools/sim-moto.php 6ea5cd85xxxx 40 2
 */

$token  = $argv[1] ?? '';
$pasos  = (int) ($argv[2] ?? 40);
$espera = (float) ($argv[3] ?? 2);
if ($token === '') { fwrite(STDERR, "Falta el token del repartidor.\n"); exit(1); }

$url = getenv('BRITECH_URL') ?: 'http://127.0.0.1:8123';

// Recorrido de ejemplo dentro de Formosa capital (origen → destino).
$origen  = ['lat' => -26.1775, 'lng' => -58.2010];   // salida
$destino = ['lat' => -26.1849, 'lng' => -58.1731];   // centro

echo "Simulando moto (token " . substr($token, 0, 8) . "…) → $pasos pasos cada {$espera}s\n";

for ($i = 0; $i <= $pasos; $i++) {
    $t = $i / $pasos;                                  // 0 → 1
    // Interpolación lineal + un poco de ruido para que parezca real.
    $lat = $origen['lat'] + ($destino['lat'] - $origen['lat']) * $t + (mt_rand(-4, 4) / 100000);
    $lng = $origen['lng'] + ($destino['lng'] - $origen['lng']) * $t + (mt_rand(-4, 4) / 100000);

    $payload = json_encode(['token' => $token, 'lat' => round($lat, 7), 'lng' => round($lng, 7)]);
    $ch = curl_init("$url/api/repartidor/posicion");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    printf("[%2d/%d] %.6f, %.6f → HTTP %d %s\n", $i, $pasos, $lat, $lng, $code, trim((string) $resp));
    if ($code !== 200) { fwrite(STDERR, "Se detuvo: respuesta $code\n"); exit(1); }
    if ($i < $pasos) usleep((int) ($espera * 1_000_000));
}
echo "Listo: la moto llegó al destino.\n";
