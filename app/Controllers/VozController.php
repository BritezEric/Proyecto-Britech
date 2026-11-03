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
        $voz   = new VozService();
        $historial = (isset($datos['historial']) && is_array($datos['historial'])) ? $datos['historial'] : [];

        // Si el POS mandó audio (navegador sin STT confiable), lo transcribimos acá.
        $texto = (string) ($datos['texto'] ?? '');
        $audio = (string) ($datos['audio'] ?? '');
        if ($audio !== '') {
            $texto = $voz->transcribir($audio, (string) ($datos['mime'] ?? 'audio/wav'));
            if ($texto === '') {
                Response::json(['ok' => true, 'texto' => '', 'respuesta' => 'No pude transcribir el audio, probá de nuevo.', 'historial' => $historial]);
                return;
            }
        }

        $resultado = $voz->interpretar($texto, $historial);
        $respuesta = $this->limpiarVoz($resultado['respuesta']);
        Response::json([
            'ok'        => true,
            'texto'     => $texto,
            'respuesta' => $respuesta,
            'historial' => $resultado['historial'] ?? $historial,
            'audio'     => $voz->hablar($respuesta),   // mp3 base64 (ElevenLabs) o '' si no hay key
        ]);
    }

    /** Saca markdown y saltos de línea para que la voz no lea asteriscos ni viñetas. */
    private function limpiarVoz(string $s): string
    {
        $s = preg_replace('/[*_`#>]+/', '', $s);          // **bold**, *, _, `, #, >
        $s = preg_replace('/^\s*[-•]\s+/m', '', $s);      // viñetas al inicio de línea
        $s = preg_replace('/\s*\n+\s*/', '. ', $s);       // saltos de línea => pausa hablada
        $s = preg_replace('/\.\s*\.\s*/', '. ', $s);      // evita ".." al juntar
        return trim($s);
    }
}
