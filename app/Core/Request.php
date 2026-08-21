<?php

namespace App\Core;

/**
 * Representa la peticion HTTP que llega. Nos da, de forma comoda:
 * - el metodo (GET, POST...)
 * - la ruta pedida (/api/productos)
 * - los parametros de la URL (?q=...)
 * - el cuerpo JSON (cuando el frontend envia datos)
 */
class Request
{
    public static function metodo(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function ruta(): string
    {
        // Saca la parte de la ruta (sin el ?query=...) y la normaliza.
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return '/' . trim($uri, '/');
    }

    // Un parametro de la URL: ?q=auricular  ->  Request::query('q')
    public static function query(string $clave, ?string $porDefecto = null): ?string
    {
        return $_GET[$clave] ?? $porDefecto;
    }

    // El cuerpo JSON que manda el frontend (por ej. al confirmar una venta).
    public static function json(): array
    {
        $datos = json_decode(file_get_contents('php://input'), true);
        return is_array($datos) ? $datos : [];
    }
}
