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
            session_start();
        }
    }

    /** Marca al usuario como logueado. */
    public static function login(int $usuarioId, array $datos = []): void
    {
        // Cambiar el id de sesión al loguear evita el ataque "session fixation".
        session_regenerate_id(true);
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

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
