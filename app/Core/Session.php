<?php

namespace App\Core;

/**
 * Manejo de la sesión (quién está conectado).
 * Una "sesión" es una memoria del lado del servidor asociada a una cookie
 * del navegador: así el sistema recuerda que ya iniciaste sesión entre pedidos.
 */
class Session
{
    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Cookie de sesión endurecida (mitiga CSRF y robo por JS):
            //  - httponly: el JavaScript no puede leer la cookie.
            //  - samesite Lax: no se envía en POST cross-site (bloquea CSRF),
            //    pero sí en la navegación normal (los links siguen andando).
            //  - secure: activar en producción (HTTPS).
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                // 'secure' => true,   // ← descomentar cuando el sitio use HTTPS
            ]);
            ini_set('session.use_strict_mode', '1');   // rechaza IDs de sesión inventados
            session_start();
        }
    }

    /** Marca al usuario (staff) como logueado. */
    public static function login(int $usuarioId, array $datos = []): void
    {
        // Cambiar el id de sesión al loguear evita el ataque "session fixation".
        session_regenerate_id(true);
        // Identidad ÚNICA: entrar como staff cierra cualquier sesión de cliente.
        // Así staff y clientes quedan bien separados (nunca conviven en la sesión).
        unset($_SESSION['cliente_id'], $_SESSION['cliente'], $_SESSION['cliente_modo']);
        $_SESSION['usuario_id'] = $usuarioId;
        $_SESSION['usuario']    = $datos;
    }

    public static function usuarioId(): ?int
    {
        return $_SESSION['usuario_id'] ?? null;
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    /** ¿El usuario logueado es admin? (para proteger los ABM del panel). */
    public static function esAdmin(): bool
    {
        return (self::usuario()['rol'] ?? '') === 'admin';
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    // ===== Sesión de CLIENTE (tienda online) — separada de la del staff =====

    public static function loginCliente(int $clienteId, array $datos = []): void
    {
        session_regenerate_id(true);
        // Identidad ÚNICA: entrar como cliente cierra cualquier sesión de staff.
        unset($_SESSION['usuario_id'], $_SESSION['usuario']);
        $_SESSION['cliente_id'] = $clienteId;
        $_SESSION['cliente']    = $datos;
    }

    public static function clienteId(): ?int
    {
        return $_SESSION['cliente_id'] ?? null;
    }

    public static function cliente(): ?array
    {
        return $_SESSION['cliente'] ?? null;
    }

    public static function logoutCliente(): void
    {
        unset($_SESSION['cliente_id'], $_SESSION['cliente'], $_SESSION['cliente_modo']);
    }

    /** Modo de navegación de la tienda: 'minorista' (default) o 'mayorista'. */
    public static function clienteModo(): string
    {
        return $_SESSION['cliente_modo'] ?? 'minorista';
    }

    public static function setClienteModo(string $modo): void
    {
        $_SESSION['cliente_modo'] = $modo === 'mayorista' ? 'mayorista' : 'minorista';
    }
}
