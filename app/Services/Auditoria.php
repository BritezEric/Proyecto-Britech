<?php

namespace App\Services;

use App\Core\Session;
use App\Repositories\AuditoriaRepository;

/**
 * Helper de auditoría. Se llama desde el backend (servicios y repositorios)
 * para registrar operaciones: nunca desde JavaScript.
 * Nunca rompe la acción real: si falla el registro, se traga la excepción.
 * El usuario se toma de la sesión salvo que se pase explícito.
 * Los campos sensibles (contraseñas, tokens, PIN) se guardan enmascarados.
 */
class Auditoria
{
    /** Campos cuyo valor nunca se guarda en claro. */
    private const SENSIBLE = '/pass|clave|token|secret|pin\b|^pin|cvv|tarjeta|api_?key/i';
    private const OCULTO = '[oculto]';

    /** Registra un evento de operación (anular, aprobar pago, cambiar estado, baja, login…). */
    public static function evento(string $entidad, ?int $id, string $accion, ?string $detalle = null, ?int $usuarioId = null): void
    {
        try {
            (new AuditoriaRepository())->registrar(
                $usuarioId ?? Session::usuarioId(), $entidad, $id, $accion, null, null, null, $detalle, self::ip()
            );
        } catch (\Throwable $e) { /* la auditoría no debe frenar la operación */ }
    }

    /** Registra un cambio de un campo puntual (valor anterior → valor nuevo). */
    public static function campo(
        string $entidad, ?int $id, string $campo, $anterior, $nuevo,
        string $accion = 'editar', ?int $usuarioId = null, ?string $detalle = null
    ): void {
        try {
            $sensible = preg_match(self::SENSIBLE, $campo) === 1;
            (new AuditoriaRepository())->registrar(
                $usuarioId ?? Session::usuarioId(), $entidad, $id, $accion, $campo,
                $sensible && $anterior !== null ? self::OCULTO : self::str($anterior),
                $sensible && $nuevo !== null ? self::OCULTO : self::str($nuevo),
                $detalle, self::ip()
            );
        } catch (\Throwable $e) { /* idem */ }
    }

    /**
     * Compara dos estados (antes/después) y registra una fila por cada campo que
     * cambió. $campos = ['campo' => 'Etiqueta', …] o lista de claves.
     * @return int cantidad de cambios registrados.
     */
    public static function cambios(string $entidad, ?int $id, array $antes, array $despues, array $campos, ?int $usuarioId = null): int
    {
        $n = 0;
        foreach ($campos as $clave => $etiqueta) {
            $campo = is_int($clave) ? $etiqueta : $clave;          // permite lista o mapa
            if (!array_key_exists($campo, $despues)) continue;
            $a = $antes[$campo] ?? null;
            $b = $despues[$campo] ?? null;
            if (self::igual($a, $b)) continue;
            self::campo($entidad, $id, is_int($clave) ? $campo : $etiqueta, $a, $b, 'editar', $usuarioId);
            $n++;
        }
        return $n;
    }

    /**
     * Movimiento de stock de un producto: cantidad anterior → nueva.
     * $accion: entrada (compra, reintegro), salida (venta, pedido) o ajuste (ABM).
     */
    public static function stock(int $productoId, int $anterior, int $nuevo, string $accion, string $detalle): void
    {
        if ($anterior === $nuevo) return;
        $dif = $nuevo - $anterior;
        self::campo('stock', $productoId, 'stock', $anterior, $nuevo, $accion, null,
            $detalle . ' (' . ($dif > 0 ? '+' : '') . $dif . ')');
    }

    /** ¿Son iguales? Los numéricos se comparan con 2 decimales (evita 10 vs 10.00). */
    private static function igual($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) return round((float) $a, 2) === round((float) $b, 2);
        return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
    }

    private static function str($v): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        return (string) $v;
    }

    /** IP de quien hace la acción (null en tareas de consola). */
    private static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
