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
