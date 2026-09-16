<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Historial de pedidos de reposición a proveedores (tabla reposicion_pedido).
 * Sirve para no volver a avisar un producto ya pedido hace poco.
 */
class ReposicionRepository
{
    /**
     * Registra un pedido: una fila por producto pedido.
     * @param int|null $proveedorId  proveedor al que se le pidió (puede ser null)
     * @param array    $items        [['producto_id'=>int, 'cantidad'=>int], ...]
     */
    public function registrar(?int $proveedorId, array $items): int
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare(
            "INSERT INTO reposicion_pedido (producto_id, proveedor_id, cantidad) VALUES (?, ?, ?)"
        );
        $n = 0;
        foreach ($items as $it) {
            $pid = (int) ($it['producto_id'] ?? 0);
            $qty = (int) ($it['cantidad'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $st->execute([$pid, $proveedorId ?: null, $qty]);
            $n++;
        }
        return $n;
    }

    /**
     * Días desde el último pedido de cada producto, dentro de una ventana.
     * @return array<int,int>  [producto_id => días desde el último pedido]
     */
    public function diasUltimoPedido(int $ventanaDias = 7): array
    {
        $st = Database::conexion()->prepare(
            "SELECT producto_id, DATEDIFF(NOW(), MAX(creado_en)) AS dias
             FROM reposicion_pedido
             WHERE creado_en >= (NOW() - INTERVAL ? DAY)
             GROUP BY producto_id"
        );
        $st->bindValue(1, $ventanaDias, \PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['producto_id']] = (int) $r['dias'];
        }
        return $out;
    }
}
