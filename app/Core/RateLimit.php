<?php

namespace App\Core;

/**
 * Límite de intentos por IP (anti fuerza bruta) para login/registro.
 * Guarda cada intento fallido en `intento_acceso` y bloquea si se pasa del
 * máximo en una ventana de tiempo. Simple y suficiente para este proyecto.
 */
class RateLimit
{
    /** ¿La IP ya superó el máximo de intentos de `$accion` en los últimos `$minutos`? */
    public static function excedido(string $accion, int $max = 10, int $minutos = 15): bool
    {
        self::limpiarViejos($minutos * 4);   // higiene: borra registros antiguos
        $st = Database::conexion()->prepare(
            "SELECT COUNT(*) FROM intento_acceso
             WHERE ip = ? AND accion = ? AND creado_en > (NOW() - INTERVAL ? MINUTE)"
        );
        $st->execute([self::ip(), $accion, $minutos]);
        return (int) $st->fetchColumn() >= $max;
    }

    /** Registra un intento (llamar cuando falla el login o en cada registro). */
    public static function registrar(string $accion): void
    {
        Database::conexion()
            ->prepare("INSERT INTO intento_acceso (ip, accion) VALUES (?, ?)")
            ->execute([self::ip(), $accion]);
    }

    private static function ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    }

    private static function limpiarViejos(int $minutos): void
    {
        Database::conexion()
            ->prepare("DELETE FROM intento_acceso WHERE creado_en < (NOW() - INTERVAL ? MINUTE)")
            ->execute([$minutos]);
    }
}
