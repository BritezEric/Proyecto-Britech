<?php

namespace App\Services;

/**
 * Asistente de voz: reenvía el texto reconocido por el POS al webhook de n8n,
 * que lo interpreta con IA y ejecuta la acción contra la API de Britech.
 * El POS nunca habla con n8n directo: pasa por acá (mismo origen, con sesión).
 */
class VozService
{
    /** Transcribe audio (base64 WAV) con Groq Whisper (ultra rápido). '' si falla. */
    private function transcribirGroq(string $audioB64, array $cfg): string
    {
        $bin = base64_decode($audioB64, true);
        if ($bin === false || $bin === '') return '';
        $tmp = tempnam(sys_get_temp_dir(), 'voz') . '.wav';
        file_put_contents($tmp, $bin);

        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['voz']['groq_key']],
            CURLOPT_POSTFIELDS     => [
                'file'            => new \CURLFile($tmp, 'audio/wav', 'audio.wav'),
                'model'           => $cfg['voz']['groq_stt'] ?? 'whisper-large-v3-turbo',
                'language'        => 'es',
                'response_format' => 'json',
                'temperature'     => '0',
            ],
            CURLOPT_TIMEOUT        => 20,
        ];
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) { $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca; }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        @unlink($tmp);

        if ($raw === false || $code < 200 || $code >= 300) {
            error_log('[Voz] Groq STT falló: ' . ($err ?: ('HTTP ' . $code . ' ' . substr((string) $raw, 0, 200))));
            return '';
        }
        $data = json_decode($raw, true);
        return trim((string) ($data['text'] ?? ''));
    }

    /**
     * Transcribe audio (base64) a texto con Gemini. Se usa cuando el navegador
     * no tiene STT confiable (ej. Brave bloquea el Web Speech de Google), así que
     * el POS graba el audio y lo manda acá. Devuelve el texto o '' si falla.
     */
    public function transcribir(string $audioB64, string $mime): string
    {
        $cfg   = require dirname(__DIR__, 2) . '/config/config.php';
        if ($audioB64 === '') {
            return '';
        }
        // Groq Whisper = transcripción casi instantánea. Si no hay key, cae a Gemini.
        if (($cfg['voz']['groq_key'] ?? '') !== '') {
            $t = $this->transcribirGroq($audioB64, $cfg);
            if ($t !== '') return $t;
        }
        $key   = $cfg['voz']['gemini_key'] ?? '';
        $model = $cfg['voz']['gemini_model'] ?? 'gemini-3.1-flash-lite';
        if ($key === '') {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $key;
        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => 'El audio está en español (Argentina). Transcribilo a texto en español. Devolvé SOLO lo dicho, sin comillas ni comentarios. Si no se entiende, devolvé lo más parecido en español.'],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $audioB64]],
                ],
            ]],
            'generationConfig' => ['temperature' => 0],
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ];
        // Bundle TLS: el curl.cainfo de muchos PHP de Windows está roto (cURL 77).
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) {
            $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca;
        }
        // Gemini flash-lite a veces tira 503 (sobrecarga) o corta la conexión:
        // reintentamos unas veces antes de darnos por vencidos.
        $raw = false; $code = 0; $err = '';
        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($raw !== false && $code >= 200 && $code < 300) break;
            usleep(350000); // 0.35s y reintenta
        }

        if ($raw === false || $code < 200 || $code >= 300) {
            error_log('[Voz] Gemini STT falló: ' . ($err ?: ('HTTP ' . $code . ' ' . substr((string) $raw, 0, 300))));
            return '';
        }

        $data = json_decode($raw, true);
        $texto = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return trim((string) $texto);
    }

    public function interpretar(string $texto, array $historial = []): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return ['respuesta' => 'No escuché nada, probá de nuevo.', 'historial' => $historial];
        }

        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $url = $cfg['voz']['n8n_url'] ?? '';
        if ($url === '') {
            return ['respuesta' => 'El asistente no está configurado.', 'historial' => $historial];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['texto' => $texto, 'historial' => $historial], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 45,   // el agente puede encadenar varias llamadas a Gemini
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code >= 500) {
            error_log('[Voz] n8n falló: ' . ($err ?: ('HTTP ' . $code)));
            return ['respuesta' => 'El asistente no está disponible en este momento.', 'historial' => $historial];
        }

        $data = json_decode($raw, true);
        $respuesta = is_array($data) ? ($data['respuesta'] ?? null) : null;
        $histNuevo = (is_array($data) && isset($data['historial']) && is_array($data['historial'])) ? $data['historial'] : $historial;
        return ['respuesta' => $respuesta ?: 'No entendí la respuesta del asistente.', 'historial' => $histNuevo];
    }

    /**
     * Convierte texto a audio con ElevenLabs (voz natural). Devuelve el mp3 en
     * base64, o '' si no hay key configurada o falla (el POS usa el TTS del
     * navegador como respaldo).
     */
    public function hablar(string $texto): string
    {
        $cfg   = require dirname(__DIR__, 2) . '/config/config.php';
        $key   = $cfg['voz']['elevenlabs_key'] ?? '';
        $voice = $cfg['voz']['elevenlabs_voice'] ?? '';
        $model = $cfg['voz']['elevenlabs_model'] ?? 'eleven_flash_v2_5';
        $texto = trim($texto);
        if ($key === '' || $voice === '' || $texto === '') {
            return '';
        }

        $url = "https://api.elevenlabs.io/v1/text-to-speech/{$voice}?output_format=mp3_44100_128";
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'text'     => $texto,
                'model_id' => $model,   // flash = baja latencia (por defecto)
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'xi-api-key: ' . $key],
            CURLOPT_TIMEOUT        => 30,
        ];
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) {
            $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            error_log('[Voz] ElevenLabs falló: ' . ($err ?: ('HTTP ' . $code . ' ' . substr((string) $raw, 0, 200))));
            return '';
        }
        return base64_encode($raw);
    }
}
