<?php

namespace App\Services;

/**
 * Envío de WhatsApp por la Cloud API de Meta (business-initiated).
 * Los mensajes proactivos requieren una PLANTILLA aprobada en Meta; por eso acá
 * sólo mandamos plantillas (con variables en el cuerpo). Si no hay token/phone_id
 * configurados, queda desactivado (no-op) y nada se rompe.
 *
 * Config (.env): WA_TOKEN, WA_PHONE_ID, WA_VERSION, WA_LANG y los nombres de plantilla.
 */
class WhatsAppService
{
    private string $token;
    private string $phoneId;
    private string $version;
    private string $lang;

    public function __construct()
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        $wa = $cfg['whatsapp'] ?? [];
        $this->token   = (string) ($wa['token'] ?? '');
        $this->phoneId = (string) ($wa['phone_id'] ?? '');
        $this->version = (string) ($wa['version'] ?? 'v21.0');
        $this->lang    = (string) ($wa['lang'] ?? 'es_AR');
    }

    public function activo(): bool
    {
        return $this->token !== '' && $this->phoneId !== '';
    }

    /**
     * Manda una plantilla aprobada. $params son las variables del cuerpo ({{1}}, {{2}}…).
     * Devuelve true si Meta la aceptó. Nunca lanza: si falla, loguea y devuelve false.
     */
    public function enviarTemplate(string $telefono, string $plantilla, array $params = []): bool
    {
        if (!$this->activo() || $plantilla === '') return false;
        $to = self::numero($telefono);
        if ($to === '') return false;

        $componentes = [];
        if ($params) {
            $componentes[] = [
                'type' => 'body',
                'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => (string) $p], $params),
            ];
        }
        $body = [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => 'template',
            'template' => [
                'name' => $plantilla,
                'language' => ['code' => $this->lang],
            ],
        ];
        if ($componentes) $body['template']['components'] = $componentes;

        $url = "https://graph.facebook.com/{$this->version}/{$this->phoneId}/messages";
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
        ];
        $ca = getenv('CURL_CA_BUNDLE') ?: null;
        if (!$ca && class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }
        if ($ca) { $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca; }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) return true;
        error_log('[WhatsApp] falló: HTTP ' . $code . ' ' . substr((string) $raw, 0, 300));
        return false;
    }

    /** Normaliza un teléfono al formato de WhatsApp (Argentina). Mejor esfuerzo. */
    public static function numero(string $tel): string
    {
        $d = preg_replace('/\D/', '', $tel);
        if ($d === '') return '';
        if (str_starts_with($d, '54')) return $d;        // ya tiene código de país
        if (str_starts_with($d, '0')) $d = substr($d, 1); // larga distancia
        if (str_starts_with($d, '15')) $d = substr($d, 2); // celular local con 15
        return '549' . $d;                                // 54 (AR) + 9 (celular) + número
    }
}
