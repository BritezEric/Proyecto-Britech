<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio de productos: el UNICO lugar que habla con la tabla producto.
 * Toda consulta SQL de productos vive aca.
 */
class ProductoRepository
{
    public function listar(): array
    {
        $pdo = Database::conexion();

        $sql = "SELECT p.id,
                       p.sku,
                       p.codigo_barras,
                       p.nombre,
                       p.es_sobre_pedido,
                       COALESCE(i.cantidad, 0) AS stock
                FROM producto p
                LEFT JOIN inventario i ON i.producto_id = p.id
                WHERE p.activo = 1
                ORDER BY p.nombre";

        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Busca productos por codigo de barras (exacto) o por nombre/SKU (parecido).
     * Trae el precio segun la lista del cliente y el stock actual.
     *
     * @param string $q     lo que se busca o se escaneo
     * @param int    $listaId  lista de precio del cliente (1 = minorista por defecto)
     */
    public function buscar(string $q, int $listaId): array
    {
        $pdo = Database::conexion();

        $sql = "SELECT p.id,
                       p.sku,
                       p.codigo_barras,
                       p.nombre,
                       p.es_sobre_pedido,
                       COALESCE(i.cantidad, 0) AS stock,
                       pr.precio
                FROM producto p
                LEFT JOIN inventario i ON i.producto_id = p.id
                LEFT JOIN precio pr    ON pr.producto_id = p.id
                                      AND pr.lista_precio_id = :lista
                WHERE p.activo = 1
                  AND (p.codigo_barras = :cb OR p.nombre LIKE :nom OR p.sku LIKE :sku)
                ORDER BY (p.codigo_barras = :cb2) DESC, p.nombre
                LIMIT 20";

        // prepare + execute = prepared statement: los datos van aparte del SQL,
        // asi es imposible inyectar codigo (anti SQL injection).
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'lista' => $listaId,
            'cb'    => $q,          // codigo de barras exacto
            'cb2'   => $q,          // mismo valor, para ordenar el exacto primero
            'nom'   => "%{$q}%",    // nombre parecido
            'sku'   => "%{$q}%",    // sku parecido
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Devuelve el precio de varios productos para una lista dada.
     * Se usa cuando cambia el cliente en el POS: hay que recalcular los precios
     * del carrito según su lista (minorista/mayorista).
     *
     * @param int[] $ids  ids de productos
     */
    public function preciosDe(array $ids, int $listaId): array
    {
        if ($ids === []) {
            return [];
        }

        $pdo = Database::conexion();

        // Arma tantos "?" como ids haya: IN (?, ?, ?)
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT producto_id, precio
                FROM precio
                WHERE lista_precio_id = ?
                  AND producto_id IN ($marcadores)";

        $stmt = $pdo->prepare($sql);
        // Primero la lista, después los ids (en el mismo orden que los "?").
        $stmt->execute(array_merge([$listaId], $ids));

        return $stmt->fetchAll();
    }

    /**
     * Trae los datos necesarios para VENDER varios productos: precio (según la
     * lista del cliente), stock, si es sobre pedido y si está activo.
     * Devuelve un array indexado por id de producto, para buscarlo fácil.
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    public function paraVenta(array $ids, int $listaId): array
    {
        if ($ids === []) {
            return [];
        }

        $pdo = Database::conexion();
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT p.id,
                       p.nombre,
                       p.activo,
                       p.es_sobre_pedido,
                       COALESCE(i.cantidad, 0) AS stock,
                       pr.precio
                FROM producto p
                LEFT JOIN inventario i ON i.producto_id = p.id
                LEFT JOIN precio pr    ON pr.producto_id = p.id
                                      AND pr.lista_precio_id = ?
                WHERE p.id IN ($marcadores)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$listaId], $ids));

        // Reindexar por id: [1 => {...}, 2 => {...}]
        $porId = [];
        foreach ($stmt->fetchAll() as $fila) {
            $porId[(int) $fila['id']] = $fila;
        }
        return $porId;
    }
}
