<?php

namespace App\Controllers;

use App\Core\Auth0Factory;
use App\Core\Request;
use App\Core\Session;
use App\Services\TiendaAuthService;

/**
 * Login social con Google mediante Auth0 (para clientes de la tienda).
 * Flujo (Regular Web App): /login redirige a Google (vía Auth0) → Google vuelve
 * a /callback con el código → se canjea, se lee el email, se busca/crea el
 * cliente y se inicia la sesión propia de la app. Son rutas de NAVEGADOR
 * (redirects), no JSON.
 */
class Auth0Controller
{
    private function cfg(): array
    {
        return (require dirname(__DIR__, 2) . '/config/config.php')['auth0'];
    }

    /** GET /api/tienda/auth0/login — manda al usuario a Google. */
    public function login(): void
    {
        $cfg = $this->cfg();
        if (!Auth0Factory::configurado($cfg)) { $this->volver('google_no_config'); return; }

        $auth0 = Auth0Factory::crear($cfg);
        // connection=google-oauth2 salta la pantalla de Auth0 y va directo a Google.
        header('Location: ' . $auth0->login(null, ['connection' => 'google-oauth2']));
        exit;
    }

    /** GET /api/tienda/auth0/callback — Google volvió: canjea, mapea e inicia sesión. */
    public function callback(): void
    {
        $cfg = $this->cfg();
        if (!Auth0Factory::configurado($cfg)) { $this->volver('google_no_config'); return; }

        // Si Auth0/Google vuelven con un error (ej: access_denied), lo mostramos.
        $err = Request::query('error');
        if ($err !== null && $err !== '') {
            $this->volver('google_fallo', Request::query('error_description') ?? $err);
            return;
        }

        $auth0 = Auth0Factory::crear($cfg);
        try {
            if ($auth0->getExchangeParameters() === null) {
                // Llegó al callback sin código (visita directa): volvemos sin ruido.
                header('Location: /tienda.html'); exit;
            }
            $auth0->exchange();
            $cred = $auth0->getCredentials();
            $auth0->clear();   // no usamos la sesión del SDK: nos quedamos con la propia

            if ($cred === null) { $this->volver('google_fallo'); return; }

            $email      = trim(mb_strtolower((string) ($cred->user['email'] ?? '')));
            $verificado = (bool) ($cred->user['email_verified'] ?? false);
            $nombre     = trim((string) ($cred->user['name'] ?? '')) ?: ($email ?: 'Cliente');

            if ($email === '' || !$verificado) { $this->volver('google_email'); return; }

            $cliente = (new TiendaAuthService())->loginConGoogle($email, $nombre);
            Session::loginCliente($cliente['id'], $cliente);
            header('Location: /tienda.html'); exit;
        } catch (\Throwable $e) {
            error_log('[Auth0] ' . $e->getMessage());
            $this->volver('google_fallo', $e->getMessage());
        }
    }

    /** Vuelve a la tienda con un código de estado (?auth=...). En modo debug
     *  adjunta el detalle real del error para poder diagnosticar. */
    private function volver(string $code, ?string $detalle = null): void
    {
        $url = '/tienda.html?auth=' . $code;
        $debug = (require dirname(__DIR__, 2) . '/config/config.php')['app']['debug'] ?? false;
        if ($debug && $detalle) {
            $url .= '&detalle=' . rawurlencode(mb_substr($detalle, 0, 200));
        }
        header('Location: ' . $url);
        exit;
    }
}
