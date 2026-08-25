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
                       p.min_mayorista,
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

    // ===== ABM (panel admin) =====

    /** @return array{rows: array, total: int} */
    public function listarPaginado(?string $q, array $filtros, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where  = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $where[] = "(p.nombre LIKE ? OR p.sku LIKE ? OR p.codigo_barras LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like, $like);
        }
        foreach (['activo' => 'p.activo', 'categoria_id' => 'p.categoria_id',
                  'marca_id' => 'p.marca_id', 'proveedor_id' => 'p.proveedor_id'] as $k => $col) {
            if (($filtros[$k] ?? null) !== null) { $where[] = "$col = ?"; $params[] = $filtros[$k]; }
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM producto p $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT p.id, p.sku, p.codigo_barras, p.nombre, p.es_sobre_pedido, p.activo,
                       c.nombre AS categoria, m.nombre AS marca, pv.nombre AS proveedor,
                       COALESCE(i.cantidad, 0) AS stock,
                       pr.precio AS precio_minorista
                FROM producto p
                LEFT JOIN categoria  c  ON c.id  = p.categoria_id
                LEFT JOIN marca      m  ON m.id  = p.marca_id
                LEFT JOIN proveedor  pv ON pv.id = p.proveedor_id
                LEFT JOIN inventario i  ON i.producto_id = p.id
                LEFT JOIN precio pr      ON pr.producto_id = p.id AND pr.lista_precio_id = 1
                $sqlWhere
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Producto completo para el formulario de edición (con stock y precios). */
    public function buscarCompleto(int $id): ?array
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare("SELECT * FROM producto WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) return null;

        $p['stock'] = (new InventarioRepository())->cantidadDe($id);
        $p['imagenes'] = implode("\n", $this->imagenesDe($id));   // para el textarea del ABM
        $st = $pdo->prepare("SELECT lista_precio_id, precio FROM precio WHERE producto_id = ?");
        $st->execute([$id]);
        $p['precio_minorista'] = null;
        $p['precio_mayorista'] = null;
        foreach ($st->fetchAll() as $row) {
            if ((int) $row['lista_precio_id'] === 1) $p['precio_minorista'] = $row['precio'];
            if ((int) $row['lista_precio_id'] === 2) $p['precio_mayorista'] = $row['precio'];
        }
        return $p;
    }

    public function skuOcodigoEnUso(?string $sku, ?string $codigo, int $exceptoId = 0): bool
    {
        if ($sku === null && $codigo === null) return false;
        $st = Database::conexion()->prepare(
            "SELECT COUNT(*) FROM producto
             WHERE id <> ? AND ((sku IS NOT NULL AND sku = ?) OR (codigo_barras IS NOT NULL AND codigo_barras = ?))"
        );
        $st->execute([$exceptoId, $sku, $codigo]);
        return (int) $st->fetchColumn() > 0;
    }

    public function crear(array $d): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO producto
                       (sku, codigo_barras, nombre, descripcion, categoria_id, marca_id,
                        proveedor_id, es_sobre_pedido, min_mayorista, precio_anterior, activo)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$d['sku'], $d['codigo_barras'], $d['nombre'], $d['descripcion'],
                       $d['categoria_id'], $d['marca_id'], $d['proveedor_id'],
                       $d['es_sobre_pedido'], $d['min_mayorista'], $d['precio_anterior'], $d['activo']]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        Database::conexion()
            ->prepare("UPDATE producto SET sku = ?, codigo_barras = ?, nombre = ?, descripcion = ?,
                       categoria_id = ?, marca_id = ?, proveedor_id = ?, es_sobre_pedido = ?,
                       min_mayorista = ?, precio_anterior = ?, activo = ?
                       WHERE id = ?")
            ->execute([$d['sku'], $d['codigo_barras'], $d['nombre'], $d['descripcion'],
                       $d['categoria_id'], $d['marca_id'], $d['proveedor_id'],
                       $d['es_sobre_pedido'], $d['min_mayorista'], $d['precio_anterior'], $d['activo'], $id]);
    }

    // ===== Catálogo de la tienda online =====

    /**
     * Productos activos para la tienda, con precio según la lista del cliente.
     * Solo muestra productos con precio cargado. Paginado + búsqueda + filtro por categoría.
     * @return array{rows: array, total: int}
     */
    public function catalogo(?string $q, ?int $categoriaId, int $listaId, int $limit, int $offset,
        ?float $precioMin = null, ?float $precioMax = null, ?string $orden = null): array
    {
        $pdo = Database::conexion();
        $where  = ['p.activo = 1', 'pr.precio IS NOT NULL'];
        $params = [$listaId];   // primer ? es el de la lista en el JOIN
        if ($q !== null && $q !== '') {
            $where[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
            $like = "%{$q}%"; array_push($params, $like, $like);
        }
        if ($categoriaId !== null) { $where[] = "p.categoria_id = ?"; $params[] = $categoriaId; }
        if ($precioMin !== null) { $where[] = "pr.precio >= ?"; $params[] = $precioMin; }
        if ($precioMax !== null) { $where[] = "pr.precio <= ?"; $params[] = $precioMax; }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);

        // Orden (lista blanca: nunca interpolar entrada del usuario en el SQL).
        $orderBy = [
            'precio_asc'  => 'pr.precio ASC',
            'precio_desc' => 'pr.precio DESC',
            'nombre'      => 'p.nombre ASC',
        ][$orden] ?? 'p.nombre ASC';

        $baseFrom = "FROM producto p
                     LEFT JOIN precio pr ON pr.producto_id = p.id AND pr.lista_precio_id = ?
                     LEFT JOIN categoria c ON c.id = p.categoria_id
                     LEFT JOIN marca m ON m.id = p.marca_id
                     LEFT JOIN inventario i ON i.producto_id = p.id";

        // COUNT: mismos JOIN/WHERE (la lista va primero también)
        $stC = $pdo->prepare("SELECT COUNT(*) $baseFrom $sqlWhere");
        foreach ($params as $i => $v) $stC->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $stC->execute();
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT p.id, p.sku, p.nombre, p.descripcion, p.es_sobre_pedido, p.min_mayorista,
                       c.nombre AS categoria, m.nombre AS marca,
                       pr.precio, COALESCE(i.cantidad, 0) AS stock,
                       (SELECT url FROM producto_imagen pi WHERE pi.producto_id = p.id
                        ORDER BY pi.orden, pi.id LIMIT 1) AS imagen
                $baseFrom $sqlWhere
                ORDER BY $orderBy
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /**
     * Productos para un bloque de la home (carrusel/grid), con precio según la
     * lista, imagen principal, precio anterior (ofertas) y stock.
     * @param int|null $categoriaId  null = de cualquier categoría
     * @param bool     $soloOfertas  true = solo con precio_anterior (ofertas)
     */
    public function porCategoria(?int $categoriaId, int $listaId, int $limite, bool $soloOfertas = false): array
    {
        $where = ['p.activo = 1', 'pr.precio IS NOT NULL'];
        $params = [$listaId];
        if ($categoriaId !== null) { $where[] = 'p.categoria_id = ?'; $params[] = $categoriaId; }
        if ($soloOfertas)          { $where[] = 'p.precio_anterior IS NOT NULL AND p.precio_anterior > pr.precio'; }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT p.id, p.sku, p.nombre, p.descripcion, p.es_sobre_pedido, p.min_mayorista, p.precio_anterior,
                       c.nombre AS categoria, m.nombre AS marca, pr.precio, COALESCE(i.cantidad,0) AS stock,
                       (SELECT url FROM producto_imagen pi WHERE pi.producto_id = p.id ORDER BY pi.orden, pi.id LIMIT 1) AS imagen
                FROM producto p
                LEFT JOIN precio pr    ON pr.producto_id = p.id AND pr.lista_precio_id = ?
                LEFT JOIN categoria c  ON c.id = p.categoria_id
                LEFT JOIN marca m      ON m.id = p.marca_id
                LEFT JOIN inventario i ON i.producto_id = p.id
                $sqlWhere
                ORDER BY p.id DESC
                LIMIT ?";
        $st = Database::conexion()->prepare($sql);
        $full = array_merge($params, [$limite]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return $st->fetchAll();
    }

    /** Fija (crea o actualiza) el precio de un producto para una lista. */
    public function fijarPrecio(int $productoId, int $listaId, float $precio): void
    {
        Database::conexion()
            ->prepare("INSERT INTO precio (producto_id, lista_precio_id, precio)
                       VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE precio = VALUES(precio)")
            ->execute([$productoId, $listaId, $precio]);
    }

    // ===== Imágenes =====

    /** URLs de las imágenes de un producto, en orden. */
    public function imagenesDe(int $productoId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT url FROM producto_imagen WHERE producto_id = ? ORDER BY orden, id"
        );
        $st->execute([$productoId]);
        return array_column($st->fetchAll(), 'url');
    }

    /** Reemplaza todas las imágenes del producto por la lista dada (en el ABM). */
    public function reemplazarImagenes(int $productoId, array $urls): void
    {
        $pdo = Database::conexion();
        $pdo->prepare("DELETE FROM producto_imagen WHERE producto_id = ?")->execute([$productoId]);
        $ins = $pdo->prepare("INSERT INTO producto_imagen (producto_id, url, orden) VALUES (?, ?, ?)");
        foreach (array_values($urls) as $i => $url) {
            $ins->execute([$productoId, $url, $i]);
        }
    }

    /** Detalle completo para la ficha de la tienda (precio según lista + imágenes). */
    public function detalleTienda(int $productoId, int $listaId): ?array
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare(
            "SELECT p.id, p.nombre, p.descripcion, p.es_sobre_pedido, p.min_mayorista,
                    p.sku, p.codigo_barras, p.precio_anterior, p.categoria_id,
                    c.nombre AS categoria, m.nombre AS marca,
                    COALESCE(i.cantidad,0) AS stock, pr.precio
             FROM producto p
             LEFT JOIN categoria c ON c.id = p.categoria_id
             LEFT JOIN marca m ON m.id = p.marca_id
             LEFT JOIN inventario i ON i.producto_id = p.id
             LEFT JOIN precio pr ON pr.producto_id = p.id AND pr.lista_precio_id = ?
             WHERE p.id = ? AND p.activo = 1"
        );
        $st->execute([$listaId, $productoId]);
        $p = $st->fetch();
        if (!$p) return null;
        $p['imagenes'] = $this->imagenesDe($productoId);
        return $p;
    }
}
