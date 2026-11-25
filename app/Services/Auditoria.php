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
        self::guardar($usuarioId ?? Session::usuarioId(), $entidad, $id, $accion, null, null, null, $detalle);
    }

    /** Registra un cambio de un campo puntual (valor anterior → valor nuevo). */
    public static function campo(
        string $entidad, ?int $id, string $campo, $anterior, $nuevo,
        string $accion = 'editar', ?int $usuarioId = null, ?string $detalle = null
    ): void {
        $sensible = preg_match(self::SENSIBLE, $campo) === 1;
        self::guardar(
            $usuarioId ?? Session::usuarioId(), $entidad, $id, $accion, $campo,
            $sensible && $anterior !== null ? self::OCULTO : self::str($anterior),
            $sensible && $nuevo !== null ? self::OCULTO : self::str($nuevo),
            $detalle
        );
    }

    /** Registros ya guardados en este pedido HTTP (evita duplicar un evento por llamadas internas repetidas). */
    private static array $vistos = [];

    /**
     * Guarda el registro y se lo pasa al detector de alertas. Nunca frena la operación:
     * si falla, se traga la excepción. Si la operación corre dentro de una transacción,
     * el registro y sus alertas se confirman o se deshacen junto con ella.
     */
    private static function guardar(?int $usuarioId, string $entidad, ?int $id, string $accion,
                                    ?string $campo, ?string $anterior, ?string $nuevo, ?string $detalle): void
    {
        $clave = md5(json_encode([$usuarioId, $entidad, $id, $accion, $campo, $anterior, $nuevo, $detalle]));
        if (isset(self::$vistos[$clave])) return;
        self::$vistos[$clave] = true;
        try {
            $ip = self::ip();
            $agente = self::agente();
            $auditoriaId = (new AuditoriaRepository())->registrar(
                $usuarioId, $entidad, $id, $accion, $campo, $anterior, $nuevo, $detalle, $ip, $agente
            );
            if ($auditoriaId) {
                AlertaService::analizar($auditoriaId, [
                    'usuario_id' => $usuarioId, 'entidad' => $entidad, 'entidad_id' => $id, 'accion' => $accion,
                    'campo' => $campo, 'anterior' => $anterior, 'nuevo' => $nuevo, 'detalle' => $detalle,
                    'ip' => $ip, 'agente' => $agente,
                ]);
            }
        } catch (\Throwable $e) { error_log('[Auditoria] ' . $e->getMessage()); }
    }

    /** Navegador / dispositivo de quien hace la acción (null en consola). Nunca cookies ni tokens. */
    private static function agente(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) && $ua !== '' ? mb_substr($ua, 0, 255) : null;
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
