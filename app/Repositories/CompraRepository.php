<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio de compras: órdenes de compra a proveedores y su detalle.
 * Único lugar que habla con orden_compra / orden_compra_detalle.
 */
class CompraRepository
{
    /** Crea la cabecera de la orden. Devuelve el id nuevo. */
    public function crear(?int $proveedorId, float $total, ?string $observacion, int $usuarioId): int
    {
        $pdo = Database::conexion();
        $pdo->prepare(
            "INSERT INTO orden_compra (proveedor_id, total_estimado, observacion, usuario_id)
             VALUES (?, ?, ?, ?)"
        )->execute([$proveedorId, $total, $observacion, $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public function fijarNumero(int $ordenId, string $numero): void
    {
        Database::conexion()
            ->prepare("UPDATE orden_compra SET numero = ? WHERE id = ?")
            ->execute([$numero, $ordenId]);
    }

    public function agregarDetalle(int $ordenId, int $productoId, int $cantidad, float $costo): void
    {
        Database::conexion()
            ->prepare("INSERT INTO orden_compra_detalle (orden_compra_id, producto_id, cantidad, costo_unitario)
                       VALUES (?, ?, ?, ?)")
            ->execute([$ordenId, $productoId, $cantidad, $costo]);
    }

    /** Lista paginada de órdenes con proveedor y progreso de recepción. */
    public function listarPaginado(?string $estado, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($estado !== null && $estado !== '') { $where[] = "oc.estado = ?"; $params[] = $estado; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM orden_compra oc $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT oc.id, oc.numero, oc.estado, oc.total_estimado, oc.creado_en, oc.recibido_en,
                       pv.nombre AS proveedor,
                       (SELECT COUNT(*) FROM orden_compra_detalle d WHERE d.orden_compra_id = oc.id) AS lineas
                FROM orden_compra oc
                LEFT JOIN proveedor pv ON pv.id = oc.proveedor_id
                $sqlWhere
                ORDER BY oc.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT oc.*, pv.nombre AS proveedor, pv.telefono AS proveedor_tel
             FROM orden_compra oc
             LEFT JOIN proveedor pv ON pv.id = oc.proveedor_id
             WHERE oc.id = ?"
        );
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** Líneas de una orden con nombre/sku del producto. */
    public function detalleDe(int $ordenId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT d.id, d.producto_id, d.cantidad, d.costo_unitario, d.cantidad_recibida,
                    p.nombre, p.sku
             FROM orden_compra_detalle d
             JOIN producto p ON p.id = d.producto_id
             WHERE d.orden_compra_id = ?
             ORDER BY d.id"
        );
        $st->execute([$ordenId]);
        return $st->fetchAll();
    }

    public function sumarRecibido(int $detalleId, int $cantidad): void
    {
        Database::conexion()
            ->prepare("UPDATE orden_compra_detalle SET cantidad_recibida = cantidad_recibida + ? WHERE id = ?")
            ->execute([$cantidad, $detalleId]);
    }

    /** Marca el estado (y la fecha de recepción si quedó completa). */
    public function fijarEstado(int $ordenId, string $estado, bool $completa = false): void
    {
        $sql = $completa
            ? "UPDATE orden_compra SET estado = ?, recibido_en = NOW() WHERE id = ?"
            : "UPDATE orden_compra SET estado = ? WHERE id = ?";
        Database::conexion()->prepare($sql)->execute([$estado, $ordenId]);
    }
}
