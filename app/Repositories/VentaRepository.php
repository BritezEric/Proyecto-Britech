<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio de ventas: todo el SQL de guardar una venta y sus partes
 * (detalle, pagos, comprobante). Lo orquesta VentaService dentro de una
 * transacción.
 */
class VentaRepository
{
    /** Crea la cabecera de la venta y devuelve su id. */
    public function crear(int $clienteId, int $usuarioId, float $subtotal, float $descuento, float $total): int
    {
        $pdo = Database::conexion();
        // numero temporal corto y único; lo reemplazamos por el definitivo con el id.
        $temporal = 'T' . bin2hex(random_bytes(6));

        $pdo->prepare("INSERT INTO venta
                       (numero, cliente_id, usuario_id, subtotal, descuento, total)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$temporal, $clienteId, $usuarioId, $subtotal, $descuento, $total]);

        return (int) $pdo->lastInsertId();
    }

    public function actualizarNumero(int $ventaId, string $numero): void
    {
        Database::conexion()
            ->prepare("UPDATE venta SET numero = ? WHERE id = ?")
            ->execute([$numero, $ventaId]);
    }

    public function agregarDetalle(
        int $ventaId,
        int $productoId,
        int $cantidad,
        float $precioUnitario,
        float $descuentoLinea,
        string $estado,
        float $subtotal
    ): void {
        Database::conexion()
            ->prepare("INSERT INTO venta_detalle
                       (venta_id, producto_id, cantidad, precio_unitario, descuento_linea, estado, subtotal)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$ventaId, $productoId, $cantidad, $precioUnitario, $descuentoLinea, $estado, $subtotal]);
    }

    public function agregarPago(int $ventaId, int $tipoPagoId, float $monto): void
    {
        Database::conexion()
            ->prepare("INSERT INTO pago (venta_id, tipo_pago_id, monto) VALUES (?, ?, ?)")
            ->execute([$ventaId, $tipoPagoId, $monto]);
    }

    public function crearComprobante(int $ventaId, string $numero): void
    {
        Database::conexion()
            ->prepare("INSERT INTO comprobante (venta_id, tipo, numero)
                       VALUES (?, 'ticket_interno', ?)")
            ->execute([$ventaId, $numero]);
    }
}
