<?php

namespace App\Core;

use Auth0\SDK\Auth0;
use Auth0\SDK\Configuration\SdkConfiguration;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Construye la instancia del SDK de Auth0 desde la config.
 * Se usa SOLO para el handshake OAuth (login con Google): una vez que Auth0
 * nos devuelve el perfil, mapeamos a un cliente y usamos la sesión propia de
 * la app (Session::loginCliente). La sesión del SDK se descarta.
 */
class Auth0Factory
{
    /** ¿Están las 4 credenciales cargadas en el .env? */
    public static function configurado(array $cfg): bool
    {
        foreach (['domain', 'client_id', 'client_secret', 'cookie_secret'] as $k) {
            if (($cfg[$k] ?? '') === '') return false;
        }
        return true;
    }

    public static function crear(array $cfg): Auth0
    {
        // Inyectamos el cliente/factories de Guzzle explícitamente en vez de
        // depender de la autodiscovery (que en este entorno no los encuentra).
        $http = new HttpFactory();
        return new Auth0(new SdkConfiguration(
            strategy: SdkConfiguration::STRATEGY_REGULAR,
            domain: $cfg['domain'],
            clientId: $cfg['client_id'],
            clientSecret: $cfg['client_secret'],
            cookieSecret: $cfg['cookie_secret'],
            redirectUri: $cfg['redirect_uri'],
            scope: ['openid', 'profile', 'email'],
            httpClient: new GuzzleClient(),
            httpRequestFactory: $http,
            httpResponseFactory: $http,
            httpStreamFactory: $http,
        ));
    }
}
