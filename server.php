<?php
/**
 * Router para el server embebido de PHP (solo desarrollo / demo).
 * Sirve los archivos estáticos de public/ y manda el resto al front controller.
 *
 *   php -S 127.0.0.1:8080 -t public server.php
 *
 * Luego exponé ese puerto con ngrok para la demo:
 *   ngrok http 8080 --url=https://TU-DOMINIO.ngrok-free.dev
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si el archivo estático existe en public/, que lo sirva el server embebido.
if ($path !== '/' && file_exists(__DIR__ . '/public' . $path)) {
    return false;
}

// Todo lo demás (rutas de la API y de la app) va al front controller.
require __DIR__ . '/public/index.php';
