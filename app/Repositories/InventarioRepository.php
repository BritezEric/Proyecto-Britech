<?php

namespace App\Repositories;

use App\Core\Database;
use App\Services\Auditoria;

/**
 * Repositorio de inventario. Maneja las dos tablas del stock:
 *  - inventario (cuánto hay hoy)
 *  - movimiento_inventario (historial de por qué cambió)
 * Se usan JUNTAS dentro de la transacción de la venta.
 * Cada cambio de cantidad queda en la auditoría (anterior → nuevo y quién).
 */
class InventarioRepository
{
    /** Descuenta cantidad del stock actual de un producto. */
    public function descontar(int $productoId, int $cantidad, ?string $motivo = null): void
    {
        $antes = $this->cantidadDe($productoId);
        Database::conexion()
            ->prepare("UPDATE inventario
                       SET cantidad = cantidad - ?, actualizado_en = NOW()
                       WHERE producto_id = ?")
            ->execute([$cantidad, $productoId]);
        Auditoria::stock($productoId, $antes, $this->cantidadDe($productoId), 'salida', $motivo ?? 'Salida por venta o pedido');
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
    public function establecer(int $productoId, int $cantidad, ?string $motivo = null): void
    {
        $antes = $this->cantidadDe($productoId);
        Database::conexion()
            ->prepare("INSERT INTO inventario (producto_id, cantidad, actualizado_en)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad), actualizado_en = NOW()")
            ->execute([$productoId, $cantidad]);
        Auditoria::stock($productoId, $antes, $cantidad, 'ajuste', $motivo ?? 'Ajuste manual de stock');
    }

    /** Devuelve cantidad al stock (al anular una venta). */
    public function reintegrar(int $productoId, int $cantidad, ?string $motivo = null): void
    {
        $antes = $this->cantidadDe($productoId);
        Database::conexion()
            ->prepare("UPDATE inventario
                       SET cantidad = cantidad + ?, actualizado_en = NOW()
                       WHERE producto_id = ?")
            ->execute([$cantidad, $productoId]);
        Auditoria::stock($productoId, $antes, $this->cantidadDe($productoId), 'entrada', $motivo ?? 'Reintegro por anulación o cancelación');
    }

    /** Suma stock creando la fila si no existía (al recibir mercadería de una compra). */
    public function aumentar(int $productoId, int $cantidad, ?string $motivo = null): void
    {
        $antes = $this->cantidadDe($productoId);
        Database::conexion()
            ->prepare("INSERT INTO inventario (producto_id, cantidad, actualizado_en)
                       VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad), actualizado_en = NOW()")
            ->execute([$productoId, $cantidad]);
        Auditoria::stock($productoId, $antes, $antes + $cantidad, 'entrada', $motivo ?? 'Ingreso de mercadería');
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
