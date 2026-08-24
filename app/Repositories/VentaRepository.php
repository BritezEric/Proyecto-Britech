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

    // --- Anulación ---

    /** Ventas recientes con nombre de cliente y vendedor, para la lista. */
    public function listarRecientes(int $limite = 50): array
    {
        $sql = "SELECT v.id, v.numero, v.total, v.estado, v.creado_en,
                       c.nombre AS cliente, u.nombre AS vendedor
                FROM venta v
                JOIN cliente c ON c.id = v.cliente_id
                JOIN usuario u ON u.id = v.usuario_id
                ORDER BY v.id DESC
                LIMIT ?";
        $st = Database::conexion()->prepare($sql);
        $st->bindValue(1, $limite, \PDO::PARAM_INT);   // LIMIT no acepta placeholder normal
        $st->execute();
        return $st->fetchAll();
    }

    public function buscarPorId(int $ventaId): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM venta WHERE id = ?");
        $st->execute([$ventaId]);
        return $st->fetch() ?: null;
    }

    /** Líneas de la venta (para saber qué stock reintegrar al anular). */
    public function detalleDe(int $ventaId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT producto_id, cantidad, estado FROM venta_detalle WHERE venta_id = ?"
        );
        $st->execute([$ventaId]);
        return $st->fetchAll();
    }

    /** Todos los datos de una venta para armar el ticket (cabecera + items + pagos). */
    public function paraTicket(int $ventaId): ?array
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare(
            "SELECT v.numero, v.subtotal, v.descuento, v.total, v.creado_en, v.estado,
                    c.nombre AS cliente, u.nombre AS vendedor
             FROM venta v
             JOIN cliente c ON c.id = v.cliente_id
             JOIN usuario u ON u.id = v.usuario_id
             WHERE v.id = ?"
        );
        $st->execute([$ventaId]);
        $venta = $st->fetch();
        if (!$venta) return null;

        $it = $pdo->prepare(
            "SELECT d.cantidad, d.precio_unitario, d.subtotal, p.nombre
             FROM venta_detalle d JOIN producto p ON p.id = d.producto_id
             WHERE d.venta_id = ?"
        );
        $it->execute([$ventaId]);

        $pg = $pdo->prepare(
            "SELECT tp.nombre, pa.monto FROM pago pa
             JOIN tipo_pago tp ON tp.id = pa.tipo_pago_id WHERE pa.venta_id = ?"
        );
        $pg->execute([$ventaId]);

        return ['venta' => $venta, 'items' => $it->fetchAll(), 'pagos' => $pg->fetchAll()];
    }

    public function marcarAnulada(int $ventaId): void
    {
        Database::conexion()
            ->prepare("UPDATE venta SET estado = 'anulada' WHERE id = ?")
            ->execute([$ventaId]);
    }

    public function registrarAnulacion(int $ventaId, int $usuarioId, string $motivo): void
    {
        Database::conexion()
            ->prepare("INSERT INTO venta_anulacion (venta_id, usuario_id, motivo)
                       VALUES (?, ?, ?)")
            ->execute([$ventaId, $usuarioId, $motivo]);
    }
}
