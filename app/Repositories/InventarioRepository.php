<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio de inventario. Maneja las dos tablas del stock:
 *  - inventario (cuánto hay hoy)
 *  - movimiento_inventario (historial de por qué cambió)
 * Se usan JUNTAS dentro de la transacción de la venta.
 */
class InventarioRepository
{
    /** Descuenta cantidad del stock actual de un producto. */
    public function descontar(int $productoId, int $cantidad): void
    {
        Database::conexion()
            ->prepare("UPDATE inventario
                       SET cantidad = cantidad - ?, actualizado_en = NOW()
                       WHERE producto_id = ?")
            ->execute([$cantidad, $productoId]);
    }

    /** Stock actual de un producto (0 si no tiene fila de inventario). */
    public function cantidadDe(int $productoId): int
    {
        $st = Database::conexion()->prepare("SELECT cantidad FROM inventario WHERE producto_id = ?");
        $st->execute([$productoId]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    /** Fija el stock de un producto (crea la fila si no existe). Para el ABM. */
    public function establecer(int $productoId, int $cantidad): void
    {
        Database::conexion()
            ->prepare("INSERT INTO inventario (producto_id, cantidad, actualizado_en)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad), actualizado_en = NOW()")
            ->execute([$productoId, $cantidad]);
    }

    /** Devuelve cantidad al stock (al anular una venta). */
    public function reintegrar(int $productoId, int $cantidad): void
    {
        Database::conexion()
            ->prepare("UPDATE inventario
                       SET cantidad = cantidad + ?, actualizado_en = NOW()
                       WHERE producto_id = ?")
            ->execute([$cantidad, $productoId]);
    }

    /** Registra un movimiento en el historial (ingreso/egreso/ajuste). */
    public function registrarMovimiento(
        int $productoId,
        string $tipo,
        int $cantidad,
        string $motivo,
        ?int $ventaId,
        int $usuarioId
    ): void {
        Database::conexion()
            ->prepare("INSERT INTO movimiento_inventario
                       (producto_id, tipo, cantidad, motivo, venta_id, usuario_id)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$productoId, $tipo, $cantidad, $motivo, $ventaId, $usuarioId]);
    }
}
