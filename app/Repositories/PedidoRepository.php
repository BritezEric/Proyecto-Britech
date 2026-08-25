<?php

namespace App\Repositories;

use App\Core\Database;

/** Repositorio de pedidos (tienda online). Único lugar con su SQL. */
class PedidoRepository
{
    public function crear(int $clienteId, float $total, ?string $observacion, string $metodoPago = 'transferencia'): int
    {
        $pdo = Database::conexion();
        $temporal = 'T' . bin2hex(random_bytes(6));
        $pdo->prepare("INSERT INTO pedido (numero, cliente_id, total, observacion, metodo_pago)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$temporal, $clienteId, $total, $observacion, $metodoPago]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizarNumero(int $id, string $numero): void
    {
        Database::conexion()->prepare("UPDATE pedido SET numero = ? WHERE id = ?")->execute([$numero, $id]);
    }

    public function agregarDetalle(int $pedidoId, int $productoId, int $cantidad, float $precio, float $subtotal): void
    {
        Database::conexion()
            ->prepare("INSERT INTO pedido_detalle (pedido_id, producto_id, cantidad, precio_unitario, subtotal)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$pedidoId, $productoId, $cantidad, $precio, $subtotal]);
    }

    /** Listado paginado para el admin (con nombre de cliente y cantidad de ítems). */
    public function listarPaginado(?string $q, ?string $estado, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $where[] = "(p.numero LIKE ? OR c.nombre LIKE ?)";
            $like = "%{$q}%"; array_push($params, $like, $like);
        }
        if ($estado !== null && $estado !== '') { $where[] = "p.estado = ?"; $params[] = $estado; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM pedido p JOIN cliente c ON c.id = p.cliente_id $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT p.id, p.numero, p.estado, p.estado_pago, p.metodo_pago, p.comprobante_url, p.total, p.observacion, p.creado_en,
                       c.nombre AS cliente,
                       (SELECT COUNT(*) FROM pedido_detalle d WHERE d.pedido_id = p.id) AS items
                FROM pedido p
                JOIN cliente c ON c.id = p.cliente_id
                $sqlWhere
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Pedidos de un cliente (su historial en la tienda). */
    public function deCliente(int $clienteId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT p.id, p.numero, p.estado, p.estado_pago, p.comprobante_url, p.total, p.creado_en,
                    e.costo AS envio_costo, e.estado AS envio_estado, e.tracking,
                    em.nombre AS envio_empresa, em.url_tracking
             FROM pedido p
             LEFT JOIN envio e ON e.pedido_id = p.id
             LEFT JOIN empresa_envio em ON em.id = e.empresa_envio_id
             WHERE p.cliente_id = ? ORDER BY p.id DESC"
        );
        $st->execute([$clienteId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            // Link público de seguimiento (si ya tiene nº cargado).
            $r['seguimiento_url'] = ($r['tracking'] && $r['url_tracking'])
                ? str_replace('{tracking}', rawurlencode($r['tracking']), $r['url_tracking'])
                : null;
            unset($r['url_tracking']);
        }
        return $rows;
    }

    public function detalle(int $pedidoId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT d.cantidad, d.precio_unitario, d.subtotal, pr.nombre AS producto
             FROM pedido_detalle d JOIN producto pr ON pr.id = d.producto_id
             WHERE d.pedido_id = ?"
        );
        $st->execute([$pedidoId]);
        return $st->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM pedido WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function cambiarEstado(int $id, string $estado): void
    {
        Database::conexion()->prepare("UPDATE pedido SET estado = ? WHERE id = ?")->execute([$estado, $id]);
    }

    /** El cliente sube el comprobante: guarda la URL y deja el pago 'en_revision'. */
    public function guardarComprobante(int $id, string $url): void
    {
        Database::conexion()
            ->prepare("UPDATE pedido SET comprobante_url = ?, estado_pago = 'en_revision' WHERE id = ?")
            ->execute([$url, $id]);
    }

    /** El admin aprueba/rechaza el pago. */
    public function cambiarEstadoPago(int $id, string $estadoPago): void
    {
        Database::conexion()->prepare("UPDATE pedido SET estado_pago = ? WHERE id = ?")->execute([$estadoPago, $id]);
    }
}
