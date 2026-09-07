<?php

namespace App\Controllers;

use App\Core\Auth0Factory;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Services\AuthService;
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

    /** Callback del staff: mismo tenant, otra URL (hay que agregarla en Auth0). */
    private function staffRedirect(): string
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        return rtrim($cfg['app']['url'], '/') . '/api/staff/auth0/callback';
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

        $auth0 = Auth0Factory::crear($cfg);
        try {
            if ($auth0->getExchangeParameters() !== null) {
                $auth0->exchange();
            }
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
            $this->volver('google_fallo');
        }
    }

    /** GET /api/staff/auth0/login — manda al staff a Google. */
    public function staffLogin(): void
    {
        $cfg = $this->cfg();
        if (!Auth0Factory::configurado($cfg)) { $this->volverStaff('google_no_config'); return; }

        $auth0 = Auth0Factory::crear($cfg);
        // Redirect propio del staff (distinto del de cliente): override en login().
        header('Location: ' . $auth0->login($this->staffRedirect(), ['connection' => 'google-oauth2']));
        exit;
    }

    /** GET /api/staff/auth0/callback — Google volvió: canjea y loguea al staff. */
    public function staffCallback(): void
    {
        $cfg = $this->cfg();
        if (!Auth0Factory::configurado($cfg)) { $this->volverStaff('google_no_config'); return; }

        $redir = $this->staffRedirect();
        $auth0 = Auth0Factory::crear($cfg);
        try {
            if ($auth0->getExchangeParameters() !== null) {
                $auth0->exchange($redir);
            }
            $cred = $auth0->getCredentials();
            $auth0->clear();

            if ($cred === null) { $this->volverStaff('google_fallo'); return; }

            $email      = trim(mb_strtolower((string) ($cred->user['email'] ?? '')));
            $verificado = (bool) ($cred->user['email_verified'] ?? false);
            if ($email === '' || !$verificado) { $this->volverStaff('google_email'); return; }

            // Solo staff YA existente (no auto-crea). Setea la sesión adentro.
            $u = (new AuthService())->loginConGoogle($email);
            header('Location: ' . ($u['rol'] === 'admin' ? '/admin.html' : '/pos.html'));
            exit;
        } catch (ValidacionException $e) {
            // Email no habilitado como staff (o inactivo): aviso claro en el login.
            header('Location: /login.html?auth=staff_no_hab');
            exit;
        } catch (\Throwable $e) {
            error_log('[Auth0 staff] ' . $e->getMessage());
            $this->volverStaff('google_fallo');
        }
    }

    /** Vuelve a la tienda con un código de estado en la URL (?auth=...). */
    private function volver(string $code): void
    {
        header('Location: /tienda.html?auth=' . $code);
        exit;
    }

    /** Vuelve al login de staff con un código de estado (?auth=...). */
    private function volverStaff(string $code): void
    {
        header('Location: /login.html?auth=' . $code);
        exit;
    }
}
