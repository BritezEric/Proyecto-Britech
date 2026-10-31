<?php

namespace App\Services;

/**
 * Asistente de voz: reenvía el texto reconocido por el POS al webhook de n8n,
 * que lo interpreta con IA y ejecuta la acción contra la API de Britech.
 * El POS nunca habla con n8n directo: pasa por acá (mismo origen, con sesión).
 */
class VozService
{
    public function interpretar(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return ['respuesta' => 'No escuché nada, probá de nuevo.'];
        }

        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $url = $cfg['voz']['n8n_url'] ?? '';
        if ($url === '') {
            return ['respuesta' => 'El asistente no está configurado.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['texto' => $texto], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,   // Gemini puede tardar unos segundos
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code >= 500) {
            error_log('[Voz] n8n falló: ' . ($err ?: ('HTTP ' . $code)));
            return ['respuesta' => 'El asistente no está disponible en este momento.'];
        }

        $data = json_decode($raw, true);
        $respuesta = is_array($data) ? ($data['respuesta'] ?? null) : null;
        return ['respuesta' => $respuesta ?: 'No entendí la respuesta del asistente.'];
    }
}
