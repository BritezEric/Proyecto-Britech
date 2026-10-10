<?php

namespace App\Services;

use App\Core\Session;
use App\Repositories\AuditoriaRepository;

/**
 * Helper de auditoría. Se llama desde los servicios para registrar cambios.
 * Nunca rompe la acción real: si falla el registro, se traga la excepción.
 * El usuario se toma de la sesión salvo que se pase explícito.
 */
class Auditoria
{
    /** Registra un evento de operación (anular, aprobar pago, cambiar estado, baja…). */
    public static function evento(string $entidad, ?int $id, string $accion, ?string $detalle = null, ?int $usuarioId = null): void
    {
        try {
            (new AuditoriaRepository())->registrar(
                $usuarioId ?? Session::usuarioId(), $entidad, $id, $accion, null, null, null, $detalle
            );
        } catch (\Throwable $e) { /* la auditoría no debe frenar la operación */ }
    }

    /** Registra un cambio de un campo puntual. */
    public static function campo(string $entidad, ?int $id, string $campo, $anterior, $nuevo, string $accion = 'editar', ?int $usuarioId = null): void
    {
        try {
            (new AuditoriaRepository())->registrar(
                $usuarioId ?? Session::usuarioId(), $entidad, $id, $accion, $campo,
                self::str($anterior), self::str($nuevo), null
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
}
