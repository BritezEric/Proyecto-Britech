<?php

namespace App\Core;

/**
 * Arma la respuesta que el backend le devuelve al frontend.
 * Siempre en JSON (porque back y front estan separados).
 */
class Response
{
    public static function json(mixed $datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    }
}
