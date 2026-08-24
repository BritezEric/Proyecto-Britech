<?php

namespace App\Repositories;

use App\Core\Database;

/** Favoritos (wishlist) de los clientes de la tienda. */
class FavoritoRepository
{
    /** Alterna el favorito: si estaba lo quita (false), si no lo agrega (true). */
    public function toggle(int $clienteId, int $productoId): bool
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare("SELECT 1 FROM favorito WHERE cliente_id = ? AND producto_id = ?");
        $st->execute([$clienteId, $productoId]);
        if ($st->fetchColumn()) {
            $pdo->prepare("DELETE FROM favorito WHERE cliente_id = ? AND producto_id = ?")
                ->execute([$clienteId, $productoId]);
            return false;
        }
        $pdo->prepare("INSERT INTO favorito (cliente_id, producto_id) VALUES (?, ?)")
            ->execute([$clienteId, $productoId]);
        return true;
    }

    /** Ids de los productos favoritos del cliente (para marcar los corazones). */
    public function idsDe(int $clienteId): array
    {
        $st = Database::conexion()->prepare("SELECT producto_id FROM favorito WHERE cliente_id = ?");
        $st->execute([$clienteId]);
        return array_map('intval', array_column($st->fetchAll(), 'producto_id'));
    }

    /** Productos favoritos (forma de catálogo, con precio según la lista). */
    public function listar(int $clienteId, int $listaId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT p.id, p.nombre, p.descripcion, p.es_sobre_pedido, p.min_mayorista,
                    c.nombre AS categoria, m.nombre AS marca,
                    pr.precio, COALESCE(i.cantidad, 0) AS stock,
                    (SELECT url FROM producto_imagen pi WHERE pi.producto_id = p.id
                     ORDER BY pi.orden, pi.id LIMIT 1) AS imagen
             FROM favorito f
             JOIN producto p       ON p.id = f.producto_id AND p.activo = 1
             LEFT JOIN precio pr    ON pr.producto_id = p.id AND pr.lista_precio_id = ?
             LEFT JOIN categoria c  ON c.id = p.categoria_id
             LEFT JOIN marca m      ON m.id = p.marca_id
             LEFT JOIN inventario i ON i.producto_id = p.id
             WHERE f.cliente_id = ?
             ORDER BY f.creado_en DESC"
        );
        $st->execute([$listaId, $clienteId]);
        return $st->fetchAll();
    }
}
