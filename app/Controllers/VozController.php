<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\VozService;

/**
 * Asistente de voz del POS. Recibe el texto dictado y lo reenvía a n8n
 * (vía VozService) para interpretarlo y ejecutar la acción. Ruta protegida:
 * solo staff logueado (vendedor o admin).
 */
class VozController
{
    public function interpretar(): void
    {
        $datos = Request::json();
        $resultado = (new VozService())->interpretar((string) ($datos['texto'] ?? ''));
        Response::json(['ok' => true, 'respuesta' => $resultado['respuesta']]);
    }
}
